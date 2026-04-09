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
use Psr\Log\LoggerInterface;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\ExpressionLanguage\Resolver;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SettingsService
 */
class SettingsService implements SingletonInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        protected EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * Return settings
     *
     * @param string $loginType Can be 'FE' or 'BE'
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    public function getSettings(string $loginType): array
    {
        $extSettings = [];

        $extSettings = $this->eventDispatcher->dispatch(
            new BeforeSettingsAreProcessedEvent($loginType, $extSettings)
        )->getSettings();

        if ($extSettings === []) {
            $extSettings = $this->getSamlConfig();
        }

        if ($extSettings === []) {
            $this->logger->error('No md_saml config found. Perhaps you did not include the site set `MdSaml base configuration (ext:md_saml)`.');
            return [];
        }

        // Merge settings according to given context (frontend or backend)
        $extSettings['saml'] = array_replace_recursive($extSettings['saml'], $extSettings[mb_strtolower($loginType) . '_users']['saml']);

        // Add base url
        $extSettings['saml']['baseurl'] = $extSettings['mdsamlSpBaseUrl'];
        $extSettings['saml']['sp']['entityId'] = $extSettings['saml']['baseurl'] . $extSettings['saml']['sp']['entityId'];
        $extSettings['saml']['sp']['assertionConsumerService']['url'] = $extSettings['saml']['baseurl'] . $extSettings['saml']['sp']['assertionConsumerService']['url'];
        $extSettings['saml']['sp']['singleLogoutService']['url'] = $extSettings['saml']['baseurl'] . $extSettings['saml']['sp']['singleLogoutService']['url'];

        // Strip SLO endpoints when the IdP does not support SLO.
        // This prevents settings validation errors in the onelogin library and
        // ensures that SP metadata does not advertise an SLO endpoint that can
        // never complete. The SLO middlewares rely on this being absent to skip
        // the SAML logout and fall back to standard TYPO3 session termination.
        $extSettings['saml'] = $this->stripSloEndpointsIfUnsupported($extSettings['saml']);

        // Resolve cert/key values that contain file paths instead of inline content.
        $extSettings['saml'] = $this->resolveCertValues($extSettings['saml']);

        return $this->eventDispatcher->dispatch(
            new AfterSettingsAreProcessedEvent($loginType, $extSettings)
        )->getSettings();
    }

    /**
     * Resolve certificate and key values that contain file paths.
     *
     * For each of the four cert/key settings (sp.x509cert, sp.privateKey,
     * sp.x509certNew, idp.x509cert) the value is tested against
     * GeneralUtility::getFileAbsFileName(). If the result is a readable file
     * the file content is loaded, PEM headers are stripped, and the raw
     * base64 string replaces the original value. Non-path values (inline
     * base64 content) are passed through unchanged.
     *
     * Supported path formats:
     *   - Absolute path:   /var/secrets/saml/sp.crt
     *   - Web-root rel.:   fileadmin/saml/sp.crt
     *   - EXT: path:       EXT:my_site/Resources/Private/Certs/sp.crt
     *
     * @param array<string, mixed> $samlSettings
     * @return array<string, mixed>
     */
    private function resolveCertValues(array $samlSettings): array
    {
        foreach (['sp.x509cert', 'sp.privateKey', 'sp.x509certNew', 'idp.x509cert'] as $dotPath) {
            [$section, $key] = explode('.', $dotPath);
            $value = (string)($samlSettings[$section][$key] ?? '');
            if ($value === '') {
                continue;
            }

            $absolutePath = GeneralUtility::getFileAbsFileName($value);
            if ($absolutePath === '' || !is_file($absolutePath) || !is_readable($absolutePath)) {
                // Not a resolvable path — treat as inline content, leave unchanged.
                continue;
            }

            $content = file_get_contents($absolutePath);
            if ($content === false) {
                $this->logger->error(
                    'md_saml: Could not read certificate file.',
                    ['path' => $value]
                );
                continue;
            }

            // Strip PEM headers/footers (-----BEGIN CERTIFICATE-----, etc.)
            // and all whitespace. The onelogin library expects raw base64.
            $content = preg_replace('/-----BEGIN[^-]+-----/', '', $content) ?? $content;
            $content = preg_replace('/-----END[^-]+-----/', '', $content) ?? $content;
            $samlSettings[$section][$key] = preg_replace('/\s+/', '', $content) ?? $content;
        }

        return $samlSettings;
    }

    /**
     * Remove SLO endpoints from the settings when the IdP does not support SLO.
     *
     * When idp.singleLogoutService.url is empty the IdP has no SLO endpoint.
     * Keeping the singleLogoutService keys in this case causes two problems:
     *
     *   1. The onelogin/php-saml Settings validator may reject the configuration
     *      if it finds inconsistent SLO data (URL absent but binding present).
     *   2. The SP metadata XML would advertise an SLO endpoint that can never
     *      complete, confusing IdP-side administrators.
     *
     * Removing both idp.singleLogoutService and sp.singleLogoutService signals
     * to the SLO middlewares (via the absent key) that they should skip SAML
     * logout and fall back to standard TYPO3 session termination.
     *
     * @param array<string, mixed> $samlSettings
     * @return array<string, mixed>
     */
    private function stripSloEndpointsIfUnsupported(array $samlSettings): array
    {
        if (($samlSettings['idp']['singleLogoutService']['url'] ?? '') !== '') {
            return $samlSettings;
        }

        unset($samlSettings['idp']['singleLogoutService']);
        unset($samlSettings['sp']['singleLogoutService']);

        return $samlSettings;
    }

    /**
     * Get SAML configuration
     *
     * @return array
     * @throws \RuntimeException
     */
    private function getSamlConfig(): array
    {
        // Strip the query string — prefix matching only needs scheme + host + path.
        $requestUrl = GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL');
        $stripped = strtok($requestUrl, '?');
        $requestUrlWithoutQuery = $stripped !== false ? $stripped : $requestUrl;

        $bestMatch = null;
        $bestMatchLength = -1;

        // Use longest-prefix matching so that sites sharing the same hostname
        // but differing only by sub-path (e.g. /sub-path-b vs /sub-path-c)
        // are resolved correctly. Without path-aware matching the first site
        // whose host matches would always win regardless of the actual path.
        /** @var Site $site */
        foreach (GeneralUtility::makeInstance(SiteFinder::class)->getAllSites() as $site) {
            // Normalise to a trailing slash so that /sub-path-b/ never
            // accidentally matches requests that start with /sub-path-bar/.
            $siteBase = rtrim((string)$site->getBase(), '/') . '/';
            if (str_starts_with($requestUrlWithoutQuery, $siteBase) && strlen($siteBase) > $bestMatchLength) {
                $bestMatch = $site;
                $bestMatchLength = strlen($siteBase);
            }

            foreach ($site->getLanguages() as $language) {
                $langBase = rtrim((string)$language->getBase(), '/') . '/';
                if (str_starts_with($requestUrlWithoutQuery, $langBase) && strlen($langBase) > $bestMatchLength) {
                    $bestMatch = $site;
                    $bestMatchLength = strlen($langBase);
                }
            }
        }

        if ($bestMatch !== null) {
            $settings = $bestMatch->getConfiguration()['settings']['md_saml'] ?? [];
            return $this->getConfigurationWithBaseVariants(
                $settings,
                $bestMatch->getConfiguration()['settings']['baseVariants'] ?? []
            );
        }

        throw new \RuntimeException('The site configuration could not be resolved.', 1648646492);
    }

    /**
     * Get SAML configuration with baseVariants
     *
     * @param array $mdSamlSettings
     * @param array|null $baseVariants
     * @return array
     */
    private function getConfigurationWithBaseVariants(array $mdSamlSettings, ?array $baseVariants): array
    {
        $overrideSettings = [];
        if ($baseVariants !== null && $baseVariants !== []) {
            $expressionLanguageResolver = GeneralUtility::makeInstance(
                Resolver::class,
                'site',
                ['applicationContext' => Environment::getContext()]
            );
            foreach ($baseVariants as $baseVariant) {
                try {
                    if ((bool)$expressionLanguageResolver->evaluate($baseVariant['condition'])) {
                        $overrideSettings = $baseVariant['md_saml'] ?? [];
                        break;
                    }

                    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
                } catch (SyntaxError) {
                    // Silently fail and do not evaluate.
                    // No logger here — Site is currently cached and serialized.
                }
            }
        }

        return array_replace_recursive($mdSamlSettings, $overrideSettings);
    }
}
