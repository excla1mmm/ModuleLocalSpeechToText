(function () {
    'use strict';

    function translation(key, fallback) {
        if (typeof globalTranslate !== 'undefined' && globalTranslate[key]) {
            return globalTranslate[key];
        }

        return fallback;
    }

    function requestedTab() {
        var params = new URLSearchParams(window.location.search);
        var tabName = params.get('tab') || window.location.hash.replace(/^#/, '');

        return /^[a-z0-9_-]+$/.test(tabName) ? tabName : '';
    }

    function calendarLocale() {
        var days = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
        var months = [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December'
        ];

        if (typeof SemanticLocalization !== 'undefined' && SemanticLocalization.calendarText) {
            days = SemanticLocalization.calendarText.days || days;
            months = SemanticLocalization.calendarText.months || months;
        }

        return {
            format: 'DD/MM/YYYY',
            separator: ' - ',
            applyLabel: translation('cal_ApplyBtn', 'Apply'),
            cancelLabel: translation('cal_CancelBtn', 'Cancel'),
            fromLabel: translation('cal_from', 'From'),
            toLabel: translation('cal_to', 'To'),
            customRangeLabel: translation('cal_CustomPeriod', 'Custom range'),
            daysOfWeek: days,
            monthNames: months,
            firstDay: 1
        };
    }

    function momentDate(value, fallback) {
        var date = moment(value, 'YYYY-MM-DD', true);

        return date.isValid() ? date : fallback.clone();
    }

    function initializeTranscriptDateFilter() {
        var $form = $('#stt-transcripts-filter-form');
        var $selector = $('#stt-transcripts-date-range-selector');

        if ($form.length === 0 || $selector.length === 0) {
            return;
        }
        if (typeof moment === 'undefined' || typeof $.fn.daterangepicker === 'undefined') {
            return;
        }

        var $dateFrom = $form.find('input[name="date_from"]');
        var $dateTo = $form.find('input[name="date_to"]');
        var today = moment();
        var startDate = momentDate($dateFrom.val(), today);
        var endDate = momentDate($dateTo.val(), startDate);

        if (startDate.isAfter(endDate)) {
            var swapDate = startDate;
            startDate = endDate;
            endDate = swapDate;
        }

        function updateHiddenInputs(start, end) {
            $dateFrom.val(start.format('YYYY-MM-DD'));
            $dateTo.val(end.format('YYYY-MM-DD'));
        }

        updateHiddenInputs(startDate, endDate);
        $selector.daterangepicker({
            ranges: {
                [translation('cal_Today', 'Today')]: [moment(), moment()],
                [translation('cal_Yesterday', 'Yesterday')]: [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                [translation('cal_LastWeek', 'Last 7 days')]: [moment().subtract(6, 'days'), moment()],
                [translation('cal_Last30Days', 'Last 30 days')]: [moment().subtract(29, 'days'), moment()],
                [translation('cal_ThisMonth', 'This month')]: [moment().startOf('month'), moment().endOf('month')],
                [translation('cal_LastMonth', 'Last month')]: [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            },
            alwaysShowCalendars: true,
            autoUpdateInput: true,
            linkedCalendars: true,
            maxDate: moment(),
            startDate: startDate.startOf('day'),
            endDate: endDate.endOf('day'),
            locale: calendarLocale()
        }, function (start, end) {
            updateHiddenInputs(start, end);
            $form.trigger('submit');
        });

        $form.on('submit', function () {
            var picker = $selector.data('daterangepicker');
            if (picker && picker.startDate && picker.endDate) {
                updateHiddenInputs(picker.startDate, picker.endDate);
            }
        });
    }

    $(function () {
        var $tabItems = $('#module-local-speech-to-text-menu .item');
        var $settingsForm = $('#module-local-speech-to-text-form');
        $tabItems.tab();
        if (requestedTab() !== '') {
            $tabItems.tab('change tab', requestedTab());
        }

        $('.module-local-speech-to-text .ui.dropdown').dropdown();
        $('.module-local-speech-to-text [data-content]').popup();
        initializeTranscriptDateFilter();
        $('.module-local-speech-to-text .stt-delete-api-key-form').on('submit', function () {
            var message = $(this).data('confirm') || '';

            return message === '' || window.confirm(message);
        });
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

        if (typeof Form !== 'undefined' && $settingsForm.length > 0) {
            Form.$formObj = $settingsForm;
            Form.url = $settingsForm.attr('action');
            Form.method = 'POST';
            Form.validateRules = {
                default_language: {
                    identifier: 'default_language',
                    rules: [{ type: 'empty' }]
                },
                poll_interval_sec: {
                    identifier: 'poll_interval_sec',
                    rules: [{ type: 'integer[1..3600]' }]
                },
                max_file_size_mb: {
                    identifier: 'max_file_size_mb',
                    rules: [{ type: 'integer[1..10240]' }]
                },
                cdr_scan_limit: {
                    identifier: 'cdr_scan_limit',
                    rules: [{ type: 'integer[1..1000]' }]
                },
                processing_timeout_sec: {
                    identifier: 'processing_timeout_sec',
                    rules: [{ type: 'integer[60..86400]' }]
                },
                selected_model: {
                    identifier: 'selected_model',
                    rules: [{ type: 'empty' }]
                }
            };
            Form.cbBeforeSendForm = function (settings) {
                return settings;
            };
            Form.cbAfterSendForm = function () {};
            Form.apiSettings.enabled = false;
            Form.apiSettings.apiObject = null;
            Form.sendOnlyChanged = false;
            Form.enableDirrity = true;
            Form.initialize();
        } else {
            $settingsForm.form({
                fields: {
                    default_language: ['empty'],
                    poll_interval_sec: ['integer[1..3600]'],
                    max_file_size_mb: ['integer[1..10240]'],
                    cdr_scan_limit: ['integer[1..1000]'],
                    processing_timeout_sec: ['integer[60..86400]'],
                    selected_model: ['empty']
                }
            });
        }
    });
}());
