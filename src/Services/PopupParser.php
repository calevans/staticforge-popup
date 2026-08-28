<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Services;

use Calevans\StaticForgePopup\Models\PopupDefinition;
use EICC\StaticForge\Core\Frontmatter;
use EICC\StaticForge\Core\YamlParser;
use EICC\StaticForge\Features\MarkdownRenderer\MarkdownProcessor;
use EICC\Utils\Log;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Pure parser: turns the raw contents of a single `.popup` file into a
 * PopupDefinition — frontmatter, then form expansion, then markdown
 * conversion. No filesystem I/O — PopupRepository owns discovering and
 * reading files.
 */
final class PopupParser
{
    public function __construct(
        private readonly Log $logger,
        private readonly MarkdownProcessor $markdownProcessor,
        private readonly PopupFormRenderer $formRenderer,
    ) {
    }

    public function parse(string $content, string $filenameId, int $defaultBlockedDays): ?PopupDefinition
    {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            $this->logger->log('WARNING', "Popup file '{$filenameId}' has no --- frontmatter fence; skipping");
            return null;
        }

        $frontmatter = $matches[1];
        $markdown = $matches[2];

        try {
            $metadata = YamlParser::parse($frontmatter);
        } catch (ParseException $e) {
            $this->logger->log('ERROR', "Failed to parse popup frontmatter for '{$filenameId}': " . $e->getMessage());
            return null;
        }

        if (!is_array($metadata)) {
            $this->logger->log('ERROR', "Popup frontmatter for '{$filenameId}' did not parse to a map");
            return null;
        }

        $metadata = Frontmatter::stripReserved($metadata);

        if (empty($metadata['popup_enabled'])) {
            return null;
        }

        $id = $metadata['id'] ?? $filenameId;
        $id = is_string($id) ? $id : $filenameId;

        if (preg_match(PopupIdValidator::ID_PATTERN, $id) !== 1) {
            $this->logger->log(
                'ERROR',
                "Popup id '{$id}' in '{$filenameId}' is invalid; must match " . PopupIdValidator::ID_PATTERN
            );
            return null;
        }

        $metadata['id'] = $id;

        $urlOverride = $metadata['url'] ?? null;
        $urlOverride = is_string($urlOverride) ? $urlOverride : null;

        // Expand form shortcodes against the raw markdown, before conversion:
        // a standalone `{{ form('x') }}` line otherwise becomes `<p>…</p>` and
        // the form lands inside a paragraph, which is invalid HTML.
        $markdown = $this->formRenderer->expand($markdown, $urlOverride);

        $html = $this->markdownProcessor->convert($markdown);

        return new PopupDefinition(
            id: $id,
            content: $html,
            metadata: $metadata,
            exitIntent: (bool) ($metadata['exit_intent'] ?? false),
            timerSeconds: (int) ($metadata['timer'] ?? 0),
            blockedDays: (int) ($metadata['popup_blocked_for'] ?? $defaultBlockedDays),
            urlOverride: $urlOverride,
        );
    }
}
