<?php
declare(strict_types=1);

namespace Mediadreams\MdSaml\Service;

/**
 *
 * This file is part of the Extension "md_saml" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022 Christoph Daecke <typo3@mediadreams.org>
 *
 */

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Class SettingsService
 * @package Mediadreams\MdSaml\Service
 */
class SettingsService
{
    /**
     * Return settings
     *
     * @param string $loginType Can be 'FE' or 'BE'
     * @return array
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    public function getSettings(string $loginType): array
    {
        // Backend mode, no TSFE loaded
        if (!isset($GLOBALS['TSFE'])) {
            $typoScriptSetup = $this->getTypoScriptSetup($this->getRootPageId());
            $settings = $typoScriptSetup['plugin']['tx_mdsaml']['settings'];
        } else {
            /** @var ConfigurationManager $configurationManager */
            $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);

            $settings = $configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
                'Mdsaml',
                ''
            );
        }

        if (count($settings) == 0) {
            throw new \RuntimeException('The TypoScript of ext:md_saml was not loaded.', 1648151884);
        }

        // Merge settings according to given context (frontend or backend)
        $settings['saml'] = array_replace_recursive($settings['saml'], $settings[mb_strtolower($loginType). '_users']['saml']);

        // Add base url
        $settings['saml']['sp']['entityId'] = $settings['saml']['baseurl'] . $settings['saml']['sp']['entityId'];
        $settings['saml']['sp']['assertionConsumerService']['url'] = $settings['saml']['baseurl'] . $settings['saml']['sp']['assertionConsumerService']['url'];
        $settings['saml']['sp']['singleLogoutService']['url'] = $settings['saml']['baseurl'] . $settings['saml']['sp']['singleLogoutService']['url'];

        $settings = $this->convertBooleans($settings);

        return $settings;
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
            function (&$value) {
                if ($value === 'true') {
                    $value = true;
                } else {
                    if ($value === 'false') {
                        $value = false;
                    }
                }
            }
        );

        return $settings;
    }

    /**
     * Get root page ID according to calling url
     *
     * @return int|null
     */
    private function getRootPageId(): ?int
    {
        $siteUrl = GeneralUtility::getIndpEnv('TYPO3_HOST_ONLY');

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $allsites = $siteFinder->getAllSites();

        /** @var \TYPO3\CMS\Core\Site\Entity\Site $site */
        foreach ($allsites as $site) {
            if ($site->getBase()->getHost() == $siteUrl) {
                return $site->getRootPageId();
            }
        }

        throw new \RuntimeException('The site configuration could not be resolved.', 1648646492);
    }

    /**
     * Get TypoScript setup
     *
     * @param int $pageId
     * @return array
     */
    private function getTypoScriptSetup(int $pageId)
    {
        $template = GeneralUtility::makeInstance(TemplateService::class);
        $template->tt_track = false;
        $rootline = GeneralUtility::makeInstance(
            RootlineUtility::class, $pageId
        )->get();
        $template->runThroughTemplates($rootline, 0);
        $template->generateConfig();
        $typoScriptSetup = $template->setup;

        $typoScriptSetup = GeneralUtility::removeDotsFromTS($typoScriptSetup);

        return $typoScriptSetup;
    }
}
