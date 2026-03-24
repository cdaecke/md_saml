<?php

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->addRules([
    // Disabled because it conflicts with PHPCS (PEAR brace rules require
    // opening and closing braces on their own lines).
    'single_line_empty_body' => false,
]);
$config->getFinder()->in('Classes')->in('Configuration');
return $config;
