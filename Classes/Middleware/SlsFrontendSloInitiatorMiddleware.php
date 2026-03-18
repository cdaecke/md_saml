<?php

declare(strict_types=1);

/*
 * This file is part of the Extension "md_saml" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022 Christoph Daecke <typo3@mediadreams.org>
 */

namespace Mediadreams\MdSaml\Middleware;

use Mediadreams\MdSaml\Service\SettingsService;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SlsFrontendSloInitiatorMiddleware
 *
 * Intercepts felogin logout POST requests for SAML-authenticated frontend users
 * and initiates SP-initiated SLO by redirecting to the IdP. Must run before
 * typo3/cms-frontend/authentication because FrontendUserAuthenticator processes
 * logintype=logout (and calls logoff()) during start(), which happens before
 * request attributes like 'frontend.user' are available to subsequent middlewares.
 */
class SlsFrontendSloInitiatorMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected SettingsService $settingsService,
        protected readonly LoggerInterface $logger
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();

        // Only intercept logout requests; ignore SLO callbacks.
        // logintype=logout can arrive as a GET parameter or in the POST body.
        $logoutTriggered = ($queryParams['logintype'] ?? '') === 'logout'
            || (is_array($parsedBody) && ($parsedBody['logintype'] ?? '') === 'logout');

        if (!$logoutTriggered || isset($queryParams['sls'])) {
            return $handler->handle($request);
        }

        // Read the current FE session directly, before the authentication middleware
        // processes it. FrontendUserAuthenticator calls FrontendUserAuthentication::start()
        // which handles logintype=logout (logoff) — so we must act before that runs.
        $cookieName = trim((string)($GLOBALS['TYPO3_CONF_VARS']['FE']['cookieName'] ?? ''));
        if ($cookieName === '') {
            $cookieName = 'fe_typo_user';
        }

        $userSessionManager = UserSessionManager::create('FE');
        $session = $userSessionManager->createFromRequestOrAnonymous($request, $cookieName);

        if ($session->isAnonymous()) {
            return $handler->handle($request);
        }

        $userId = $session->getUserId();
        if ($userId === null) {
            return $handler->handle($request);
        }

        // Look up the fe_users record to check whether this is a SAML-authenticated user
        // and to retrieve the NameID / SessionIndex needed for the LogoutRequest.
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('fe_users');
        $user = $queryBuilder
            ->select('md_saml_source', 'md_saml_nameid', 'md_saml_session_index', 'md_saml_nameid_format')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($user === false || (int)$user['md_saml_source'] !== 1) {
            return $handler->handle($request);
        }

        $extSettings = $this->settingsService->getSettings('FE');
        if ($extSettings === []) {
            return $handler->handle($request);
        }

        try {
            $auth = new Auth($extSettings['saml']);
            // Pass NameID and session index so the IdP can identify the session
            // to terminate. Stored in fe_users at login because TYPO3 does not
            // use PHP sessions (where the library would normally keep this data).
            $sloUrl = $auth->logout(
                nameId: ($user['md_saml_nameid'] ?? '') ?: null,
                sessionIndex: ($user['md_saml_session_index'] ?? '') ?: null,
                nameIdFormat: $user['md_saml_nameid_format'] ?? '',
                stay: true,
            );

            if (is_string($sloUrl) && $sloUrl !== '') {
                // Store the referer as the post-logout redirect target so the user
                // lands back on the felogin page (now showing the login form).
                // Use a cookie because ADFS does not preserve custom RelayState.
                $referer = $request->getHeaderLine('Referer');
                $redirectAfter = ($referer !== '' && str_starts_with($referer, Utils::getSelfURLhost()))
                    ? $referer
                    : '/';

                $response = new RedirectResponse($sloUrl, 303);
                $response = $response->withAddedHeader(
                    'Set-Cookie',
                    'md_saml_slo_context=FE; Path=/; Max-Age=300; HttpOnly; SameSite=Lax'
                );
                return $response->withAddedHeader(
                    'Set-Cookie',
                    'md_saml_slo_redirect=' . urlencode($redirectAfter) . '; Path=/; Max-Age=300; HttpOnly; SameSite=Lax'
                );
            }
        } catch (Error $e) {
            $this->logger->error(
                'md_saml: Could not build SAML SLO redirect URL during FE logout. '
                . 'Is idp.singleLogoutService configured?',
                ['exception' => $e->getMessage()]
            );
        }

        return $handler->handle($request);
    }
}
