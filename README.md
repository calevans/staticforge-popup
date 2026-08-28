# [StaticForge](https://calevans.com/staticforge) Popup Feature

A powerful popup management feature for StaticForge sites. This library allows you to define popups using Markdown and YAML frontmatter, and easily attach them to specific pages.

Copyright 2025, Cal Evans<br />
License: MIT<br />

## Features

*   **Markdown Support**: Define popup content using standard Markdown.
*   **Flexible Triggers**:
    *   **Timer**: Show popup after a specified delay.
    *   **Exit Intent**: Show popup when the user moves their mouse out of the viewport.
*   **Smart Blocking**: Uses cookies to prevent showing the same popup repeatedly (configurable duration).
*   **Page-Specific Targeting**: Enable popups on specific pages via frontmatter.
*   **Custom Styling**: Support for global popup CSS and per-popup CSS files.
*   **Form Integration**: Built-in support for StaticForge forms.

## Installation

Install via Composer:

```bash
composer require calevans/staticforge-popup
php bin/staticforge feature:setup calevans/staticforge-popup
```

`feature:setup` copies the Twig partials into `templates/{your-template}/`, drops a starter
`content/assets/css/popup.css` you can theme, and writes `siteconfig.yaml.example.staticforge-popup`
for you to merge into your own `siteconfig.yaml`.

## Configuration & Usage

Implementing a popup involves 5 steps. If you are not using a form in your popup, you can skip Step 1.

### Step 1: Define the Form (Optional)

If your popup includes a form (like a newsletter signup), you must first define it in your `siteconfig.yaml`. This allows the popup feature to know about the fields and submission URL.

**`siteconfig.yaml`**:
```yaml
forms:
  newsletter:
    provider_url: "https://your-newsletter-provider.com/subscribe"
    submit_text: "Subscribe Now"
    success_message: "Thanks for signing up!"
    error_message: "Something went wrong. Please try again."
    fields:
      - name: first_name
        label: First Name
        type: text
        required: true
      - name: email
        label: Email Address
        type: email
        required: true
```

**What is `provider_url`?**

The `provider_url` is the endpoint where the form data will be POSTed when the user clicks submit. This can be any service that accepts form data.

