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
*   `id` (optional): Unique identifier. Defaults to the filename.
*   `exit_intent` (optional): `true` to trigger when the user mouses out of the viewport.
*   `timer` (optional): Seconds to wait before showing the popup.
*   `popup_blocked_for` (optional): Days to hide the popup after it has been shown (default: 30).

### Step 3: Create the Templates

You need Twig templates to control the HTML structure of your popups.

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
<form action="{{ endpoint }}" method="POST" class="sf-popup-form" data-success-message="{{ success_message }}" data-error-message="{{ error_message }}">
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

### Step 4: Add Styling

The feature automatically looks for CSS files to style your popup.

1.  **Global Styles**: Create `content/assets/css/popup.css`. This file is automatically injected on pages with popups.
2.  **Specific Styles**: Create `content/assets/css/{popup-id}.css` for styles specific to one popup.

**Example `content/assets/css/popup.css`**:
```css
.sf-popup-overlay {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 1000;
    display: flex;
    justify-content: center;
    align-items: center;
}
.sf-popup-content {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    position: relative;
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

## Requirements

*   PHP 8.4+
*   StaticForge
*   jQuery (Automatically injected if not present)

## License

MIT
