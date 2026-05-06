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
        initializeTranscriptPlayer();
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
