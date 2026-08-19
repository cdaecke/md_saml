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

namespace Mediadreams\MdSaml\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversNothing]
final class VersionCompatibilityTest extends UnitTestCase
{
    #[Test]
    public function currentVersionIsSupported(): void
    {
        $supportedVersions = [13, 14];
        $currentVersion = (new Typo3Version())->getMajorVersion();
        self::assertContains(
            $currentVersion,
            $supportedVersions,
        );
    }
}
