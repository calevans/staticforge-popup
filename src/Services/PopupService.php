<?php

namespace Calevans\StaticForgePopup\Services;

use EICC\Utils\Container;
use EICC\Utils\Log;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RegexIterator;
use Twig\Environment;

class PopupService
{
    private PopupParser $parser;
    private Log $logger;
    private Environment $twig;
    private array $popups = [];

    public function __construct(PopupParser $parser, Log $logger, Environment $twig)
    {
        $this->parser = $parser;
        $this->logger = $logger;
        $this->twig = $twig;
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

    public function injectPopups(string $content, array $metadata): string
    {
        if (empty($metadata['popup'])) {
            return $content;
        }

        $requestedPopups = $metadata['popup'];
        if (!is_array($requestedPopups)) {
            $requestedPopups = [$requestedPopups];
        }

        $popupsToRender = [];
        $popupConfigs = [];
        $cssInjections = [];

        // Always inject base popup CSS if we have any popups
        $cssInjections[] = '<link rel="stylesheet" href="/assets/css/popup.css">';

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

            // Check for specific CSS
            $specificCssPath = 'content/assets/css/' . $popupId . '.css';
            if (file_exists(getcwd() . '/' . $specificCssPath)) {
                $cssInjections[] = '<link rel="stylesheet" href="/assets/css/' . $popupId . '.css">';
            }
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

        // Prepare JS injection
        // Note: We assume popup.js is in the feature directory, but for the built site it should be in assets.
        // However, the legacy code read it from __DIR__ . '/popup.js'.
        // We should probably read it from the feature directory.
        // Since this service is in Services/, we need to go up one level.
        $jsContent = file_get_contents(dirname(__DIR__) . '/popup.js');
        $jquery = '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>';
        $configScript = '<script>window.sfPopups = ' . json_encode($popupConfigs) . ';</script>';

        // Inject CSS into head
        $headClose = '</head>';
        $cssString = implode("\n", $cssInjections);
        $content = str_replace($headClose, $cssString . "\n" . $headClose, $content);

        // Inject HTML and JS before body close
        $bodyClose = '</body>';
        $injection = "\n<!-- Popup Feature -->\n";
        $injection .= $renderedHtml . "\n";
        $injection .= $jquery . "\n";
        $injection .= $configScript . "\n";
        $injection .= "<script>\n" . $jsContent . "\n</script>\n";

        $content = str_replace($bodyClose, $injection . $bodyClose, $content);

        return $content;
    }

    private function processForms(string $content, array $metadata, Container $container): string
    {
        if (preg_match_all('/\{\{\s*form\([\'"]([a-zA-Z0-9_-]+)[\'"]\)\s*\}\}/', $content, $matches, PREG_SET_ORDER)) {
            $siteConfig = $container->getVariable('site_config');

            if (!$siteConfig) {
                 $siteConfig = $container->get('config');
            }

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
}
