<?php

namespace Calevans\StaticForgePopup\Services;

use EICC\Utils\Container;
use EICC\Utils\Log;
use EICC\StaticForge\Core\AssetManager;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RegexIterator;
use Twig\Environment;

class PopupService
{
    private PopupParser $parser;
    private Log $logger;
    private Environment $twig;
    private AssetManager $assetManager;
    private array $popups = [];

    public function __construct(PopupParser $parser, Log $logger, Environment $twig, AssetManager $assetManager)
    {
        $this->parser = $parser;
        $this->logger = $logger;
        $this->twig = $twig;
        $this->assetManager = $assetManager;
    }

    public function loadPopups(Container $container): void
    {
        $config = $container->get('config');
        $sourceDir = $config['source_dir'] ?? 'content';
        $sourcePath = getcwd() . '/' . $sourceDir;

        if (!is_dir($sourcePath)) {
            return;
        }

        $directory = new RecursiveDirectoryIterator($sourcePath);
        $iterator = new RecursiveIteratorIterator($directory);
        $regex = new RegexIterator($iterator, '/^.+\.popup$/i', RegexIterator::GET_MATCH);

        foreach ($regex as $file) {
            $filePath = $file[0];
            $content = file_get_contents($filePath);
            if ($content) {
                $parsed = $this->parser->parse($content, basename($filePath, '.popup'));
                if ($parsed) {
                    // Process forms before storing
                    $parsed['content'] = $this->processForms($parsed['content'], $parsed['metadata'], $container);

                    $id = $parsed['metadata']['id'];
                    $this->popups[$id] = $parsed;
                }
            }
        }
    }

    public function registerAssets(Container $container, array $metadata): void
    {
        // Get config to see if paths are overridden
        $config = $container->get('config');
        $popupCss = $config['popup']['css_url'] ?? '/assets/css/popup.css';
        $popupJs = $config['popup']['js_url'] ?? '/assets/js/popup.js';

        // Always inject base popup CSS if we have any popups
        $this->assetManager->addStyle('popup-base', $popupCss);

        // Add jQuery dependency for popups (AssetManager will handle duplicates)
        $this->assetManager->addScript('jquery', 'https://code.jquery.com/jquery-3.7.1.min.js', [], true);

        // Add popup JS
        $this->assetManager->addScript('popup-js', $popupJs, ['jquery'], true);

        // Add per-popup CSS if available
        $requestedPopups = $metadata['popup'];
        if (!is_array($requestedPopups)) {
            $requestedPopups = [$requestedPopups];
        }

        foreach ($requestedPopups as $popupId) {
            // Check for specific CSS in content source
            $specificCssPath = 'content/assets/css/' . $popupId . '.css';
            if (file_exists(getcwd() . '/' . $specificCssPath)) {
                $this->assetManager->addStyle('popup-' . $popupId, '/assets/css/' . $popupId . '.css');
            }
        }
    }


    public function injectPopups(string $content, array $metadata, ?Container $container = null): string
    {
        if (empty($metadata['popup'])) {
            return $content;
        }

        // Manual CSS Injection (Safety net if AssetManager didn't output styles)
        if ($container) {
             $config = $container->get('config');
             $cssUrl = $config['popup']['css_url'] ?? '/assets/css/popup.css';

             if (strpos($content, $cssUrl) === false) {
                  $link = '<link rel="stylesheet" href="' . $cssUrl . '">';
                  if (strpos($content, '</head>') !== false) {
                      $content = str_replace('</head>', $link . "\n</head>", $content);
                  }
             }
        }

        $requestedPopups = $metadata['popup'];
        if (!is_array($requestedPopups)) {
            $requestedPopups = [$requestedPopups];
        }

        $popupsToRender = [];
        $popupConfigs = [];

        foreach ($requestedPopups as $popupId) {
            if (!isset($this->popups[$popupId])) {
                $this->logger->log('WARNING', "Popup '$popupId' requested but not found.");
                continue;
            }

            $popup = $this->popups[$popupId];
            $popupsToRender[] = $popup;

            // Config for JS
            $popupConfigs[] = [
                'id' => $popup['metadata']['id'],
                'exit_intent' => $popup['metadata']['exit_intent'] ?? false,
                'timer' => $popup['metadata']['timer'] ?? 0,
                'blocked_days' => $popup['metadata']['popup_blocked_for'] ?? 30
            ];
        }

        if (empty($popupsToRender)) {
            return $content;
        }

        // Render HTML
        $renderedHtml = '';
        foreach ($popupsToRender as $popup) {
            $templateName = $popup['metadata']['id'] . '.html.twig';
            try {
                $renderedHtml .= $this->twig->render($templateName, ['popup' => $popup]);
            } catch (\Exception $e) {
                // Fallback to default
                try {
                    $renderedHtml .= $this->twig->render('popup.html.twig', ['popup' => $popup]);
                } catch (\Exception $ex) {
                    $this->logger->log('ERROR', 'Failed to render popup ' . $popup['metadata']['id'] . ': ' . $ex->getMessage());
                }
            }
        }

        $configScript = '<script>window.sfPopups = ' . json_encode($popupConfigs) . ';</script>';

        // Inject HTML and JS before body close
        $bodyClose = '</body>';
        $injection = "\n<!-- Popup Feature -->\n";
        $injection .= $renderedHtml . "\n";
        $injection .= $configScript . "\n";

        $content = str_replace($bodyClose, $injection . $bodyClose, $content);

        return $content;
    }

