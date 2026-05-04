(function () {
    'use strict';

    $(function () {
        $('#module-local-speech-to-text-form').form({
            fields: {
                default_language: ['empty'],
                poll_interval_sec: ['integer[1..3600]'],
                max_file_size_mb: ['integer[1..10240]']
            }
        });
    });
}());
