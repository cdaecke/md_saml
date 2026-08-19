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

use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Builds and signs a SAML LogoutRequest for the HTTP-Redirect binding, matching
 * exactly what onelogin/php-saml's own Auth::buildRequestSignature() /
 * Utils::validateBinarySign() expect: the signature covers the raw query string
 * "SAMLRequest=...&SigAlg=..." (RelayState omitted here, not used by md_saml's
 * IdP-initiated SLO handling), signed with the private key belonging to the
 * x509cert configured as idp.x509cert on the SP side.
 *
 * This is test-support code, not part of the extension's autoloaded production
 * classes — it lives under Tests/ and is only reachable via the dev autoloader.
 */
final class RedirectBindingSigner
{
    public static function idpCertPath(): string
    {
        return __DIR__ . '/idp.crt';
    }

    public static function idpKeyPath(): string
    {
        return __DIR__ . '/idp.key';
    }

    private static function logoutRequestXml(string $destination, string $issuer, string $nameId): string
    {
        $id = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $destinationAttr = htmlspecialchars($destination, ENT_QUOTES);
        $issuerXml = htmlspecialchars($issuer, ENT_QUOTES);
        $nameIdXml = htmlspecialchars($nameId, ENT_QUOTES);

        return '<samlp:LogoutRequest'
            . ' xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"'
            . ' xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            . ' ID="' . $id . '"'
            . ' Version="2.0"'
            . ' IssueInstant="' . $issueInstant . '"'
            . ' Destination="' . $destinationAttr . '">'
            . '<saml:Issuer>' . $issuerXml . '</saml:Issuer>'
            . '<saml:NameID>' . $nameIdXml . '</saml:NameID>'
            . '</samlp:LogoutRequest>';
    }

    /**
     * @return array{SAMLRequest: string, SigAlg: string, Signature: string, queryString: string}
     */
    public static function signedLogoutRequest(string $destination, string $issuer, string $nameId): array
    {
        $xml = self::logoutRequestXml($destination, $issuer, $nameId);
        $samlRequest = base64_encode((string)gzdeflate($xml));
        $sigAlg = XMLSecurityKey::RSA_SHA256;

        $signedQuery = 'SAMLRequest=' . rawurlencode($samlRequest) . '&SigAlg=' . rawurlencode($sigAlg);

        $key = new XMLSecurityKey($sigAlg, ['type' => 'private']);
        $key->loadKey(file_get_contents(self::idpKeyPath()), false);

        $signature = base64_encode((string)$key->signData($signedQuery));

        $queryString = $signedQuery . '&Signature=' . rawurlencode($signature);

        return [
            'SAMLRequest' => $samlRequest,
            'SigAlg' => $sigAlg,
            'Signature' => $signature,
            'queryString' => $queryString,
        ];
    }

    /**
     * Same LogoutRequest, but without SigAlg/Signature — used to verify that
     * SlsFrontendSamlMiddleware::performNameIdFallbackLogoff() refuses to resolve
     * a session from an unsigned request (see the security note on that method).
     *
     * @return array{SAMLRequest: string, queryString: string}
     */
    public static function unsignedLogoutRequest(string $destination, string $issuer, string $nameId): array
    {
        $xml = self::logoutRequestXml($destination, $issuer, $nameId);
        $samlRequest = base64_encode((string)gzdeflate($xml));

        return [
            'SAMLRequest' => $samlRequest,
            'queryString' => 'SAMLRequest=' . rawurlencode($samlRequest),
        ];
    }
}
