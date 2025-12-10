$(document).ready(function() {
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
        }

        $popup.find('.close-popup').on('click', function() {
            $popup.fadeOut();
        });

        // Close on click outside
        $popup.on('click', function(e) {
            if ($(e.target).is($popup)) {
                $popup.fadeOut();
            }
        });

        // Handle Form Submission
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

            $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method'),
                data: $form.serialize(),
                success: function(response) {
                    // Sendy returns "1" or "true" on success when boolean=true is passed
                    // Otherwise it returns an error message string
                    if (response === '1' || response === 'true' || response === true) {
                        $form.find('.form-group, .form-actions').slideUp();
                        $successMsg.text(successText).fadeIn();
                        setCookie(cookieName, 'shown', blockedDays);

                        // Optional: Close popup after a delay
                        setTimeout(function() {
                            $popup.fadeOut();
                        }, 3000);
                    } else {
                        // It's an error message from Sendy
                        $errorMsg.text(response).fadeIn();
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
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
