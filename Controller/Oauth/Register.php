<?php

declare(strict_types=1);

namespace Yu\McpServer\Controller\Oauth;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Yu\McpServer\Model\Auth\ClientIpResolver;
use Yu\McpServer\Model\Auth\RegistrationRateLimiter;
use Yu\McpServer\Model\Oauth\ClientFactory;
use Yu\McpServer\Model\Oauth\ClientRepository;
use Yu\McpServer\Model\Oauth\RedirectUriPolicy;

/**
 * Dynamic Client Registration endpoint (RFC 7591), so an MCP client (e.g. claude.ai) can
 * register itself and receive a client_id instead of relying on a hardcoded one.
 *
 * Every registered client is treated as a public client: PKCE is the only
 * proof-of-possession mechanism, no client_secret is issued or checked.
 */
class Register implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_REDIRECT_URIS = 10;
    private const MAX_CLIENT_NAME_LENGTH = 255;

    public function __construct(
        private readonly Http $request,
        private readonly ResultFactory $resultFactory,
        private readonly ClientRepository $clientRepository,
        private readonly ClientFactory $clientFactory,
        private readonly RedirectUriPolicy $redirectUriPolicy,
        private readonly RegistrationRateLimiter $rateLimiter,
        private readonly ClientIpResolver $clientIpResolver
    ) {
    }

    /**
     * Never raises a CSRF exception — this is a stateless JSON API for OAuth clients,
     * not a form submission.
     *
     * @param RequestInterface $request Current HTTP request.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Always reports the request as CSRF-valid — see createCsrfValidationException().
     *
     * @param RequestInterface $request Current HTTP request.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Registers a new OAuth client from a JSON RFC 7591 registration request body.
     */
    public function execute(): ResultInterface
    {
        if (!$this->rateLimiter->registerAndCheck($this->clientIpResolver->resolve())) {
            $result = $this->registrationError('too_many_requests', 'Too many client registrations. Try again later.');
            $result->setHttpResponseCode(429);

            return $result;
        }

        $decoded = json_decode((string)$this->request->getContent(), true);

        if (!is_array($decoded)) {
            return $this->registrationError('invalid_client_metadata', 'Request body must be a JSON object.');
        }

        $redirectUris = $decoded['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || $redirectUris === [] || !array_is_list($redirectUris)) {
            return $this->registrationError(
                'invalid_redirect_uri',
                'redirect_uris must be a non-empty array of URIs.'
            );
        }
        if (count($redirectUris) > self::MAX_REDIRECT_URIS) {
            return $this->registrationError(
                'invalid_redirect_uri',
                sprintf('At most %d redirect_uris may be registered.', self::MAX_REDIRECT_URIS)
            );
        }

        foreach ($redirectUris as $uri) {
            $error = $this->redirectUriPolicy->getRegistrationError($uri);
            if ($error !== null) {
                return $this->registrationError('invalid_redirect_uri', $error);
            }
        }

        $clientName = $decoded['client_name'] ?? null;

        $client = $this->clientFactory->create();
        $client->setClientId(bin2hex(random_bytes(16)));
        $client->setClientName(
            is_string($clientName) && trim($clientName) !== ''
                ? mb_substr(trim($clientName), 0, self::MAX_CLIENT_NAME_LENGTH)
                : null
        );
        $client->setRedirectUris(implode("\n", $redirectUris));
        $this->clientRepository->save($client);

        return $this->jsonResult([
            'client_id' => $client->getClientId(),
            'client_name' => $client->getClientName(),
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ], 201);
    }

    /**
     * Builds a standard RFC 7591 registration error response body.
     *
     * @param string $error RFC 7591 error code (e.g. "invalid_client_metadata").
     * @param string $description Human-readable error description.
     */
    private function registrationError(string $error, string $description): Json
    {
        return $this->jsonResult([
            'error' => $error,
            'error_description' => $description,
        ], 400);
    }

    /**
     * @param array $data Response body to encode as JSON.
     * @param int $httpCode HTTP status code to set on the response.
     */
    private function jsonResult(array $data, int $httpCode): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode($httpCode);
        $result->setData($data);

        return $result;
    }
}
