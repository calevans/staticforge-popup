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
php bin/staticforge feature:setup staticforge-popup
```

## Configuration

### 1. Define a Popup

Create a `.popup` file in your content directory (e.g., `content/popups/newsletter.popup`).

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

*   `popup_enabled` (required): Set to `true` to enable this popup definition.
*   `id` (optional): Unique identifier for the popup. Defaults to the filename.
*   `exit_intent` (optional): `true` to show when the user tries to leave the page.
*   `timer` (optional): Number of seconds to wait before showing the popup.
*   `popup_blocked_for` (optional): Number of days to hide the popup after it has been shown (default: 30).

### 2. Enable on a Page

Add the `popup` key to the frontmatter of any page where you want the popup to appear.

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

### 3. Templates & Styling

The feature looks for Twig templates to render the popup.

1.  **Default Template**: Create `templates/popup.html.twig`.
2.  **Specific Template**: Create `templates/{popup-id}.html.twig` (e.g., `templates/newsletter-signup.html.twig`).

**Example `popup.html.twig`:**

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

**CSS:**
*   The feature automatically injects a link to `/assets/css/popup.css`.
*   If a file exists at `content/assets/css/{popup-id}.css`, it will also be injected.

### 4. Form Template (Optional)

If you use the `{{ form() }}` helper in your popups, you must provide a template named `_popup_form.html.twig`.

**Example `_popup_form.html.twig`:**

```twig
<form action="{{ endpoint }}" method="POST" class="popup-form">
    {% for field in fields %}
        <div class="form-group">
            <label for="{{ field.name }}">{{ field.label }}</label>
            <input type="{{ field.type }}" name="{{ field.name }}" id="{{ field.name }}" {% if field.required %}required{% endif %}>
        </div>
    {% endfor %}
    <button type="submit">{{ submit_text }}</button>
</form>
```

## Requirements

*   PHP 8.4+
*   StaticForge
*   jQuery (Automatically injected if not present)

## License

MIT
