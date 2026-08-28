<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Services;

use EICC\Utils\Log;

/**
 * Single source of truth for what a popup id may look like. Shared by
 * PopupParser (ids declared in a `.popup` file's own frontmatter) and by
 * Feature/PopupAssetService (ids a *page* requests via its `popup:`
 * frontmatter). Both sources feed a filesystem path segment (per-popup CSS
 * lookup) and, eventually, an unescaped `<link href>`/`<script src>` emitted
 * by core's AssetManager, so both must be gated by exactly the same pattern
 * — keeping one copy is what guarantees they can't drift apart.
 *
 * `\z` (not `$`) plus the `D` modifier is deliberate: without them PCRE lets
 * a trailing "\n" through, which YAML double-quoted scalars can smuggle in.
 */
final class PopupIdValidator
{
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}\z/D';

    /**
     * Normalizes a raw `popup:` frontmatter value (string or list of
     * strings) into a list of ids, dropping anything that is not a
     * non-empty string matching ID_PATTERN. Every rejection is logged so a
     * silently-dropped popup is never a mystery.
     *
     * @return array<int, string>
     */
    public static function normalize(mixed $requested, Log $logger, string $context): array
    {
        if (!is_array($requested)) {
            $requested = [$requested];
        }

        $ids = [];
        foreach ($requested as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }

            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                $logger->log(
                    'ERROR',
                    "Invalid popup id '{$id}' requested in {$context}; must match " . self::ID_PATTERN
                );
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
