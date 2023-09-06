# TYPO3 Extension `md_saml`
Single Sign-on extension for TYPO3. It enables you, to log into the TYPO3 backend or the website frontend by using an
Identity Provider (IdP), for example an ADFS server (Active Directory Federation Services). It is fully configurable by TypoScript.

## Screenshots
TYPO3 login:

<img src="./Documentation/Images/typo3_login.png?raw=true" alt="TYPO3 login" width="346" height="389" style="border:1px solid #999999" />

Frontend login:

<img src="./Documentation/Images/frontend_login.png?raw=true" alt="Frontend login" width="388" height="389" style="border:1px solid #999999" />

## Requirements
- TYPO3 v11.5 or v12.4

## Installation
- Install the extension with the following composer command: `composer req mediadreams/md_saml` or use the extension manager
- Include the static TypoScript of the extension
- Configure the extension by setting your own constants

## Configuration
### TypoScript

#### SAML
The Service Provider (SP) and Identity Provider (IdP) can be configured by adapting the settings in TypoScript.

- Copy file `ext:md_saml/Configuration/TypoScript/setup.typoscript` to your
own extension and modify according your needs.
- Generate a certificate for the Service Provider (SP)<br>
`openssl req -newkey rsa:3072 -new -x509 -days 3652 -nodes -out sp.crt -keyout sp.key`
- Open certificate files and remove all line breaks. Copy value of  `sp.crt` to
`plugin.tx_mdsaml.settings.saml.sp.x509cert` and value of `sp.key` to `plugin.tx_mdsaml.settings.saml.sp.privateKey`

**Backend**

- `plugin.tx_mdsaml.settings.be_users.saml.sp.entityId`<br>
Identifier of the backend (TYPO3) SP entity  (must be a URI)<br>
ATTENTION: `baseurl` will be attached automatically<br>
Default: `/typo3/index.php?loginProvider=1648123062&mdsamlmetadata`
- `plugin.tx_mdsaml.settings.be_users.saml.sp.assertionConsumerService.url`<br>
Specifies info about where and how the <AuthnResponse> message of a backend (TYPO3) login MUST be returned to the
requester, in this case our SP.<br>
Default: `/typo3/index.php?loginProvider=1648123062&login-provider=md_saml&login_status=login&acs`
- `saml.sp.assertionConsumerService.auto`<br>
If enabled, login is detected from url above (assertionConsumerService.url), the arguments "?loginProvider=1648123062&login-provider=md_saml&login_status=login&acs&logintype=login" re not needed.
If there is a login plugin on assertionConsumerService.url page and redirect method ''getpost'' is selected, redirection is done with given RelayState (should be the referrer)

**Frontend**

- `plugin.tx_mdsaml.settings.fe_users.saml.sp.entityId`<br>
Identifier of the frontend SP entity  (must be a URI)<br>
ATTENTION: `baseurl` will be attached automatically<br>
Example (just replace the speaking path ("/login/") according to your needs): `/login/?loginProvider=1648123062&mdsamlmetadata`
- `plugin.tx_mdsaml.settings.fe_users.saml.sp.assertionConsumerService.url`<br>
Specifies info about where and how the <AuthnResponse> message of a frontend login MUST be returned to the requester,
in this case our SP.<br>
Example (just replace the speaking path ("/login/") according to your needs): `/login/?loginProvider=1648123062&login-provider=md_saml&login_status=login&acs&logintype=login`

**Note**

All default settings, which are configured in `plugin.tx_mdsaml.settings.saml` can be overwritten for backend or
frontend needs with properties in `plugin.tx_mdsaml.settings.be_users.saml...` (backend) and
`plugin.tx_mdsaml.settings.fe_users.saml...` (frontend).

