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

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SlsBackendSamlMiddleware
 */
class SlsBackendSamlMiddleware extends SlsSamlMiddleware
{
    /**
     * Process request
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws Error
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context = 'BE';

        // SP-initiated SLO: intercept the standard TYPO3 v13 BE logout URL
        // (/typo3/logout?token=...) which no longer includes loginProvider/sls
        // parameters. We redirect to the IdP's SLO endpoint here, before the
        // route dispatcher runs, so we can return a clean RedirectResponse
        // without calling exit(). The actual TYPO3 session termination happens
        // when the IdP redirects back to the configured sp.singleLogoutService.url
        // and SlsSamlMiddleware::process() handles the SAMLResponse via processSLO().
        $routePath = (string)($request->getAttribute('route')?->getPath() ?? '');
        if (
            $routePath === '/logout'
            && !isset($request->getQueryParams()['sls'])
            && isset($GLOBALS['BE_USER']->user)
            && ($GLOBALS['BE_USER']->user['md_saml_source'] ?? 0) === 1
        ) {
            $backendConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('md_saml');

            if (($backendConfiguration['activateBackendLogin'] ?? '0') === '1') {
                $extSettings = $this->settingsService->getSettings('BE');

                if ($extSettings !== []) {
                    try {
                        $auth = new Auth($extSettings['saml']);
                        // $stay=true returns the IdP SLO redirect URL without calling exit().
                        $sloUrl = $auth->logout(stay: true);

                        if (is_string($sloUrl) && $sloUrl !== '') {
                            return new RedirectResponse($sloUrl, 303);
                        }
                    } catch (Error $e) {
                        $this->logger->error(
                            'md_saml: Could not build SAML SLO redirect URL during BE logout. '
                            . 'Is idp.singleLogoutService configured?',
                            ['exception' => $e->getMessage()]
                        );
                    }
                }
            }
        }

        return parent::process($request, $handler);
    }

    protected function performLogoff(ServerRequestInterface $request): void
    {
        if (isset($GLOBALS['BE_USER']->user)) {
            $GLOBALS['BE_USER']->logoff();
        }
    }
}
