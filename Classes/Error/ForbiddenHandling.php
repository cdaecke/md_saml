<?php

namespace Mediadreams\MdSaml\Error;

use Mediadreams\MdSaml\Service\SettingsService;
use OneLogin\Saml2\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Error\PageErrorHandler\PageErrorHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ForbiddenHandling implements PageErrorHandlerInterface
{
    /**
     * @var SettingsService $settingsService
     */
    protected $settingsService;

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
     */
    public function handlePageError(
        ServerRequestInterface $request,
        string $message,
        array $reasons = []
    ): ResponseInterface {
        $loginType = 'FE';
        $extSettings = $this->settingsService->getSettings($loginType);
        $auth = new Auth($extSettings['saml']);
        $auth->login();
        // above code redirects
        return new RedirectResponse('/', 403);
    }
}
