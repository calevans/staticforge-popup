<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Services;

use EICC\Utils\Container;
use EICC\Utils\Log;
use Twig\Environment;

/**
 * Expands `{{ form('name') }}` shortcodes inside a popup's raw markdown body
 * into rendered forms, using `site_config['forms']`.
 *
 * Runs before markdown conversion (see PopupParser), matching core's Forms
 * feature: `<form>` is not valid inside `<p>`, so expanding after conversion
 * would leave the form wrapped in the paragraph the shortcode line became.
 *
 * Deliberate divergence from core's Features/Forms/Services/FormsService:
 * core appends `?FORMID=` unconditionally whenever provider_url is non-empty,
 * even with no form_id set. This package appends it only when `form_id` is
 * set AND `append_form_id` is true. Do not "fix" this into delegating to
 * FormsService::generateFormHtml() — doing so would silently change the
 * action URL of every already-deployed popup form that has no form_id.
 */
final class PopupFormRenderer
{
    public function __construct(
        private readonly Log $logger,
        private readonly Environment $twig,
        private readonly Container $container,
    ) {
    }

    /**
     * @param string $content Raw markdown body of the popup.
     * @param string|null $urlOverride The popup's `url:` frontmatter override.
     */
    public function expand(string $content, ?string $urlOverride): string
    {
        $pattern = '/\{\{\s*form\((["\'])([a-zA-Z0-9_-]+)\1\)\s*\}\}/';
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return $content;
        }

        $siteConfig = $this->container->getVariable('site_config') ?? [];
        $formsConfig = is_array($siteConfig['forms'] ?? null) ? $siteConfig['forms'] : [];

        foreach ($matches as $match) {
            $formName = $match[2];

            if (!isset($formsConfig[$formName]) || !is_array($formsConfig[$formName])) {
                $this->logger->log('WARNING', "Form '{$formName}' not found in siteconfig.yaml");
                continue;
            }

            $formHtml = $this->generateFormHtml($formsConfig[$formName], $urlOverride, $formName);
            $content = str_replace($match[0], $formHtml, $content);
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function generateFormHtml(array $config, ?string $urlOverride, string $formName): string
    {
        $override = $this->requireHttpsUrl($urlOverride ?? '', 'popup frontmatter url override');
        $providerUrl = $this->requireHttpsUrl($this->stringConfig($config, 'provider_url'), 'provider_url');
        $providerUrl = $override !== '' ? $override : $providerUrl;

        $formId = $this->stringConfig($config, 'form_id');
        $appendFormId = (bool) ($config['append_form_id'] ?? true);

        $endpoint = $providerUrl;
        if ($providerUrl !== '' && $formId !== '' && $appendFormId) {
            $endpoint .= str_contains($providerUrl, '?') ? '&FORMID=' . $formId : '?FORMID=' . $formId;
        }

        $challengeUrl = $this->requireHttpsUrl($this->stringConfig($config, 'challenge_url'), 'challenge_url');
        $assumeSuccess = (bool) ($config['assume_success_on_opaque_response'] ?? false);

        $context = [
            'endpoint' => $endpoint,
            'challenge_url' => $challengeUrl ?: null,
            'submit_text' => $config['submit_text'] ?? 'Submit',
            'success_message' => $config['success_message'] ?? 'Thank you for your message.',
            'error_message' => $config['error_message'] ?? 'There was an error sending your message.',
            'fields' => $config['fields'] ?? [],
            'assume_success' => $assumeSuccess,
        ];

        $html = $this->twig->render('_popup_form.html.twig', $context);

        if ($assumeSuccess && !str_contains($html, 'data-assume-success')) {
            $this->logger->log(
                'WARNING',
                "Popup form '{$formName}' has assume_success_on_opaque_response enabled, but its "
                    . 'rendered _popup_form.html.twig does not include data-assume-success; add '
                    . 'data-assume-success="1" (or the {% if assume_success %} conditional) to the '
                    . 'form tag or the flag has no effect.'
            );
        }

        // The result is spliced into markdown, and CommonMark ends an HTML
        // block at the first blank line: any whitespace-only line inside the
        // form would drop everything after it out of the HTML block and into
        // an indented code block. Dropping blank lines keeps it one block.
        return (string) preg_replace('/^[ \t]*\R/m', '', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function stringConfig(array $config, string $key): string
    {
        $value = $config[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Returns $url unchanged if it's https: (or empty), otherwise logs a
     * warning and returns an empty string so an http:/javascript:-shaped
     * value never reaches a rendered `action=` attribute.
     */
    private function requireHttpsUrl(string $url, string $label): string
    {
        if ($url === '' || str_starts_with($url, 'https://')) {
            return $url;
        }

        $this->logger->log('WARNING', "Popup form {$label} must use https:, ignoring: {$url}");
        return '';
    }
}
