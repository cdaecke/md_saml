<?php

declare(strict_types=1);

namespace Mediadreams\MdSaml\Event;

final class BeforeSettingsAreProcessedEvent
{
    public function __construct(
        private readonly string $loginType,
        private array $settings
    ){}

    public function getLoginType(): string
    {
        return $this->loginType;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }
}