*   **[Sendpoint](https://github.com/calevans/sendpoint)**: A simple, secure, self-hosted solution designed specifically for handling form submissions from static sites. It does one thing: validates data and sends emails.
*   **[n8n](https://n8n.io)**: A powerful workflow automation tool that can receive webhooks and process the data (e.g., add to a CRM, send an email, etc.).
*   **Other Services**: Any newsletter provider (Mailchimp, ConvertKit) or form handling service (Formspree) that provides a submission URL.

**`provider_url` and `challenge_url` must use `https://`.** A value with any other scheme is
ignored and a warning is logged, so a misconfigured endpoint fails closed instead of silently
posting your visitors' data over cleartext.

**Your endpoint must send CORS headers.** The form is submitted with AJAX from the browser, so
the endpoint needs to return `Access-Control-Allow-Origin` for your site. Without it the browser
blocks the response, the submission is reported to the visitor as a failure, and there is no way
for the JavaScript to tell that apart from a genuine error. If you do not control the endpoint and
cannot get it to send that header, `assume_success_on_opaque_response` (below) is the escape hatch.

**Optional form keys:**

*   `form_id`: An identifier some providers require.
*   `append_form_id` (default `true`): When a `form_id` is set, append `?FORMID=<form_id>` to the
    endpoint (or `&FORMID=` if it already has a query string). Set to `false` to send the endpoint
    exactly as written. Nothing is appended when `form_id` is empty.
*   `challenge_url`: An [Altcha](https://altcha.org) challenge endpoint, if your form template uses one.
*   `assume_success_on_opaque_response` (default `false`): For an endpoint that does not send
    `Access-Control-Allow-Origin` and cannot be made to. The submission genuinely succeeds, but the
    browser blocks the response so the JS cannot read it. When `true`, a blocked/opaque response
    (no HTTP status reached the page, i.e. a CORS block) is treated as success; a real HTTP error
    (e.g. a 500) still reports failure. Using this requires your `_popup_form.html.twig` to render
    `data-assume-success="1"` on the `<form>` tag — existing sites have their own copy of that
    template, so setting this flag alone does nothing until you add the attribute (see Step 3).

### Step 2: Create the Popup Content

Create a `.popup` file in your content directory (e.g., `content/popups/newsletter.popup`). This file defines the content and behavior of your popup.

**`content/popups/newsletter.popup`**:
```markdown
---
popup_enabled: true
id: newsletter-signup
exit_intent: true
timer: 5
popup_blocked_for: 30
---

# Join our Newsletter!

Get the latest updates directly to your inbox.

{{ form('newsletter') }}
```

**Frontmatter Options:**
*   `popup_enabled` (required): Set to `true` to enable this popup.
*   `id` (optional): Unique identifier. Defaults to the filename. Must match `[A-Za-z0-9_-]` and be
    1-64 characters; a popup with an invalid id is skipped and an error is logged. The id becomes a
    Twig template name, a CSS filename, a DOM id and a cookie name, which is why it is constrained.
*   `exit_intent` (optional): `true` to trigger when the user mouses out of the viewport.
*   `timer` (optional): Seconds to wait before showing the popup.
*   `popup_blocked_for` (optional): Days to hide the popup after it has been shown. Defaults to
    `popup.default_blocked_days` from `siteconfig.yaml`, which itself defaults to 30.
*   `url` (optional): Overrides the `provider_url` defined in `siteconfig.yaml` for this specific popup's form.

A popup that sets neither `timer` nor `exit_intent` can never be displayed; the JavaScript logs a
console warning if it finds one.

**Overriding the Form URL**

While `siteconfig.yaml` defines the default `provider_url` for your forms, you may have a specific popup that needs to submit data to a different endpoint (e.g., a different newsletter list or a specific campaign handler).

To do this, simply add the `url` key to your popup's frontmatter:

```yaml
---
popup_enabled: true
id: special-campaign
url: "https://hooks.zapier.com/hooks/catch/123456/abcdef/"
---
```

When this popup is rendered, the form action will use this URL instead of the global one defined in your configuration. Like `provider_url`, it must be `https://`.

> **Multi-author caveat.** The `url:` override lets whoever writes a `.popup` file choose where
> your visitors' form submissions are sent, and a `.popup` body is raw authored content that is
> injected into *every* page referencing it — not just one page, the way a markdown file is. If you
> accept content from people you do not fully trust, review `.popup` files the same way you would
> review code.

### Step 3: Create the Templates

You need Twig templates to control the HTML structure of your popups. `feature:setup` installs both
of these for you; the versions below are the minimum each one has to provide.

**A. The Popup Container (`templates/popup.html.twig`)**
This template wraps your popup content. It handles the overlay and the close button.

```twig
<div id="sf-popup-{{ popup.metadata.id }}" class="sf-popup-overlay" style="display:none;">
    <div class="sf-popup-content">
        <button class="close-popup">&times;</button>
        <div class="popup-body">
            {{ popup.content | raw }}
        </div>
    </div>
</div>
```
*Note: You can create specific templates for individual popups by naming them `templates/{popup-id}.html.twig`.*

**B. The Form Template (`templates/_popup_form.html.twig`)**
If you used `{{ form() }}` in Step 2, you **must** create this template to render the form fields.

```twig
<form action="{{ endpoint }}" method="POST" class="sf-popup-form" data-success-message="{{ success_message }}" data-error-message="{{ error_message }}"{% if assume_success %} data-assume-success="1"{% endif %}>
    {% for field in fields %}
        <div class="form-group">
            <label for="{{ field.name }}">{{ field.label }}</label>
            <input type="{{ field.type }}" name="{{ field.name }}" id="{{ field.name }}" {% if field.required %}required{% endif %}>
        </div>
    {% endfor %}
    <button type="submit">{{ submit_text }}</button>
    <div class="success-message" style="display:none;"></div>
    <div class="error-message" style="display:none;"></div>
</form>
```

If a form has `assume_success_on_opaque_response: true` in `siteconfig.yaml` but your
`_popup_form.html.twig` does not render `data-assume-success`, the feature logs a WARNING naming
the form and telling you to add the attribute — the flag otherwise has no visible effect, since
the JS never sees it.

### Step 4: Add Styling

Two stylesheets are involved, and they are deliberately separate:

1.  **Structural styles** ship with the feature as `sf-popup.css` and are published to
    `/assets/css/sf-popup.css` on every build. This is what makes the overlay cover the screen and
    center its content. You do not edit this file.
2.  **Your theme**: `content/assets/css/popup.css`, installed by `feature:setup`. It loads *after*
    the structural styles, so anything you put here wins. This file is yours; the feature never
    overwrites it.
3.  **Per-popup styles**: `content/assets/css/{popup-id}.css`, loaded after your theme for pages
    showing that popup.

**Example `content/assets/css/popup.css`**:
```css
.sf-popup-content {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
}
.close-popup {
    position: absolute;
    top: 10px;
    right: 10px;
    border: none;
    background: none;
    font-size: 1.5rem;
    cursor: pointer;
}
```

### Step 5: Enable on a Page

Finally, tell StaticForge which pages should display the popup by adding the `popup` key to the page's frontmatter.

**`content/index.md`**:
```markdown
---
title: Home Page
popup: newsletter-signup
---

Welcome to my website!
```

You can also attach multiple popups:
```markdown
popup:
  - newsletter-signup
  - special-offer
```

## Site Configuration

Every key below is optional; the feature works with no `popup:` block at all. See
`siteconfig.yaml.example` for the annotated version.

```yaml
popup:
  css_url: /assets/css/sf-popup.css
  js_url: /assets/js/popup.js
  publish_assets: true
  default_blocked_days: 30
  load_jquery: true
  jquery_url: https://code.jquery.com/jquery-3.7.1.min.js
  jquery_integrity: sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs
```

*   `publish_assets`: set to `false` if you vendor your own copies of `popup.js`/`sf-popup.css` and
    do not want them overwritten on each build.
*   `load_jquery`: set to `false` if your template already loads jQuery.
*   `jquery_url` / `jquery_integrity`: the CDN tag is pinned with a Subresource Integrity hash. If
    you self-host jQuery, set `jquery_url` to a root-relative path and either supply the matching
    hash or set `jquery_integrity: ''`. Overriding the URL while leaving the default hash in place
    would produce a script the browser refuses to run, so in that case the feature drops the hash
    and logs a warning instead.

## jQuery

This feature's JavaScript requires jQuery, and injects it into `<head>` on pages that show a popup,
pinned with an SRI hash and `crossorigin="anonymous"`. If your theme already provides jQuery, set
`popup.load_jquery: false` so it is not loaded twice.

## Upgrading from earlier 3.x

This release changes some shipped behavior. None of it requires you to edit templates, but read
these before you deploy:

*   **Failed form submissions now report failure.** Previously, if the POST failed and the form
    action contained `mlm/subscribe`, the visitor was shown the success message anyway. That
    hardcoded vendor workaround is gone. If you use Sendy, make sure your endpoint returns
    `Access-Control-Allow-Origin` or your visitors will now correctly see an error.
*   **`provider_url`, `challenge_url` and frontmatter `url:` must be `https://`.** Non-https values
    are dropped with a warning instead of being rendered into the form action.
*   **Popup ids must match `[A-Za-z0-9_-]{1,64}`.** A popup with an invalid id is skipped and an
    error is logged.
*   **The bundled stylesheet is now `/assets/css/sf-popup.css`.** It used to be published over
    `/assets/css/popup.css`, which collided with the themeable file of the same name that
    `feature:setup` installs; which one won was undefined. `popup.css` is now exclusively yours.
    If you hardcoded `/assets/css/popup.css` in your own template, nothing breaks — but the
    structural styles now live at the new URL.
*   **The `popup:` block in `siteconfig.yaml` now actually takes effect.** It was previously read
    from a container key that does not exist in StaticForge 3.x, so every value silently fell back
    to its default. If you set `css_url` or `js_url` in the past and worked around them being
    ignored, check those settings now.
*   **jQuery is pinned with an SRI hash** and can be turned off with `popup.load_jquery: false`.
*   **Malformed popup frontmatter is now logged** as an error instead of silently skipped.

## Requirements

*   PHP 8.5+
*   StaticForge 3.x
*   jQuery (injected automatically unless you turn it off)

## Development

```bash
composer test   # phpunit
composer stan   # phpstan, level 8
composer cs     # phpcs, PSR-12
```

## License

MIT
