<?php

declare(strict_types=1);

namespace Mediadreams\MdSaml\EventListener;

use Mediadreams\MdSaml\Service\SettingsService;
use OneLogin\Saml2\Auth;
use TYPO3\CMS\Core\Authentication\Event\AfterUserLoggedOutEvent;

use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SamlAfterUserLoggedOutEventListener
{
    public function __invoke(AfterUserLoggedOutEvent $event): void
    {
        // SSO Logout
        $settingsService = GeneralUtility::makeInstance(SettingsService::class);
        if ($settingsService->getInCharge()) {
            //      if ($settingsService->isFrontendLoginActive()) {
            $extSettings = $settingsService->getSettings('fe');
            $auth = new Auth($extSettings['saml']);
            $auth->logout();
        }
    }
}
