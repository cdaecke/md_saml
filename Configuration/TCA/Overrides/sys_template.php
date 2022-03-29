<?php
defined('TYPO3_MODE') || die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'md_saml',
    'Configuration/TypoScript',
    'Single Sign-on with SAML'
);
