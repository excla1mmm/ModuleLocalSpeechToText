(function () {
    'use strict';

    function requestedTab() {
        var params = new URLSearchParams(window.location.search);
        var tabName = params.get('tab') || window.location.hash.replace(/^#/, '');

        return /^[a-z0-9_-]+$/.test(tabName) ? tabName : '';
    }

    $(function () {
        var $tabItems = $('#module-local-speech-to-text-menu .item');
        $tabItems.tab();
        if (requestedTab() !== '') {
            $tabItems.tab('change tab', requestedTab());
        }

        $('.module-local-speech-to-text .ui.dropdown').dropdown();
        $('.module-local-speech-to-text [data-content]').popup();
        $('.module-local-speech-to-text .stt-copy-button').on('click', function () {
            var text = $(this).data('clipboard-text') || '';
            var $button = $(this);

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    $button.addClass('positive');
                    setTimeout(function () {
                        $button.removeClass('positive');
                    }, 900);
                });
            }
        });

        $('#module-local-speech-to-text-form').form({
            fields: {
                default_language: ['empty'],
                poll_interval_sec: ['integer[1..3600]'],
                max_file_size_mb: ['integer[1..10240]'],
                cdr_scan_limit: ['integer[1..1000]'],
                processing_timeout_sec: ['integer[60..86400]'],
                selected_model: ['empty']
            }
        });
    });
}());
