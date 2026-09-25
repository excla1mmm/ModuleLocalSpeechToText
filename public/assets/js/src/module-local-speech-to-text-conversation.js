(function (window, $) {
    'use strict';

    var PLAYBACK_RATES = [0.5, 1, 2];

    function safeNumber(value) {
        var number = Number(value);

        return Number.isFinite(number) ? Math.max(0, number) : 0;
    }

    function speakerClass(key) {
        return String(key || '').replace(/[^a-z0-9_-]/gi, '-') || 'left';
    }

    function createLegend(speakers) {
        var $legend = $('<div>', { class: 'stt-transcript-legend' });

        speakers.forEach(function (speaker) {
            $legend.append(
                $('<span>', { class: 'stt-transcript-legend-item stt-speaker-' + speakerClass(speaker.key) }).append(
                    $('<span>', { class: 'stt-speaker-dot' }),
                    document.createTextNode(String(speaker.label || ''))
                )
            );
        });

        return $legend;
    }

    // Same markup as the server-rendered transcript card.
    function createSpeed(labels) {
        var $speed = $('<div>', { class: 'stt-player-speed', role: 'group', 'aria-label': labels.speed || '' });
        PLAYBACK_RATES.forEach(function (rate) {
            $speed.append($('<button>', {
                type: 'button',
                class: 'stt-player-speed-option' + (rate === 1 ? ' active' : ''),
                'data-rate': rate,
                'aria-pressed': rate === 1 ? 'true' : 'false'
            }).text(rate + 'x'));
        });

        return $speed;
    }

    function createPlayer(labels, durationLabel) {
        var $player = $('<div>', {
            class: 'stt-transcript-player',
            'data-duration': safeNumber(labels.durationSeconds)
        }).append(
            $('<audio>', {
                class: 'stt-transcript-audio',
                preload: 'metadata',
                'data-src': String(labels.audioUrl || '')
            }),
            $('<button>', {
                type: 'button',
                class: 'stt-player-play',
                'aria-label': labels.play,
                'data-content': labels.play
            }).append($('<i>', { class: 'play icon' })),
            $('<span>', { class: 'stt-player-duration' }).text('00:00 / ' + durationLabel),
            createSpeed(labels)
        );

        if (labels.downloadUrl) {
            $player.append(
                $('<a>', {
                    class: 'stt-player-download',
                    href: String(labels.downloadUrl),
                    'data-content': labels.download,
                    'aria-label': labels.download,
                    title: labels.download
                }).append($('<i>', { class: 'download icon' }))
            );
        }

        return $player;
    }

    function createOverview(conversation, labels) {
        var speakers = Array.isArray(conversation.speakers) ? conversation.speakers : [];
        var durationLabel = String(conversation.durationLabel || '0:00');
        var playable = Boolean(labels.audioUrl);
        var $overview = $('<div>', { class: 'stt-transcript-overview' });
        var $overviewHeader = $('<div>', { class: 'stt-transcript-overview-header' }).append(
            playable
                ? createPlayer(labels, durationLabel)
                : $('<span>', { class: 'stt-transcript-duration' }).text(durationLabel),
            createLegend(speakers)
        );
        var $lanes = $('<div>', { class: 'stt-transcript-lanes' });

        speakers.forEach(function (speaker) {
            var key = speakerClass(speaker.key);
            var $lane = $('<div>', { class: 'stt-transcript-lane stt-speaker-' + key });
            var segments = Array.isArray(speaker.segments) ? speaker.segments : [];

            segments.forEach(function (turn) {
                $lane.append($('<span>', {
                    class: 'stt-transcript-lane-segment',
                    'data-start': safeNumber(turn.start),
                    'data-end': safeNumber(turn.end)
                }).css({
                    left: safeNumber(turn.startPercent) + '%',
                    width: safeNumber(turn.widthPercent) + '%'
                }));
            });
            $lanes.append($lane);
        });

        var $seek = $('<div>', { class: 'stt-transcript-seek' }).append($lanes);
        if (playable) {
            $seek.addClass('is-playable').append(
                $('<span>', { class: 'stt-transcript-playhead' }),
                $('<span>', { class: 'stt-transcript-seek-tooltip' }).text('0:00'),
                $('<input>', {
                    class: 'stt-transcript-range',
                    type: 'range',
                    min: 0,
                    max: 1000,
                    value: 0,
                    'aria-label': labels.play
                })
            );
        }

        return $overview.append(
            $overviewHeader,
            $seek,
            $('<div>', { class: 'stt-transcript-scale' }).append(
                $('<span>').text('0:00'),
                $('<span>').text(durationLabel)
            )
        );
    }

    function createToolbar(speakers, labels) {
        var $filters = $('<div>', { class: 'stt-transcript-filters', role: 'group' });

        $filters.append($('<button>', {
            type: 'button',
            class: 'stt-transcript-filter active',
            'data-speaker': 'all',
            'aria-pressed': 'true'
        }).text(labels.all));
        speakers.forEach(function (speaker) {
            $filters.append($('<button>', {
                type: 'button',
                class: 'stt-transcript-filter',
                'data-speaker': speakerClass(speaker.key),
                'aria-pressed': 'false'
            }).text(String(speaker.label || '')));
        });

        return $('<div>', { class: 'stt-transcript-toolbar' }).append(
            $('<label>', { class: 'stt-transcript-search' }).append(
                $('<i>', { class: 'search icon' }),
                $('<input>', {
                    type: 'search',
                    placeholder: labels.search,
                    'aria-label': labels.search
                })
            ),
            $filters,
            // Optional actions menu supplied by the host (the call-history
            // modal), matching the full transcript card's toolbar.
            labels.actions instanceof $ ? labels.actions : null
        );
    }

    function createTurn(turn, playable) {
        var key = speakerClass(turn.speakerKey);
        var $row = $('<article>', {
            class: 'stt-transcript-turn stt-turn-' + key + (playable ? ' stt-playable-turn' : ''),
            'data-speaker': key,
            'data-start': safeNumber(turn.start),
            'data-end': safeNumber(turn.end),
            tabindex: playable ? 0 : null
        });
        var $meta = $('<div>', { class: 'stt-turn-meta' }).append(
            $('<span>', { class: 'stt-speaker-dot' }),
            $('<strong>', { class: 'stt-turn-speaker' }).text(String(turn.speakerLabel || ''))
        );

        return $row.append(
            $('<time>', { class: 'stt-turn-time' }).text(String(turn.startLabel || '')),
            $('<div>', { class: 'stt-turn-content' }).append(
                $meta,
                $('<div>', { class: 'stt-turn-text', dir: 'auto' }).text(String(turn.text || ''))
            )
        );
    }

    function create(conversation, labels) {
        var data = conversation && typeof conversation === 'object' ? conversation : {};
        var speakers = Array.isArray(data.speakers) ? data.speakers : [];
        var timeline = Array.isArray(data.timeline) ? data.timeline : [];
        var text = Object.assign({ all: 'All', search: 'Search', empty: '', play: '', download: '' }, labels || {});
        var playable = Boolean(text.audioUrl);
        var $root = $('<div>', { class: 'stt-transcript-view' });
        var $list = $('<div>', { class: 'stt-transcript-list' });

        timeline.forEach(function (turn) {
            if (!turn || typeof turn !== 'object') {
                return;
            }
            if (turn.gapBeforeLabel) {
                $list.append(
                    $('<div>', { class: 'stt-transcript-gap' }).append(
                        $('<span>').text('··· ' + String(turn.gapBeforeLabel))
                    )
                );
            }
            $list.append(createTurn(turn, playable));
        });

        $root.append(
            createOverview(data, text),
            createToolbar(speakers, text),
            $list,
            $('<div>', { class: 'stt-transcript-empty ui message', hidden: true }).text(text.empty)
        );
        initialize($root);

        return $root;
    }

    function formatTime(seconds) {
        var total = Math.floor(Math.max(0, seconds || 0));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var rest = total % 60;

        if (hours > 0) {
            return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
        }

        return Math.floor(total / 60) + ':' + String(rest).padStart(2, '0');
    }

    // A transcript page address may carry the recording (stage result id) and
    // second the reader stopped at, and the open call leg:
    // ?id=<result>&lstt_stage=<result>&lstt_t=<seconds>&lstt_leg=internal.
    // The module prefix keeps these names clear of Core and other modules;
    // links shared before it (stage/t/leg) still open and are rewritten on
    // the next address update.
    var LOCATION_KEYS = { stage: 'lstt_stage', t: 'lstt_t', leg: 'lstt_leg' };
    var LEGACY_LOCATION_KEYS = { stage: 'stage', t: 't', leg: 'leg' };

    var transcriptLocation = {
        read: function (search) {
            var query;
            try {
                query = new URLSearchParams(search || '');
            } catch (error) {
                return { stage: '', t: 0, leg: '' };
            }
            var keys = query.has(LOCATION_KEYS.stage) || query.has(LOCATION_KEYS.leg) ? LOCATION_KEYS : LEGACY_LOCATION_KEYS;
            var stage = String(query.get(keys.stage) || '');
            var seconds = Number(query.get(keys.t));
            var valid = /^[1-9][0-9]*$/.test(stage) && query.get(keys.t) !== null && Number.isFinite(seconds) && seconds >= 0 && seconds <= 86400;

            return {
                stage: valid ? stage : '',
                t: valid ? Math.floor(seconds) : 0,
                leg: query.get(keys.leg) === 'internal' ? 'internal' : ''
            };
        },
        search: function (search, changes) {
            var query;
            try {
                query = new URLSearchParams(search || '');
            } catch (error) {
                return '';
            }
            var current = transcriptLocation.read(search);
            var next = {
                stage: current.stage,
                t: current.t,
                leg: current.leg
            };
            if (Object.prototype.hasOwnProperty.call(changes, 'stage')) {
                next.stage = changes.stage ? String(changes.stage) : '';
                next.t = Math.floor(Number(changes.t) || 0);
            }
            if (Object.prototype.hasOwnProperty.call(changes, 'leg')) {
                next.leg = changes.leg === 'internal' ? 'internal' : '';
            }
            [LOCATION_KEYS, LEGACY_LOCATION_KEYS].forEach(function (keys) {
                query.delete(keys.stage);
                query.delete(keys.t);
                query.delete(keys.leg);
            });
            if (next.stage && next.t > 0) {
                query.set(LOCATION_KEYS.stage, next.stage);
                query.set(LOCATION_KEYS.t, String(next.t));
            }
            if (next.leg === 'internal') {
                query.set(LOCATION_KEYS.leg, 'internal');
            }
            var result = query.toString();

            return result === '' ? '' : '?' + result;
        },
        write: function (changes) {
            try {
                var location = window.location;
                window.history.replaceState(window.history.state, '', location.pathname + transcriptLocation.search(location.search, changes) + location.hash);
            } catch (error) {
                // The address is a convenience; playback never depends on it.
            }
        }
    };

    function initializePlayer($root) {
        var audio = $root.find('.stt-transcript-audio').get(0);
        var $player = $root.find('.stt-transcript-player');
        var $playButton = $player.find('.stt-player-play');
        var $playIcon = $playButton.find('i');
        var $duration = $player.find('.stt-player-duration');
        var $seek = $root.find('.stt-transcript-seek.is-playable');
        var $range = $seek.find('.stt-transcript-range');
        var $playhead = $seek.find('.stt-transcript-playhead');
        var $tooltip = $seek.find('.stt-transcript-seek-tooltip');
        var $scaleEnd = $root.find('.stt-transcript-scale span').last();
        var $segments = $seek.find('.stt-transcript-lane-segment');
        var $turns = $root.find('.stt-playable-turn');
        var pendingRangeValue = null;
        var waitingForMetadata = false;
        var synchronizedDuration = 0;
        // Only server-rendered transcript pages name their stage; the call-history modal never touches the address.
        var stageId = String($root.attr('data-stt-stage') || '');
        var linkable = /^[1-9][0-9]*$/.test(stageId);

        if (!audio || $range.length === 0 || $root.data('stt-player-initialized')) {
            return;
        }
        $root.data('stt-player-initialized', true);

        function fallbackDuration() {
            return safeNumber($player.data('duration'));
        }

        function currentDuration() {
            return Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : fallbackDuration();
        }

        function ensureSource() {
            if (audio.getAttribute('src')) {
                return true;
            }
            var source = String(audio.getAttribute('data-src') || '');
            if (!source) {
                return false;
            }
            audio.setAttribute('src', source);
            audio.load();

            return true;
        }

        function setPlayIcon(playing) {
            $playIcon.toggleClass('play', !playing).toggleClass('pause', playing);
        }

        function setActiveTurns() {
            var current = audio.currentTime;
            $turns.each(function () {
                var $turn = $(this);
                var start = safeNumber($turn.data('start'));
                var end = safeNumber($turn.data('end'));
                $turn.toggleClass('stt-turn-active', current >= start && current <= Math.max(end, start + 1));
            });
        }

        function synchronizeTimeline(duration) {
            if (duration <= 0 || Math.abs(duration - synchronizedDuration) < 0.01) {
                return;
            }
            synchronizedDuration = duration;
            $scaleEnd.text(formatTime(duration));
            $segments.each(function () {
                var $segment = $(this);
                var start = Math.min(safeNumber($segment.data('start')), duration);
                var end = Math.min(Math.max(safeNumber($segment.data('end')), start), duration);
                $segment.css({
                    left: ((start / duration) * 100) + '%',
                    width: (((end - start) / duration) * 100) + '%'
                });
            });
        }

        function update() {
            var duration = currentDuration();
            var value = duration > 0 ? Math.min(1000, Math.round((audio.currentTime / duration) * 1000)) : 0;
            synchronizeTimeline(duration);
            $range.val(value).attr('aria-valuetext', formatTime(audio.currentTime));
            $playhead.css('left', (value / 10) + '%');
            $duration.text(formatTime(audio.currentTime) + ' / ' + (duration > 0 ? formatTime(duration) : '--:--'));
            setActiveTurns();
        }

        function play() {
            if (!ensureSource()) {
                return;
            }
            var result = audio.play();
            if (result && typeof result.catch === 'function') {
                result.catch(function () {
                    setPlayIcon(false);
                });
            }
        }

        function seekByRange(value) {
            function applySeek() {
                var duration = currentDuration();
                if (duration <= 0) {
                    return;
                }
                var value = pendingRangeValue;
                pendingRangeValue = null;
                audio.currentTime = (duration * value) / 1000;
                update();
            }

            pendingRangeValue = value;
            if (!ensureSource()) {
                return;
            }
            if (audio.readyState >= 1) {
                applySeek();
            } else if (!waitingForMetadata) {
                waitingForMetadata = true;
                audio.addEventListener('loadedmetadata', function () {
                    waitingForMetadata = false;
                    applySeek();
                }, { once: true });
            }
        }

        function seekTurn($turn) {
            var seconds = safeNumber($turn.data('start'));
            rememberMoment(seconds);

            function applySeek() {
                audio.currentTime = Math.min(seconds, currentDuration() || seconds);
                update();
                play();
            }

            if (!ensureSource()) {
                return;
            }
            if (audio.readyState >= 1) {
                applySeek();
            } else {
                audio.addEventListener('loadedmetadata', applySeek, { once: true });
            }
        }

        function synchronize() {
            update();
        }

        function rememberMoment(seconds) {
            if (linkable) {
                transcriptLocation.write({ stage: stageId, t: seconds });
            }
        }

        // A shared moment is held without playing: browsers start audio only on a user gesture.
        function holdMoment(seconds) {
            function apply() {
                var duration = currentDuration();
                audio.currentTime = duration > 0 ? Math.min(seconds, Math.max(0, duration - 0.01)) : seconds;
                update();
                var turn = $root.find('.stt-turn-active').get(0) || $root.get(0);
                if (turn && typeof turn.scrollIntoView === 'function') {
                    turn.scrollIntoView({ block: 'center' });
                }
            }

            if (!ensureSource()) {
                return;
            }
            if (audio.readyState >= 1) {
                apply();
            } else {
                audio.addEventListener('loadedmetadata', apply, { once: true });
            }
        }

        // defaultPlaybackRate survives the audio.load() that attaches the
        // lazily loaded source; playbackRate applies to the running audio.
        $player.find('.stt-player-speed-option').on('click.sttTranscript', function () {
            var rate = safeNumber($(this).data('rate')) || 1;
            audio.defaultPlaybackRate = rate;
            audio.playbackRate = rate;
            $player.find('.stt-player-speed-option').removeClass('active').attr('aria-pressed', 'false');
            $(this).addClass('active').attr('aria-pressed', 'true');
        });
        $playButton.on('click.sttTranscript', function () {
            if (audio.paused) {
                play();
            } else {
                audio.pause();
                rememberMoment(audio.currentTime);
            }
        });
        $range.on('input.sttTranscript change.sttTranscript', function () {
            seekByRange(safeNumber(this.value));
        });
        // Only the settled position is written; browsers throttle address updates.
        $range.on('change.sttTranscript', function () {
            rememberMoment((currentDuration() * safeNumber(this.value)) / 1000);
        });
        $seek.on('mousemove.sttTranscript', function (event) {
            var offset = $seek.offset();
            var width = $seek.width();
            var percent = width > 0 ? Math.max(0, Math.min(100, ((event.pageX - offset.left) / width) * 100)) : 0;
            $tooltip.text(formatTime((currentDuration() * percent) / 100)).css('left', percent + '%');
        });
        $turns.on('click.sttTranscript', function () {
            seekTurn($(this));
        }).on('keydown.sttTranscript', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                seekTurn($(this));
            }
        });

        audio.addEventListener('loadedmetadata', synchronize);
        audio.addEventListener('timeupdate', update);
        audio.addEventListener('play', function () {
            setPlayIcon(true);
        });
        audio.addEventListener('pause', function () {
            setPlayIcon(false);
        });
        audio.addEventListener('ended', function () {
            audio.currentTime = 0;
            update();
        });
        update();
        ensureSource();
        if (linkable) {
            var moment = transcriptLocation.read(window.location.search);
            if (moment.stage === stageId) {
                holdMoment(moment.t);
            }
        }
    }

    function initialize(root) {
        var $root = root && root.jquery ? root : $(root);
        var $input = $root.find('.stt-transcript-search input');
        var $filters = $root.find('.stt-transcript-filter');
        var $rows = $root.find('.stt-transcript-turn');
        var $gaps = $root.find('.stt-transcript-gap');
        var $empty = $root.find('.stt-transcript-empty');
        var activeSpeaker = 'all';

        if ($root.length === 0 || $root.data('stt-transcript-initialized')) {
            return;
        }
        $root.data('stt-transcript-initialized', true);

        function apply() {
            var query = String($input.val() || '').trim().toLocaleLowerCase();
            var visible = 0;

            $rows.each(function () {
                var $row = $(this);
                var speakerMatches = activeSpeaker === 'all' || $row.data('speaker') === activeSpeaker;
                var textMatches = query === '' || $row.find('.stt-turn-text').text().toLocaleLowerCase().indexOf(query) !== -1;
                var shown = speakerMatches && textMatches;

                $row.prop('hidden', !shown);
                if (shown) {
                    visible += 1;
                }
            });
            $gaps.prop('hidden', query !== '' || activeSpeaker !== 'all');
            $empty.prop('hidden', visible !== 0);
        }

        $input.on('input.sttTranscript', apply);
        $filters.on('click.sttTranscript', function () {
            activeSpeaker = String($(this).data('speaker') || 'all');
            $filters.removeClass('active').attr('aria-pressed', 'false');
            $(this).addClass('active').attr('aria-pressed', 'true');
            apply();
        });
        apply();
        initializePlayer($root);
    }

    window.SttTranscriptView = {
        create: create,
        initialize: initialize,
        location: transcriptLocation
    };

    $(function () {
        $('.stt-transcript-view').each(function () {
            initialize(this);
        });
    });
}(window, jQuery));
