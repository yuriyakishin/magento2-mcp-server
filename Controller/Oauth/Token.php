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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Yu\McpServer\Model\Oauth\AuthCodeRepository;
use Yu\McpServer\Model\Oauth\TokenFactory;
use Yu\McpServer\Model\Oauth\TokenRepository;

/**
 * OAuth 2.1 token endpoint: exchanges an authorization code (+ PKCE code_verifier) or a
 * refresh token for a fresh access/refresh token pair.
 *
 * This is a stateless API endpoint, not a form submission, so it is intentionally exempt
 * from Magento's form-key CSRF validation (same rationale as Controller/Index/Index.php).
 */
class Token implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const ACCESS_TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly Http $request,
        private readonly ResultFactory $resultFactory,
        private readonly AuthCodeRepository $authCodeRepository,
        private readonly TokenRepository $tokenRepository,
        private readonly TokenFactory $tokenFactory,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Never raises a CSRF exception — see the class-level note on why this endpoint is
     * exempt from form-key validation.
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
     * Dispatches to the authorization_code or refresh_token grant handler.
     */
    public function execute(): ResultInterface
    {
        $grantType = (string)$this->request->getParam('grant_type', '');

        return match ($grantType) {
            'authorization_code' => $this->exchangeAuthorizationCode(),
            'refresh_token' => $this->exchangeRefreshToken(),
            default => $this->oauthError(
                'unsupported_grant_type',
                'Only authorization_code and refresh_token grants are supported.'
            ),
        };
    }

    /**
     * Verifies the authorization code and its PKCE proof, then issues a fresh token pair.
     */
    private function exchangeAuthorizationCode(): ResultInterface
    {
        $code = (string)$this->request->getParam('code', '');
        $redirectUri = (string)$this->request->getParam('redirect_uri', '');
        $clientId = (string)$this->request->getParam('client_id', '');
        $codeVerifier = (string)$this->request->getParam('code_verifier', '');

        if ($code === '' || $redirectUri === '' || $clientId === '' || $codeVerifier === '') {
            return $this->oauthError(
                'invalid_request',
                'code, redirect_uri, client_id, and code_verifier are all required.'
            );
        }

        try {
            $authCode = $this->authCodeRepository->getByCode($code);
        } catch (NoSuchEntityException) {
            return $this->oauthError('invalid_grant', 'The authorization code is invalid.');
        }

        $now = $this->dateTime->gmtTimestamp();

        if ($authCode->getUsedAt() !== null
            || strtotime((string)$authCode->getExpiresAt()) <= $now
            || $authCode->getClientId() !== $clientId
            || $authCode->getRedirectUri() !== $redirectUri
        ) {
            return $this->oauthError('invalid_grant', 'The authorization code is invalid, expired, or already used.');
        }

        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string)$authCode->getCodeChallenge(), $expectedChallenge)) {
            return $this->oauthError('invalid_grant', 'The PKCE code_verifier does not match the code_challenge.');
        }

        $authCode->setUsedAt(date('Y-m-d H:i:s', $now));
        $this->authCodeRepository->save($authCode);

        return $this->issueTokenPair((int)$authCode->getAdminUserId(), $clientId);
    }

    /**
     * Rotates a refresh token for a fresh access/refresh token pair, revoking the old one.
     */
    private function exchangeRefreshToken(): ResultInterface
    {
        $refreshToken = (string)$this->request->getParam('refresh_token', '');
        $clientId = (string)$this->request->getParam('client_id', '');

        if ($refreshToken === '' || $clientId === '') {
            return $this->oauthError('invalid_request', 'refresh_token and client_id are both required.');
        }

        try {
            $token = $this->tokenRepository->getByRefreshToken($refreshToken);
        } catch (NoSuchEntityException) {
            return $this->oauthError('invalid_grant', 'The refresh token is invalid.');
        }

        if ($token->getRevokedAt() !== null || $token->getClientId() !== $clientId) {
            return $this->oauthError('invalid_grant', 'The refresh token is invalid or has been revoked.');
        }

        $token->setRevokedAt(date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp()));
        $this->tokenRepository->save($token);

        return $this->issueTokenPair((int)$token->getAdminUserId(), $clientId);
    }

    /**
     * Persists a new access/refresh token pair and renders the OAuth token response.
     */
    private function issueTokenPair(int $adminUserId, string $clientId): Json
    {
        $token = $this->tokenFactory->create();
        $token->setAccessToken(bin2hex(random_bytes(32)));
        $token->setRefreshToken(bin2hex(random_bytes(32)));
        $token->setClientId($clientId);
        $token->setAdminUserId($adminUserId);
        $token->setExpiresAt(
            date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + self::ACCESS_TOKEN_TTL_SECONDS)
        );
        $this->tokenRepository->save($token);

        return $this->jsonResult([
            'access_token' => $token->getAccessToken(),
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL_SECONDS,
            'refresh_token' => $token->getRefreshToken(),
        ], 200);
    }

    /**
     * Builds a standard OAuth error response body.
     */
    private function oauthError(string $error, string $description): Json
    {
        return $this->jsonResult([
            'error' => $error,
            'error_description' => $description,
        ], 400);
    }

    /**
     * @param array $data Response body to encode as JSON.
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
