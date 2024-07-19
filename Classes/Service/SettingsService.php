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

namespace Mediadreams\MdSaml\Service;

use Mediadreams\MdSaml\Event\AfterSettingsAreProcessedEvent;
use Mediadreams\MdSaml\Event\BeforeSettingsAreProcessedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Class SettingsService
 */
class SettingsService implements SingletonInterface
{
    protected bool $inCharge = false;

    protected array $extSettings = [];

    protected EventDispatcherInterface $eventDispatcher;

    public function __construct()
    {
        $this->eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
    }

    public function getInCharge(): bool
    {
        return $this->inCharge;
    }

    public function setInCharge(bool $inCharge): void
    {
        $this->inCharge = $inCharge;
    }

    public function useFrontendAssertionConsumerServiceAuto(string $path): bool
    {
        $extSettings = $this->getSettings('fe');
        $auto = filter_var($extSettings['fe_users']['saml']['sp']['assertionConsumerService']['auto'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($auto && $this->isFrontendLoginActive()) {
            $assertionConsumerServiceUrl = $extSettings['fe_users']['saml']['sp']['assertionConsumerService']['url'] ?? '/';
            return $path === $assertionConsumerServiceUrl && $_POST['SAMLResponse'];
        }

        return false;
    }

    /**
     * Return settings
     *
     * @param string $loginType Can be 'FE' or 'BE'
     * @return array
     * @throws \RuntimeException
     */
    public function getSettings(string $loginType): array
    {
        $this->extSettings = $this->eventDispatcher->dispatch(
            new BeforeSettingsAreProcessedEvent($loginType, $this->extSettings)
        )->getSettings();

        if ($this->extSettings === []) {
            // Backend mode, no TSFE loaded
            if (!isset($GLOBALS['TSFE'])) {
                $typoScriptSetup = $this->getTypoScriptSetup($this->getRootPageId());
                $this->extSettings = $typoScriptSetup['plugin']['tx_mdsaml']['settings'] ?? [];
            } else {
                /** @var ConfigurationManager $configurationManager */
                $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
                $this->extSettings = $configurationManager->getConfiguration(
                    ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
                    'Mdsaml',
                    ''
                );
            }

            if ((is_countable($this->extSettings) ? count($this->extSettings) : 0) === 0) {
                throw new \RuntimeException('The TypoScript of ext:md_saml was not loaded.', 1648151884);
            }
        }

        // Merge settings according to given context (frontend or backend)
        $this->extSettings['saml'] = array_replace_recursive($this->extSettings['saml'], $this->extSettings[mb_strtolower($loginType) . '_users']['saml']);

        // Add base url
        $this->extSettings['saml']['baseurl'] = $this->extSettings['mdsamlSpBaseUrl'];
        $this->extSettings['saml']['sp']['entityId'] = $this->extSettings['saml']['baseurl'] . $this->extSettings['saml']['sp']['entityId'];
        $this->extSettings['saml']['sp']['assertionConsumerService']['url'] = $this->extSettings['saml']['baseurl'] . $this->extSettings['saml']['sp']['assertionConsumerService']['url'];
        $this->extSettings['saml']['sp']['singleLogoutService']['url'] = $this->extSettings['saml']['baseurl'] . $this->extSettings['saml']['sp']['singleLogoutService']['url'];

        $this->extSettings = $this->convertBooleans($this->extSettings);

        return $this->eventDispatcher->dispatch(
            new AfterSettingsAreProcessedEvent($loginType, $this->extSettings)
        )->getSettings();
    }

    /**
     * Get TypoScript setup
     *
     * @param int $pageId
     * @return array
     */
    private function getTypoScriptSetup(int $pageId): array
    {
        $template = GeneralUtility::makeInstance(TemplateService::class);
        $template->tt_track = false;
        $rootline = GeneralUtility::makeInstance(
            RootlineUtility::class,
            $pageId
        )->get();
        $template->runThroughTemplates($rootline, 0);
        $template->generateConfig();

        $typoScriptSetup = $template->setup;

        return GeneralUtility::removeDotsFromTS($typoScriptSetup);
    }

    /**
     * Get root page ID according to calling url
     *
     * @return int|null
     * @throws \RuntimeException
     */
    private function getRootPageId(): ?int
    {
        $siteUrl = GeneralUtility::getIndpEnv('TYPO3_HOST_ONLY');

        /** @var Site $site */
        foreach (GeneralUtility::makeInstance(SiteFinder::class)->getAllSites() as $site) {
            if ($site->getBase()->getHost() === $siteUrl) {
                return $site->getRootPageId();
            }

            /** @var SiteLanguage $language */
            foreach ($site->getLanguages() as $language) {
                if ($language->getBase()->getHost() == $siteUrl) {
                    return $site->getRootPageId();
                }
            }
        }

        throw new \RuntimeException('The site configuration could not be resolved.', 1648646492);
    }

    /**
     * Convert booleans to real booleans
     *
     * @param array $settings
     * @return array
     */
    private function convertBooleans(array $settings): array
    {
        array_walk_recursive(
            $settings,
            static function (&$value): void {
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                }
            }
        );

        return $settings;
    }

    public function isFrontendLoginActive(): bool
    {
        $extSettings = $this->getSettings('fe');
        return filter_var($extSettings['fe_users']['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