    private function processForms(string $content, array $metadata, Container $container): string
    {
        if (preg_match_all('/\{\{\s*form\([\'"]([a-zA-Z0-9_-]+)[\'"]\)\s*\}\}/', $content, $matches, PREG_SET_ORDER)) {
            $siteConfig = $container->getVariable('site_config');
            $formsConfig = $siteConfig['forms'] ?? [];

            foreach ($matches as $match) {
                $fullMatch = $match[0];
                $formName = $match[1];

                if (!isset($formsConfig[$formName])) {
                    $this->logger->log('WARNING', "Form '{$formName}' not found in siteconfig.yaml");
                    continue;
                }

                $formHtml = $this->generateFormHtml($formsConfig[$formName], $metadata);
                $content = str_replace($fullMatch, $formHtml, $content);
            }
        }
        return $content;
    }

    private function generateFormHtml(array $config, array $metadata = []): string
    {
        $providerUrl = $metadata['url'] ?? $config['provider_url'] ?? '';
        $formId = $config['form_id'] ?? '';
        $appendFormId = $config['append_form_id'] ?? true;

        $endpoint = $providerUrl;
        if ($formId && $appendFormId) {
            if (strpos($providerUrl, '?') !== false) {
                $endpoint .= '&FORMID=' . $formId;
            } else {
                $endpoint .= '?FORMID=' . $formId;
            }
        }


        $context = [
            'endpoint' => $endpoint,
            'challenge_url' => $config['challenge_url'] ?? null,
            'submit_text' => $config['submit_text'] ?? 'Submit',
            'success_message' => $config['success_message'] ?? 'Thank you for your message.',
            'error_message' => $config['error_message'] ?? 'There was an error sending your message.',
            'fields' => $config['fields'] ?? [],
        ];

        return $this->twig->render('_popup_form.html.twig', $context);
    }

    public function copyAssets(Container $container): void
    {
        $outputDir = $container->getVariable('OUTPUT_DIR');
        if (!$outputDir) {
             $this->logger->log('WARNING', 'OUTPUT_DIR not set in container, defaulting to "output"');
             $outputDir = 'output';
        }

        // Define destination paths
        $jsDestDir = $outputDir . '/assets/js';
        $cssDestDir = $outputDir . '/assets/css';

        // Ensure directories exist
        if (!is_dir($jsDestDir)) {
            mkdir($jsDestDir, 0755, true);
        }
        if (!is_dir($cssDestDir)) {
            mkdir($cssDestDir, 0755, true);
        }

        // Feature source directory (up one level from Services)
        $featureDir = dirname(__DIR__);

        // Copy JS
        $jsSource = $featureDir . '/popup.js';
        if (file_exists($jsSource)) {
            copy($jsSource, $jsDestDir . '/popup.js');
            $this->logger->log('DEBUG', 'Copied popup.js to assets');
        } else {
             $this->logger->log('WARNING', 'Could not find popup.js at ' . $jsSource);
        }

        // Copy CSS
        $cssSource = $featureDir . '/popup.css';
        if (file_exists($cssSource)) {
            copy($cssSource, $cssDestDir . '/popup.css');
            $this->logger->log('DEBUG', 'Copied popup.css to assets');
        } else {
             $this->logger->log('WARNING', 'Could not find popup.css at ' . $cssSource);
        }
    }
}
