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
            'data-duration': safeNumber(labels.durationSeconds),
            'data-label-loading': labels.loading || '',
            'data-label-unavailable': labels.unavailable || '',
            'data-label-failed': labels.failed || ''
        }).append(
            $('<audio>', {
                class: 'stt-transcript-audio',
                preload: 'none',
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
            ),
            playable ? $('<p>', { class: 'stt-player-status', role: 'status', hidden: true }) : null
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

    // Playback follows the standard MikoPBX call history player (CDRPlayer):
    // opening a transcript downloads nothing, a HEAD request only checks that
    // the recording exists. The first Play attaches the URL as a stream (the
    // browser fetches it with Range requests) and audio starts on
    // canplaythrough, once enough is buffered, from the held position: 0:00,
    // a clicked turn, the slider or a shared moment. Only one recording plays
    // at a time on the page.
    var activePlayer = null;

    function stopOthers(player) {
        if (activePlayer && activePlayer !== player) {
            activePlayer.pause();
        }
        activePlayer = player;
    }

    // A missing file or a network failure disables the player, as in CDRPlayer.
    function probeRecording(url, done) {
        var headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (typeof window.TokenManager !== 'undefined' && window.TokenManager && window.TokenManager.accessToken) {
            headers.Authorization = 'Bearer ' + window.TokenManager.accessToken;
        }
        if (typeof window.fetch !== 'function') {
            done(true);
            return;
        }
        window.fetch(url, { method: 'HEAD', credentials: 'same-origin', headers: headers }).then(function (response) {
            // An endpoint that cannot answer HEAD says nothing about the file.
            done(response.ok || response.status === 405 || response.status === 501);
        }, function () {
            done(false);
        });
    }

    // Stereo call recordings carry one party per channel; like CDRPlayer the
    // channels are blended 65/35 so both parties are heard in each ear. One
    // AudioContext serves every player; it is created on the first Play click.
    var mixerContext = null;

    function blendChannels(event) {
        var input = event.inputBuffer;
        var output = event.outputBuffer;
        var outL = output.getChannelData(0);
        var outR = output.numberOfChannels > 1 ? output.getChannelData(1) : null;
        var inL = input.getChannelData(0);
        var inR = input.numberOfChannels > 1 ? input.getChannelData(1) : inL;
        var index;

        for (index = 0; index < inL.length; index += 1) {
            outL[index] = (inL[index] * 0.65) + (inR[index] * 0.35);
            if (outR) {
                outR[index] = (inL[index] * 0.35) + (inR[index] * 0.65);
            }
        }
    }

    function attachMixer(audio) {
        var Context = window.AudioContext || window.webkitAudioContext;
        var processor;
        var source;

        if (!Context) {
            return;
        }
        try {
            if (!audio.sttMixer) {
                mixerContext = mixerContext || new Context();
                // The processor exists before the element is captured: a capture
                // without a connected graph would leave the recording silent.
                processor = mixerContext.createScriptProcessor(4096, 2, 2);
                processor.onaudioprocess = blendChannels;
                source = mixerContext.createMediaElementSource(audio);
                source.connect(processor);
                processor.connect(mixerContext.destination);
                audio.sttMixer = { source: source, processor: processor };
            }
            if (mixerContext.state === 'suspended') {
                mixerContext.resume();
            }
        } catch (error) {
            // Plain stereo playback through the element.
        }
    }

    function detachMixer(audio) {
        var graph = audio && audio.sttMixer;
        if (!graph) {
            return;
        }
        audio.sttMixer = null;
        try {
            graph.processor.onaudioprocess = null;
            graph.source.disconnect();
            graph.processor.disconnect();
        } catch (error) {
            // Already disconnected.
        }
    }

    function initializePlayer($root) {
        var audio = $root.find('.stt-transcript-audio').get(0);
        var $player = $root.find('.stt-transcript-player');
        var $playButton = $player.find('.stt-player-play');
        var $playIcon = $playButton.find('i');
        var $duration = $player.find('.stt-player-duration');
        var $status = $root.find('.stt-player-status');
        var $seek = $root.find('.stt-transcript-seek.is-playable');
        var $range = $seek.find('.stt-transcript-range');
        var $playhead = $seek.find('.stt-transcript-playhead');
        var $tooltip = $seek.find('.stt-transcript-seek-tooltip');
        var $scaleEnd = $root.find('.stt-transcript-scale span').last();
        var $segments = $seek.find('.stt-transcript-lane-segment');
        var $turns = $root.find('.stt-playable-turn');
        var source = String((audio && audio.getAttribute('data-src')) || '');
        var labels = {
            loading: String($player.attr('data-label-loading') || ''),
            unavailable: String($player.attr('data-label-unavailable') || ''),
            failed: String($player.attr('data-label-failed') || '')
        };
        var synchronizedDuration = 0;
        // phase: checking, idle, loading, ready, unavailable, failed
        var phase = 'checking';
        var intent = null;
        var position = 0;
        var buffering = false;
        var player;
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

        function attached() {
            return Boolean(audio.getAttribute('src'));
        }

        function clamp(seconds) {
            var value = safeNumber(seconds);
            var duration = currentDuration();

            return duration > 0 ? Math.min(value, Math.max(0, duration - 0.01)) : value;
        }

        // Until the stream can play through, the clock and playhead hold the requested position.
        function shownTime() {
            if (intent !== null) {
                return intent;
            }

            return attached() && audio.readyState >= 1 ? audio.currentTime : position;
        }

        function showStatus(text, error) {
            $status.text(text).toggleClass('is-error', Boolean(error)).prop('hidden', text === '');
        }

        function renderButton() {
            var loading = intent !== null || buffering;
            var playing = !audio.paused && !loading;

            $playIcon.removeClass('play pause spinner loading');
            if (loading) {
                $playIcon.addClass('spinner loading');
            } else {
                $playIcon.addClass(playing ? 'pause' : 'play');
            }
            $playButton.attr('aria-busy', loading ? 'true' : null);
            if (loading) {
                showStatus(labels.loading, false);
            } else if ($status.text() === labels.loading) {
                showStatus('', false);
            }
        }

        function setActiveTurns(current) {
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
            var current = shownTime();
            var value = duration > 0 ? Math.min(1000, Math.round((current / duration) * 1000)) : 0;

            if (phase === 'unavailable') {
                return;
            }
            synchronizeTimeline(duration);
            $range.val(value).attr('aria-valuetext', formatTime(current));
            $playhead.css('left', (value / 10) + '%');
            $duration.text(formatTime(current) + ' / ' + (duration > 0 ? formatTime(duration) : '--:--'));
            setActiveTurns(current);
        }

        function disable() {
            phase = 'unavailable';
            $playButton.prop('disabled', true).addClass('disabled');
            $player.find('.stt-player-speed, .stt-player-download').hide();
            $range.prop('disabled', true);
            $turns.removeClass('stt-playable-turn').removeAttr('tabindex');
            $duration.text('--:-- / --:--');
            showStatus(labels.unavailable, false);
        }

        function fail() {
            intent = null;
            buffering = false;
            showStatus(labels.failed, true);
            renderButton();
        }

        function moveTo(seconds) {
            if (Math.abs((audio.currentTime || 0) - seconds) < 0.25) {
                return;
            }
            try {
                audio.currentTime = seconds;
            } catch (error) {
                // Applied again once metadata arrives.
            }
        }

        function apply() {
            var seconds;
            var result;

            if (phase !== 'ready' || intent === null) {
                return;
            }
            seconds = clamp(intent);
            intent = null;
            position = seconds;
            moveTo(seconds);
            try {
                result = audio.play();
                if (result && typeof result.catch === 'function') {
                    result.catch(fail);
                }
            } catch (error) {
                fail();
                return;
            }
            renderButton();
            update();
        }

        function play(seconds) {
            if (phase === 'unavailable' || !source) {
                return;
            }
            intent = clamp(seconds);
            position = intent;
            if ($status.hasClass('is-error')) {
                showStatus('', false);
            }
            stopOthers(player);
            // Inside the click: browsers only start an AudioContext on a gesture.
            attachMixer(audio);
            if (phase === 'ready' && attached()) {
                apply();
                return;
            }
            if (!attached()) {
                phase = 'loading';
                audio.setAttribute('src', source);
                audio.load();
            }
            renderButton();
            update();
        }

        function pause() {
            intent = null;
            buffering = false;
            if (!audio.paused) {
                audio.pause();
            }
            renderButton();
        }

        // Moves the held or live position without changing whether it plays.
        function seek(seconds) {
            var value = clamp(seconds);

            position = value;
            if (intent !== null) {
                intent = value;
            } else if (attached() && audio.readyState >= 1) {
                moveTo(value);
            }
            update();
        }

        function rememberMoment(seconds) {
            if (linkable) {
                transcriptLocation.write({ stage: stageId, t: seconds });
            }
        }

        // A shared moment is held without playing or loading: browsers start audio only on a user gesture.
        function holdMoment(seconds) {
            var turn;

            seek(seconds);
            turn = $root.find('.stt-turn-active').get(0) || $root.get(0);
            if (turn && typeof turn.scrollIntoView === 'function') {
                turn.scrollIntoView({ block: 'center' });
            }
        }

        player = { pause: pause };
        $root.data('stt-player', {
            pause: pause,
            dispose: function () {
                pause();
                if (activePlayer === player) {
                    activePlayer = null;
                }
                audio.removeAttribute('src');
                audio.load();
                detachMixer(audio);
            }
        });

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
            if (!audio.paused || intent !== null) {
                rememberMoment(shownTime());
                pause();
            } else {
                play(shownTime());
            }
        });
        $range.on('input.sttTranscript change.sttTranscript', function () {
            seek((currentDuration() * safeNumber(this.value)) / 1000);
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
            var seconds = safeNumber($(this).data('start'));
            rememberMoment(seconds);
            play(seconds);
        }).on('keydown.sttTranscript', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                $(this).trigger('click');
            }
        });

        // loadedmetadata: jump to the held position before buffering starts there.
        audio.addEventListener('loadedmetadata', function () {
            if (intent !== null) {
                moveTo(clamp(intent));
            }
            update();
        });
        // canplaythrough: enough is buffered to play without stalling.
        audio.addEventListener('canplaythrough', function () {
            if (!attached()) {
                return;
            }
            phase = 'ready';
            apply();
        });
        audio.addEventListener('waiting', function () {
            if (!audio.paused && !buffering) {
                buffering = true;
                renderButton();
            }
        });
        audio.addEventListener('playing', function () {
            buffering = false;
            renderButton();
        });
        audio.addEventListener('timeupdate', update);
        audio.addEventListener('play', renderButton);
        audio.addEventListener('pause', renderButton);
        audio.addEventListener('ended', function () {
            audio.currentTime = 0;
            position = 0;
            if (activePlayer === player) {
                activePlayer = null;
            }
            renderButton();
            update();
        });
        // The stream is dropped so the next Play attaches it again.
        audio.addEventListener('error', function () {
            if (!attached()) {
                return;
            }
            audio.removeAttribute('src');
            audio.load();
            phase = 'failed';
            fail();
        });

        update();
        if (linkable) {
            var moment = transcriptLocation.read(window.location.search);
            if (moment.stage === stageId) {
                holdMoment(moment.t);
            }
        }
        if (!source) {
            return;
        }
        probeRecording(source, function (ok) {
            if (phase !== 'checking') {
                return;
            }
            if (ok) {
                phase = 'idle';
            } else {
                disable();
            }
        });
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

    function eachPlayer(root, action) {
        $(root).find('.stt-transcript-view').addBack('.stt-transcript-view').each(function () {
            var player = $(this).data('stt-player');
            if (player) {
                player[action]();
            }
        });
    }

    // Pauses and cancels a pending start (a recording still buffering would
    // otherwise begin playing once it can), e.g. when a tab hides it.
    function pause(root) {
        eachPlayer(root, 'pause');
    }

    // Stops the audio, aborts its stream and releases the mixer, e.g. when
    // the call-history modal closes.
    function dispose(root) {
        eachPlayer(root, 'dispose');
    }

    window.SttTranscriptView = {
        create: create,
        initialize: initialize,
        pause: pause,
        dispose: dispose,
        location: transcriptLocation
    };

    $(function () {
        $('.stt-transcript-view').each(function () {
            initialize(this);
        });
    });
}(window, jQuery));
