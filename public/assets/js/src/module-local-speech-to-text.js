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

    function formatAudioTime(seconds) {
        var totalSeconds = Math.floor(Math.max(0, seconds || 0));
        var hours = Math.floor(totalSeconds / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var rest = totalSeconds % 60;

        if (hours > 0) {
            return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
        }

        return String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
    }

    function initializeTranscriptPlayer() {
        var audio = document.getElementById('stt-transcript-audio');
        var $player = $('#stt-transcript-player');
        var $turns = $('.module-local-speech-to-text .stt-playable-turn');

        if (!audio || $player.length === 0) {
            return;
        }

        var $playButton = $player.find('.stt-player-play');
        var $playIcon = $playButton.find('i');
        var $range = $player.find('.stt-player-range');
        var $duration = $player.find('.stt-player-duration');
        var isSeekingBySlider = false;
        var isWaitingForAudio = false;

        function turnStart($turn) {
            var value = parseFloat($turn.data('start'));

            return Number.isFinite(value) ? Math.max(0, value) : 0;
        }

        function turnEnd($turn) {
            var value = parseFloat($turn.data('end'));

            return Number.isFinite(value) ? Math.max(0, value) : 0;
        }

        function setActiveTurn($turn) {
            $turns.removeClass('stt-turn-active');
            if ($turn && $turn.length > 0) {
                $turn.addClass('stt-turn-active');
            }
        }

        function fallbackDuration() {
            var value = parseFloat($player.data('duration'));

            return Number.isFinite(value) ? Math.max(0, value) : 0;
        }

        function currentDuration() {
            if (Number.isFinite(audio.duration) && audio.duration > 0) {
                return audio.duration;
            }

            return fallbackDuration();
        }

        function setPlayIcon(isPlaying) {
            $playButton.removeClass('loading disabled');
            if (isPlaying) {
                $playIcon.removeClass('play').addClass('pause');
                return;
            }

            $playIcon.removeClass('pause').addClass('play');
        }

        function setPlayerLoading(isLoading) {
            isWaitingForAudio = isLoading;
            if (isLoading) {
                $playButton.addClass('loading disabled');
                return;
            }

            $playButton.removeClass('loading disabled');
        }

        function updateDurationLabel() {
            var duration = currentDuration();
            var durationLabel = duration > 0 ? formatAudioTime(duration) : '--:--';

            $duration.text(formatAudioTime(audio.currentTime) + '/' + durationLabel);
        }

        function updateSlider() {
            var duration = currentDuration();
            if (duration <= 0 || typeof $range.range !== 'function') {
                updateDurationLabel();
                return;
            }

            var percent = Math.min(Math.round((audio.currentTime / duration) * 100), 100);
            $range.range('set value', percent);
            updateDurationLabel();
        }

        function ensureAudioSource() {
            var currentSource = audio.getAttribute('src');
            var lazySource = audio.getAttribute('data-src');

            if (currentSource) {
                return true;
            }

            if (!lazySource) {
                return false;
            }

            audio.setAttribute('src', lazySource);

            return true;
        }

        function playAudio() {
            var playResult = audio.play();
            if (playResult && typeof playResult.catch === 'function') {
                playResult.catch(function () {
                    setPlayerLoading(false);
                    setPlayIcon(false);
                });
            }
            setPlayIcon(true);
        }

        function playWhenReady(seconds, $turn) {
            var started = false;
            var hasSeekTarget = Number.isFinite(seconds);

            function seekIfNeeded() {
                if (!hasSeekTarget || audio.readyState === 0) {
                    return;
                }

                audio.currentTime = Math.max(0, seconds);
                hasSeekTarget = false;
            }

            function run() {
                if (started) {
                    return;
                }
                started = true;
                setPlayerLoading(false);
                seekIfNeeded();
                playAudio();
                if ($turn && $turn.length > 0) {
                    setActiveTurn($turn);
                }
                updateSlider();
            }

            if (!ensureAudioSource()) {
                setPlayIcon(false);
                return;
            }

            if (audio.readyState >= 2) {
                run();
                return;
            }

            setPlayerLoading(true);
            audio.addEventListener('loadedmetadata', seekIfNeeded, { once: true });
            audio.addEventListener('loadeddata', run, { once: true });
            audio.addEventListener('canplay', run, { once: true });
            audio.addEventListener('error', function () {
                setPlayerLoading(false);
                setPlayIcon(false);
            }, { once: true });
            audio.load();
        }

        function seekAndPlay(seconds, $turn) {
            playWhenReady(seconds, $turn);
        }

        function initializeSliderTooltip() {
            var $tooltip = $('<div class="cdr-slider-tooltip stt-slider-tooltip">00:00</div>');
            $range.append($tooltip);

            $range.on('mousemove', function (event) {
                var offset = $range.offset();
                var width = $range.width();
                var mouseX = event.pageX - offset.left;
                var percent = width > 0 ? Math.max(0, Math.min(100, (mouseX / width) * 100)) : 0;
                var duration = currentDuration();

                if (duration > 0) {
                    $tooltip.text(formatAudioTime((duration * percent) / 100));
                }
                $tooltip.css('left', percent + '%');
            });
        }

        $playButton.on('click', function (event) {
            event.preventDefault();
            if (isWaitingForAudio) {
                return;
            }
            if (audio.paused) {
                playWhenReady(null, null);
                return;
            }

            audio.pause();
            setPlayIcon(false);
        });

        if (typeof $range.range === 'function') {
            $range.range({
                min: 0,
                max: 100,
                start: 0,
                onChange: function (value, meta) {
                    var duration = currentDuration();

                    if (meta && meta.triggeredByUser && duration > 0) {
                        isSeekingBySlider = true;
                        audio.currentTime = (duration * value) / 100;
                        isSeekingBySlider = false;
                    }
                    updateDurationLabel();
                }
            });
            initializeSliderTooltip();
        }

        if ($turns.length > 0) {
            $turns.on('click', function () {
                var $turn = $(this);
                seekAndPlay(turnStart($turn), $turn);
            });

            $turns.on('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    var $turn = $(this);
                    seekAndPlay(turnStart($turn), $turn);
                }
            });
        }

        audio.addEventListener('loadedmetadata', updateSlider);
        audio.addEventListener('play', function () {
            setPlayIcon(true);
        });
        audio.addEventListener('pause', function () {
            setPlayIcon(false);
        });
        audio.addEventListener('ended', function () {
            setPlayIcon(false);
            setActiveTurn($());
            if (typeof $range.range === 'function') {
                $range.range('set value', 0);
            }
            updateDurationLabel();
        });

        audio.addEventListener('timeupdate', function () {
            var currentTime = audio.currentTime;
            var $activeTurns = $();

            if (!isSeekingBySlider) {
                updateSlider();
            }

            $turns.each(function () {
                var $turn = $(this);
                var start = turnStart($turn);
                var end = turnEnd($turn);
                if (end <= start) {
                    end = start + 1;
                }

                if (currentTime >= start && currentTime <= end) {
                    $activeTurns = $activeTurns.add($turn);
                }

                return true;
            });

            setActiveTurn($activeTurns);
        });

        updateDurationLabel();
    }

    function initializeModuleLogs() {
        var $form = $('#stt-module-logs-form');
        var $content = $('#stt-module-log-content');
        var $viewer = $('#log-content-readonly');
        var $segment = $('#system-logs-segment');
        var $meta = $('#stt-module-logs-meta');
        var $timeRange = $('#time-slider-container');
        var $fullscreenButton = $('.stt-logs-fullscreen-toggle');
        var $fullscreenIcon = $fullscreenButton.find('i.icon');

        if ($form.length === 0 || $content.length === 0 || $viewer.length === 0) {
            return;
        }

        var dataUrl = $form.data('url');
        var $periodButtons = $form.find('.period-btn');
        var $refreshButton = $form.find('.stt-log-refresh');
        var currentPeriod = parseInt($periodButtons.filter('.active').data('period'), 10) || 3600;
        var loadedOnce = false;
        var request = null;

        function metaText(response) {
            var template = translation(
                'module_local_speech_to_text_LogsMeta',
                '%lines% lines from %files% files, updated %time%'
            );

            return template
                .replace('%lines%', response.lineCount || 0)
                .replace('%files%', response.fileCount || 0)
                .replace('%time%', response.generatedAt || '');
        }

        function setLoading() {
            $segment.addClass('stt-logs-loading');
            $viewer.addClass('stt-log-loading');
            $content.text(translation(
                'module_local_speech_to_text_LogsLoading',
                'Loading logs...'
            ));
        }

        function updatePeriodLabel(response) {
            var activeLabel = $periodButtons.filter('.active').text() || '';
            var lineCount = response && response.lineCount ? response.lineCount : 0;

            $timeRange.text(activeLabel + ' · ' + lineCount + ' lines');
        }

        function loadLogs(force) {
            if (!force && loadedOnce) {
                return;
            }
            if (!dataUrl) {
                return;
            }
            if (request !== null) {
                request.abort();
            }

            setLoading();
            $refreshButton.addClass('loading disabled');
            request = $.ajax({
                url: dataUrl,
                method: 'GET',
                dataType: 'json',
                data: {
                    period: currentPeriod
                }
            }).done(function (response) {
                var logText = response && response.logText ? response.logText : '';

                $viewer.removeClass('stt-log-loading');
                $content.text(logText || translation(
                    'module_local_speech_to_text_LogsEmpty',
                    'No module log entries found for the selected period.'
                ));
                if (response) {
                    $meta.text(metaText(response));
                    updatePeriodLabel(response);
                }
                loadedOnce = true;
                $viewer.scrollTop($viewer[0].scrollHeight);
            }).fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }

                $viewer.removeClass('stt-log-loading');
                $content.text(translation(
                    'module_local_speech_to_text_LogsError',
                    'Unable to load module logs.'
                ));
            }).always(function () {
                request = null;
                $segment.removeClass('stt-logs-loading');
                $refreshButton.removeClass('loading disabled');
            });
        }

        function setFullscreenState(isFullscreen) {
            $segment.toggleClass('fullscreen', isFullscreen);
            $fullscreenIcon.toggleClass('expand', !isFullscreen);
            $fullscreenIcon.toggleClass('compress', isFullscreen);
        }

        $periodButtons.on('click', function () {
            var $button = $(this);
            currentPeriod = parseInt($button.data('period'), 10) || 3600;
            $periodButtons.removeClass('active');
            $button.addClass('active');
            updatePeriodLabel({ lineCount: 0 });
            loadedOnce = false;
            loadLogs(true);
        });

        $refreshButton.on('click', function () {
            loadLogs(true);
        });

        $fullscreenButton.on('click', function () {
            var element = $segment[0];

            if (!document.fullscreenElement && element && element.requestFullscreen) {
                element.requestFullscreen();
                return;
            }

            if (document.fullscreenElement && document.exitFullscreen) {
                document.exitFullscreen();
            }
        });

        document.addEventListener('fullscreenchange', function () {
            setFullscreenState(document.fullscreenElement === $segment[0]);
        });

        $('#module-local-speech-to-text-menu .item[data-tab="logs"]').on('click', function () {
            window.setTimeout(function () {
                loadLogs(false);
            }, 0);
        });

        updatePeriodLabel({ lineCount: 0 });
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

    function vocabularyTemplateTerms() {
        return [
            'MikoPBX',
            'Asterisk',
            'SIP',
            'VoIP',
            'CRM',
            'API',
            'Quality control',
            'Customer profile',
            'Call queue',
            'Voice analytics'
        ];
    }

    function splitVocabularyTerms(text) {
        return String(text || '')
            .replace(/^\uFEFF/, '')
            .split(/[\r\n,;]+/)
            .map(function (term) {
                return term.trim().slice(0, 120);
            })
            .filter(function (term) {
                return term !== '';
            });
    }

    function uniqueVocabularyTerms(terms) {
        var seen = {};
        var result = [];

        terms.forEach(function (term) {
            var key = term.toLowerCase();

            if (seen[key]) {
                return;
            }

            seen[key] = true;
            result.push(term);
        });

        return result.slice(0, 100);
    }

    function updateVocabularyTermsCount($textarea) {
        $('#stt-vocabulary-terms-count').text(uniqueVocabularyTerms(splitVocabularyTerms($textarea.val())).length);
    }

    function refreshSettingsDirtyState() {
        if (typeof Form === 'undefined') {
            return;
        }
        if (typeof Form.checkValues === 'function') {
            Form.checkValues();
            return;
        }
        if (typeof Form.dataChanged === 'function') {
            Form.dataChanged();
        }
    }

    function downloadVocabularyTemplate() {
        var text = vocabularyTemplateTerms().join(', ') + '\n';
        var fileName = 'stt-terms-template.txt';
        var link = document.createElement('a');
        var objectUrl = '';

        if (window.Blob && window.URL && window.URL.createObjectURL) {
            objectUrl = window.URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
            link.href = objectUrl;
        } else {
            link.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent(text);
        }

        link.download = fileName;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        if (objectUrl !== '') {
            window.setTimeout(function () {
                window.URL.revokeObjectURL(objectUrl);
            }, 0);
        }
    }

    function initializeVocabularyTermsTools($settingsForm) {
        var $textarea = $settingsForm.find('textarea[name="custom_vocabulary_terms"]');
        var $fileInput = $('#stt-vocabulary-terms-file');
        var $fileName = $('#stt-vocabulary-file-name');
        var $downloadButton = $('#stt-vocabulary-template-download');

        if ($textarea.length === 0) {
            return;
        }

        updateVocabularyTermsCount($textarea);

        $textarea.on('input change', function () {
            updateVocabularyTermsCount($textarea);
        });

        $downloadButton.on('click', function () {
            downloadVocabularyTemplate();
        });

        $fileInput.on('change', function () {
            var file = this.files && this.files[0] ? this.files[0] : null;
            var reader;

            if (!file) {
                return;
            }

            if (!/\.txt$/i.test(file.name || '')) {
                window.alert(translation(
                    'module_local_speech_to_text_VocabularyTxtInvalid',
                    'Choose a TXT file with comma-separated terms.'
                ));
                $fileInput.val('');
                return;
            }

            if (typeof FileReader === 'undefined') {
                window.alert(translation(
                    'module_local_speech_to_text_VocabularyTxtReadError',
                    'Could not read the TXT file.'
                ));
                return;
            }

            reader = new FileReader();
            reader.onload = function (event) {
                var importedTerms = splitVocabularyTerms(event.target.result || '');
                var mergedTerms = uniqueVocabularyTerms(splitVocabularyTerms($textarea.val()).concat(importedTerms));

                if (importedTerms.length === 0) {
                    window.alert(translation(
                        'module_local_speech_to_text_VocabularyTxtInvalid',
                        'Choose a TXT file with comma-separated terms.'
                    ));
                    $fileInput.val('');
                    return;
                }

                $textarea.val(mergedTerms.join(', ')).trigger('input').trigger('change');
                $fileName.text(translation('module_local_speech_to_text_VocabularyTxtLoaded', 'TXT file added:') + ' ' + file.name);
                updateVocabularyTermsCount($textarea);
                refreshSettingsDirtyState();
            };
            reader.onerror = function () {
                window.alert(translation(
                    'module_local_speech_to_text_VocabularyTxtReadError',
                    'Could not read the TXT file.'
                ));
            };
            reader.readAsText(file);
        });
    }

    function initializeModelMarketplace($modelForm) {
        var $saveButton = $('#module-local-speech-to-text-model-save');
        var $modelInputs = $('.module-local-speech-to-text .stt-model-marketplace-table input[name="selected_model"]');
        var initialSelectedModel = String($modelForm.data('initial-selected-model') || $modelInputs.filter(':checked').val() || '');

        function selectedModel() {
            return String($modelInputs.filter(':checked').val() || '');
        }

        function updateSaveButtonState() {
            var hasChanges = selectedModel() !== '' && selectedModel() !== initialSelectedModel;

            $saveButton.toggleClass('disabled', !hasChanges);
            $saveButton.prop('disabled', !hasChanges);
        }

        if (typeof $.fn.accordion === 'function') {
            $('.module-local-speech-to-text .stt-custom-model-dropdown, '
                + '.module-local-speech-to-text .stt-advanced-settings-dropdown').accordion();
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
            if (selectedModel() === '' || selectedModel() === initialSelectedModel) {
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
        $('.module-local-speech-to-text .stt-delete-custom-model-form').each(function () {
            bindConfirmableForm($(this));
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
        initializeTranscriptPlayer();
        initializeModuleLogs();
        initializeCdrHistorySlider($settingsForm);
        initializeTranscriptRetentionSlider($settingsForm);
        updateRetentionConflict($settingsForm);
        initializeVocabularyTermsTools($settingsForm);
        initializeConfirmModal();

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
        $('.module-local-speech-to-text .stt-custom-model-form').form({
            fields: {
                model_repo: ['empty'],
                runtime_model: ['empty'],
                model_title: ['empty']
            }
        });
    });
}());
