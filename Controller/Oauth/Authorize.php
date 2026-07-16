<?php

declare(strict_types=1);

namespace Yu\McpServer\Controller\Oauth;

use Magento\Backend\Model\Auth as BackendAuth;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Yu\McpServer\Model\Auth\LoginRateLimiter;
use Yu\McpServer\Model\Auth\TfaChallenge;
use Yu\McpServer\Model\Auth\TwoFactorService;
use Yu\McpServer\Model\Oauth\AuthCodeFactory;
use Yu\McpServer\Model\Oauth\AuthCodeRepository;
use Yu\McpServer\Model\Oauth\ClientRepository;

/**
 * Renders the MCP OAuth login form (GET) and, on valid credentials (POST), issues a
 * short-lived authorization code and redirects back to the client's redirect_uri.
 *
 * When Magento_TwoFactorAuth enforces an authenticator app for the admin, a second form
 * step asks for the TOTP code before the authorization code is issued — the vendor module's
 * own enforcement lives in an adminhtml-area predispatch observer that this frontend-area
 * flow never passes through, so the check is made explicitly here (see TwoFactorService).
 *
 * This is a dedicated HTML form, not a redirect into the standard Magento admin login
 * page/session; credentials are still validated through Magento's own admin auth service.
 *
 * The flow is deliberately session-free and does NOT use Magento's form_key: the vendor
 * PageCache module flushes the session form key mid-flow (FlushFormKey fires on
 * admin_user_authenticate_after, i.e. inside Auth::login()) and re-syncs it from the
 * visitor's storefront form_key cookie on every request (RegisterFormKeyFromCookie), so
 * the global CsrfValidator would reject the 2FA-code POST in any browser that has visited
 * the storefront — an endless login loop. CSRF here is covered without a form key: the
 * password step's effect is determined by the submitted credentials (nothing rides on the
 * victim's cookies), and the 2FA step is bound to the unguessable single-use tfa_challenge
 * token. OAuth-level request forgery is the client's state/PKCE responsibility.
 */
