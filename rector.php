<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use Rector\Php74\Rector\LNumber\AddLiteralSeparatorToNumberRector;
use Rector\PostRector\Rector\NameImportingPostRector;
use Rector\Set\ValueObject\DowngradeLevelSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ClassMethod\AddArrayReturnDocTypeRector;
use Ssch\TYPO3Rector\Configuration\Typo3Option;
use Ssch\TYPO3Rector\FileProcessor\Composer\Rector\ExtensionComposerRector;
use Ssch\TYPO3Rector\FileProcessor\Composer\Rector\RemoveCmsPackageDirFromExtraComposerRector;
// not found with latest version of ssch/typo3-rector
//use Ssch\TYPO3Rector\FileProcessor\TypoScript\Rector\v10\v0\ExtbasePersistenceTypoScriptRector;
use Ssch\TYPO3Rector\FileProcessor\TypoScript\Rector\v9\v0\FileIncludeToImportStatementTypoScriptRector;
use Ssch\TYPO3Rector\CodeQuality\General\ExtEmConfRector;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

/**
 * TYPO3 >= v12
 * PHP >= 8.1
 *
 * Use rector:
 * composer require ssch/typo3-rector:^2.6 rector/rector:^1.2 --dev
 * 
 *
 */
return static function (RectorConfig $rectorConfig): void {

    // If you want to override the number of spaces for your typoscript files you can define it here, the default value is 4
    // $parameters = $rectorConfig->parameters();
    // $parameters->set(Typo3Option::TYPOSCRIPT_INDENT_SIZE, 2);

    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        LevelSetList::UP_TO_PHP_81,
        DowngradeLevelSetList::DOWN_TO_PHP_81,
        SetList::TYPE_DECLARATION,
        Typo3LevelSetList::UP_TO_TYPO3_12,
    ]);

    $rectorConfig->parallel(120, 5);

    // Register a single rule. Single rules don't load the main config file, therefore the config file needs to be loaded manually.
    // $rectorConfig->import(__DIR__ . '/vendor/ssch/typo3-rector/config/config.php');
    // $rectorConfig->rule(\Ssch\TYPO3Rector\Rector\v9\v0\InjectAnnotationRector::class);

    // To have a better analysis from phpstan, we teach it here some more things
    $rectorConfig->phpstanConfig(Typo3Option::PHPSTAN_FOR_RECTOR_PATH);

    // FQN classes are not imported by default. If you don't do it manually after every Rector run, enable it by:
    $rectorConfig->importNames();

    // Disable parallel otherwise non php file processing is not working i.e. typoscript or flexform
    $rectorConfig->disableParallel();

    // this will not import root namespace classes, like \DateTime or \Exception
    $rectorConfig->importShortClasses(false);

    // Define your target version which you want to support
    $rectorConfig->phpVersion(PhpVersion::PHP_81);

    // If you only want to process one/some TYPO3 extension(s), you can specify its path(s) here.
    // If you use the option --config change __DIR__ to getcwd()
    $rectorConfig->paths([
        getcwd() . '/',
    ]);

    // If you use the option --config change __DIR__ to getcwd()
    $rectorConfig->skip([
        getcwd() . '/**/Resources/',

        // @see https://github.com/sabbelasichon/typo3-rector/issues/2536
        getcwd() . '/**/Configuration/ExtensionBuilder/*',
        // We skip those directories on purpose as there might be node_modules or similar
        // that include typescript which would result in false positive processing
        getcwd() . '/**/Resources/**/node_modules/*',
        getcwd() . '/**/Resources/**/NodeModules/*',
        getcwd() . '/**/Resources/**/BowerComponents/*',
        getcwd() . '/**/Resources/**/bower_components/*',
        getcwd() . '/**/Resources/**/build/*',
        getcwd() . '/vendor/*',
        getcwd() . '/Build/*',
        getcwd() . '/public/*',
        getcwd() . '/.github/*',
        getcwd() . '/.Build/*',

        NameImportingPostRector::class => [
            'ext_localconf.php',
            'ext_emconf.php',
            'ext_tables.php',
            getcwd() . '/**/Configuration/*',
            getcwd() . '/**/Configuration/**/*.php',
        ],

        AddLiteralSeparatorToNumberRector::class,
    ]);

    // Optional non-php file functionalities:
    // @see https://github.com/sabbelasichon/typo3-rector/blob/main/docs/beyond_php_file_processors.md

    // Rewrite your extbase persistence class mapping from typoscript into php according to official docs.
    // This processor will create a summarized file with all of the typoscript rewrites combined into a single file.
    // The filename can be passed as argument, "Configuration_Extbase_Persistence_Classes.php" is default.

    // not found with latest version of ssch/typo3-rector
    /*
    $rectorConfig->ruleWithConfiguration(
        ExtbasePersistenceTypoScriptRector::class,
        [
            ExtbasePersistenceTypoScriptRector::FILENAME => 'Configuration_Extbase_Persistence_Classes.php',
        ]
    );
    */

    // Add some general TYPO3 rules
#    $rectorConfig->rule(ConvertImplicitVariablesToExplicitGlobalsRector::class);

    $rectorConfig->ruleWithConfiguration(
        ExtEmConfRector::class,
        [
            ExtEmConfRector::TYPO3_VERSION_CONSTRAINT => '12.4.0-12.4.99',
            ExtEmConfRector::ADDITIONAL_VALUES_TO_BE_REMOVED => [
                'dependencies',
                'conflicts',
                'suggests',
                'private',
                'download_password',
                'TYPO3_version',
                'PHP_version',
                'internal',
                'module',
                'loadOrder',
                'lockType',
                'shy',
                'priority',
                'modify_tables',
                'CGLcompliance',
                'CGLcompliance_note',
            ],
        ]
    );
};
