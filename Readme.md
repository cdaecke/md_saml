# TYPO3 Extension `md_saml`
Single Sign-on extension for TYPO3. It enables you, to log into the TYPO3 backend by using an Identity Provider (IdP),
for example a ADFS server (Active Directory Federation Services). It is fully configurable by TypoScript.

## Requirements
- TYPO3 v10.4

## Installation
- Install the extension with the following composer command: `composer req mediadreams/md_saml`
- Include the static TypoScript of the extension

## Configuration
### TypoScript
The Service Provider (SP) and Identity Provider (IdP) can be configured by adapting the settings in TypoScript.

- Copy file `ext:md_saml/Configuration/TypoScript/setup.typoscript` to your
own extension and modify according your needs.
- Generate a certificate for the Service Provider (SP)<br>
`openssl req -newkey rsa:3072 -new -x509 -days 3652 -nodes -out sp.crt -keyout sp.key`
- Open certificate files and remove all line breaks. Copy value of  `sp.crt` to
`plugin.tx_mdsaml.settings.saml.sp.x509cert` and value of `sp.key` to `plugin.tx_mdsaml.settings.saml.sp.privateKey`

As underlying SAML toolkit the library of OneLogin is used (no account with OneLogin is needed!).
See full [documentation](https://github.com/onelogin/php-saml) for details on the configuration.

### ADFS
The following steps are an example on how to configure an ADFS server as IdP (Identity Provider).

Since I don't have the configuration in english, the following section is available in german only. I am sorry for that!

- Get SP (Service Provider) meta data. Log into TYPO3 (important!) and call `/typo3/index.php?loginProvider=1648123062&mdsamlmetadata`
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
In `LocalConfiguration.php` or `AdditionalConfiguration.php` the `['BE']['cookieSameSite']` must be set to `lax`:

    $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax'

## Troubleshooting
If your login fails with the parameter `?commandLI=setCookie` (typo3/index.php?commandLI=setCookie), please make sure,
that you have set `$GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax'`.

# THANKS
Thanks a lot to all who make this outstanding TYPO3 project possible!

## Credits
- Thanks to the guys at OneLogin who provide the [SAML toolkit for PHP](https://github.com/onelogin/php-saml), which I use.
- Extension icon by [Font Awesome](https://fontawesome.com/icons/key?s=solid).
