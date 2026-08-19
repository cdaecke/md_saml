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

use OneLogin\Saml2\Utils as SamlUtils;

/**
 * Builds a signed SAML Response for the HTTP-POST binding (the ACS endpoint):
 * a plain Assertion XML fragment is built, then signed via the same
 * OneLogin\Saml2\Utils::addSign() helper onelogin/php-saml itself uses to sign
 * metadata/requests (enveloped signature, exclusive C14N, SHA-256) — reusing the
 * library's own proven signing code rather than re-implementing XML-DSig here.
 * The signed Assertion is then embedded into a hand-built Response wrapper.
 */
final class PostBindingResponseSigner
{
    public static function idpCertPath(): string
    {
        return __DIR__ . '/idp.crt';
    }

    public static function idpKeyPath(): string
    {
        return __DIR__ . '/idp.key';
    }

    /**
     * @param array<string, string> $attributes Attribute Name => single value.
     */
    public static function signedResponse(
        string $destination,
        string $issuer,
        string $audience,
        string $nameId,
        string $sessionIndex,
        array $attributes
    ): string {
        $now = time();
        $notBefore = self::samlTime($now - 60);
        $notOnOrAfter = self::samlTime($now + 300);
        $sessionNotOnOrAfter = self::samlTime($now + 3600);
        $issueInstant = self::samlTime($now);

        $assertionId = '_' . bin2hex(random_bytes(16));
        $responseId = '_' . bin2hex(random_bytes(16));

        $attributeStatements = '';
        foreach ($attributes as $name => $value) {
            $attributeStatements .= '<saml:Attribute Name="' . htmlspecialchars($name, ENT_QUOTES) . '"'
                . ' NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic">'
                . '<saml:AttributeValue>' . htmlspecialchars($value, ENT_QUOTES) . '</saml:AttributeValue>'
                . '</saml:Attribute>';
        }

        $assertionXml = '<saml:Assertion'
            . ' xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            . ' ID="' . $assertionId . '"'
            . ' Version="2.0"'
            . ' IssueInstant="' . $issueInstant . '">'
            . '<saml:Issuer>' . htmlspecialchars($issuer, ENT_QUOTES) . '</saml:Issuer>'
            . '<saml:Subject>'
            . '<saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">'
            . htmlspecialchars($nameId, ENT_QUOTES) . '</saml:NameID>'
            . '<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">'
            . '<saml:SubjectConfirmationData NotOnOrAfter="' . $notOnOrAfter . '"'
            . ' Recipient="' . htmlspecialchars($destination, ENT_QUOTES) . '"/>'
            . '</saml:SubjectConfirmation>'
            . '</saml:Subject>'
            . '<saml:Conditions NotBefore="' . $notBefore . '" NotOnOrAfter="' . $notOnOrAfter . '">'
            . '<saml:AudienceRestriction><saml:Audience>' . htmlspecialchars($audience, ENT_QUOTES)
            . '</saml:Audience></saml:AudienceRestriction>'
            . '</saml:Conditions>'
            . '<saml:AuthnStatement AuthnInstant="' . $issueInstant . '"'
            . ' SessionIndex="' . htmlspecialchars($sessionIndex, ENT_QUOTES) . '"'
            . ' SessionNotOnOrAfter="' . $sessionNotOnOrAfter . '">'
            . '<saml:AuthnContext><saml:AuthnContextClassRef>'
            . 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport'
            . '</saml:AuthnContextClassRef></saml:AuthnContext>'
            . '</saml:AuthnStatement>'
            . '<saml:AttributeStatement>' . $attributeStatements . '</saml:AttributeStatement>'
            . '</saml:Assertion>';

        $signedAssertionXml = SamlUtils::addSign(
            $assertionXml,
            (string)file_get_contents(self::idpKeyPath()),
            (string)file_get_contents(self::idpCertPath())
        );
        // addSign() returns a full XML document including the leading XML declaration;
        // strip it since this fragment is embedded inside the Response document below.
        $signedAssertionXml = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $signedAssertionXml) ?? $signedAssertionXml;

        $responseXml = '<samlp:Response'
            . ' xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"'
            . ' xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            . ' ID="' . $responseId . '"'
            . ' Version="2.0"'
            . ' IssueInstant="' . $issueInstant . '"'
            . ' Destination="' . htmlspecialchars($destination, ENT_QUOTES) . '">'
            . '<saml:Issuer>' . htmlspecialchars($issuer, ENT_QUOTES) . '</saml:Issuer>'
            . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            . $signedAssertionXml
            . '</samlp:Response>';

        return base64_encode($responseXml);
    }

    private static function samlTime(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
