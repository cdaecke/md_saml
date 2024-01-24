<?php

declare(strict_types=1);

namespace Mediadreams\MdSaml\Event;

use Psr\EventDispatcher\StoppableEventInterface;

final class ChangeUserEvent implements StoppableEventInterface
{
    /**
     * @var ServerRequestInterface
     */
    private $userData = [];

    public function __construct(array $userData)
    {
        $this->userData = $userData;
    }

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
