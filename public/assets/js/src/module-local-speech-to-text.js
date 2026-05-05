(function () {
    'use strict';

    $(function () {
        $('#module-local-speech-to-text-menu .item').tab();
        $('.module-local-speech-to-text .ui.dropdown').dropdown();
        $('.module-local-speech-to-text [data-content]').popup();

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
