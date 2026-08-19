<?php

declare(strict_types=1);

use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;
use TYPO3\CodingStandards\CsFixerConfig;

$config = CsFixerConfig::create();
$config->setParallelConfig(ParallelConfigFactory::detect());
$config->addRules([
    // Disabled because it conflicts with PHPCS (PEAR brace rules require
    // opening and closing braces on their own lines).
    'single_line_empty_body' => false,
]);
$config->getFinder()->in('Classes')->in('Configuration')->in('Tests');
return $config;
