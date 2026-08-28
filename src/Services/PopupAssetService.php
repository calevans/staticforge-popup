<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Services;

use EICC\StaticForge\Core\AssetManager;
use EICC\StaticForge\Core\PathGuard;
use EICC\Utils\Container;
use EICC\Utils\Log;
use RuntimeException;

/**
 * Registers the popup CSS/JS with AssetManager for the current page and
 * publishes the feature's bundled assets into OUTPUT_DIR at POST_LOOP.
 */
final class PopupAssetService
{
    private const DEFAULT_CSS_URL = '/assets/css/sf-popup.css';
    private const DEFAULT_JS_URL = '/assets/js/popup.js';
    private const BASE_HANDLE = 'popup-base';
    private const USER_HANDLE = 'popup-user';

    public function __construct(
        private readonly Log $logger,
        private readonly AssetManager $assetManager,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function registerAssets(Container $container, array $metadata): void
    {
        $popupConfig = $this->popupConfig($container);
        $jsUrl = $this->resolveConfiguredUrl($popupConfig['js_url'] ?? null, self::DEFAULT_JS_URL, 'js_url');

        $requestedIds = PopupIdValidator::normalize(
            $metadata['popup'] ?? [],
            $this->logger,
            "page 'popup:' frontmatter"
        );

        $cascade = [];
        foreach ($this->stylesheetUrls($container, $requestedIds) as $handle => $url) {
            $this->assetManager->addStyle($handle, $url, $cascade);

            // popup-base/popup-user form the cascade every per-popup sheet
            // depends on; the per-popup sheets do not depend on each other.
            if ($handle === self::BASE_HANDLE || $handle === self::USER_HANDLE) {
                $cascade = [$handle];
            }
        }

        $this->assetManager->addScript('popup-js', $jsUrl, ['jquery'], true);
    }

    /**
     * The stylesheets this feature registers for a page, as handle => URL, in
     * cascade order: bundled base, then the site's optional popup.css, then
     * any per-popup override that exists under SOURCE_DIR.
     *
     * Pure: derived from site_config plus file_exists() checks, so PRE_RENDER
     * (registration) and POST_RENDER (link injection) can each recompute it
     * rather than sharing state across the two events.
     *
     * @param array<int, string> $popupIds
     * @return array<string, string>
     */
    public function stylesheetUrls(Container $container, array $popupIds): array
    {
        $popupConfig = $this->popupConfig($container);
        $sourceDir = $container->getVariable('SOURCE_DIR');
        $sourceDir = is_string($sourceDir) ? $sourceDir : null;

        $cssUrl = $this->resolveConfiguredUrl($popupConfig['css_url'] ?? null, self::DEFAULT_CSS_URL, 'css_url');

        $urls = [self::BASE_HANDLE => $cssUrl];

        if ($sourceDir === null) {
            return $urls;
        }

        if (file_exists($sourceDir . '/assets/css/popup.css')) {
            $urls[self::USER_HANDLE] = '/assets/css/popup.css';
        }

        foreach ($popupIds as $popupId) {
            // Defense in depth: every caller of this public method is expected
            // to have already run popup ids through PopupIdValidator (the
            // filenames it builds below are never allowed to reach the
            // filesystem otherwise), but re-checking here means this method
            // stays safe even if a future caller forgets to.
            if (preg_match(PopupIdValidator::ID_PATTERN, $popupId) !== 1) {
                continue;
            }

            if (file_exists($sourceDir . '/assets/css/' . $popupId . '.css')) {
                $urls['popup-' . $popupId] = '/assets/css/' . $popupId . '.css';
            }
        }

        return $urls;
    }

    public function copyAssets(Container $container): void
    {
        $outputDir = $container->getVariable('OUTPUT_DIR');
        if (!is_string($outputDir) || $outputDir === '') {
            $this->logger->log('ERROR', 'OUTPUT_DIR not set, cannot copy popup assets');
            return;
        }

        $popupConfig = $this->popupConfig($container);
        if (($popupConfig['publish_assets'] ?? true) === false) {
            $this->logger->log('DEBUG', 'popup.publish_assets is false; skipping bundled asset copy');
            return;
        }

        $featureRoot = dirname(__DIR__);

        $this->publish($featureRoot . '/assets/js/popup.js', $outputDir, '/assets/js/popup.js');
        $this->publish($featureRoot . '/assets/css/sf-popup.css', $outputDir, '/assets/css/sf-popup.css');
    }

    private function publish(string $source, string $outputDir, string $relativeTarget): void
    {
        if (!file_exists($source)) {
            $this->logger->log('WARNING', "Could not find bundled popup asset at {$source}");
            return;
        }

        try {
            $target = PathGuard::resolveInside($outputDir . $relativeTarget, $outputDir);
        } catch (RuntimeException $e) {
            $this->logger->log('ERROR', "Refusing to write popup asset outside OUTPUT_DIR: {$relativeTarget}");
            return;
        }

        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            $this->logger->log('ERROR', "Failed to create directory: {$targetDir}");
            return;
        }

        if (!copy($source, $target)) {
            $this->logger->log('ERROR', "Failed to copy popup asset to {$target}");
            return;
        }

        $this->logger->log('DEBUG', "Copied popup asset to {$target}");
    }

    /**
     * @return array<string, mixed>
     */
    private function popupConfig(Container $container): array
    {
        $siteConfig = $container->getVariable('site_config') ?? [];
        return is_array($siteConfig['popup'] ?? null) ? $siteConfig['popup'] : [];
    }

    /**
     * Applies the same https:/root-relative gate used for popup.jquery_url
     * to popup.css_url/popup.js_url: both are site-operator-controlled but
     * still end up in an unescaped <link>/<script> tag via AssetManager, so
     * an accidental `javascript:`/bare-scheme value must never reach it.
     */
    private function resolveConfiguredUrl(mixed $value, string $default, string $configKey): string
    {
        if (!is_string($value) || $value === '') {
            return $default;
        }

        if (!str_starts_with($value, 'https://') && !str_starts_with($value, '/')) {
            $this->logger->log(
                'WARNING',
                "popup.{$configKey} must be https: or root-relative, ignoring: {$value}"
            );
            return $default;
        }

        return $value;
    }
}
