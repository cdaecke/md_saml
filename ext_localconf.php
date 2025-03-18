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
