<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

ExtensionManagementUtility::addStaticFile(
    'md_saml',
    'Configuration/TypoScript',
    'Single Sign-on with SAML'
);
