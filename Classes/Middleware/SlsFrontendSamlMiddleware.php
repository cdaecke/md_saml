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

use OneLogin\Saml2\Error;
use OneLogin\Saml2\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Class SlsFrontendSamlMiddleware
 */
class SlsFrontendSamlMiddleware extends SlsSamlMiddleware
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
        $this->context = 'FE';

        // After a successful IdP callback (ACS), redirect to the original URL that was
        // passed as RelayState. felogin cannot do this itself because redirect_url is
        // not part of the IdP POST-back body — only SAMLResponse and RelayState are.
        // This middleware runs after FrontendUserAuthenticator, so the user is already
        // authenticated when we reach this point.
        if (isset($request->getQueryParams()['acs'])) {
            $response = $handler->handle($request);

            $parsedBody = $request->getParsedBody();
            $relayState = is_array($parsedBody) ? (string)($parsedBody['RelayState'] ?? '') : '';
            $feUser = $request->getAttribute('frontend.user');

            if (
                $relayState !== ''
                && $feUser instanceof FrontendUserAuthentication
                && $feUser->user !== null
                && str_starts_with($relayState, Utils::getSelfURLhost())
                && !str_starts_with($relayState, Utils::getSelfRoutedURLNoQuery())
            ) {
                return new RedirectResponse($relayState, 303);
            }

            return $response;
        }

        return parent::process($request, $handler);
    }

    protected function performLogoff(ServerRequestInterface $request): void
    {
        $context = GeneralUtility::makeInstance(Context::class);

        if ((bool)$context->getPropertyFromAspect('frontend.user', 'isLoggedIn')) {
            $feUser = $request->getAttribute('frontend.user');
            if ($feUser instanceof FrontendUserAuthentication) {
                $feUser->logoff();
            }
        }
    }
}
