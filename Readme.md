# TYPO3 Extension `md_saml`
Single Sign-on extension for TYPO3. It enables you, to log into the TYPO3 backend by using an ADFS server as Identity Provider (IdP).
It is fully configurable by TypoScript.

## Requirements
- TYPO3 >= 10.4

## Installation
- Install the extension with the following composer command: `composer req mediadreams/md_saml`
- Include the static TypoScript of the extension

## Configuration
Configure all settings via TypoScript. Just copy file `ext:md_saml/Configuration/TypoScript/setup.typoscript` to your
own extension and modify according your needs.

As underlying SAML toolkit the library of OneLogin is used (no account with OneLogin is needed!).
See full [documentation](https://github.com/onelogin/php-saml) for details on the configuration.

In `LocalConfiguration.php` or `AdditionalConfiguration.php` the `['BE']['cookieSameSite']` must be set to `lax`:

    $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax'

## Todo
- Make root pageId for `SettingsService->getTypoScriptSetup()` configurable
- Add proper documentation

## Troubleshooting
If your login fails with the parameter `?commandLI=setCookie` (typo3/index.php?commandLI=setCookie), please make sure,
that you have set `$GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax'`.

# THANKS
Thanks a lot to all who make this outstanding TYPO3 project possible!

## Credits
- Thanks to the guys at OneLogin who provide the [SAML toolkit for PHP](https://github.com/onelogin/php-saml), which I use.
- Extension icon by [Font Awesome](https://fontawesome.com/icons/key?s=solid).
