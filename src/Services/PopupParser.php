<?php

namespace Calevans\StaticForgePopup\Services;

use EICC\StaticForge\Features\MarkdownRenderer\MarkdownProcessor;
use Symfony\Component\Yaml\Yaml;

class PopupParser
{
    private MarkdownProcessor $markdownProcessor;

    public function __construct(MarkdownProcessor $markdownProcessor)
    {
        $this->markdownProcessor = $markdownProcessor;
    }

    public function parse(string $content, string $filenameId): ?array
    {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            return null;
        }

        $frontmatter = $matches[1];
        $markdown = $matches[2];

        try {
            $metadata = Yaml::parse($frontmatter);
        } catch (\Exception $e) {
            return null;
        }

        if (empty($metadata['popup_enabled'])) {
            return null;
        }

        if (empty($metadata['id'])) {
            $metadata['id'] = $filenameId;
        }

        $html = $this->markdownProcessor->convert($markdown);

        return [
            'metadata' => $metadata,
            'content' => $html
        ];
    }
}
