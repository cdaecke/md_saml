<?php
defined('TYPO3') || die();

$subtype = '';

if (($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['md_saml']['activateBackendLogin'] ?? 0) == 1) {
    // Activate backend login
    $subtype = ',authUserBE,getUserBE';

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders'][1648123062] = [
        'provider' => \Mediadreams\MdSaml\LoginProvider\SamlLoginProvider::class,
        'sorting' => 50,
        'iconIdentifier' => 'actions-key',
        'label' => 'LLL:EXT:md_saml/Resources/Private/Language/locallang.xlf:login.md_saml',
    ];
}

// Exclude SAML-specific URL parameters from cHash validation so the ACS and SLO
// callback URLs (?acs, ?sls) and the login provider identifier (?loginProvider,
// ?login-provider) never trigger a "cHash empty" 404. These parameters carry no
// cacheable page content and must pass through to the authentication middleware.
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] = array_unique(
    array_merge(
        (array)($GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] ?? []),
        ['loginProvider', 'login-provider', 'login_status', 'logintype', 'acs', 'sls']
    )
);

/**
 * Register the auth service
 */
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addService(
    'md_saml',
    'auth',
    \Mediadreams\MdSaml\Authentication\SamlAuthService::class,
    [
        'title' => 'BE/FE ADFS Authentication',
        'description' => 'Authentication with a Microsoft ADFS',
        'subtype' => 'authUserFE,getUserFE' . $subtype,
        'available' => true,
        'priority' => 80,
        'quality' => 80,
        'os' => '',
        'exec' => '',
        'className' => \Mediadreams\MdSaml\Authentication\SamlAuthService::class,
    ]
);
