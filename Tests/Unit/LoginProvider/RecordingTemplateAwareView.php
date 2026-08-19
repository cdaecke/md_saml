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
use TYPO3Fluid\Fluid\View\TemplateAwareViewInterface;

/**
 * A self-referential ViewInterface+TemplateAwareViewInterface test double:
 * getRenderingContext()/getTemplatePaths() both return $this, so the
 * production code's three-deep method chain
 * (getRenderingContext()->getTemplatePaths()->setTemplatePathAndFilename())
 * resolves without needing separate stub classes for each link.
 */
class RecordingTemplateAwareView implements ViewInterface, TemplateAwareViewInterface
{
    /**
     * @var array<string, mixed>
     */
    public array $assigned = [];

    public ?string $templatePathAndFilename = null;

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

    public function getRenderingContext(): self
    {
        return $this;
    }

    public function getTemplatePaths(): self
    {
        return $this;
    }

    public function setTemplatePathAndFilename(string $path): void
    {
        $this->templatePathAndFilename = $path;
    }
}
