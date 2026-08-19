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

namespace Mediadreams\MdSaml\Security;

use OneLogin\Saml2\Utils;

/**
 * Guards against open-redirect attacks in SAML RelayState / redirect-target
 * handling (login RelayState, ACS callback RelayState, SLO redirect cookie).
 *
 * These values are attacker-influenceable (POST body, RelayState, Referer-derived
 * cookie), so they must be validated to genuinely belong to the current SP host
 * before being used as a redirect target.
 */
final class SameOriginUrlGuard
{
    /**
     * True when $url is exactly the current host, or a path/query/fragment rooted
     * at it. A bare string-prefix match on Utils::getSelfURLhost() would incorrectly
     * accept "https://typo3.example.com.evil.com/phish" as same-origin, since that
     * string also starts with "https://typo3.example.com" — this additionally
     * requires the character right after the host to be a valid URL boundary
     * ('/', '?', '#') or the end of the string.
     */
    public static function isSameOrigin(string $url): bool
    {
        $selfHost = Utils::getSelfURLhost();
        if (!str_starts_with($url, $selfHost)) {
            return false;
        }

        $remainder = substr($url, strlen($selfHost));
        return $remainder === '' || in_array($remainder[0], ['/', '?', '#'], true);
    }

    /**
     * True for a relative path rooted at '/' (but not a protocol-relative URL like
     * '//evil.com' — that starts with '/' too, yet browsers resolve it to an
     * external host), or an absolute same-origin URL per isSameOrigin().
     */
    public static function isSameOriginOrRootRelative(string $url): bool
    {
        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }

        return str_starts_with($url, '/') || self::isSameOrigin($url);
    }
}
