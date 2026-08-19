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

namespace Mediadreams\MdSaml\Tests\Unit\LoginProvider;

use TYPO3\CMS\Core\View\ViewInterface;

/**
 * A plain ViewInterface test double that records assign() calls and does NOT
 * implement TemplateAwareViewInterface — matching a Fluid version where the
 * concrete view class lacks getRenderingContext().
 */
class RecordingView implements ViewInterface
{
    /**
     * @var array<string, mixed>
     */
    public array $assigned = [];

    public function assign(string $key, mixed $value): self
    {
        $this->assigned[$key] = $value;
        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function assignMultiple(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->assigned[$key] = $value;
        }

        return $this;
    }

    public function render(string $templateFileName = ''): string
    {
        return '';
    }
}
