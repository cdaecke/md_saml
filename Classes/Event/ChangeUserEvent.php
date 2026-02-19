<?php

declare(strict_types=1);

namespace Mediadreams\MdSaml\Event;

use Psr\EventDispatcher\StoppableEventInterface;

final class ChangeUserEvent implements StoppableEventInterface
{
    public function __construct(private array $userData) {}

    public function getUserData(): array
    {
        return $this->userData;
    }

    public function setUserData(array $userData): void
    {
        $this->userData = $userData;
    }

    public function isPropagationStopped(): bool
    {
        return false;
    }
}
