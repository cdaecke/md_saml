<?php
defined('TYPO3') or die();

call_user_func(function () {
    /**
     * Register the auth service
     */
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addService(
        'md_saml',
        'auth',
        \Mediadreams\MdSaml\Authentication\SamlAuthService::class,
        [
            'title' => 'BE ADFS Authentication',
            'description' => 'Authentication with a Microsoft ADFS',
            'subtype' => 'authUserFE, getUserFE, authUserBE, getUserBE',
            'available' => true,
            'priority' => 80,
            'quality' => 80,
            'os' => '',
            'exec' => '',
            'className' => \Mediadreams\MdSaml\Authentication\SamlAuthService::class
        ]
    );

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders'][1648123062] = [
        'provider' => \Mediadreams\MdSaml\LoginProvider\SamlLoginProvider::class,
        'sorting' => 50,
        'icon-class' => 'fa-sign-in',
        'label' => 'LLL:EXT:md_saml/Resources/Private/Language/locallang.xlf:login.md_saml'
    ];

});
