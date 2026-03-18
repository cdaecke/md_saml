<?php

declare(strict_types=1);

namespace Mediadreams\MdSaml\Error;

use Mediadreams\MdSaml\Service\SettingsService;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Error\PageErrorHandler\PageErrorHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ForbiddenHandling implements PageErrorHandlerInterface
{
    protected SettingsService $settingsService;

    /**
     * PageContentErrorHandler constructor.
     * @throws \InvalidArgumentException
     */
    public function __construct(int $statusCode, array $configuration)
    {
        $this->settingsService = GeneralUtility::makeInstance(SettingsService::class);
    }

    /**
     * @param ServerRequestInterface $request
     * @param string $message
     * @param array $reasons
     * @return ResponseInterface
     * @throws Error
     */
    public function handlePageError(
        ServerRequestInterface $request,
        string $message,
        array $reasons = []
    ): ResponseInterface {
        $loginType = 'FE';
        $extSettings = $this->settingsService->getSettings($loginType);
        if (isset($extSettings['saml'])) {
            $auth = new Auth($extSettings['saml']);
            // stay=true prevents the library from calling exit() internally and
            // returns the IdP redirect URL instead, so we can issue a clean
            // PSR-7 RedirectResponse without disrupting the TYPO3 lifecycle.
            $loginUrl = $auth->login(stay: true);
            if (is_string($loginUrl) && $loginUrl !== '') {
                return new RedirectResponse($loginUrl, 302);
            }
        }

        // Fallback: SAML is not configured or login URL could not be built.
        return new RedirectResponse('/', 302);
    }
}
