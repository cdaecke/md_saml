<?php

declare(strict_types=1);

/*
 * This file is part of the Extension "md_saml" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022 Christoph Daecke <typo3@mediadreams.org>
 */

namespace Mediadreams\MdSaml\Tests\Fixtures\Saml;

use OneLogin\Saml2\Constants;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Builds and signs a SAML LogoutResponse for the HTTP-Redirect binding, matching
 * what onelogin/php-saml's LogoutResponse::isValid() / Utils::validateBinarySign()
 * expect: the signature covers the raw query string "SAMLResponse=...&SigAlg=...",
 * signed with the private key belonging to the x509cert configured as idp.x509cert
 * on the SP side. Used to test the SP-initiated SLO callback (the IdP replying to
 * a LogoutRequest built by SlsFrontendSloInitiatorMiddleware / initiateBackendSlo()),
 * as opposed to RedirectBindingSigner's LogoutRequest, which covers IdP-initiated SLO.
 *
 * This is test-support code, not part of the extension's autoloaded production
 * classes — it lives under Tests/ and is only reachable via the dev autoloader.
 */
final class RedirectBindingLogoutResponseSigner
{
    public static function idpCertPath(): string
    {
        return __DIR__ . '/idp.crt';
    }

    public static function idpKeyPath(): string
    {
        return __DIR__ . '/idp.key';
    }

    private static function logoutResponseXml(string $destination, string $issuer, string $status): string
    {
        $id = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $destinationAttr = htmlspecialchars($destination, ENT_QUOTES);
        $issuerXml = htmlspecialchars($issuer, ENT_QUOTES);
        $statusAttr = htmlspecialchars($status, ENT_QUOTES);

        return '<samlp:LogoutResponse'
            . ' xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"'
            . ' xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            . ' ID="' . $id . '"'
            . ' Version="2.0"'
            . ' IssueInstant="' . $issueInstant . '"'
            . ' Destination="' . $destinationAttr . '">'
            . '<saml:Issuer>' . $issuerXml . '</saml:Issuer>'
            . '<samlp:Status><samlp:StatusCode Value="' . $statusAttr . '"/></samlp:Status>'
            . '</samlp:LogoutResponse>';
    }

    /**
     * @return array{SAMLResponse: string, SigAlg: string, Signature: string, queryString: string}
     */
    public static function signedLogoutResponse(
        string $destination,
        string $issuer,
        string $status = Constants::STATUS_SUCCESS
    ): array {
        $xml = self::logoutResponseXml($destination, $issuer, $status);
        $samlResponse = base64_encode((string)gzdeflate($xml));
        $sigAlg = XMLSecurityKey::RSA_SHA256;

        $signedQuery = 'SAMLResponse=' . rawurlencode($samlResponse) . '&SigAlg=' . rawurlencode($sigAlg);

        $key = new XMLSecurityKey($sigAlg, ['type' => 'private']);
        $key->loadKey(file_get_contents(self::idpKeyPath()), false);

        $signature = base64_encode((string)$key->signData($signedQuery));

        $queryString = $signedQuery . '&Signature=' . rawurlencode($signature);

        return [
            'SAMLResponse' => $samlResponse,
            'SigAlg' => $sigAlg,
            'Signature' => $signature,
            'queryString' => $queryString,
        ];
    }
}
