# Version 3.0.2 (2023-02-28)
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
