<?php

declare(strict_types=1);

namespace Mediadreams\MdSaml\Event;

use Psr\EventDispatcher\StoppableEventInterface;

final class ChangeUserEvent implements StoppableEventInterface
{
    private bool $propagationStopped = false;

    public function __construct(private array $userData)
    {
    }

    public function getUserData(): array
    {
        return $this->userData;
    }

    public function setUserData(array $userData): void
    {
        $this->userData = $userData;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
