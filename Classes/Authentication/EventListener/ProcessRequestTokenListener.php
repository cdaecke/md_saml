<?php

declare(strict_types=1);

namespace Mediadreams\MdSaml\Authentication\EventListener;

/**
 *
 * This file is part of the Extension "md_saml" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022 Christoph Daecke <typo3@mediadreams.org>
 *
 */

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\Event\BeforeRequestTokenProcessedEvent;
use TYPO3\CMS\Core\Security\RequestToken;

/**
 * Class ProcessRequestTokenListener
 * @package Mediadreams\MdSaml\Authentication\EventListener
 */
final class ProcessRequestTokenListener
{
    public function __invoke(BeforeRequestTokenProcessedEvent $event): void
    {
        $user = $event->getUser();
        $requestToken = $event->getRequestToken();
        
        if ($requestToken instanceof RequestToken) {
            return;
        }

        $event->setRequestToken(
            RequestToken::create('core/user-auth/' . strtolower($user->loginType))
        );
    }
}
