<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Models;

/**
 * A single parsed `.popup` file. `$metadata` is the full stripReserved()'d
 * frontmatter array (not just the fields this package cares about) so user
 * templates reading arbitrary custom frontmatter keys via
 * `{{ popup.metadata.whatever }}` keep working.
 */
final readonly class PopupDefinition
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $content,
        public array $metadata,
        public bool $exitIntent,
        public int $timerSeconds,
        public int $blockedDays,
        public ?string $urlOverride,
    ) {
    }
}