class Authorize implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    private const AUTH_CODE_TTL_SECONDS = 60;
    private const REQUIRED_CODE_CHALLENGE_METHOD = 'S256';

    public function __construct(
        private readonly Http $request,
        private readonly ResultFactory $resultFactory,
        private readonly BackendAuth $backendAuth,
        private readonly ClientRepository $clientRepository,
        private readonly AuthCodeRepository $authCodeRepository,
        private readonly AuthCodeFactory $authCodeFactory,
        private readonly LoginRateLimiter $rateLimiter,
        private readonly TwoFactorService $twoFactorService,
        private readonly TfaChallenge $tfaChallenge,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Never raises a CSRF exception — see the class-level comment for why the global
     * form_key validation can't be used on this flow.
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
     * Renders the login form on GET; validates credentials (and, when required, the 2FA
     * code) and issues an authorization code on POST.
     */
    public function execute(): ResultInterface
    {
        try {
            $params = $this->resolveAuthorizeParams();
        } catch (\RuntimeException $e) {
            return $this->htmlResult($this->escape($e->getMessage()), 400);
        }

        if ($this->request->isPost()) {
            return $this->request->getParam('tfa_challenge') !== null
                ? $this->handleTwoFactor($params)
                : $this->handleLogin($params);
        }

        return $this->renderLoginForm($params);
    }

    /**
     * Validates the incoming OAuth params against a registered client, so a tampered or
     * unknown redirect_uri is rejected outright rather than redirected to.
     *
     * @return array{client_id: string, redirect_uri: string, code_challenge: string,
     *     code_challenge_method: string, state: string}
     */
    private function resolveAuthorizeParams(): array
    {
        $clientId = (string)$this->request->getParam('client_id', '');
        $redirectUri = (string)$this->request->getParam('redirect_uri', '');
        $codeChallenge = (string)$this->request->getParam('code_challenge', '');
        $codeChallengeMethod = (string)$this->request->getParam('code_challenge_method', '');
        $state = (string)$this->request->getParam('state', '');

        // response_type belongs to the initial GET authorization request, not the login
        // form submission, so it isn't threaded through as a hidden field and is only
        // validated once, here, on the GET.
        if (!$this->request->isPost()) {
            $responseType = (string)$this->request->getParam('response_type', '');
            if ($responseType !== 'code') {
                throw new \RuntimeException('Unsupported response_type; only "code" is supported.');
            }
        }

        if ($clientId === '' || $redirectUri === '' || $codeChallenge === '') {
            throw new \RuntimeException(
                'Missing required parameter: client_id, redirect_uri, or code_challenge.'
            );
        }

        if ($codeChallengeMethod !== self::REQUIRED_CODE_CHALLENGE_METHOD) {
            throw new \RuntimeException('Unsupported code_challenge_method; only "S256" is supported.');
        }

        try {
            $client = $this->clientRepository->getById($clientId);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException('Unknown client_id.');
        }

        $allowedRedirectUris = array_filter(array_map('trim', explode("\n", (string)$client->getRedirectUris())));
        if (!in_array($redirectUri, $allowedRedirectUris, true)) {
            throw new \RuntimeException('redirect_uri does not match any URI registered for this client.');
        }

        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'state' => $state,
        ];
    }

    /**
     * Validates submitted credentials via Magento's own admin auth service, then either
     * issues the authorization code right away or, when 2FA is enforced for the admin,
     * challenges them for an authenticator-app code first.
     *
     * @param array{client_id: string, redirect_uri: string, code_challenge: string,
     *     code_challenge_method: string, state: string} $params
     */
    private function handleLogin(array $params): ResultInterface
    {
        $ipAddress = (string)$this->request->getClientIp();

        if ($this->rateLimiter->isBlocked($ipAddress)) {
            return $this->htmlResult('Too many login attempts. Please try again later.', 429);
        }

        $username = (string)$this->request->getParam('username', '');
        $password = (string)$this->request->getParam('password', '');

        try {
            $this->backendAuth->login($username, $password);
        } catch (AuthenticationException) {
            $this->rateLimiter->registerFailure($ipAddress);
            $this->logger->warning(sprintf('oauth/authorize: failed login attempt from %s', $ipAddress));

            return $this->renderLoginForm($params, 'Invalid username or password.');
        }

        $adminUser = $this->backendAuth->getUser();
        if ($adminUser === null || !$adminUser->getId()) {
            $this->rateLimiter->registerFailure($ipAddress);

            return $this->renderLoginForm($params, 'Invalid username or password.');
        }

        $this->rateLimiter->reset($ipAddress);
        $adminUserId = (int)$adminUser->getId();

        switch ($this->twoFactorService->check($adminUserId)) {
            case TwoFactorService::RESULT_REQUIRED:
                $this->logger->info(
                    sprintf('oauth/authorize: admin id %d passed password, awaiting 2FA code', $adminUserId)
                );

                return $this->renderTfaForm($params, $this->tfaChallenge->issue($adminUserId));
            case TwoFactorService::RESULT_NOT_CONFIGURED:
                $this->logger->warning(
                    sprintf('oauth/authorize: admin id %d has 2FA enforced but not configured', $adminUserId)
                );

                return $this->renderLoginForm(
                    $params,
                    'Two-factor authentication is required for this account but has not been set up yet. '
                    . 'Sign in to the Magento Admin panel first to configure your authenticator app, then try again.'
                );
            case TwoFactorService::RESULT_UNSUPPORTED:
                $this->logger->warning(
                    sprintf('oauth/authorize: admin id %d uses an unsupported 2FA provider', $adminUserId)
                );

                return $this->renderLoginForm(
                    $params,
                    'This account uses a two-factor method this sign-in form does not support. '
                    . 'Only authenticator-app (TOTP) codes are supported here.'
                );
        }

        $this->logger->info(sprintf('oauth/authorize: admin id %d authenticated', $adminUserId));

        return $this->issueAuthCode($params, $adminUserId);
    }

    /**
     * Verifies the submitted authenticator-app code against the pending challenge and, on
     * success, issues the authorization code the password step deferred.
     *
     * @param array{client_id: string, redirect_uri: string, code_challenge: string,
     *     code_challenge_method: string, state: string} $params
     */
    private function handleTwoFactor(array $params): ResultInterface
    {
        $ipAddress = (string)$this->request->getClientIp();

        if ($this->rateLimiter->isBlocked($ipAddress)) {
            return $this->htmlResult('Too many login attempts. Please try again later.', 429);
        }

        $challengeToken = (string)$this->request->getParam('tfa_challenge', '');
        $adminUserId = $this->tfaChallenge->getAdminUserId($challengeToken);
        if ($adminUserId === null) {
            return $this->renderLoginForm(
                $params,
                'Your sign-in attempt expired. Please enter your credentials again.'
            );
        }

        $tfaCode = trim((string)$this->request->getParam('tfa_code', ''));
        if (!$this->twoFactorService->verifyCode($adminUserId, $tfaCode)) {
            $this->tfaChallenge->registerFailure($challengeToken);
            $this->rateLimiter->registerFailure($ipAddress);
            $this->logger->warning(
                sprintf('oauth/authorize: invalid 2FA code for admin id %d from %s', $adminUserId, $ipAddress)
            );

            return $this->renderTfaForm($params, $challengeToken, 'Invalid verification code. Please try again.');
        }

        $this->tfaChallenge->redeem($challengeToken);
        $this->rateLimiter->reset($ipAddress);
        $this->logger->info(
            sprintf('oauth/authorize: admin id %d authenticated (password + 2FA)', $adminUserId)
        );

        return $this->issueAuthCode($params, $adminUserId);
    }

    /**
     * Issues a single-use authorization code bound to the admin user and the PKCE
     * challenge, and redirects the browser back to the client's redirect_uri.
     *
     * @param array{client_id: string, redirect_uri: string, code_challenge: string,
     *     code_challenge_method: string, state: string} $params
     */
    private function issueAuthCode(array $params, int $adminUserId): ResultInterface
    {
        $code = bin2hex(random_bytes(32));

        $authCode = $this->authCodeFactory->create();
        $authCode->setCode($code);
        $authCode->setClientId($params['client_id']);
        $authCode->setAdminUserId($adminUserId);
        $authCode->setCodeChallenge($params['code_challenge']);
        $authCode->setCodeChallengeMethod($params['code_challenge_method']);
        $authCode->setRedirectUri($params['redirect_uri']);
        $authCode->setExpiresAt(
            date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + self::AUTH_CODE_TTL_SECONDS)
        );
        $this->authCodeRepository->save($authCode);

        return $this->redirectWithCode($params['redirect_uri'], $code, $params['state']);
    }

    /**
     * Renders the minimal HTML username/password form.
     *
     * @param array{client_id: string, redirect_uri: string, code_challenge: string,
     *     code_challenge_method: string, state: string} $params
     */
    private function renderLoginForm(array $params, ?string $error = null): Raw
    {
        $fields = <<<HTML
<label for="username">Username</label>
<input type="text" id="username" name="username" autofocus autocomplete="username">
<label for="password">Password</label>
<input type="password" id="password" name="password" autocomplete="current-password">
<button type="submit">Sign in</button>
HTML;

        return $this->renderAuthPage(
            'Sign in to Magento MCP Server',
            'Sign in with your Magento admin username and password. '
            . 'The client will only be able to perform actions your admin role permissions allow.',
            $fields,
            $params,
            $error
        );
    }

    /**
     * Renders the second-step form asking for the authenticator-app (TOTP) code, carrying
     * the pending challenge token as a hidden field.
     *
     * @param array{client_id: string, redirect_uri: string, code_challenge: string,
     *     code_challenge_method: string, state: string} $params
     */
    private function renderTfaForm(array $params, string $challengeToken, ?string $error = null): Raw
    {
        $tokenField = sprintf(
            '<input type="hidden" name="tfa_challenge" value="%s">',
            $this->escape($challengeToken)
        );
        $fields = <<<HTML
{$tokenField}
<label for="tfa_code">Verification code</label>
<input type="text" id="tfa_code" name="tfa_code" inputmode="numeric" autocomplete="one-time-code" autofocus>
<button type="submit">Verify</button>
HTML;

        return $this->renderAuthPage(
            'Two-factor authentication',
            'Enter the code from your authenticator app to finish signing in.',
            $fields,
            $params,
            $error
        );
    }

    /**
     * Renders the shared single-page HTML shell around a form step, threading the OAuth
     * params through as hidden fields since this flow doesn't rely on a server-side
     * session between requests.
     *
     * @param array{client_id: string, redirect_uri: string, code_challenge: string,
     *     code_challenge_method: string, state: string} $params
     */
    private function renderAuthPage(
        string $heading,
        string $note,
        string $formFieldsHtml,
        array $params,
        ?string $error = null
    ): Raw {
        $hiddenFields = '';
        foreach (['client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state'] as $field) {
            $hiddenFields .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                $this->escape($field),
                $this->escape($params[$field])
            );
        }

        $errorHtml = $error !== null
            ? '<div class="error">' . $this->escape($error) . '</div>'
            : '';
        $hostHtml = $this->escape($this->request->getHttpHost());
        $headingHtml = $this->escape($heading);
        $noteHtml = $this->escape($note);

        // Styles are inline on purpose: the page is served outside the Magento theme and
        // must stay a single self-contained response with no static-asset dependencies.
        // The look intentionally mirrors the Magento admin login page (dark #373330
        // backdrop, square white panel, #007bdb focus, #eb5202 primary button): the user
        // is entering their real admin credentials here, and the familiar admin styling
        // is the trust signal that this is the legitimate place to do so.
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in to Magento MCP Server — {$hostHtml}</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 559.5 720.1'%3E%3Cpath fill='%23f26322' d='M279.8 0 0 161.6v323.2l79.9 46.2V207.7L279.7 92.4l199.9 115.3v323.2l79.9-46.1V161.5L279.8 0z'/%3E%3Cpath fill='%23f26322' d='m319.7 604.9-40 23.1-40-23.1V281.6l-79.9 46.1v323.2l119.9 69.2 120-69.2V327.7l-80-46.1v323.3z'/%3E%3C/svg%3E">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: "Open Sans", "Helvetica Neue", Helvetica, Arial, sans-serif;
    background: #373330; color: #41362f;
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh; padding: 24px;
}
.panel {
    background: #fff; width: 100%; max-width: 430px; padding: 40px;
    box-shadow: 0 0 8px rgba(0, 0, 0, .4);
}
.logo { display: block; width: 42px; margin: 0 auto 8px; }
h1 {
    font-size: 18px; font-weight: 600; text-align: center; color: #41362f;
    margin-bottom: 6px;
}
.sub { color: #777; font-size: 13px; text-align: center; margin-bottom: 12px; }
.note {
    color: #777; font-size: 13px; line-height: 1.5; text-align: center;
    margin-bottom: 28px;
}
.error {
    background: #fff8d6; border-left: 3px solid #e22626; color: #41362f;
    font-size: 13px; padding: 10px 12px; margin-bottom: 20px;
}
label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 5px; }
input[type=text], input[type=password] {
    width: 100%; font-size: 15px; padding: 9px 10px; margin-bottom: 20px;
    border: 1px solid #adadad; border-radius: 1px; background: #fff; color: #41362f;
}
input[type=text]:focus, input[type=password]:focus {
    outline: none; border-color: #007bdb; box-shadow: 0 0 0 1px #007bdb;
}
button {
    display: block; margin-left: auto; font-size: 15px; font-weight: 600;
    padding: 10px 24px; border: 1px solid #eb5202; border-radius: 1px;
    background: #eb5202; color: #fff; cursor: pointer;
}
button:hover { background: #ba4000; border-color: #ba4000; }
</style>
</head>
<body>
<main class="panel">
<svg class="logo" viewBox="0 0 559.5 720.1" aria-hidden="true">
<path fill="#f26322" d="M279.8 0 0 161.6v323.2l79.9 46.2V207.7L279.7 92.4l199.9 115.3v323.2l79.9-46.1V161.5L279.8 0z"/>
<path fill="#f26322" d="m319.7 604.9-40 23.1-40-23.1V281.6l-79.9 46.1v323.2l119.9 69.2 120-69.2V327.7l-80-46.1v323.3z"/>
</svg>
<h1>{$headingHtml}</h1>
<p class="sub">An MCP client is requesting access to your store {$hostHtml}.</p>
<p class="note">{$noteHtml}</p>
{$errorHtml}
<form method="post">
{$hiddenFields}
{$formFieldsHtml}
</form>
</main>
</body>
</html>
HTML;

        return $this->htmlResult($html, 200);
    }

    /**
     * Escapes a value for safe interpolation into the HTML form.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Redirects the browser back to the client with the freshly issued authorization code.
     */
    private function redirectWithCode(string $redirectUri, string $code, string $state): Raw
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $separator . http_build_query(array_filter([
            'code' => $code,
            'state' => $state !== '' ? $state : null,
        ]));

        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode(302);
        $result->setHeader('Location', $location);
        $result->setContents('');

        return $result;
    }

    /**
     * Builds a plain HTML response with the given status code.
     */
    private function htmlResult(string $content, int $httpCode): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode($httpCode);
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8');
        $result->setContents($content);

        return $result;
    }
}
