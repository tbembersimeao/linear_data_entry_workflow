document.addEventListener('DOMContentLoaded', function() {
    var settings = linearDataEntryWorkflow.rfio;
    if (settings.hideNextRecordButton && !settings.isException) {
        // Hiding "Save & Go To Next Record" buttons.
        removeButtons('savenextrecord');
    }

    if (settings.forceButtonsDisplay !== 'show') {
        // Hiding "Save & Go to Next Form" buttons.
        hideNextFormButtons(settings.instrument, settings.forceButtonsDisplay === 'hide');
    }

    /**
     * Hide "Save and Go to Next Form" buttons.
     */
    function hideNextFormButtons(instrument, force = false) {
        var $buttonsBottom = $('#__SUBMITBUTTONS__-div .btn-group');
        var $buttonsTop = $('#formSaveTip .btn-group');

        // Handling "Ignore and go to next form" button on required fields
        // dialog.
        $('#reqPopup').on('dialogopen', function(event, ui) {
            var buttons = $(this).dialog('option', 'buttons');

            $.each(buttons, function(i, button) {
                if (button.name === 'Ignore and go to next form') {
                    delete buttons[i];
                    return false;
                }
            });

            $(this).dialog('option', 'buttons', buttons);
        });

        if (force) {
            removeButtons('savenextform');
            return;
        }

        const FORM_STATUS_COMPLETE = '2';
        var $complete = $('[name="' + instrument + '_complete"]');

        // Storing original buttons markup.
        var originalBottom = $buttonsBottom.html();
        var originalTop = $buttonsTop.html();

        // Checking initial form status.
        if ($complete.val() !== FORM_STATUS_COMPLETE) {
            removeButtons('savenextform');
        }

        // Dinamically remove or restore buttons according with form status.
        $complete.change(function() {
            if ($(this).val() === FORM_STATUS_COMPLETE) {
                // Restoring buttons.
                $buttonsBottom.html(originalBottom);
                $buttonsTop.html(originalTop);
            }
            else {
                removeButtons('savenextform');
            }
        });
    }

    /**
     * Removes the given submit buttons set.
     */
    function removeButtons(buttonName) {
        var $buttons = $('button[name="submit-btn-' + buttonName + '"]');

        // Check if buttons are outside the dropdown menu.
        if ($buttons.length !== 0) {
            $.each($buttons, function(index, button) {
                // Get first button in dropdown-menu.
                var replacement = $(button).siblings('.dropdown-menu').find('a')[0];

                // Modify button to behave like $replacement.
                button.id = replacement.id;
                button.name = replacement.name;
                button.onclick = replacement.onclick;
                button.innerHTML = replacement.innerHTML;

                // Get rid of replacement.
                $(replacement).remove();
            });
        }
        else {
            // Disable button inside the dropdown menu.
            // Obs.: yes, this is a weird selector - "#" prefix is not being
            // used - but this approach is needed on this page because there
            // are multiple DOM elements with the same ID - which is
            // totally wrong.
            $('a[id="submit-btn-' + buttonName + '"]').hide();
        }
    }
});