As underlying SAML toolkit the library of OneLogin is used (no account with OneLogin is needed!).
See full [documentation](https://github.com/onelogin/php-saml) for details on the configuration.

#### Users
You are able to create new users, if they are not present at the time of login.
 - Backend<br>
 `plugin.tx_mdsaml.settings.be_users.createIfNotExist`...<br>
 Default = 1, so be_users will be created, if they do not exist.
 - Frontend<br>
 `plugin.tx_mdsaml.settings.fe_users.createIfNotExist`...<br>
  Default = 1, so fe_users will be created, if they do not exist.

You are able to update existing users, if they are already present at the time of login.
 - Backend<br>
 `plugin.tx_mdsaml.settings.be_users.updateIfExist`...<br>
 Default = 1, so be_users will be updated, if they exist.
 - Frontend<br>
 `plugin.tx_mdsaml.settings.fe_users.updateIfExist`...<br>
  Default = 1, so fe_users will be updated, if they exist.

**Backend**

- `plugin.tx_mdsaml.settings.be_users.createIfNotExist`<br>
Decide whether a new backend user should be created (Default = 1)
- `plugin.tx_mdsaml.settings.be_users.updateIfExist`<br>
Decide whether a backend user should be updated (Default = 1)
- `plugin.tx_mdsaml.settings.be_users.databaseDefaults`...<br>
This section allows you to set defaults for a newly created backend user. You can add any fields of the database here.<br>
Example: `plugin.tx_mdsaml.settings.be_users.databaseDefaults.usergroup = 123` will create a new user with usergroup 123 attached.

**Frontend**

- `plugin.tx_mdsaml.settings.fe_users.createIfNotExist`<br>
Decide whether a new frontend user should be created (Default = 1)
- `plugin.tx_mdsaml.settings.fe_users.updateIfExist`<br>
Decide whether a frontend user should be updated (Default = 1)
- `plugin.tx_mdsaml.settings.fe_users.databaseDefaults`...<br>
This section allows you to set defaults for a newly created frontend user. You can add any fields of the database here.<br>
Example: `plugin.tx_mdsaml.settings.fe_users.databaseDefaults.usergroup = 123` will create a new user with usergroup 123 attached.<br>
ATTENTION: `plugin.tx_mdsaml.settings.fe_users.databaseDefaults.pid` will be used as storage for newsly created fe_users.

#### SSO
The returned value of the SSO provider can be anything. With the following configuration set the names of the returned
values to the ones needed in TYPO3:

**Backend**

- `plugin.tx_mdsaml.settings.be_users.transformationArr`<br>
Example: `plugin.tx_mdsaml.be_users.settings.transformationArr.username = http://schemas.microsoft.com/ws/2008/06/identity/claims/windowsaccountname` <br>
The above example shows the returning value of an ADFS server, which contains the username for TYPO3.

**Frontend**

- `plugin.tx_mdsaml.settings.fe_users.transformationArr`<br>
Example: `plugin.tx_mdsaml.settings.fe_users.transformationArr.username = http://schemas.microsoft.com/ws/2008/06/identity/claims/windowsaccountname` <br>
The above example shows the returning value of an ADFS server, which contains the username for a frontend user.

### ADFS
The following steps are an example on how to configure an ADFS server as IdP (Identity Provider).

Since I don't have the configuration in english, the following section is available in german only. I am sorry for that!

- Get SP (Service Provider) meta data. Log into TYPO3 (important!) and call `/typo3/index.php?loginProvider=1648123062&mdsamlmetadata&loginType=backend`
for the backend configuration and `/typo3/index.php?loginProvider=1648123062&mdsamlmetadata&loginType=frontend` for the
frontend configuration.
- Neue `Vertrauensstellung der vertrauenden Seite` erstellen

    1. Willkommen

        - Modus `Ansprüche unterstützen` auswählen
        - Knopf `Start` klicken

    2. Datenquelle auswählen

        - Option `Daten über vertrauende Seite aus einer Datei importieren` auswählen
        - XML der Metadaten aus dem ersten Schritt auswählen
        - Knopf `Weiter` klicken

    3. Anzeigennamen angeben

        - Einen Wert für `Anzeige Name` eintragen
        - `Weiter` klicken

    4. Zugriffssteuerungsrichtline auswählen

        - Im Feld `Wählen Sie eine Zugriffssteuerungsrichtlinie aus`, den `Zugriff-OTP` auswählen
        - `Weiter` klicken

    5. Bereit zum Hinzufügen der Vertrauensstellung

        - Daten prüfen und `Weiter` klicken

    6. Fertig stellen

        - `Schließen` klicken

- Die `Ansprucheaustellungsrichtlinie für diese Anwendung konfigurieren` prüfen
- Neue Regel mit `Regel hinzufügen ...` hinzufügen
- Im Feld `Anspruchsregelvorlage` die Option `Ansprüche mithilfe einer benutzerdefinierten Regel senden` auswählen und `Weiter` klicken
- Im Feld `Anspruchsregelname` den Wert `Name Identifier` eingeben
- Im Feld `Benutzerdefinierte Regel` folgendes eingeben:<br>
`c:[Type == "http://schemas.microsoft.com/ws/2008/06/identity/claims/windowsaccountname"] => issue(Type = "http://schemas.xmlsoap.org/ws/2005/05/identity/claims/nameidentifier", Issuer = c.Issuer, OriginalIssuer = c.OriginalIssuer, Value = c.Value, ValueType = c.ValueType, Properties["http://schemas.xmlsoap.org/ws/2005/05/identity/claimproperties/format"] = "urn:oasis:names:tc:SAML:1.1:nameid-format:WindowsDomainQualifiedName");`
- Knopf `Fertig stellen` klicken
- Neue Regel hinzufügen mit klick auf `Regel hinzufügen ...`
- Im Feld `Anspruchsregelvorlage` den Wert `Ansprüche mithilfe einer benutzerdefinierten Regel senden` auswählen und `Weiter` klicken
- Im Feld `Anspruchsregelname` den `Data Rule` eingeben
- Im Feld `Benutzerdefinierte Regel` folgendes eingeben:<br>
`c:[Type == "http://schemas.microsoft.com/ws/2008/06/identity/claims/windowsaccountname", Issuer == "AD AUTHORITY"] => issue(store = "Active Directory", types = ("http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress", "http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname", "http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname", "http://schemas.microsoft.com/ws/2008/06/identity/claims/windowsaccountname", "distinguishedName", "memberOf"), query = ";mail,displayName,sn,sAMAccountName,distinguishedName,memberOf;{0}", param = c.Value);`
- Knopf `Fertig stellen` klicken
- Die `Ansprucheaustellungsrichtlinie` mit `OK` verlassen

ACHTUNG:<br>Die Reihenfolge der Regeln ist wichtig! Die erste muss die `Name Identifier` Regel sein!

Als letztes muss noch im Reiter `Bezeichner` der `Vertrauensstellung` im Feld `Bezeichner der vertrauenden Seite` der
Wert, der in `plugin.tx_mdsaml.settings.mdsamlSpBaseUrl` eingegeben werden.

### TYPO3

#### General
<ul>
    <li>
        In `LocalConfiguration.php` or `AdditionalConfiguration.php` the `['BE']['cookieSameSite']` must be set to `lax`:<br>
        <pre><code>$GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax'</code></pre>
    </li>
    <li>
        In `Site Configuration` set the value of `Entry Point` (`base`) to a full qualified entry point.
        For example set `https://www.domain.tld/` instead of just using `/`.
    </li>
</ul>

#### Site Config
```yaml
errorHandling:
    errorCode: 403
    errorHandler: PHP
    errorPhpClassFQCN: Mediadreams\MdSaml\Error\ForbiddenHandling
```
#### Change User Event

event to customize user data before insert/update on login

```php
namespace XXX\XXX\EventListener;

use Mediadreams\MdSaml\Event\ChangeUserEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AddGroupChangeUserEventListener {

  protected int $adminGroupUid = 3;

  // SSO User Changes
  public function __invoke(ChangeUserEvent $event): void
  {
      // get current data
      $userData = $event->getUserData();
      $email = $userData['email'] ?? null;
      // some conditions, if true add group
      if (1) {
          $usergroups = GeneralUtility::intExplode(',', $userData['usergroup']);
          $usergroups[] = $this->adminGroupUid;
	  // change some data
          $userData['usergroup'] = implode(',', $usergroups);
	  // save new data
          $event->setUserData($userData);
      }
  }
}
```
You must register the event listener in `Services.yaml`

## FAQ
<dl>
    <dt>Is is possible, to remove the default login with username and password?</dt>
    <dd>
        Yes, just add following line in the `ext_localconf.php` of your the extension:<br>
        <pre><code>unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders'][1433416747]);</code></pre>
    </dd>
    <dt>I get a `1648646492 RuntimeException, The site configuration could not be resolved.`</dt>
    <dd>
        Make sure, that the domain of your website is configured in the site configuration
        (`sites/identifier/config.yaml`) for `base`.
    </dd>
</dl>

## Troubleshooting
If your login fails with the parameter `?commandLI=setCookie` (typo3/index.php?commandLI=setCookie), please make sure,
that you have set `$GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax'`.

## Bugs and Known Issues
If you find a bug, it would be nice if you add an issue on [Github](https://github.com/cdaecke/md_saml/issues).

# THANKS
Thanks a lot to all who make this outstanding TYPO3 project possible!

## Credits
- Thanks to the guys at OneLogin who provide the [SAML toolkit for PHP](https://github.com/onelogin/php-saml), which I use.
- Extension icon by [Font Awesome](https://fontawesome.com/icons/key?s=solid).
