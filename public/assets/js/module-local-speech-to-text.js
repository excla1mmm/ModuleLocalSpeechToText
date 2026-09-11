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

    function initializeModuleLogs() {
        var $form = $('#stt-module-logs-form');
        var $segment = $('#system-logs-segment');
        var $results = $('#stt-log-results');
        var $state = $('#stt-log-state');
        var $resultCount = $('#stt-log-result-count');
        var $updatedAt = $('#stt-log-updated-at');
        var $levelFilter = $('#stt-log-level-filter');
        var $componentFilter = $('#stt-log-component-filter');
        var $search = $('#stt-log-search');

        if ($form.length === 0 || $results.length === 0 || $state.length === 0) {
            return;
        }

        var dataUrl = $form.data('url');
        var $periodButtons = $form.find('.period-btn');
        var $refreshButton = $form.find('.stt-log-refresh');
        var currentPeriod = parseInt($periodButtons.filter('.active').data('period'), 10);
        var loadedOnce = false;
        var request = null;
        var requestGeneration = 0;
        var currentEntries = [];
        var currentResponse = null;

        if (!Number.isFinite(currentPeriod)) {
            currentPeriod = 3600;
        }

        function levelName(level) {
            var levels = {
                debug: ['module_local_speech_to_text_LogLevelDebug', 'Debug'],
                info: ['module_local_speech_to_text_LogLevelInfo', 'Info'],
                notice: ['module_local_speech_to_text_LogLevelNotice', 'Notice'],
                warning: ['module_local_speech_to_text_LogLevelWarning', 'Warning'],
                error: ['module_local_speech_to_text_LogLevelError', 'Error']
            };
            var item = levels[level] || levels.info;

            return translation(item[0], item[1]);
        }

        function componentName(component) {
            var aliases = {
                api: 'api',
                queue: 'queue',
                scanner: 'scanner',
                worker: 'worker',
                workerlocalspeechtotextjobs: 'worker',
                settings: 'settings',
                web_ui: 'settings',
                setup: 'setup',
                storage: 'storage',
                transcript: 'transcript',
                system: 'system',
                localspeechtotextmain: 'system',
                logger: 'system'
            };
            var labels = {
                api: ['module_local_speech_to_text_LogComponentApi', 'API'],
                queue: ['module_local_speech_to_text_LogComponentQueue', 'Queue'],
                scanner: ['module_local_speech_to_text_LogComponentScanner', 'Recording scanner'],
                worker: ['module_local_speech_to_text_LogComponentWorker', 'Worker'],
                settings: ['module_local_speech_to_text_LogComponentSettings', 'Settings'],
                setup: ['module_local_speech_to_text_LogComponentSetup', 'Setup'],
                storage: ['module_local_speech_to_text_LogComponentStorage', 'Storage'],
                transcript: ['module_local_speech_to_text_LogComponentTranscript', 'Transcripts'],
                system: ['module_local_speech_to_text_LogComponentSystem', 'System'],
                other: ['module_local_speech_to_text_LogComponentOther', 'Other']
            };
            var label = labels[aliases[component] || 'other'];

            return translation(label[0], label[1]);
        }

        function formatTimestamp(timestamp) {
            var date = new Date(timestamp);
            if (Number.isNaN(date.getTime())) {
                return timestamp || '—';
            }

            return date.toLocaleString();
        }

        function renderState(kind, message) {
            if (!message) {
                $state.prop('hidden', true).removeClass('info warning error').text('');
                return;
            }

            $state
                .removeClass('info warning error')
                .addClass(kind || 'info')
                .text(message)
                .prop('hidden', false);
        }

        function filterEntries(entries) {
            var level = String($levelFilter.val() || '');
            var component = String($componentFilter.val() || '');
            var query = String($search.val() || '').trim().toLowerCase();

            return entries.filter(function (entry) {
                if (level && entry.level !== level) {
                    return false;
                }
                if (component && entry.component !== component) {
                    return false;
                }
                if (!query) {
                    return true;
                }

                var haystack = [
                    entry.message,
                    entry.component,
                    entry.event,
                    entry.severity,
                    entry.pid,
                    JSON.stringify(entry.context || {})
                ].join(' ').toLowerCase();

                return haystack.indexOf(query) !== -1;
            });
        }

        function renderEntries(entries) {
            $results.empty();
            entries.forEach(function (entry) {
                var $card = $('<article>').addClass('stt-log-entry stt-log-level-' + entry.level);
                var $header = $('<div>').addClass('stt-log-entry-header');
                var $identity = $('<div>').addClass('stt-log-entry-identity');
                var $level = $('<span>').addClass('ui tiny label stt-log-level-label').text(levelName(entry.level));
                var $component = $('<span>').addClass('stt-log-component').text(componentName(entry.component));
                var $time = $('<time>').addClass('stt-log-time').text(formatTimestamp(entry.ts));
                var $message = $('<div>').addClass('stt-log-message').text(entry.message || '—');
                var $details = $('<details>').addClass('stt-log-details');
                var $summary = $('<summary>').text(translation(
                    'module_local_speech_to_text_LogsTechnicalDetails',
                    'Technical details'
                ));
                var detailText = translation('module_local_speech_to_text_LogsEvent', 'Event')
                    + ': ' + (entry.event || '—');
                detailText += '\nseverity: ' + (entry.severity || '—');
                detailText += '\npid: ' + (entry.pid === undefined ? '—' : entry.pid);
                if (entry.context && Object.keys(entry.context).length > 0) {
                    detailText += '\n' + JSON.stringify(entry.context, null, 2);
                }

                $identity.append($level, $component);
                $header.append($identity, $time);
                $details.append($summary, $('<pre>').text(detailText));
                $card.append($header, $message, $details);
                $results.append($card);
            });
        }

        function updateComponentOptions(entries) {
            var selected = String($componentFilter.val() || '');
            var components = [];
            entries.forEach(function (entry) {
                if (entry.component && components.indexOf(entry.component) === -1) {
                    components.push(entry.component);
                }
            });
            components.sort(function (left, right) {
                return componentName(left).localeCompare(componentName(right));
            });

            $componentFilter.empty().append(
                $('<option>').val('').text(translation(
                    'module_local_speech_to_text_LogsComponentAll',
                    'All components'
                ))
            );
            components.forEach(function (component) {
                $componentFilter.append($('<option>').val(component).text(componentName(component)));
            });
            if (selected && components.indexOf(selected) !== -1) {
                $componentFilter.val(selected);
            }
        }

        function updateMeta(count, response) {
            var found = translation('module_local_speech_to_text_LogsFound', '%count% events')
                .replace('%count%', count);
            var updated = translation('module_local_speech_to_text_LogsUpdated', 'Updated: %time%')
                .replace('%time%', response && response.generatedAt
                    ? response.generatedAt
                    : translation('module_local_speech_to_text_LogsNever', 'never'));
            $resultCount.text(found);
            $updatedAt.text(updated);
        }

        function renderCurrent() {
            if (!currentResponse) {
                return;
            }
            var filtered = filterEntries(currentEntries);
            renderEntries(filtered);
            updateMeta(filtered.length, currentResponse);

            if (currentResponse.sourceStatus === 'missing') {
                renderState('info', translation(
                    'module_local_speech_to_text_LogsMissing',
                    'The module journal has not been created yet.'
                ));
                return;
            }
            if (currentResponse.sourceStatus === 'unavailable') {
                renderState('error', translation(
                    'module_local_speech_to_text_LogsUnavailable',
                    'The module journal is unavailable.'
                ));
                return;
            }
            if (currentResponse.sourceStatus === 'corrupt') {
                renderState('error', translation(
                    'module_local_speech_to_text_LogsCorrupt',
                    'The journal contains no readable events.'
                ));
                return;
            }
            if (currentEntries.length === 0) {
                renderState('info', translation(
                    'module_local_speech_to_text_LogsEmpty',
                    'No module events were found for this period.'
                ));
                return;
            }
            if (filtered.length === 0) {
                renderState('info', translation(
                    'module_local_speech_to_text_LogsFilteredEmpty',
                    'No events match the selected filters.'
                ));
                return;
            }

            var notices = [];
            if (currentResponse.sourceStatus === 'partial') {
                notices.push(translation(
                    'module_local_speech_to_text_LogsPartial',
                    '%count% damaged journal lines were skipped.'
                ).replace('%count%', currentResponse.malformedCount || 0));
            }
            if (currentResponse.truncated) {
                notices.push(translation(
                    'module_local_speech_to_text_LogsTruncated',
                    'Only the newest 1000 events are shown.'
                ));
            }
            renderState(notices.length > 0 ? 'warning' : '', notices.join(' '));
        }

        function setLoading() {
            $segment.addClass('stt-logs-loading');
            $results.empty();
            updateMeta(0, null);
            renderState('info', translation(
                'module_local_speech_to_text_LogsLoading',
                'Loading module events...'
            ));
        }

        function loadLogs(force) {
            if (!force && loadedOnce) {
                return;
            }
            if (!dataUrl) {
                return;
            }
            requestGeneration += 1;
            var requestId = requestGeneration;
            if (request !== null) {
                request.abort();
            }

            setLoading();
            $refreshButton.addClass('loading disabled');
            var currentRequest = $.ajax({
                url: dataUrl,
                method: 'GET',
                dataType: 'json',
                data: {
                    period: currentPeriod
                }
            }).done(function (response) {
                if (requestId !== requestGeneration) {
                    return;
                }
                currentResponse = response || {};
                currentEntries = Array.isArray(currentResponse.entries) ? currentResponse.entries : [];
                updateComponentOptions(currentEntries);
                renderCurrent();
                loadedOnce = true;
            }).fail(function (xhr, status) {
                if (requestId !== requestGeneration || status === 'abort') {
                    return;
                }
                currentResponse = null;
                currentEntries = [];
                renderState('error', translation(
                    'module_local_speech_to_text_LogsError',
                    'Unable to load module events.'
                ));
            }).always(function () {
                if (requestId !== requestGeneration) {
                    return;
                }
                if (request === currentRequest) {
                    request = null;
                }
                $segment.removeClass('stt-logs-loading');
                $refreshButton.removeClass('loading disabled');
            });
            request = currentRequest;
        }

        $periodButtons.on('click', function () {
            var $button = $(this);
            currentPeriod = parseInt($button.data('period'), 10);
            if (!Number.isFinite(currentPeriod)) {
                currentPeriod = 3600;
            }
            $periodButtons.removeClass('active');
            $button.addClass('active');
            loadedOnce = false;
            loadLogs(true);
        });

        $refreshButton.on('click', function () {
            loadLogs(true);
        });

        $levelFilter.add($componentFilter).on('change', renderCurrent);
        $search.on('input', renderCurrent);

        $('#module-local-speech-to-text-menu .item[data-tab="logs"]').on('click', function () {
            window.setTimeout(function () {
                loadLogs(false);
            }, 0);
        });

        updateMeta(0, null);
        if (requestedTab() === 'logs' || $form.closest('.tab.segment').hasClass('active')) {
            loadLogs(false);
        }
    }

    function initializeCdrHistorySlider($settingsForm) {
        var $slider = $('#stt-cdr-history-days-slider');
        var $input = $settingsForm.find('input[name="cdr_history_days"]');

        if ($slider.length === 0 || $input.length === 0 || typeof $slider.slider !== 'function') {
            return;
        }

        var values = ['1', '7', '30', '90', '180', '365', '0'];
        var labels = {
            0: translation('module_local_speech_to_text_CdrHistory1Day', '1 day'),
            1: translation('module_local_speech_to_text_CdrHistory7Days', '7 days'),
            2: translation('module_local_speech_to_text_CdrHistory30Days', '30 days'),
            3: translation('module_local_speech_to_text_CdrHistory90Days', '90 days'),
            4: translation('module_local_speech_to_text_CdrHistory180Days', '6 months'),
            5: translation('module_local_speech_to_text_CdrHistory365Days', '1 year'),
            6: translation('module_local_speech_to_text_CdrHistoryAll', 'All')
        };
        var currentValue = String($input.val() || '30');
        var initialIndex = values.indexOf(currentValue);
        var initialized = false;

        if (initialIndex < 0) {
            initialIndex = values.indexOf('30');
            $input.val('30');
        }

        $slider.slider({
            min: 0,
            max: values.length - 1,
            step: 1,
            smooth: true,
            autoAdjustLabels: false,
            interpretLabel: function (value) {
                return labels[value] || '';
            },
            onChange: function (value) {
                var selected = values[value] || '30';

                $input.val(selected);
                if (initialized) {
                    $input.trigger('change');
                    updateRetentionConflict($settingsForm);
                    if (typeof Form !== 'undefined' && typeof Form.checkValues === 'function') {
                        Form.checkValues();
                    }
                }
            }
        });
        $slider.slider('set value', initialIndex, false);
        initialized = true;
    }

    function initializeTranscriptRetentionSlider($settingsForm) {
        var $slider = $('#stt-transcript-retention-days-slider');
        var $input = $settingsForm.find('input[name="transcript_retention_days"]');

        if ($slider.length === 0 || $input.length === 0 || typeof $slider.slider !== 'function') {
            return;
        }

        var values = ['30', '90', '180', '365', '0'];
        var labels = {
            0: translation('module_local_speech_to_text_TranscriptRetention30Days', '30 days'),
            1: translation('module_local_speech_to_text_TranscriptRetention90Days', '90 days'),
            2: translation('module_local_speech_to_text_TranscriptRetention180Days', '180 days'),
            3: translation('module_local_speech_to_text_TranscriptRetention365Days', '1 year'),
            4: translation('module_local_speech_to_text_TranscriptRetentionUnlimited', 'Unlimited')
        };
        var currentValue = String($input.val() || '365');
        var initialIndex = values.indexOf(currentValue);
        var initialized = false;

        if (initialIndex < 0) {
            initialIndex = values.indexOf('365');
            $input.val('365');
        }

        $slider.slider({
            min: 0,
            max: values.length - 1,
            step: 1,
            smooth: true,
            autoAdjustLabels: false,
            interpretLabel: function (value) {
                return labels[value] || '';
            },
            onChange: function (value) {
                var selected = values[value] || '365';

                $input.val(selected);
                if (initialized) {
                    $input.trigger('change');
                    updateRetentionConflict($settingsForm);
                    if (typeof Form !== 'undefined' && typeof Form.checkValues === 'function') {
                        Form.checkValues();
                    }
                }
            }
        });
        $slider.slider('set value', initialIndex, false);
        initialized = true;
    }

    function retentionAllowsProcessing(processingDays, retentionDays) {
        return retentionDays === 0 || (processingDays > 0 && processingDays <= retentionDays);
    }

    function updateRetentionConflict($settingsForm) {
        var processingDays = Number($settingsForm.find('input[name="cdr_history_days"]').val());
        var retentionDays = Number($settingsForm.find('input[name="transcript_retention_days"]').val());
        var compatible = Number.isFinite(processingDays)
            && Number.isFinite(retentionDays)
            && retentionAllowsProcessing(processingDays, retentionDays);
        var $message = $settingsForm.find('.stt-retention-conflict-message');

        $message.toggleClass('hidden', compatible).attr('aria-hidden', compatible ? 'true' : 'false');

        return compatible;
    }

    function initializeModelMarketplace($modelForm) {
        var $saveButton = $('#module-local-speech-to-text-model-save');
        var $modelInputs = $('.module-local-speech-to-text .stt-model-marketplace-table input[name="selected_model"]');
        var $compatibilityMessage = $('#stt-model-language-compatibility');
        var initialSelectedModel = String($modelForm.data('initial-selected-model') || $modelInputs.filter(':checked').val() || '');
        var selectedLanguage = String($modelForm.data('selected-language') || '').trim().toLowerCase();

        function selectedModel() {
            return String($modelInputs.filter(':checked').val() || '');
        }

        function selectedModelSupportsLanguage() {
            var $selectedInput = $modelInputs.filter(':checked');
            var supportedLanguages;

            if (selectedLanguage === '' || selectedLanguage === 'auto') {
                return true;
            }
            if ($selectedInput.length === 0) {
                return false;
            }

            supportedLanguages = String($selectedInput.attr('data-supported-languages') || '')
                .split(',')
                .map(function (language) { return language.trim().toLowerCase(); })
                .filter(function (language) { return language !== ''; });

            return supportedLanguages.indexOf(selectedLanguage) !== -1;
        }

        function updateCompatibilityState() {
            var compatible = selectedModelSupportsLanguage();

            $compatibilityMessage
                .toggleClass('hidden', compatible)
                .attr('aria-hidden', compatible ? 'true' : 'false');

            return compatible;
        }

        function updateSaveButtonState() {
            var compatible = updateCompatibilityState();
            var hasChanges = selectedModel() !== ''
                && selectedModel() !== initialSelectedModel
                && compatible;

            $saveButton.toggleClass('disabled', !hasChanges);
            $saveButton.prop('disabled', !hasChanges);
        }

        if (typeof $.fn.accordion === 'function') {
            $('.module-local-speech-to-text .stt-advanced-settings-dropdown').accordion();
        }

        $('.module-local-speech-to-text .stt-model-marketplace-table tbody tr').on('click', function (event) {
            if ($(event.target).closest('a, button, form, input, label').length > 0) {
                return;
            }

            $(this).find('.ui.radio.checkbox').checkbox('check');
        });

        $modelInputs.on('change', function () {
            var $row = $(this).closest('tr');

            $row.addClass('positive').siblings().removeClass('positive');
            updateSaveButtonState();
        });

        $modelForm.on('submit', function () {
            if (!selectedModelSupportsLanguage()
                || selectedModel() === ''
                || selectedModel() === initialSelectedModel) {
                return false;
            }
            if ($saveButton.length > 0) {
                $saveButton.addClass('loading disabled active');
                $saveButton.prop('disabled', true);
            }
        });

        updateSaveButtonState();
    }

    function initializeConfirmModal() {
        var $modal = $('#stt-confirm-modal');

        if ($modal.length === 0 || typeof $modal.modal !== 'function') {
            return;
        }

        // Pre-initialize so Semantic UI moves the element to <body> and hides it properly
        $modal.modal({});

        function showConfirm(message, onConfirm) {
            $modal.find('.stt-confirm-message').text(message);
            $modal.modal({
                closable: true,
                onApprove: function () {
                    onConfirm();
                }
            }).modal('show');
        }

        function bindConfirmableForm($form) {
            $form.on('submit.stt-confirm', function (event) {
                var message = $(this).data('confirm') || '';

                if (message === '') {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();

                var $self = $(this);
                showConfirm(message, function () {
                    $self.off('submit.stt-confirm').trigger('submit');
                });
            });
        }

        $('.module-local-speech-to-text .stt-delete-api-key-form').each(function () {
            bindConfirmableForm($(this));
        });
    }

    function initializeTranscriptRows() {
        $('.module-local-speech-to-text .stt-transcript-row').on('click', function (event) {
            if ($(event.target).closest('a, button, input, select, textarea').length > 0) {
                return;
            }

            var destination = $(this).data('transcript-url');
            if (destination) {
                window.location.href = destination;
            }
        });
    }

    $(function () {
        var $tabItems = $('#module-local-speech-to-text-menu .item');
        var $settingsForm = $('#module-local-speech-to-text-form');
        var $modelForm = $('#module-local-speech-to-text-model-form');
        $tabItems.tab();
        if (requestedTab() !== '') {
            $tabItems.tab('change tab', requestedTab());
        }

        $('.module-local-speech-to-text .ui.dropdown').dropdown();
        $('.module-local-speech-to-text .ui.checkbox').checkbox();
        $('.module-local-speech-to-text [data-content]').popup();
        initializeModelMarketplace($modelForm);
        initializeTranscriptDateFilter();
        initializeModuleLogs();
        initializeCdrHistorySlider($settingsForm);
        initializeTranscriptRetentionSlider($settingsForm);
        updateRetentionConflict($settingsForm);
        initializeConfirmModal();
        initializeTranscriptRows();

        if ($.fn.form && $.fn.form.settings && $.fn.form.settings.rules) {
            $.fn.form.settings.rules.sttProcessingRange = function (value, range) {
                var limits = String(range || '').split(',').map(Number);
                var number = Number(value);

                return limits.length === 2
                    && Number.isFinite(number)
                    && number >= limits[0]
                    && number <= limits[1];
            };
            $.fn.form.settings.rules.sttProcessingOverlap = function (value) {
                var overlapMS = Math.round(Number(value) * 1000);
                var durationMS = Math.round(Number($settingsForm.find('[name="max_segment_duration_sec"]').val()) * 1000);

                return Number.isFinite(overlapMS)
                    && Number.isFinite(durationMS)
                    && overlapMS >= 0
                    && overlapMS < durationMS;
            };
            $.fn.form.settings.rules.sttRetentionCompatibility = function () {
                return updateRetentionConflict($settingsForm);
            };
        }

        var processingValidationFields = {
            temperature: {
                identifier: 'temperature',
                rules: [{ type: 'sttProcessingRange[0,1]' }]
            },
            temperature_fallback_step: {
                identifier: 'temperature_fallback_step',
                rules: [{ type: 'sttProcessingRange[0,1]' }]
            },
            temperature_fallback_count: {
                identifier: 'temperature_fallback_count',
                rules: [{ type: 'integer[0..10]' }]
            },
            top_k: {
                identifier: 'top_k',
                rules: [{ type: 'integer[1..100]' }]
            },
            compression_ratio_threshold: {
                identifier: 'compression_ratio_threshold',
                rules: [{ type: 'sttProcessingRange[0,10]' }]
            },
            log_probability_threshold: {
                identifier: 'log_probability_threshold',
                rules: [{ type: 'sttProcessingRange[-10,0]' }]
            },
            first_token_log_probability_threshold: {
                identifier: 'first_token_log_probability_threshold',
                rules: [{ type: 'sttProcessingRange[-10,0]' }]
            },
            no_speech_threshold: {
                identifier: 'no_speech_threshold',
                rules: [{ type: 'sttProcessingRange[0,1]' }]
            },
            max_segment_duration_sec: {
                identifier: 'max_segment_duration_sec',
                rules: [{ type: 'sttProcessingRange[1,120]' }]
            },
            segment_overlap_sec: {
                identifier: 'segment_overlap_sec',
                rules: [
                    { type: 'sttProcessingRange[0,10]' },
                    { type: 'sttProcessingOverlap' }
                ]
            }
        };

        function updateVadDependentFields() {
            var enabled = $settingsForm.find('[name="voice_activity_detection"]').is(':checked');

            $settingsForm.find('.stt-vad-dependent')
                .toggleClass('disabled', !enabled)
                .attr('aria-disabled', enabled ? 'false' : 'true');
        }

        $settingsForm.find('[name="voice_activity_detection"]').on('change', updateVadDependentFields);
        updateVadDependentFields();
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
            Form.validateRules = Object.assign({
                default_language: {
                    identifier: 'default_language',
                    rules: [{ type: 'empty' }]
                },
                poll_interval_sec: {
                    identifier: 'poll_interval_sec',
                    rules: [{ type: 'integer[30..3600]' }]
                },
                max_recording_duration_min: {
                    identifier: 'max_recording_duration_min',
                    rules: [{ type: 'integer[1..1440]' }]
                },
                cdr_scan_limit: {
                    identifier: 'cdr_scan_limit',
                    rules: [{ type: 'integer[1..1000]' }]
                },
                cdr_history_days: {
                    identifier: 'cdr_history_days',
                    rules: [{ type: 'integer[0..365]' }]
                },
                transcript_retention_days: {
                    identifier: 'transcript_retention_days',
                    rules: [
                        { type: 'integer[0..365]' },
                        {
                            type: 'sttRetentionCompatibility',
                            prompt: translation(
                                'module_local_speech_to_text_TranscriptRetentionConflict',
                                'The recording processing window cannot exceed transcript retention. Increase retention or reduce the processing window.'
                            )
                        }
                    ]
                },
                processing_timeout_sec: {
                    identifier: 'processing_timeout_sec',
                    rules: [{ type: 'integer[60..86400]' }]
                }
            }, processingValidationFields);
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
                fields: Object.assign({
                    default_language: ['empty'],
                    poll_interval_sec: ['integer[30..3600]'],
                    max_recording_duration_min: ['integer[1..1440]'],
                    cdr_scan_limit: ['integer[1..1000]'],
                    cdr_history_days: ['integer[0..365]'],
                    transcript_retention_days: [
                        'integer[0..365]',
                        {
                            type: 'sttRetentionCompatibility',
                            prompt: translation(
                                'module_local_speech_to_text_TranscriptRetentionConflict',
                                'The recording processing window cannot exceed transcript retention. Increase retention or reduce the processing window.'
                            )
                        }
                    ],
                    processing_timeout_sec: ['integer[60..86400]']
                }, processingValidationFields)
            });
        }

        $modelForm.form({
            fields: {
                selected_model: ['empty']
            }
        });
    });
}());
