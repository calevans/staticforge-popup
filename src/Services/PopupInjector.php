<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Services;

use Calevans\StaticForgePopup\Models\PopupDefinition;
use EICC\Utils\Container;
use EICC\Utils\Log;
use Twig\Environment;
use Twig\Error\Error;

/**
 * Splices resolved popup HTML, the `#sf-popups` JSON config script, the popup
 * stylesheet <link> tags, and (when applicable) a pinned jQuery <script> tag
 * into a rendered page. Config is emitted as `<script type="application/json">`
 * rather than an inline `window.sfPopups = ...` assignment so a strict CSP
 * (script-src without 'unsafe-inline') doesn't have to be relaxed for it;
 * popup.js reads and parses the element itself.
 *
 * jQuery injection lives here rather than in PopupAssetService because it
 * must land in <head>, which is only reachable once the page HTML exists
 * (POST_RENDER) — AssetManager's own footer-script block is already emitted
 * by the template by that point, so popup-js (which depends on jQuery being
 * loaded first) would otherwise run before jQuery does.
 */
final class PopupInjector
{
    private const DEFAULT_JQUERY_URL = 'https://code.jquery.com/jquery-3.7.1.min.js';
    private const DEFAULT_JQUERY_INTEGRITY = 'sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs';

    public function __construct(
        private readonly Log $logger,
        private readonly Environment $twig,
        private readonly Container $container,
    ) {
    }

    /**
     * @param array<int, PopupDefinition> $popups Already resolved against
     *   the requested ids; a requested-but-missing id is Feature's concern.
     * @param array<string, string> $stylesheetUrls Handle => URL of the
     *   stylesheets PopupAssetService registered for this page, in cascade
     *   order.
     */
    public function inject(string $content, array $popups, array $stylesheetUrls): string
    {
        if ($popups === []) {
            return $content;
        }

        $renderedHtml = '';
        $popupConfigs = [];

        foreach ($popups as $popup) {
            $templateName = $this->twig->getLoader()->exists("{$popup->id}.html.twig")
                ? "{$popup->id}.html.twig"
                : 'popup.html.twig';

            try {
                $renderedHtml .= $this->twig->render($templateName, ['popup' => $popup]);
            } catch (Error $e) {
                $this->logger->log('ERROR', "Failed to render popup '{$popup->id}': " . $e->getMessage());
                continue;
            }

            $popupConfigs[] = [
                'id' => $popup->id,
                'exit_intent' => $popup->exitIntent,
                'timer' => $popup->timerSeconds,
                'blocked_days' => $popup->blockedDays,
            ];
        }

        if ($popupConfigs === []) {
            return $content;
        }

        $configJson = json_encode(
            $popupConfigs,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $injection = "\n<!-- Popup Feature -->\n" . $renderedHtml . "\n"
            . '<script type="application/json" id="sf-popups">' . $configJson . "</script>\n";

        $bodyPos = strrpos($content, '</body>');
        $content = $bodyPos === false
            ? $content . $injection
            : substr_replace($content, $injection . '</body>', $bodyPos, strlen('</body>'));

        return $this->injectJquery($this->injectStyles($content, $stylesheetUrls));
    }

    /**
     * AssetManager registration alone does not get these onto the page: core's
     * TemplateRenderer::injectAssets() (TemplateRenderer.php:181) only injects
     * AssetManager styles when the rendered HTML contains no
     * `<link rel="stylesheet"` at all, so any template with a hardcoded
     * stylesheet — which is every realistic one — silently drops them. Without
     * this the popup renders unstyled, i.e. not positioned or layered at all.
     * Templates that do emit `{{ styles }}` are covered by the per-URL guard.
     *
     * @param array<string, string> $stylesheetUrls Handle => URL, cascade order.
     */
    private function injectStyles(string $content, array $stylesheetUrls): string
    {
        $tags = '';
        foreach ($stylesheetUrls as $url) {
            if (str_contains($content, $url)) {
                continue;
            }

            $tags .= '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "\">\n";
        }

        if ($tags === '') {
            return $content;
        }

        $headPos = strpos($content, '</head>');
        if ($headPos === false) {
            $this->logger->log('WARNING', 'Cannot inject popup stylesheets: no </head> tag found in rendered output');
            return $content;
        }

        return substr_replace($content, $tags, $headPos, 0);
    }

    private function injectJquery(string $content): string
    {
        $siteConfig = $this->container->getVariable('site_config') ?? [];
        $popupConfig = is_array($siteConfig['popup'] ?? null) ? $siteConfig['popup'] : [];

        if (($popupConfig['load_jquery'] ?? true) === false) {
            return $content;
        }

        if (preg_match('/<script[^>]*jquery[^>]*>/i', $content) === 1) {
            return $content;
        }

        $headPos = strpos($content, '</head>');
        if ($headPos === false) {
            $this->logger->log('WARNING', 'Cannot inject jQuery: no </head> tag found in rendered output');
            return $content;
        }

        $url = is_string($popupConfig['jquery_url'] ?? null) ? $popupConfig['jquery_url'] : self::DEFAULT_JQUERY_URL;

        if (!str_starts_with($url, 'https://') && !str_starts_with($url, '/')) {
            $this->logger->log('WARNING', "popup.jquery_url must be https: or root-relative, ignoring: {$url}");
            return $content;
        }

        $integrity = array_key_exists('jquery_integrity', $popupConfig)
            ? $popupConfig['jquery_integrity']
            : self::DEFAULT_JQUERY_INTEGRITY;
        $integrity = is_string($integrity) ? $integrity : '';

        if ($url !== self::DEFAULT_JQUERY_URL && $integrity === self::DEFAULT_JQUERY_INTEGRITY) {
            $this->logger->log(
                'WARNING',
                'popup.jquery_url was overridden but popup.jquery_integrity was left at its default; '
                    . 'dropping the integrity attribute since the hash cannot match'
            );
            $integrity = '';
        }

        if ($integrity === '' && str_starts_with($url, 'https://')) {
            // Catches both an intentional `jquery_integrity: ''` and a YAML
            // typo (empty/null/non-string value) that resolves the same way
            // — either way, the operator should know this script is unpinned.
            $this->logger->log(
                'WARNING',
                "popup.jquery_url ({$url}) has no integrity hash; the injected <script> tag will be unpinned"
            );
        }

        $tag = '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
        if ($integrity !== '') {
            $tag .= ' integrity="' . htmlspecialchars($integrity, ENT_QUOTES, 'UTF-8') . '" crossorigin="anonymous"';
        }
        $tag .= '></script>';

        return substr_replace($content, $tag . "\n</head>", $headPos, strlen('</head>'));
    }
}
