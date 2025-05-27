# Version 4.0.3 (2025-05-27)

- [TASK] Allow array values in user array. The current functionality defaults to the first subvalue in case of array values. However, sometimes we need access to the entire array. For example if a user has multiple usergroups assigned in AD, and we want to implement a custom mapping via the provided EventListener. see https://github.com/cdaecke/md_saml/pull/66 Thanks to [kauz56](https://github.com/kauz56)!

All changes
https://github.com/cdaecke/md_saml/compare/4.0.2...4.0.3


# Version 4.0.2 (2025-05-12)

- [TASK] check, if site configuration exists. Thanks to [Georg Ringer](https://github.com/georgringer)!

All changes
https://github.com/cdaecke/md_saml/compare/4.0.1...4.0.2

# Version 4.0.1 (2025-05-05)

- [FEATURE] allow `baseVariants` in configuration. Thanks to [Bruno86](https://github.com/Bruno86)!

All changes
https://github.com/cdaecke/md_saml/compare/4.0.0...4.0.1

# Version 4.0.0 (2025-04-04)

- [FEATURE] TYPO3 v13 compatibility

## Migration from v3 to v4
- Activation of backend login is done in the extension configuration, which can be found 
in the TYPO3 backend in `Settings -> Extension Configuration -> md_saml`. Please set 
checkbox according to your needs!
- Remove the Typoscript constants of `ext:md_saml` from your configuration.
- Include the Site Set `MdSaml base configuration (ext:md_saml)` in the Site Configuration
  of your website.
- Add custom Site Set in your site package as shown below:

The following example shows, how to modify the default configuration of `ext:md_saml`:

EXT:my_extension/Configuration/Sets/MySet/config.yaml:

    name: my_extension/md_saml
    label: MdSaml config for my website
    dependencies:
      - mediadreams/md_saml

EXT:my_extension/Configuration/Sets/MySet/settings.yaml:

    md_saml:
      mdsamlSpBaseUrl: 'https://%env(BASE_DOMAIN)%'

      be_users:
        databaseDefaults:
          usergroup: 3
          lang: 'de'

      fe_users:
        saml:
          sp:
            entityId: '/login/?loginProvider=1648123062&mdsamlmetadata'
            assertionConsumerService:
              url: '/login/?loginProvider=1648123062&login-provider=md_saml&login_status=login&acs&logintype=login'

      saml:
        sp:
          x509cert: '%env(SAML_SP_X509CERT)%'
          privateKey: '%env(SAML_SP_PRIVATE_KEY)%'

        idp:
          entityId: 'https://auth.myprovider.de/adfs/services/trust'
          singleSignOnService:
            url: 'https://auth.myprovider.de/adfs/ls/'

          singleLogoutService:
            url: 'https://auth.myprovider.de/adfs/ls/'

          x509cert: '%env(SAML_IDP_X509CERT)%'

As you can see, you can use environment variables in your configuration in order
to configure different setups.

ATTENTION
Somehow, it is not possible to use environment variables in site sets at the moment. So if you want to use env vars, do it in the general site configuration in `<project-root>/config/sites/<identifier>/config.yaml`. Add following at the bottom of the config file:

    settings:
      md_saml:
        mdsamlSpBaseUrl: '%env(SAML_BASE_DOMAIN)%'

General information on site sets can be found
[here](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/SiteHandling/SiteSets.html).

All changes
https://github.com/cdaecke/md_saml/compare/3.0.7...4.0.0

# Version 3.0.7 (2025-03-17)
- [FEATURE] Add IdP-initiated logout with proper redirects. Big thanks [Jonas Wolf](https://github.com/jwtue)
- [TASK] Use contructor injection for Logger. Big thanks [Sybille](https://github.com/sypets)
- [TASK] Update rector, apply rector rules. Big thanks [Sybille](https://github.com/sypets)
- [TASK] Non-breaking empty configuration. Only continue if settings is initialized, log ERROR if not. Big thanks [Sybille](https://github.com/sypets)

All changes
https://github.com/cdaecke/md_saml/compare/3.0.6...3.0.7

# Version 3.0.6 (2025-02-18)
- [TASK] activate SSO for backend
- [TASK] Update SamlAuthService.php. Big thanks [AlexKvrlp](https://github.com/AlexKvrlp)
- [TASK] Create BeforeSettingsAreProcessedEvent, Create AfterSettingsAreProcessedEvent. Big thanks [AlexKvrlp](https://github.com/AlexKvrlp)
- [BUGFIX] Renamed SlsFrontendSamlMiddleware to SlsBackendSamlMiddleware. Big thanks [Christian Bülter](https://github.com/christianbltr)
- [BUGFIX] Renamed SlsFrontendSamlMiddleware to SlsBackendSamlMiddleware. Big thanks [Christian Bülter](https://github.com/christianbltr)
- [TASK] Handle unset 'login-provider' in SamlAuthService. Big thanks [Christian Bülter](https://github.com/christianbltr)
- [BUGFIX] Fix mismatching type. Big thanks to [Julian Hoffmann](https://github.com/julianhofmann)
- [TASK] make SAML-Metadata (optionally) also available without Typo3-BE-login. Big thanks to [Christoph Straßer](https://github.com/christophs78)

All changes
https://github.com/cdaecke/md_saml/compare/3.0.5...3.0.6

# Version 3.0.5 (2024-02-29)
- [BUGFIX] Add handling for mapping language(s) to different TLD(s) for BE login. Big thanks Matthias Vossen  (https://github.com/web-it-solutions)

All changes
https://github.com/cdaecke/md_saml/compare/3.0.4...3.0.5

# Version 3.0.4 (2024-02-28)
- [BUGFIX] Fix version range in ext_emconf.php

All changes
https://github.com/cdaecke/md_saml/compare/3.0.3...3.0.4

# Version 3.0.3 (2024-02-28)
- [BUGFIX] replace `actions/checkout@v3` with `actions/checkout@v4` in TER release script

All changes
https://github.com/cdaecke/md_saml/compare/3.0.2...3.0.3

# Version 3.0.2 (2024-02-28)
- [BUGFIX] add missing handling of requestToken for TYPO3 v12
- [FEATURE] Setup CGL/quality-tools & cleanup code(-style). Big thanks to Julian Hoffmann (https://github.com/julianhofmann)
- [BUGFIX] Migrate HttpUtility::redirect(). Big thanks to Julian Hoffmann (https://github.com/julianhofmann)

All changes
https://github.com/cdaecke/md_saml/compare/3.0.1...3.0.2

# Version 3.0.1 (2023-12-12)
- [BUGFIX] set ext settings correctly

All changes
https://github.com/cdaecke/md_saml/compare/3.0.0...3.0.1

# Version 3.0.0 (2023-12-08)
- Add support for TYPO2 v12 (Thanks to https://github.com/abplana)
- Add simple logout service (Thanks to https://github.com/abplana)

All changes
https://github.com/cdaecke/md_saml/compare/2.0.0...3.0.0

# Version 2.0.0 (2022-08-12)
[!!!][FEATURE] Add SAML frontend login

Attention: The release comes with breaking changes:
- Typoscript config `plugin.tx_mdsaml.settings.beUser` was renamed in `plugin.tx_mdsaml.settings.be_users`
- Typoscript config `plugin.tx_mdsaml.settings.fe_users` was introduced
- Typoscript constant `plugin.tx_mdsaml.settings.fe_users.active = 1` was introduced

All changes
https://github.com/cdaecke/md_saml/compare/1.0.7...2.0.0

# Version 1.0.7 (2022-05-06)
[BUGFIX] update github workflow for TER deployment: since XSD files of vendor onelogn are not accasible in the phar file, use vendor as normal folder

All changes
https://github.com/cdaecke/md_saml/compare/1.0.6...1.0.7

# Version 1.0.6 (2022-04-29)
[BUGFIX] update github workflow for TER deployment

All changes
https://github.com/cdaecke/md_saml/compare/1.0.5...1.0.6

# Version 1.0.5 (2022-04-14)
[BUGFIX] update path in `build:ter:vendors` scripts in composer.json

All changes
https://github.com/cdaecke/md_saml/compare/1.0.4...1.0.5

# Version 1.0.4 (2022-04-14)
[TASK] update `build:ter:vendors` scripts in composer.json, because `vendors.phar` is not deployed by Tylor (https://github.com/TYPO3/tailor/issues/53)

All changes
https://github.com/cdaecke/md_saml/compare/1.0.3...1.0.4

# Version 1.0.3 (2022-04-13)
[TASK] Fix github workflow to publish extension to TER

All changes
https://github.com/cdaecke/md_saml/compare/1.0.2...1.0.3

# Version 1.0.2 (2022-04-13)
[TASK] Fix github workflow to publish extension to TER

All changes
https://github.com/cdaecke/md_saml/compare/1.0.1...1.0.2

# Version 1.0.1 (2022-04-13)
[TASK] Fix github workflow to publish extension to TER

All changes
https://github.com/cdaecke/md_saml/compare/1.0.0...1.0.1

# Version 1.0.0 (2022-04-13)
Initial release
