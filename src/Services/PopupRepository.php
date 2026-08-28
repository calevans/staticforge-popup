<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Services;

use Calevans\StaticForgePopup\Models\PopupDefinition;
use EICC\StaticForge\Core\PathGuard;
use EICC\Utils\Container;
use EICC\Utils\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Scans SOURCE_DIR for `*.popup` files and holds the parsed, form-expanded
 * result keyed by id. The only stateful collaborator in this feature —
 * FeatureFactory does not memoize unregistered classes, so only Feature may
 * depend on this directly; every other collaborator must receive the
 * PopupDefinition(s) it needs as method parameters.
 *
 * Matching pathnames are sorted lexicographically before loading, so when
 * two files declare the same id, which one "wins" is deterministic and
 * reproducible across machines/filesystems rather than depending on
 * readdir() order.
 */
final class PopupRepository
{
    private const DEFAULT_BLOCKED_DAYS = 30;

    /**
     * @var array<string, PopupDefinition>
     */
    private array $popups = [];

    public function __construct(
        private readonly Log $logger,
        private readonly PopupParser $parser,
    ) {
    }

    public function load(Container $container): void
    {
        $sourceDir = $container->getVariable('SOURCE_DIR');

        if (!is_string($sourceDir) || $sourceDir === '' || !is_dir($sourceDir)) {
            $this->logger->log('WARNING', 'SOURCE_DIR not set or not a directory; no popups loaded');
            return;
        }

        $siteConfig = $container->getVariable('site_config') ?? [];
        $defaultBlockedDays = (int) ($siteConfig['popup']['default_blocked_days'] ?? self::DEFAULT_BLOCKED_DAYS);

        $directory = new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator(
            $directory,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        $paths = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'popup') {
                continue;
            }

            $paths[] = $file->getPathname();
        }

        sort($paths);

        foreach ($paths as $path) {
            $this->loadFile($path, $sourceDir, $defaultBlockedDays);
        }
    }

    public function get(string $id): ?PopupDefinition
    {
        return $this->popups[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->popups[$id]);
    }

    /**
     * @return array<string, PopupDefinition>
     */
    public function all(): array
    {
        return $this->popups;
    }

    private function loadFile(string $filePath, string $sourceDir, int $defaultBlockedDays): void
    {
        try {
            $filePath = PathGuard::resolveInside($filePath, $sourceDir);
        } catch (RuntimeException $e) {
            $this->logger->log('WARNING', "Skipping popup file outside SOURCE_DIR: {$filePath}");
            return;
        }

        // PathGuard::resolveInside() is pure string normalization; it never
        // touches the filesystem, so it does not (and cannot) catch a
        // symlinked *file* whose pathname is inside SOURCE_DIR but whose
        // target is not. RecursiveDirectoryIterator happily yields such a
        // file (it only skips symlinked *directories* by default), so we
        // resolve the real path here and re-check the boundary against it.
        $realFilePath = realpath($filePath);
        $realSourceDir = realpath($sourceDir);
        if (
            $realFilePath === false
            || $realSourceDir === false
            || PathGuard::relativeTo($realFilePath, $realSourceDir) === null
        ) {
            $this->logger->log('WARNING', "Skipping symlinked popup file that escapes SOURCE_DIR: {$filePath}");
            return;
        }

        if (!is_readable($filePath)) {
            $this->logger->log('WARNING', "Popup file is not readable: {$filePath}");
            return;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->logger->log('WARNING', "Failed to read popup file: {$filePath}");
            return;
        }

        $popup = $this->parser->parse($content, basename($filePath, '.popup'), $defaultBlockedDays);
        if ($popup === null) {
            return;
        }

        if (isset($this->popups[$popup->id])) {
            $this->logger->log(
                'WARNING',
                "Duplicate popup id '{$popup->id}' found in {$filePath}; keeping the first one loaded"
            );
            return;
        }

        $this->popups[$popup->id] = $popup;
    }
}
