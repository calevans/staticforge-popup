$(document).ready(function() {
    // The config lives in a <script type="application/json"> tag rather than
    // an inline `window.sfPopups = ...` assignment (so a strict CSP doesn't
    // need 'unsafe-inline'). Read it here, not at file-execution time: core
    // emits the <script src="popup.js"> tag before this feature's POST_RENDER
    // splice, so #sf-popups does not exist in the DOM yet when this file runs
    // — only by the time $(document).ready() fires.
    var sfPopupsEl = document.getElementById('sf-popups');
    if (sfPopupsEl) {
        try {
            window.sfPopups = JSON.parse(sfPopupsEl.textContent);
        } catch (e) {
            // Malformed/absent content: fall through and let the guard below
            // no-op rather than letting JSON.parse's exception kill the handler.
        }
    }

    if (typeof window.sfPopups === 'undefined' || !Array.isArray(window.sfPopups)) {
        return;
    }

    window.sfPopups.forEach(function(popupConfig) {
        var id = popupConfig.id;
        var $popup = $('#sf-popup-' + id);

        if ($popup.length === 0) return;

        var exitIntent = popupConfig.exit_intent;
        var timer = popupConfig.timer;
        var blockedDays = popupConfig.blocked_days;
        var cookieName = 'sf_popup_' + id;
        var shown = false;

        if (getCookie(cookieName)) {
            return;
        }

        function showPopup() {
            if (shown || getCookie(cookieName)) return;
            shown = true;
            $popup.css('display', 'flex').hide().fadeIn();
            setCookie(cookieName, 'shown', blockedDays);
        }

        if (timer > 0) {
            setTimeout(showPopup, timer * 1000);
        }

        if (exitIntent) {
            $(document).on('mouseleave', function(e) {
                if (e.clientY < 0) {
                    showPopup();
                }
            });
        } else if (timer <= 0) {
            console.warn('sf-popup: popup "' + id + '" has no timer and no exit_intent, so it can never be shown.');
        }

        $popup.find('.close-popup').on('click', function() {
            $popup.fadeOut();
        });

        $popup.on('click', function(e) {
            if ($(e.target).is($popup)) {
                $popup.fadeOut();
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $popup.is(':visible')) {
                $popup.fadeOut();
            }
        });

        $popup.find('form.sf-popup-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $successMsg = $form.find('.success-message');
            var $errorMsg = $form.find('.error-message');

            var successText = $form.data('success-message');
            var errorText = $form.data('error-message');

            $btn.prop('disabled', true);
            $errorMsg.hide();
            $successMsg.hide();

            function handleSuccess() {
                $form.find('.form-group, .form-actions').slideUp();
                $successMsg.text(successText).fadeIn();
                setCookie(cookieName, 'shown', blockedDays);

                setTimeout(function() {
                    $popup.fadeOut();
                }, 3000);
            }

            $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method'),
                data: $form.serialize(),
                timeout: 15000,
                success: function(response) {
                    // Some endpoints (e.g. Sendy) return "1"/"true" on success and an
                    // error message string on failure with an HTTP 200 either way.
                    // An empty body or "OK" are treated as success for endpoints that
                    // don't follow that convention.
                    if (response === '1' || response === 'true' || response === true ||
                        response === '' || response === 'OK') {
                        handleSuccess();
                    } else {
                        $errorMsg.text(response).fadeIn();
                        $btn.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    // A status of 0 means the browser blocked the response outright
                    // (e.g. a CORS-blocked cross-origin POST) rather than the server
                    // reporting a real failure; a genuine HTTP error (404, 500, ...)
                    // still has a status and is never treated as success here.
                    if ($form.attr('data-assume-success') !== undefined && xhr.status === 0) {
                        handleSuccess();
                        return;
                    }

                    $errorMsg.text(errorText).fadeIn();
                    $btn.prop('disabled', false);
                }
            });
        });
    });

    function setCookie(name, value, days) {
        var expires = "";
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "") + expires + "; path=/";
    }

    function getCookie(name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) == ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
    }
});
