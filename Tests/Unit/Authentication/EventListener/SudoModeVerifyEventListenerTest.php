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

namespace Mediadreams\MdSaml\Tests\Unit\Authentication\EventListener;

use Mediadreams\MdSaml\Authentication\EventListener\SudoModeVerifyEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessClaim;
use TYPO3\CMS\Backend\Security\SudoMode\Access\ServerRequestInstruction;
use TYPO3\CMS\Backend\Security\SudoMode\Event\SudoModeRequiredEvent;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * SAML-authenticated backend users have no TYPO3 password, so TYPO3's sudo-mode
 * password re-verification (TYPO3-CORE-SA-2025-013) would permanently lock them
 * out of elevated actions like editing their own profile — this listener bypasses
 * that check for them, and only for them.
 */
#[CoversClass(SudoModeVerifyEventListener::class)]
final class SudoModeVerifyEventListenerTest extends UnitTestCase
{
    private mixed $beUserBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beUserBackup = $GLOBALS['BE_USER'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_USER'] = $this->beUserBackup;

        parent::tearDown();
    }

    private function buildEvent(): SudoModeRequiredEvent
    {
        $instruction = ServerRequestInstruction::createForServerRequest(
            new ServerRequest('https://typo3-testing.local/typo3/')
        );
        $claim = new AccessClaim($instruction, time() + 300);

        return new SudoModeRequiredEvent($claim);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function setBeUser(array $user): void
    {
        $GLOBALS['BE_USER'] = new class ($user) {
            /**
             * @param array<string, mixed> $user
             */
            public function __construct(public array $user)
            {
            }
        };
    }

    #[Test]
    public function skipsVerificationForSamlAuthenticatedBackendUser(): void
    {
        $this->setBeUser(['md_saml_source' => 1]);

        $event = $this->buildEvent();
        (new SudoModeVerifyEventListener())($event);

        self::assertFalse($event->isVerificationRequired());
    }

    #[Test]
    public function keepsVerificationRequiredForNonSamlBackendUser(): void
    {
        $this->setBeUser(['md_saml_source' => 0]);

        $event = $this->buildEvent();
        (new SudoModeVerifyEventListener())($event);

        self::assertTrue($event->isVerificationRequired());
    }

    #[Test]
    public function keepsVerificationRequiredWhenMdSamlSourceIsMissing(): void
    {
        $this->setBeUser([]);

        $event = $this->buildEvent();
        (new SudoModeVerifyEventListener())($event);

        self::assertTrue($event->isVerificationRequired());
    }
}
