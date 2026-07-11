<?php

declare(strict_types=1);

namespace Yu\McpServer\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use Yu\McpServer\Exception\UnauthorizedException;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\Auth\AdminTokenValidator;
use Yu\McpServer\Model\Auth\AnonymousRateLimiter;
use Yu\McpServer\Model\Config;
use Yu\McpServer\Model\JsonRpcHandler;

/**
 * POST /mcp is a stateless JSON-RPC API endpoint, not a form submission,
 * so it is intentionally exempt from Magento's form-key CSRF validation.
 *
 * Also implements HttpGetActionInterface. GET opens a minimal SSE stream (see
 * openSseStreamResult()) instead of the 405 the Streamable HTTP spec would technically allow:
 * Claude Desktop's own connector-setup flow probes with a plain GET before ever attempting
 * OAuth registration, and treats a non-2xx there as "not a valid MCP server" — aborting before
 * POST /mcp/oauth/register is ever called. A spec-compliant 405 is therefore not viable in
 * practice against this client. We don't implement real server-initiated push (no tool needs
 * it), so the stream carries no events and closes immediately.
 */
class Index implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    private const HTTP_OK = 200;
    private const HTTP_ACCEPTED = 202;
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_TOO_MANY_REQUESTS = 429;

    public function __construct(
        private readonly Http $request,
        private readonly ResultFactory $resultFactory,
        private readonly JsonRpcHandler $jsonRpcHandler,
        private readonly AdminTokenValidator $adminTokenValidator,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly AnonymousRateLimiter $anonymousRateLimiter
    ) {
    }

    /**
     * Never raises a CSRF exception — see the class-level note on why this endpoint
     * is exempt from form-key validation.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Always reports the request as CSRF-valid — see createCsrfValidationException().
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Resolves the caller's identity, parses the JSON-RPC body (single message or batch),
     * and dispatches it to JsonRpcHandler.
     */
    public function execute(): ResultInterface
    {
        if ($this->request->isGet()) {
            return $this->openSseStreamResult();
        }

        try {
            $adminContext = $this->resolveAdminContext();
        } catch (UnauthorizedException $e) {
            return $this->unauthorizedResult($e->getMessage());
        }

        // Anonymous traffic is throttled per IP; authenticated callers are exempt (their
        // damage potential is bounded by ACL, not volume). getClientIp() (proxy-aware,
        // reads X-Forwarded-For) instead of plain REMOTE_ADDR is essential here: behind
        // a reverse proxy, CDN or tunnel REMOTE_ADDR is the proxy's address for every
        // external request, which would put all visitors into one shared bucket.
        if ($adminContext === null) {
            $ipAddress = (string) $this->request->getClientIp();
            if (!$this->anonymousRateLimiter->registerAndCheck($ipAddress)) {
                $this->logger->warning(sprintf('Anonymous rate limit exceeded for IP %s', $ipAddress));

                return $this->tooManyRequestsResult();
            }
        }

        $rawContent = (string) $this->request->getContent();
        $this->logFullBody('Full request body', $rawContent);

        $decoded = json_decode($rawContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Received invalid JSON body on POST /mcp');

            return $this->jsonResult($this->parseErrorResponse(), self::HTTP_BAD_REQUEST);
        }

        if (!is_array($decoded) || $decoded === []) {
            $this->logger->warning('Received an empty or non-array JSON-RPC body on POST /mcp');

            return $this->jsonResult($this->invalidRequestResponse(), self::HTTP_BAD_REQUEST);
        }

        try {
            if (array_is_list($decoded)) {
                $responses = $this->jsonRpcHandler->handleBatch($decoded, $adminContext);
                if ($responses === []) {
                    return $this->emptyResult();
                }

                $this->logFullBody('Full response body', $responses);

                return $this->jsonResult($responses, self::HTTP_OK);
            }

            $response = $this->jsonRpcHandler->handle($decoded, $adminContext);
        } catch (UnauthorizedException $e) {
            // A restricted tool was called anonymously — same transport-level treatment as
            // an invalid bearer token, so OAuth-aware clients get the 401 they need to
            // trigger their login flow. See JsonRpcHandler::handle() for why this is thrown
            // rather than returned as a JSON-RPC error body.
            return $this->unauthorizedResult($e->getMessage());
        }

        if ($response === null) {
            return $this->emptyResult();
        }

        $this->logFullBody('Full response body', $response);

        return $this->jsonResult($response, self::HTTP_OK);
    }

    /**
     * Logs the given payload at debug level, but only when full request/response logging
     * is enabled in configuration (Stores > Configuration > Advanced > MCP Server) — see
     * Yu\McpServer\Model\Config. Disabled by default: payloads can contain personal data
     * (customer emails, order numbers) and must never be dumped in production by default.
     *
     * @param string|array $data Raw request body string, or a response array to JSON-encode.
     */
    private function logFullBody(string $label, string|array $data): void
    {
        if (!$this->config->isFullRequestLoggingEnabled()) {
            return;
        }

        $body = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $data;
        $this->logger->debug(sprintf('%s: %s', $label, $body));
    }

    /**
     * Resolves the `Authorization: Bearer <token>` header, if any, into an AdminContext.
     * A missing header means anonymous access; a present-but-invalid one is a hard failure.
     *
     * @throws UnauthorizedException if a header is present but the token is invalid.
     */
    private function resolveAdminContext(): ?AdminContext
    {
        $token = $this->getBearerToken();
        if ($token === null) {
            return null;
        }

        return $this->adminTokenValidator->validate($token);
    }

    /**
     * Extracts the bearer token from the Authorization header, or null if absent/malformed.
     */
    private function getBearerToken(): ?string
    {
        // Magento's Request::getHeader() already unwraps the header to its plain string
        // field value (or false if absent) — unlike the raw Laminas parent method, it does
        // not return a Header object here.
        $value = $this->request->getHeader('Authorization');
        if ($value === false || !str_starts_with($value, 'Bearer ')) {
            return null;
        }

        return substr($value, strlen('Bearer '));
    }

    /**
     * @param array $data JSON-RPC response body (or batch of responses) to encode as JSON.
     */
    private function jsonResult(array $data, int $httpCode): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode($httpCode);
        $result->setData($data);

        return $result;
    }

    /**
     * Answers GET /mcp with a `200` SSE stream that carries no events and closes immediately.
     * See the class-level note for why this exists instead of a spec-permitted 405.
     */
    private function openSseStreamResult(): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode(self::HTTP_OK);
        $result->setHeader('Content-Type', 'text/event-stream');
        $result->setHeader('Cache-Control', 'no-cache');
        $result->setHeader('X-Accel-Buffering', 'no');
        $result->setContents(": connected\n\n");

        return $result;
    }

    /**
     * Builds the empty `202 Accepted` response sent for JSON-RPC notifications.
     */
    private function emptyResult(): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode(self::HTTP_ACCEPTED);
        $result->setContents('');

        return $result;
    }

    /**
     * Builds the `401 Unauthorized` response for an invalid/expired/revoked bearer token,
     * with a `WWW-Authenticate` header so OAuth-aware clients know to re-authenticate.
     *
     * The header carries `resource_metadata` (RFC 9728 §5.1) — the MCP Authorization spec
     * makes it mandatory, and it's what an OAuth-aware client (claude.ai) reads to find
     * the discovery document and start its login flow. Without it the 401 is a dead end.
     */
    private function unauthorizedResult(string $message): Json
    {
        $result = $this->jsonResult([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32001,
                'message' => $message,
            ],
        ], self::HTTP_UNAUTHORIZED);

        $resourceMetadataUrl = $this->request->getScheme() . '://' . $this->request->getHttpHost()
            . '/.well-known/oauth-protected-resource/mcp';

        $result->setHeader(
            'WWW-Authenticate',
            sprintf(
                'Bearer error="invalid_token", error_description="%s", resource_metadata="%s"',
                // Double quotes inside a quoted-string break RFC 7235 header parsing —
                // and the messages do contain them (e.g. Tool "x" requires authentication).
                str_replace('"', "'", $message),
                $resourceMetadataUrl
            )
        );

        return $result;
    }

    /**
     * Builds the `429 Too Many Requests` response for over-limit anonymous traffic,
     * with a `Retry-After` header so well-behaved clients know when to come back.
     */
    private function tooManyRequestsResult(): Json
    {
        $result = $this->jsonResult([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32000,
                'message' => 'Rate limit exceeded for anonymous requests. '
                    . 'Retry later or authenticate to lift the limit.',
            ],
        ], self::HTTP_TOO_MANY_REQUESTS);
        $result->setHeader('Retry-After', (string) $this->anonymousRateLimiter->retryAfterSeconds());

        return $result;
    }

    /**
     * @return array JSON-RPC "Parse error" response body.
     */
    private function parseErrorResponse(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32700,
                'message' => 'Parse error',
            ],
        ];
    }

    /**
     * @return array JSON-RPC "Invalid Request" response body.
     */
    private function invalidRequestResponse(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32600,
                'message' => 'Invalid Request',
            ],
        ];
    }
}
