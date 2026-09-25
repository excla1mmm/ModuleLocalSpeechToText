function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
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
    var $legend = $('<div>', {
      class: 'stt-transcript-legend'
    });
    speakers.forEach(function (speaker) {
      $legend.append($('<span>', {
        class: 'stt-transcript-legend-item stt-speaker-' + speakerClass(speaker.key)
      }).append($('<span>', {
        class: 'stt-speaker-dot'
      }), document.createTextNode(String(speaker.label || ''))));
    });
    return $legend;
  }

  // Same markup as the server-rendered transcript card.
  function createSpeed(labels) {
    var $speed = $('<div>', {
      class: 'stt-player-speed',
      role: 'group',
      'aria-label': labels.speed || ''
    });
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
    }).append($('<audio>', {
      class: 'stt-transcript-audio',
      preload: 'none',
      'data-src': String(labels.audioUrl || '')
    }), $('<button>', {
      type: 'button',
      class: 'stt-player-play',
      'aria-label': labels.play,
      'data-content': labels.play
    }).append($('<i>', {
      class: 'play icon'
    })), $('<span>', {
      class: 'stt-player-duration'
    }).text('00:00 / ' + durationLabel), createSpeed(labels));
    if (labels.downloadUrl) {
      $player.append($('<a>', {
        class: 'stt-player-download',
        href: String(labels.downloadUrl),
        'data-content': labels.download,
        'aria-label': labels.download,
        title: labels.download
      }).append($('<i>', {
        class: 'download icon'
      })));
    }
    return $player;
  }
  function createOverview(conversation, labels) {
    var speakers = Array.isArray(conversation.speakers) ? conversation.speakers : [];
    var durationLabel = String(conversation.durationLabel || '0:00');
    var playable = Boolean(labels.audioUrl);
    var $overview = $('<div>', {
      class: 'stt-transcript-overview'
    });
    var $overviewHeader = $('<div>', {
      class: 'stt-transcript-overview-header'
    }).append(playable ? createPlayer(labels, durationLabel) : $('<span>', {
      class: 'stt-transcript-duration'
    }).text(durationLabel), createLegend(speakers));
    var $lanes = $('<div>', {
      class: 'stt-transcript-lanes'
    });
    speakers.forEach(function (speaker) {
      var key = speakerClass(speaker.key);
      var $lane = $('<div>', {
        class: 'stt-transcript-lane stt-speaker-' + key
      });
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
    var $seek = $('<div>', {
      class: 'stt-transcript-seek'
    }).append($lanes);
    if (playable) {
      $seek.addClass('is-playable').append($('<span>', {
        class: 'stt-transcript-playhead'
      }), $('<span>', {
        class: 'stt-transcript-seek-tooltip'
      }).text('0:00'), $('<input>', {
        class: 'stt-transcript-range',
        type: 'range',
        min: 0,
        max: 1000,
        value: 0,
        'aria-label': labels.play
      }));
    }
    return $overview.append($overviewHeader, $seek, $('<div>', {
      class: 'stt-transcript-scale'
    }).append($('<span>').text('0:00'), $('<span>').text(durationLabel)), playable ? $('<p>', {
      class: 'stt-player-status',
      role: 'status',
      hidden: true
    }) : null);
  }
  function createToolbar(speakers, labels) {
    var $filters = $('<div>', {
      class: 'stt-transcript-filters',
      role: 'group'
    });
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
    return $('<div>', {
      class: 'stt-transcript-toolbar'
    }).append($('<label>', {
      class: 'stt-transcript-search'
    }).append($('<i>', {
      class: 'search icon'
    }), $('<input>', {
      type: 'search',
      placeholder: labels.search,
      'aria-label': labels.search
    })), $filters,
    // Optional actions menu supplied by the host (the call-history
    // modal), matching the full transcript card's toolbar.
    labels.actions instanceof $ ? labels.actions : null);
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
    var $meta = $('<div>', {
      class: 'stt-turn-meta'
    }).append($('<span>', {
      class: 'stt-speaker-dot'
    }), $('<strong>', {
      class: 'stt-turn-speaker'
    }).text(String(turn.speakerLabel || '')));
    return $row.append($('<time>', {
      class: 'stt-turn-time'
    }).text(String(turn.startLabel || '')), $('<div>', {
      class: 'stt-turn-content'
    }).append($meta, $('<div>', {
      class: 'stt-turn-text',
      dir: 'auto'
    }).text(String(turn.text || ''))));
  }
  function create(conversation, labels) {
    var data = conversation && _typeof(conversation) === 'object' ? conversation : {};
    var speakers = Array.isArray(data.speakers) ? data.speakers : [];
    var timeline = Array.isArray(data.timeline) ? data.timeline : [];
    var text = Object.assign({
      all: 'All',
      search: 'Search',
      empty: '',
      play: '',
      download: ''
    }, labels || {});
    var playable = Boolean(text.audioUrl);
    var $root = $('<div>', {
      class: 'stt-transcript-view'
    });
    var $list = $('<div>', {
      class: 'stt-transcript-list'
    });
    timeline.forEach(function (turn) {
      if (!turn || _typeof(turn) !== 'object') {
        return;
      }
      if (turn.gapBeforeLabel) {
        $list.append($('<div>', {
          class: 'stt-transcript-gap'
        }).append($('<span>').text('··· ' + String(turn.gapBeforeLabel))));
      }
      $list.append(createTurn(turn, playable));
    });
    $root.append(createOverview(data, text), createToolbar(speakers, text), $list, $('<div>', {
      class: 'stt-transcript-empty ui message',
      hidden: true
    }).text(text.empty));
    initialize($root);
    return $root;
  }
  function formatTime(seconds) {
    var total = Math.floor(Math.max(0, seconds || 0));
    var hours = Math.floor(total / 3600);
    var minutes = Math.floor(total % 3600 / 60);
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
  var LOCATION_KEYS = {
    stage: 'lstt_stage',
    t: 'lstt_t',
    leg: 'lstt_leg'
  };
  var LEGACY_LOCATION_KEYS = {
    stage: 'stage',
    t: 't',
    leg: 'leg'
  };
  var transcriptLocation = {
    read: function (search) {
      var query;
      try {
        query = new URLSearchParams(search || '');
      } catch (error) {
        return {
          stage: '',
          t: 0,
          leg: ''
        };
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
    var headers = {
      'X-Requested-With': 'XMLHttpRequest'
    };
    if (typeof window.TokenManager !== 'undefined' && window.TokenManager && window.TokenManager.accessToken) {
      headers.Authorization = 'Bearer ' + window.TokenManager.accessToken;
    }
    if (typeof window.fetch !== 'function') {
      done(true);
      return;
    }
    window.fetch(url, {
      method: 'HEAD',
      credentials: 'same-origin',
      headers: headers
    }).then(function (response) {
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
      outL[index] = inL[index] * 0.65 + inR[index] * 0.35;
      if (outR) {
        outR[index] = inL[index] * 0.35 + inR[index] * 0.65;
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
        audio.sttMixer = {
          source: source,
          processor: processor
        };
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
    var source = String(audio && audio.getAttribute('data-src') || '');
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
          left: start / duration * 100 + '%',
          width: (end - start) / duration * 100 + '%'
        });
      });
    }
    function update() {
      var duration = currentDuration();
      var current = shownTime();
      var value = duration > 0 ? Math.min(1000, Math.round(current / duration * 1000)) : 0;
      if (phase === 'unavailable') {
        return;
      }
      synchronizeTimeline(duration);
      $range.val(value).attr('aria-valuetext', formatTime(current));
      $playhead.css('left', value / 10 + '%');
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
        transcriptLocation.write({
          stage: stageId,
          t: seconds
        });
      }
    }

    // A shared moment is held without playing or loading: browsers start audio only on a user gesture.
    function holdMoment(seconds) {
      var turn;
      seek(seconds);
      turn = $root.find('.stt-turn-active').get(0) || $root.get(0);
      if (turn && typeof turn.scrollIntoView === 'function') {
        turn.scrollIntoView({
          block: 'center'
        });
      }
    }
    player = {
      pause: pause
    };
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
      seek(currentDuration() * safeNumber(this.value) / 1000);
    });
    // Only the settled position is written; browsers throttle address updates.
    $range.on('change.sttTranscript', function () {
      rememberMoment(currentDuration() * safeNumber(this.value) / 1000);
    });
    $seek.on('mousemove.sttTranscript', function (event) {
      var offset = $seek.offset();
      var width = $seek.width();
      var percent = width > 0 ? Math.max(0, Math.min(100, (event.pageX - offset.left) / width * 100)) : 0;
      $tooltip.text(formatTime(currentDuration() * percent / 100)).css('left', percent + '%');
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
})(window, jQuery);

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LWNvbnZlcnNhdGlvbi5qcyIsIm5hbWVzIjpbIndpbmRvdyIsIiQiLCJQTEFZQkFDS19SQVRFUyIsInNhZmVOdW1iZXIiLCJ2YWx1ZSIsIm51bWJlciIsIk51bWJlciIsImlzRmluaXRlIiwiTWF0aCIsIm1heCIsInNwZWFrZXJDbGFzcyIsImtleSIsIlN0cmluZyIsInJlcGxhY2UiLCJjcmVhdGVMZWdlbmQiLCJzcGVha2VycyIsIiRsZWdlbmQiLCJjbGFzcyIsImZvckVhY2giLCJzcGVha2VyIiwiYXBwZW5kIiwiZG9jdW1lbnQiLCJjcmVhdGVUZXh0Tm9kZSIsImxhYmVsIiwiY3JlYXRlU3BlZWQiLCJsYWJlbHMiLCIkc3BlZWQiLCJyb2xlIiwic3BlZWQiLCJyYXRlIiwidHlwZSIsInRleHQiLCJjcmVhdGVQbGF5ZXIiLCJkdXJhdGlvbkxhYmVsIiwiJHBsYXllciIsImR1cmF0aW9uU2Vjb25kcyIsImxvYWRpbmciLCJ1bmF2YWlsYWJsZSIsImZhaWxlZCIsInByZWxvYWQiLCJhdWRpb1VybCIsInBsYXkiLCJkb3dubG9hZFVybCIsImhyZWYiLCJkb3dubG9hZCIsInRpdGxlIiwiY3JlYXRlT3ZlcnZpZXciLCJjb252ZXJzYXRpb24iLCJBcnJheSIsImlzQXJyYXkiLCJwbGF5YWJsZSIsIkJvb2xlYW4iLCIkb3ZlcnZpZXciLCIkb3ZlcnZpZXdIZWFkZXIiLCIkbGFuZXMiLCIkbGFuZSIsInNlZ21lbnRzIiwidHVybiIsInN0YXJ0IiwiZW5kIiwiY3NzIiwibGVmdCIsInN0YXJ0UGVyY2VudCIsIndpZHRoIiwid2lkdGhQZXJjZW50IiwiJHNlZWsiLCJhZGRDbGFzcyIsIm1pbiIsImhpZGRlbiIsImNyZWF0ZVRvb2xiYXIiLCIkZmlsdGVycyIsImFsbCIsInBsYWNlaG9sZGVyIiwic2VhcmNoIiwiYWN0aW9ucyIsImNyZWF0ZVR1cm4iLCJzcGVha2VyS2V5IiwiJHJvdyIsInRhYmluZGV4IiwiJG1ldGEiLCJzcGVha2VyTGFiZWwiLCJzdGFydExhYmVsIiwiZGlyIiwiY3JlYXRlIiwiZGF0YSIsIl90eXBlb2YiLCJ0aW1lbGluZSIsIk9iamVjdCIsImFzc2lnbiIsImVtcHR5IiwiJHJvb3QiLCIkbGlzdCIsImdhcEJlZm9yZUxhYmVsIiwiaW5pdGlhbGl6ZSIsImZvcm1hdFRpbWUiLCJzZWNvbmRzIiwidG90YWwiLCJmbG9vciIsImhvdXJzIiwibWludXRlcyIsInJlc3QiLCJwYWRTdGFydCIsIkxPQ0FUSU9OX0tFWVMiLCJzdGFnZSIsInQiLCJsZWciLCJMRUdBQ1lfTE9DQVRJT05fS0VZUyIsInRyYW5zY3JpcHRMb2NhdGlvbiIsInJlYWQiLCJxdWVyeSIsIlVSTFNlYXJjaFBhcmFtcyIsImVycm9yIiwia2V5cyIsImhhcyIsImdldCIsInZhbGlkIiwidGVzdCIsImNoYW5nZXMiLCJjdXJyZW50IiwibmV4dCIsInByb3RvdHlwZSIsImhhc093blByb3BlcnR5IiwiY2FsbCIsImRlbGV0ZSIsInNldCIsInJlc3VsdCIsInRvU3RyaW5nIiwid3JpdGUiLCJsb2NhdGlvbiIsImhpc3RvcnkiLCJyZXBsYWNlU3RhdGUiLCJzdGF0ZSIsInBhdGhuYW1lIiwiaGFzaCIsImFjdGl2ZVBsYXllciIsInN0b3BPdGhlcnMiLCJwbGF5ZXIiLCJwYXVzZSIsInByb2JlUmVjb3JkaW5nIiwidXJsIiwiZG9uZSIsImhlYWRlcnMiLCJUb2tlbk1hbmFnZXIiLCJhY2Nlc3NUb2tlbiIsIkF1dGhvcml6YXRpb24iLCJmZXRjaCIsIm1ldGhvZCIsImNyZWRlbnRpYWxzIiwidGhlbiIsInJlc3BvbnNlIiwib2siLCJzdGF0dXMiLCJtaXhlckNvbnRleHQiLCJibGVuZENoYW5uZWxzIiwiZXZlbnQiLCJpbnB1dCIsImlucHV0QnVmZmVyIiwib3V0cHV0Iiwib3V0cHV0QnVmZmVyIiwib3V0TCIsImdldENoYW5uZWxEYXRhIiwib3V0UiIsIm51bWJlck9mQ2hhbm5lbHMiLCJpbkwiLCJpblIiLCJpbmRleCIsImxlbmd0aCIsImF0dGFjaE1peGVyIiwiYXVkaW8iLCJDb250ZXh0IiwiQXVkaW9Db250ZXh0Iiwid2Via2l0QXVkaW9Db250ZXh0IiwicHJvY2Vzc29yIiwic291cmNlIiwic3R0TWl4ZXIiLCJjcmVhdGVTY3JpcHRQcm9jZXNzb3IiLCJvbmF1ZGlvcHJvY2VzcyIsImNyZWF0ZU1lZGlhRWxlbWVudFNvdXJjZSIsImNvbm5lY3QiLCJkZXN0aW5hdGlvbiIsInJlc3VtZSIsImRldGFjaE1peGVyIiwiZ3JhcGgiLCJkaXNjb25uZWN0IiwiaW5pdGlhbGl6ZVBsYXllciIsImZpbmQiLCIkcGxheUJ1dHRvbiIsIiRwbGF5SWNvbiIsIiRkdXJhdGlvbiIsIiRzdGF0dXMiLCIkcmFuZ2UiLCIkcGxheWhlYWQiLCIkdG9vbHRpcCIsIiRzY2FsZUVuZCIsImxhc3QiLCIkc2VnbWVudHMiLCIkdHVybnMiLCJnZXRBdHRyaWJ1dGUiLCJhdHRyIiwic3luY2hyb25pemVkRHVyYXRpb24iLCJwaGFzZSIsImludGVudCIsInBvc2l0aW9uIiwiYnVmZmVyaW5nIiwic3RhZ2VJZCIsImxpbmthYmxlIiwiZmFsbGJhY2tEdXJhdGlvbiIsImN1cnJlbnREdXJhdGlvbiIsImR1cmF0aW9uIiwiYXR0YWNoZWQiLCJjbGFtcCIsInNob3duVGltZSIsInJlYWR5U3RhdGUiLCJjdXJyZW50VGltZSIsInNob3dTdGF0dXMiLCJ0b2dnbGVDbGFzcyIsInByb3AiLCJyZW5kZXJCdXR0b24iLCJwbGF5aW5nIiwicGF1c2VkIiwicmVtb3ZlQ2xhc3MiLCJzZXRBY3RpdmVUdXJucyIsImVhY2giLCIkdHVybiIsInN5bmNocm9uaXplVGltZWxpbmUiLCJhYnMiLCIkc2VnbWVudCIsInVwZGF0ZSIsInJvdW5kIiwidmFsIiwiZGlzYWJsZSIsImhpZGUiLCJyZW1vdmVBdHRyIiwiZmFpbCIsIm1vdmVUbyIsImFwcGx5IiwiY2F0Y2giLCJoYXNDbGFzcyIsInNldEF0dHJpYnV0ZSIsImxvYWQiLCJzZWVrIiwicmVtZW1iZXJNb21lbnQiLCJob2xkTW9tZW50Iiwic2Nyb2xsSW50b1ZpZXciLCJibG9jayIsImRpc3Bvc2UiLCJyZW1vdmVBdHRyaWJ1dGUiLCJvbiIsImRlZmF1bHRQbGF5YmFja1JhdGUiLCJwbGF5YmFja1JhdGUiLCJvZmZzZXQiLCJwZXJjZW50IiwicGFnZVgiLCJwcmV2ZW50RGVmYXVsdCIsInRyaWdnZXIiLCJhZGRFdmVudExpc3RlbmVyIiwibW9tZW50Iiwicm9vdCIsImpxdWVyeSIsIiRpbnB1dCIsIiRyb3dzIiwiJGdhcHMiLCIkZW1wdHkiLCJhY3RpdmVTcGVha2VyIiwidHJpbSIsInRvTG9jYWxlTG93ZXJDYXNlIiwidmlzaWJsZSIsInNwZWFrZXJNYXRjaGVzIiwidGV4dE1hdGNoZXMiLCJpbmRleE9mIiwic2hvd24iLCJlYWNoUGxheWVyIiwiYWN0aW9uIiwiYWRkQmFjayIsIlN0dFRyYW5zY3JpcHRWaWV3IiwialF1ZXJ5Il0sInNvdXJjZXMiOlsic3JjL21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1jb252ZXJzYXRpb24uanMiXSwic291cmNlc0NvbnRlbnQiOlsiKGZ1bmN0aW9uICh3aW5kb3csICQpIHtcbiAgICAndXNlIHN0cmljdCc7XG5cbiAgICB2YXIgUExBWUJBQ0tfUkFURVMgPSBbMC41LCAxLCAyXTtcblxuICAgIGZ1bmN0aW9uIHNhZmVOdW1iZXIodmFsdWUpIHtcbiAgICAgICAgdmFyIG51bWJlciA9IE51bWJlcih2YWx1ZSk7XG5cbiAgICAgICAgcmV0dXJuIE51bWJlci5pc0Zpbml0ZShudW1iZXIpID8gTWF0aC5tYXgoMCwgbnVtYmVyKSA6IDA7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gc3BlYWtlckNsYXNzKGtleSkge1xuICAgICAgICByZXR1cm4gU3RyaW5nKGtleSB8fCAnJykucmVwbGFjZSgvW15hLXowLTlfLV0vZ2ksICctJykgfHwgJ2xlZnQnO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZUxlZ2VuZChzcGVha2Vycykge1xuICAgICAgICB2YXIgJGxlZ2VuZCA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWxlZ2VuZCcgfSk7XG5cbiAgICAgICAgc3BlYWtlcnMuZm9yRWFjaChmdW5jdGlvbiAoc3BlYWtlcikge1xuICAgICAgICAgICAgJGxlZ2VuZC5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWxlZ2VuZC1pdGVtIHN0dC1zcGVha2VyLScgKyBzcGVha2VyQ2xhc3Moc3BlYWtlci5rZXkpIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC1zcGVha2VyLWRvdCcgfSksXG4gICAgICAgICAgICAgICAgICAgIGRvY3VtZW50LmNyZWF0ZVRleHROb2RlKFN0cmluZyhzcGVha2VyLmxhYmVsIHx8ICcnKSlcbiAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICApO1xuICAgICAgICB9KTtcblxuICAgICAgICByZXR1cm4gJGxlZ2VuZDtcbiAgICB9XG5cbiAgICAvLyBTYW1lIG1hcmt1cCBhcyB0aGUgc2VydmVyLXJlbmRlcmVkIHRyYW5zY3JpcHQgY2FyZC5cbiAgICBmdW5jdGlvbiBjcmVhdGVTcGVlZChsYWJlbHMpIHtcbiAgICAgICAgdmFyICRzcGVlZCA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC1wbGF5ZXItc3BlZWQnLCByb2xlOiAnZ3JvdXAnLCAnYXJpYS1sYWJlbCc6IGxhYmVscy5zcGVlZCB8fCAnJyB9KTtcbiAgICAgICAgUExBWUJBQ0tfUkFURVMuZm9yRWFjaChmdW5jdGlvbiAocmF0ZSkge1xuICAgICAgICAgICAgJHNwZWVkLmFwcGVuZCgkKCc8YnV0dG9uPicsIHtcbiAgICAgICAgICAgICAgICB0eXBlOiAnYnV0dG9uJyxcbiAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC1wbGF5ZXItc3BlZWQtb3B0aW9uJyArIChyYXRlID09PSAxID8gJyBhY3RpdmUnIDogJycpLFxuICAgICAgICAgICAgICAgICdkYXRhLXJhdGUnOiByYXRlLFxuICAgICAgICAgICAgICAgICdhcmlhLXByZXNzZWQnOiByYXRlID09PSAxID8gJ3RydWUnIDogJ2ZhbHNlJ1xuICAgICAgICAgICAgfSkudGV4dChyYXRlICsgJ3gnKSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHJldHVybiAkc3BlZWQ7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlUGxheWVyKGxhYmVscywgZHVyYXRpb25MYWJlbCkge1xuICAgICAgICB2YXIgJHBsYXllciA9ICQoJzxkaXY+Jywge1xuICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1wbGF5ZXInLFxuICAgICAgICAgICAgJ2RhdGEtZHVyYXRpb24nOiBzYWZlTnVtYmVyKGxhYmVscy5kdXJhdGlvblNlY29uZHMpLFxuICAgICAgICAgICAgJ2RhdGEtbGFiZWwtbG9hZGluZyc6IGxhYmVscy5sb2FkaW5nIHx8ICcnLFxuICAgICAgICAgICAgJ2RhdGEtbGFiZWwtdW5hdmFpbGFibGUnOiBsYWJlbHMudW5hdmFpbGFibGUgfHwgJycsXG4gICAgICAgICAgICAnZGF0YS1sYWJlbC1mYWlsZWQnOiBsYWJlbHMuZmFpbGVkIHx8ICcnXG4gICAgICAgIH0pLmFwcGVuZChcbiAgICAgICAgICAgICQoJzxhdWRpbz4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1hdWRpbycsXG4gICAgICAgICAgICAgICAgcHJlbG9hZDogJ25vbmUnLFxuICAgICAgICAgICAgICAgICdkYXRhLXNyYyc6IFN0cmluZyhsYWJlbHMuYXVkaW9VcmwgfHwgJycpXG4gICAgICAgICAgICB9KSxcbiAgICAgICAgICAgICQoJzxidXR0b24+Jywge1xuICAgICAgICAgICAgICAgIHR5cGU6ICdidXR0b24nLFxuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXBsYXllci1wbGF5JyxcbiAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5wbGF5LFxuICAgICAgICAgICAgICAgICdkYXRhLWNvbnRlbnQnOiBsYWJlbHMucGxheVxuICAgICAgICAgICAgfSkuYXBwZW5kKCQoJzxpPicsIHsgY2xhc3M6ICdwbGF5IGljb24nIH0pKSxcbiAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtcGxheWVyLWR1cmF0aW9uJyB9KS50ZXh0KCcwMDowMCAvICcgKyBkdXJhdGlvbkxhYmVsKSxcbiAgICAgICAgICAgIGNyZWF0ZVNwZWVkKGxhYmVscylcbiAgICAgICAgKTtcblxuICAgICAgICBpZiAobGFiZWxzLmRvd25sb2FkVXJsKSB7XG4gICAgICAgICAgICAkcGxheWVyLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8YT4nLCB7XG4gICAgICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXBsYXllci1kb3dubG9hZCcsXG4gICAgICAgICAgICAgICAgICAgIGhyZWY6IFN0cmluZyhsYWJlbHMuZG93bmxvYWRVcmwpLFxuICAgICAgICAgICAgICAgICAgICAnZGF0YS1jb250ZW50JzogbGFiZWxzLmRvd25sb2FkLFxuICAgICAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5kb3dubG9hZCxcbiAgICAgICAgICAgICAgICAgICAgdGl0bGU6IGxhYmVscy5kb3dubG9hZFxuICAgICAgICAgICAgICAgIH0pLmFwcGVuZCgkKCc8aT4nLCB7IGNsYXNzOiAnZG93bmxvYWQgaWNvbicgfSkpXG4gICAgICAgICAgICApO1xuICAgICAgICB9XG5cbiAgICAgICAgcmV0dXJuICRwbGF5ZXI7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlT3ZlcnZpZXcoY29udmVyc2F0aW9uLCBsYWJlbHMpIHtcbiAgICAgICAgdmFyIHNwZWFrZXJzID0gQXJyYXkuaXNBcnJheShjb252ZXJzYXRpb24uc3BlYWtlcnMpID8gY29udmVyc2F0aW9uLnNwZWFrZXJzIDogW107XG4gICAgICAgIHZhciBkdXJhdGlvbkxhYmVsID0gU3RyaW5nKGNvbnZlcnNhdGlvbi5kdXJhdGlvbkxhYmVsIHx8ICcwOjAwJyk7XG4gICAgICAgIHZhciBwbGF5YWJsZSA9IEJvb2xlYW4obGFiZWxzLmF1ZGlvVXJsKTtcbiAgICAgICAgdmFyICRvdmVydmlldyA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LW92ZXJ2aWV3JyB9KTtcbiAgICAgICAgdmFyICRvdmVydmlld0hlYWRlciA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LW92ZXJ2aWV3LWhlYWRlcicgfSkuYXBwZW5kKFxuICAgICAgICAgICAgcGxheWFibGVcbiAgICAgICAgICAgICAgICA/IGNyZWF0ZVBsYXllcihsYWJlbHMsIGR1cmF0aW9uTGFiZWwpXG4gICAgICAgICAgICAgICAgOiAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZHVyYXRpb24nIH0pLnRleHQoZHVyYXRpb25MYWJlbCksXG4gICAgICAgICAgICBjcmVhdGVMZWdlbmQoc3BlYWtlcnMpXG4gICAgICAgICk7XG4gICAgICAgIHZhciAkbGFuZXMgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sYW5lcycgfSk7XG5cbiAgICAgICAgc3BlYWtlcnMuZm9yRWFjaChmdW5jdGlvbiAoc3BlYWtlcikge1xuICAgICAgICAgICAgdmFyIGtleSA9IHNwZWFrZXJDbGFzcyhzcGVha2VyLmtleSk7XG4gICAgICAgICAgICB2YXIgJGxhbmUgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sYW5lIHN0dC1zcGVha2VyLScgKyBrZXkgfSk7XG4gICAgICAgICAgICB2YXIgc2VnbWVudHMgPSBBcnJheS5pc0FycmF5KHNwZWFrZXIuc2VnbWVudHMpID8gc3BlYWtlci5zZWdtZW50cyA6IFtdO1xuXG4gICAgICAgICAgICBzZWdtZW50cy5mb3JFYWNoKGZ1bmN0aW9uICh0dXJuKSB7XG4gICAgICAgICAgICAgICAgJGxhbmUuYXBwZW5kKCQoJzxzcGFuPicsIHtcbiAgICAgICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sYW5lLXNlZ21lbnQnLFxuICAgICAgICAgICAgICAgICAgICAnZGF0YS1zdGFydCc6IHNhZmVOdW1iZXIodHVybi5zdGFydCksXG4gICAgICAgICAgICAgICAgICAgICdkYXRhLWVuZCc6IHNhZmVOdW1iZXIodHVybi5lbmQpXG4gICAgICAgICAgICAgICAgfSkuY3NzKHtcbiAgICAgICAgICAgICAgICAgICAgbGVmdDogc2FmZU51bWJlcih0dXJuLnN0YXJ0UGVyY2VudCkgKyAnJScsXG4gICAgICAgICAgICAgICAgICAgIHdpZHRoOiBzYWZlTnVtYmVyKHR1cm4ud2lkdGhQZXJjZW50KSArICclJ1xuICAgICAgICAgICAgICAgIH0pKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJGxhbmVzLmFwcGVuZCgkbGFuZSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHZhciAkc2VlayA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXNlZWsnIH0pLmFwcGVuZCgkbGFuZXMpO1xuICAgICAgICBpZiAocGxheWFibGUpIHtcbiAgICAgICAgICAgICRzZWVrLmFkZENsYXNzKCdpcy1wbGF5YWJsZScpLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtcGxheWhlYWQnIH0pLFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1zZWVrLXRvb2x0aXAnIH0pLnRleHQoJzA6MDAnKSxcbiAgICAgICAgICAgICAgICAkKCc8aW5wdXQ+Jywge1xuICAgICAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXJhbmdlJyxcbiAgICAgICAgICAgICAgICAgICAgdHlwZTogJ3JhbmdlJyxcbiAgICAgICAgICAgICAgICAgICAgbWluOiAwLFxuICAgICAgICAgICAgICAgICAgICBtYXg6IDEwMDAsXG4gICAgICAgICAgICAgICAgICAgIHZhbHVlOiAwLFxuICAgICAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5wbGF5XG4gICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICk7XG4gICAgICAgIH1cblxuICAgICAgICByZXR1cm4gJG92ZXJ2aWV3LmFwcGVuZChcbiAgICAgICAgICAgICRvdmVydmlld0hlYWRlcixcbiAgICAgICAgICAgICRzZWVrLFxuICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtc2NhbGUnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nKS50ZXh0KCcwOjAwJyksXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JykudGV4dChkdXJhdGlvbkxhYmVsKVxuICAgICAgICAgICAgKSxcbiAgICAgICAgICAgIHBsYXlhYmxlID8gJCgnPHA+JywgeyBjbGFzczogJ3N0dC1wbGF5ZXItc3RhdHVzJywgcm9sZTogJ3N0YXR1cycsIGhpZGRlbjogdHJ1ZSB9KSA6IG51bGxcbiAgICAgICAgKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjcmVhdGVUb29sYmFyKHNwZWFrZXJzLCBsYWJlbHMpIHtcbiAgICAgICAgdmFyICRmaWx0ZXJzID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZmlsdGVycycsIHJvbGU6ICdncm91cCcgfSk7XG5cbiAgICAgICAgJGZpbHRlcnMuYXBwZW5kKCQoJzxidXR0b24+Jywge1xuICAgICAgICAgICAgdHlwZTogJ2J1dHRvbicsXG4gICAgICAgICAgICBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWZpbHRlciBhY3RpdmUnLFxuICAgICAgICAgICAgJ2RhdGEtc3BlYWtlcic6ICdhbGwnLFxuICAgICAgICAgICAgJ2FyaWEtcHJlc3NlZCc6ICd0cnVlJ1xuICAgICAgICB9KS50ZXh0KGxhYmVscy5hbGwpKTtcbiAgICAgICAgc3BlYWtlcnMuZm9yRWFjaChmdW5jdGlvbiAoc3BlYWtlcikge1xuICAgICAgICAgICAgJGZpbHRlcnMuYXBwZW5kKCQoJzxidXR0b24+Jywge1xuICAgICAgICAgICAgICAgIHR5cGU6ICdidXR0b24nLFxuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZmlsdGVyJyxcbiAgICAgICAgICAgICAgICAnZGF0YS1zcGVha2VyJzogc3BlYWtlckNsYXNzKHNwZWFrZXIua2V5KSxcbiAgICAgICAgICAgICAgICAnYXJpYS1wcmVzc2VkJzogJ2ZhbHNlJ1xuICAgICAgICAgICAgfSkudGV4dChTdHJpbmcoc3BlYWtlci5sYWJlbCB8fCAnJykpKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgcmV0dXJuICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXRvb2xiYXInIH0pLmFwcGVuZChcbiAgICAgICAgICAgICQoJzxsYWJlbD4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtc2VhcmNoJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGk+JywgeyBjbGFzczogJ3NlYXJjaCBpY29uJyB9KSxcbiAgICAgICAgICAgICAgICAkKCc8aW5wdXQ+Jywge1xuICAgICAgICAgICAgICAgICAgICB0eXBlOiAnc2VhcmNoJyxcbiAgICAgICAgICAgICAgICAgICAgcGxhY2Vob2xkZXI6IGxhYmVscy5zZWFyY2gsXG4gICAgICAgICAgICAgICAgICAgICdhcmlhLWxhYmVsJzogbGFiZWxzLnNlYXJjaFxuICAgICAgICAgICAgICAgIH0pXG4gICAgICAgICAgICApLFxuICAgICAgICAgICAgJGZpbHRlcnMsXG4gICAgICAgICAgICAvLyBPcHRpb25hbCBhY3Rpb25zIG1lbnUgc3VwcGxpZWQgYnkgdGhlIGhvc3QgKHRoZSBjYWxsLWhpc3RvcnlcbiAgICAgICAgICAgIC8vIG1vZGFsKSwgbWF0Y2hpbmcgdGhlIGZ1bGwgdHJhbnNjcmlwdCBjYXJkJ3MgdG9vbGJhci5cbiAgICAgICAgICAgIGxhYmVscy5hY3Rpb25zIGluc3RhbmNlb2YgJCA/IGxhYmVscy5hY3Rpb25zIDogbnVsbFxuICAgICAgICApO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZVR1cm4odHVybiwgcGxheWFibGUpIHtcbiAgICAgICAgdmFyIGtleSA9IHNwZWFrZXJDbGFzcyh0dXJuLnNwZWFrZXJLZXkpO1xuICAgICAgICB2YXIgJHJvdyA9ICQoJzxhcnRpY2xlPicsIHtcbiAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtdHVybiBzdHQtdHVybi0nICsga2V5ICsgKHBsYXlhYmxlID8gJyBzdHQtcGxheWFibGUtdHVybicgOiAnJyksXG4gICAgICAgICAgICAnZGF0YS1zcGVha2VyJzoga2V5LFxuICAgICAgICAgICAgJ2RhdGEtc3RhcnQnOiBzYWZlTnVtYmVyKHR1cm4uc3RhcnQpLFxuICAgICAgICAgICAgJ2RhdGEtZW5kJzogc2FmZU51bWJlcih0dXJuLmVuZCksXG4gICAgICAgICAgICB0YWJpbmRleDogcGxheWFibGUgPyAwIDogbnVsbFxuICAgICAgICB9KTtcbiAgICAgICAgdmFyICRtZXRhID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXR1cm4tbWV0YScgfSkuYXBwZW5kKFxuICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC1zcGVha2VyLWRvdCcgfSksXG4gICAgICAgICAgICAkKCc8c3Ryb25nPicsIHsgY2xhc3M6ICdzdHQtdHVybi1zcGVha2VyJyB9KS50ZXh0KFN0cmluZyh0dXJuLnNwZWFrZXJMYWJlbCB8fCAnJykpXG4gICAgICAgICk7XG5cbiAgICAgICAgcmV0dXJuICRyb3cuYXBwZW5kKFxuICAgICAgICAgICAgJCgnPHRpbWU+JywgeyBjbGFzczogJ3N0dC10dXJuLXRpbWUnIH0pLnRleHQoU3RyaW5nKHR1cm4uc3RhcnRMYWJlbCB8fCAnJykpLFxuICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXR1cm4tY29udGVudCcgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICRtZXRhLFxuICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10dXJuLXRleHQnLCBkaXI6ICdhdXRvJyB9KS50ZXh0KFN0cmluZyh0dXJuLnRleHQgfHwgJycpKVxuICAgICAgICAgICAgKVxuICAgICAgICApO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZShjb252ZXJzYXRpb24sIGxhYmVscykge1xuICAgICAgICB2YXIgZGF0YSA9IGNvbnZlcnNhdGlvbiAmJiB0eXBlb2YgY29udmVyc2F0aW9uID09PSAnb2JqZWN0JyA/IGNvbnZlcnNhdGlvbiA6IHt9O1xuICAgICAgICB2YXIgc3BlYWtlcnMgPSBBcnJheS5pc0FycmF5KGRhdGEuc3BlYWtlcnMpID8gZGF0YS5zcGVha2VycyA6IFtdO1xuICAgICAgICB2YXIgdGltZWxpbmUgPSBBcnJheS5pc0FycmF5KGRhdGEudGltZWxpbmUpID8gZGF0YS50aW1lbGluZSA6IFtdO1xuICAgICAgICB2YXIgdGV4dCA9IE9iamVjdC5hc3NpZ24oeyBhbGw6ICdBbGwnLCBzZWFyY2g6ICdTZWFyY2gnLCBlbXB0eTogJycsIHBsYXk6ICcnLCBkb3dubG9hZDogJycgfSwgbGFiZWxzIHx8IHt9KTtcbiAgICAgICAgdmFyIHBsYXlhYmxlID0gQm9vbGVhbih0ZXh0LmF1ZGlvVXJsKTtcbiAgICAgICAgdmFyICRyb290ID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtdmlldycgfSk7XG4gICAgICAgIHZhciAkbGlzdCA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWxpc3QnIH0pO1xuXG4gICAgICAgIHRpbWVsaW5lLmZvckVhY2goZnVuY3Rpb24gKHR1cm4pIHtcbiAgICAgICAgICAgIGlmICghdHVybiB8fCB0eXBlb2YgdHVybiAhPT0gJ29iamVjdCcpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAodHVybi5nYXBCZWZvcmVMYWJlbCkge1xuICAgICAgICAgICAgICAgICRsaXN0LmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZ2FwJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nKS50ZXh0KCfCt8K3wrcgJyArIFN0cmluZyh0dXJuLmdhcEJlZm9yZUxhYmVsKSlcbiAgICAgICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgICAgICk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICAkbGlzdC5hcHBlbmQoY3JlYXRlVHVybih0dXJuLCBwbGF5YWJsZSkpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkcm9vdC5hcHBlbmQoXG4gICAgICAgICAgICBjcmVhdGVPdmVydmlldyhkYXRhLCB0ZXh0KSxcbiAgICAgICAgICAgIGNyZWF0ZVRvb2xiYXIoc3BlYWtlcnMsIHRleHQpLFxuICAgICAgICAgICAgJGxpc3QsXG4gICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1lbXB0eSB1aSBtZXNzYWdlJywgaGlkZGVuOiB0cnVlIH0pLnRleHQodGV4dC5lbXB0eSlcbiAgICAgICAgKTtcbiAgICAgICAgaW5pdGlhbGl6ZSgkcm9vdCk7XG5cbiAgICAgICAgcmV0dXJuICRyb290O1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGZvcm1hdFRpbWUoc2Vjb25kcykge1xuICAgICAgICB2YXIgdG90YWwgPSBNYXRoLmZsb29yKE1hdGgubWF4KDAsIHNlY29uZHMgfHwgMCkpO1xuICAgICAgICB2YXIgaG91cnMgPSBNYXRoLmZsb29yKHRvdGFsIC8gMzYwMCk7XG4gICAgICAgIHZhciBtaW51dGVzID0gTWF0aC5mbG9vcigodG90YWwgJSAzNjAwKSAvIDYwKTtcbiAgICAgICAgdmFyIHJlc3QgPSB0b3RhbCAlIDYwO1xuXG4gICAgICAgIGlmIChob3VycyA+IDApIHtcbiAgICAgICAgICAgIHJldHVybiBob3VycyArICc6JyArIFN0cmluZyhtaW51dGVzKS5wYWRTdGFydCgyLCAnMCcpICsgJzonICsgU3RyaW5nKHJlc3QpLnBhZFN0YXJ0KDIsICcwJyk7XG4gICAgICAgIH1cblxuICAgICAgICByZXR1cm4gTWF0aC5mbG9vcih0b3RhbCAvIDYwKSArICc6JyArIFN0cmluZyhyZXN0KS5wYWRTdGFydCgyLCAnMCcpO1xuICAgIH1cblxuICAgIC8vIEEgdHJhbnNjcmlwdCBwYWdlIGFkZHJlc3MgbWF5IGNhcnJ5IHRoZSByZWNvcmRpbmcgKHN0YWdlIHJlc3VsdCBpZCkgYW5kXG4gICAgLy8gc2Vjb25kIHRoZSByZWFkZXIgc3RvcHBlZCBhdCwgYW5kIHRoZSBvcGVuIGNhbGwgbGVnOlxuICAgIC8vID9pZD08cmVzdWx0PiZsc3R0X3N0YWdlPTxyZXN1bHQ+JmxzdHRfdD08c2Vjb25kcz4mbHN0dF9sZWc9aW50ZXJuYWwuXG4gICAgLy8gVGhlIG1vZHVsZSBwcmVmaXgga2VlcHMgdGhlc2UgbmFtZXMgY2xlYXIgb2YgQ29yZSBhbmQgb3RoZXIgbW9kdWxlcztcbiAgICAvLyBsaW5rcyBzaGFyZWQgYmVmb3JlIGl0IChzdGFnZS90L2xlZykgc3RpbGwgb3BlbiBhbmQgYXJlIHJld3JpdHRlbiBvblxuICAgIC8vIHRoZSBuZXh0IGFkZHJlc3MgdXBkYXRlLlxuICAgIHZhciBMT0NBVElPTl9LRVlTID0geyBzdGFnZTogJ2xzdHRfc3RhZ2UnLCB0OiAnbHN0dF90JywgbGVnOiAnbHN0dF9sZWcnIH07XG4gICAgdmFyIExFR0FDWV9MT0NBVElPTl9LRVlTID0geyBzdGFnZTogJ3N0YWdlJywgdDogJ3QnLCBsZWc6ICdsZWcnIH07XG5cbiAgICB2YXIgdHJhbnNjcmlwdExvY2F0aW9uID0ge1xuICAgICAgICByZWFkOiBmdW5jdGlvbiAoc2VhcmNoKSB7XG4gICAgICAgICAgICB2YXIgcXVlcnk7XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIHF1ZXJ5ID0gbmV3IFVSTFNlYXJjaFBhcmFtcyhzZWFyY2ggfHwgJycpO1xuICAgICAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4geyBzdGFnZTogJycsIHQ6IDAsIGxlZzogJycgfTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHZhciBrZXlzID0gcXVlcnkuaGFzKExPQ0FUSU9OX0tFWVMuc3RhZ2UpIHx8IHF1ZXJ5LmhhcyhMT0NBVElPTl9LRVlTLmxlZykgPyBMT0NBVElPTl9LRVlTIDogTEVHQUNZX0xPQ0FUSU9OX0tFWVM7XG4gICAgICAgICAgICB2YXIgc3RhZ2UgPSBTdHJpbmcocXVlcnkuZ2V0KGtleXMuc3RhZ2UpIHx8ICcnKTtcbiAgICAgICAgICAgIHZhciBzZWNvbmRzID0gTnVtYmVyKHF1ZXJ5LmdldChrZXlzLnQpKTtcbiAgICAgICAgICAgIHZhciB2YWxpZCA9IC9eWzEtOV1bMC05XSokLy50ZXN0KHN0YWdlKSAmJiBxdWVyeS5nZXQoa2V5cy50KSAhPT0gbnVsbCAmJiBOdW1iZXIuaXNGaW5pdGUoc2Vjb25kcykgJiYgc2Vjb25kcyA+PSAwICYmIHNlY29uZHMgPD0gODY0MDA7XG5cbiAgICAgICAgICAgIHJldHVybiB7XG4gICAgICAgICAgICAgICAgc3RhZ2U6IHZhbGlkID8gc3RhZ2UgOiAnJyxcbiAgICAgICAgICAgICAgICB0OiB2YWxpZCA/IE1hdGguZmxvb3Ioc2Vjb25kcykgOiAwLFxuICAgICAgICAgICAgICAgIGxlZzogcXVlcnkuZ2V0KGtleXMubGVnKSA9PT0gJ2ludGVybmFsJyA/ICdpbnRlcm5hbCcgOiAnJ1xuICAgICAgICAgICAgfTtcbiAgICAgICAgfSxcbiAgICAgICAgc2VhcmNoOiBmdW5jdGlvbiAoc2VhcmNoLCBjaGFuZ2VzKSB7XG4gICAgICAgICAgICB2YXIgcXVlcnk7XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIHF1ZXJ5ID0gbmV3IFVSTFNlYXJjaFBhcmFtcyhzZWFyY2ggfHwgJycpO1xuICAgICAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gJyc7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB2YXIgY3VycmVudCA9IHRyYW5zY3JpcHRMb2NhdGlvbi5yZWFkKHNlYXJjaCk7XG4gICAgICAgICAgICB2YXIgbmV4dCA9IHtcbiAgICAgICAgICAgICAgICBzdGFnZTogY3VycmVudC5zdGFnZSxcbiAgICAgICAgICAgICAgICB0OiBjdXJyZW50LnQsXG4gICAgICAgICAgICAgICAgbGVnOiBjdXJyZW50LmxlZ1xuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgIGlmIChPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoY2hhbmdlcywgJ3N0YWdlJykpIHtcbiAgICAgICAgICAgICAgICBuZXh0LnN0YWdlID0gY2hhbmdlcy5zdGFnZSA/IFN0cmluZyhjaGFuZ2VzLnN0YWdlKSA6ICcnO1xuICAgICAgICAgICAgICAgIG5leHQudCA9IE1hdGguZmxvb3IoTnVtYmVyKGNoYW5nZXMudCkgfHwgMCk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKGNoYW5nZXMsICdsZWcnKSkge1xuICAgICAgICAgICAgICAgIG5leHQubGVnID0gY2hhbmdlcy5sZWcgPT09ICdpbnRlcm5hbCcgPyAnaW50ZXJuYWwnIDogJyc7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBbTE9DQVRJT05fS0VZUywgTEVHQUNZX0xPQ0FUSU9OX0tFWVNdLmZvckVhY2goZnVuY3Rpb24gKGtleXMpIHtcbiAgICAgICAgICAgICAgICBxdWVyeS5kZWxldGUoa2V5cy5zdGFnZSk7XG4gICAgICAgICAgICAgICAgcXVlcnkuZGVsZXRlKGtleXMudCk7XG4gICAgICAgICAgICAgICAgcXVlcnkuZGVsZXRlKGtleXMubGVnKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgaWYgKG5leHQuc3RhZ2UgJiYgbmV4dC50ID4gMCkge1xuICAgICAgICAgICAgICAgIHF1ZXJ5LnNldChMT0NBVElPTl9LRVlTLnN0YWdlLCBuZXh0LnN0YWdlKTtcbiAgICAgICAgICAgICAgICBxdWVyeS5zZXQoTE9DQVRJT05fS0VZUy50LCBTdHJpbmcobmV4dC50KSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAobmV4dC5sZWcgPT09ICdpbnRlcm5hbCcpIHtcbiAgICAgICAgICAgICAgICBxdWVyeS5zZXQoTE9DQVRJT05fS0VZUy5sZWcsICdpbnRlcm5hbCcpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIHJlc3VsdCA9IHF1ZXJ5LnRvU3RyaW5nKCk7XG5cbiAgICAgICAgICAgIHJldHVybiByZXN1bHQgPT09ICcnID8gJycgOiAnPycgKyByZXN1bHQ7XG4gICAgICAgIH0sXG4gICAgICAgIHdyaXRlOiBmdW5jdGlvbiAoY2hhbmdlcykge1xuICAgICAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICAgICB2YXIgbG9jYXRpb24gPSB3aW5kb3cubG9jYXRpb247XG4gICAgICAgICAgICAgICAgd2luZG93Lmhpc3RvcnkucmVwbGFjZVN0YXRlKHdpbmRvdy5oaXN0b3J5LnN0YXRlLCAnJywgbG9jYXRpb24ucGF0aG5hbWUgKyB0cmFuc2NyaXB0TG9jYXRpb24uc2VhcmNoKGxvY2F0aW9uLnNlYXJjaCwgY2hhbmdlcykgKyBsb2NhdGlvbi5oYXNoKTtcbiAgICAgICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICAgICAgLy8gVGhlIGFkZHJlc3MgaXMgYSBjb252ZW5pZW5jZTsgcGxheWJhY2sgbmV2ZXIgZGVwZW5kcyBvbiBpdC5cbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH07XG5cbiAgICAvLyBQbGF5YmFjayBmb2xsb3dzIHRoZSBzdGFuZGFyZCBNaWtvUEJYIGNhbGwgaGlzdG9yeSBwbGF5ZXIgKENEUlBsYXllcik6XG4gICAgLy8gb3BlbmluZyBhIHRyYW5zY3JpcHQgZG93bmxvYWRzIG5vdGhpbmcsIGEgSEVBRCByZXF1ZXN0IG9ubHkgY2hlY2tzIHRoYXRcbiAgICAvLyB0aGUgcmVjb3JkaW5nIGV4aXN0cy4gVGhlIGZpcnN0IFBsYXkgYXR0YWNoZXMgdGhlIFVSTCBhcyBhIHN0cmVhbSAodGhlXG4gICAgLy8gYnJvd3NlciBmZXRjaGVzIGl0IHdpdGggUmFuZ2UgcmVxdWVzdHMpIGFuZCBhdWRpbyBzdGFydHMgb25cbiAgICAvLyBjYW5wbGF5dGhyb3VnaCwgb25jZSBlbm91Z2ggaXMgYnVmZmVyZWQsIGZyb20gdGhlIGhlbGQgcG9zaXRpb246IDA6MDAsXG4gICAgLy8gYSBjbGlja2VkIHR1cm4sIHRoZSBzbGlkZXIgb3IgYSBzaGFyZWQgbW9tZW50LiBPbmx5IG9uZSByZWNvcmRpbmcgcGxheXNcbiAgICAvLyBhdCBhIHRpbWUgb24gdGhlIHBhZ2UuXG4gICAgdmFyIGFjdGl2ZVBsYXllciA9IG51bGw7XG5cbiAgICBmdW5jdGlvbiBzdG9wT3RoZXJzKHBsYXllcikge1xuICAgICAgICBpZiAoYWN0aXZlUGxheWVyICYmIGFjdGl2ZVBsYXllciAhPT0gcGxheWVyKSB7XG4gICAgICAgICAgICBhY3RpdmVQbGF5ZXIucGF1c2UoKTtcbiAgICAgICAgfVxuICAgICAgICBhY3RpdmVQbGF5ZXIgPSBwbGF5ZXI7XG4gICAgfVxuXG4gICAgLy8gQSBtaXNzaW5nIGZpbGUgb3IgYSBuZXR3b3JrIGZhaWx1cmUgZGlzYWJsZXMgdGhlIHBsYXllciwgYXMgaW4gQ0RSUGxheWVyLlxuICAgIGZ1bmN0aW9uIHByb2JlUmVjb3JkaW5nKHVybCwgZG9uZSkge1xuICAgICAgICB2YXIgaGVhZGVycyA9IHsgJ1gtUmVxdWVzdGVkLVdpdGgnOiAnWE1MSHR0cFJlcXVlc3QnIH07XG4gICAgICAgIGlmICh0eXBlb2Ygd2luZG93LlRva2VuTWFuYWdlciAhPT0gJ3VuZGVmaW5lZCcgJiYgd2luZG93LlRva2VuTWFuYWdlciAmJiB3aW5kb3cuVG9rZW5NYW5hZ2VyLmFjY2Vzc1Rva2VuKSB7XG4gICAgICAgICAgICBoZWFkZXJzLkF1dGhvcml6YXRpb24gPSAnQmVhcmVyICcgKyB3aW5kb3cuVG9rZW5NYW5hZ2VyLmFjY2Vzc1Rva2VuO1xuICAgICAgICB9XG4gICAgICAgIGlmICh0eXBlb2Ygd2luZG93LmZldGNoICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICBkb25lKHRydWUpO1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG4gICAgICAgIHdpbmRvdy5mZXRjaCh1cmwsIHsgbWV0aG9kOiAnSEVBRCcsIGNyZWRlbnRpYWxzOiAnc2FtZS1vcmlnaW4nLCBoZWFkZXJzOiBoZWFkZXJzIH0pLnRoZW4oZnVuY3Rpb24gKHJlc3BvbnNlKSB7XG4gICAgICAgICAgICAvLyBBbiBlbmRwb2ludCB0aGF0IGNhbm5vdCBhbnN3ZXIgSEVBRCBzYXlzIG5vdGhpbmcgYWJvdXQgdGhlIGZpbGUuXG4gICAgICAgICAgICBkb25lKHJlc3BvbnNlLm9rIHx8IHJlc3BvbnNlLnN0YXR1cyA9PT0gNDA1IHx8IHJlc3BvbnNlLnN0YXR1cyA9PT0gNTAxKTtcbiAgICAgICAgfSwgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgZG9uZShmYWxzZSk7XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIC8vIFN0ZXJlbyBjYWxsIHJlY29yZGluZ3MgY2Fycnkgb25lIHBhcnR5IHBlciBjaGFubmVsOyBsaWtlIENEUlBsYXllciB0aGVcbiAgICAvLyBjaGFubmVscyBhcmUgYmxlbmRlZCA2NS8zNSBzbyBib3RoIHBhcnRpZXMgYXJlIGhlYXJkIGluIGVhY2ggZWFyLiBPbmVcbiAgICAvLyBBdWRpb0NvbnRleHQgc2VydmVzIGV2ZXJ5IHBsYXllcjsgaXQgaXMgY3JlYXRlZCBvbiB0aGUgZmlyc3QgUGxheSBjbGljay5cbiAgICB2YXIgbWl4ZXJDb250ZXh0ID0gbnVsbDtcblxuICAgIGZ1bmN0aW9uIGJsZW5kQ2hhbm5lbHMoZXZlbnQpIHtcbiAgICAgICAgdmFyIGlucHV0ID0gZXZlbnQuaW5wdXRCdWZmZXI7XG4gICAgICAgIHZhciBvdXRwdXQgPSBldmVudC5vdXRwdXRCdWZmZXI7XG4gICAgICAgIHZhciBvdXRMID0gb3V0cHV0LmdldENoYW5uZWxEYXRhKDApO1xuICAgICAgICB2YXIgb3V0UiA9IG91dHB1dC5udW1iZXJPZkNoYW5uZWxzID4gMSA/IG91dHB1dC5nZXRDaGFubmVsRGF0YSgxKSA6IG51bGw7XG4gICAgICAgIHZhciBpbkwgPSBpbnB1dC5nZXRDaGFubmVsRGF0YSgwKTtcbiAgICAgICAgdmFyIGluUiA9IGlucHV0Lm51bWJlck9mQ2hhbm5lbHMgPiAxID8gaW5wdXQuZ2V0Q2hhbm5lbERhdGEoMSkgOiBpbkw7XG4gICAgICAgIHZhciBpbmRleDtcblxuICAgICAgICBmb3IgKGluZGV4ID0gMDsgaW5kZXggPCBpbkwubGVuZ3RoOyBpbmRleCArPSAxKSB7XG4gICAgICAgICAgICBvdXRMW2luZGV4XSA9IChpbkxbaW5kZXhdICogMC42NSkgKyAoaW5SW2luZGV4XSAqIDAuMzUpO1xuICAgICAgICAgICAgaWYgKG91dFIpIHtcbiAgICAgICAgICAgICAgICBvdXRSW2luZGV4XSA9IChpbkxbaW5kZXhdICogMC4zNSkgKyAoaW5SW2luZGV4XSAqIDAuNjUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gYXR0YWNoTWl4ZXIoYXVkaW8pIHtcbiAgICAgICAgdmFyIENvbnRleHQgPSB3aW5kb3cuQXVkaW9Db250ZXh0IHx8IHdpbmRvdy53ZWJraXRBdWRpb0NvbnRleHQ7XG4gICAgICAgIHZhciBwcm9jZXNzb3I7XG4gICAgICAgIHZhciBzb3VyY2U7XG5cbiAgICAgICAgaWYgKCFDb250ZXh0KSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIGlmICghYXVkaW8uc3R0TWl4ZXIpIHtcbiAgICAgICAgICAgICAgICBtaXhlckNvbnRleHQgPSBtaXhlckNvbnRleHQgfHwgbmV3IENvbnRleHQoKTtcbiAgICAgICAgICAgICAgICAvLyBUaGUgcHJvY2Vzc29yIGV4aXN0cyBiZWZvcmUgdGhlIGVsZW1lbnQgaXMgY2FwdHVyZWQ6IGEgY2FwdHVyZVxuICAgICAgICAgICAgICAgIC8vIHdpdGhvdXQgYSBjb25uZWN0ZWQgZ3JhcGggd291bGQgbGVhdmUgdGhlIHJlY29yZGluZyBzaWxlbnQuXG4gICAgICAgICAgICAgICAgcHJvY2Vzc29yID0gbWl4ZXJDb250ZXh0LmNyZWF0ZVNjcmlwdFByb2Nlc3Nvcig0MDk2LCAyLCAyKTtcbiAgICAgICAgICAgICAgICBwcm9jZXNzb3Iub25hdWRpb3Byb2Nlc3MgPSBibGVuZENoYW5uZWxzO1xuICAgICAgICAgICAgICAgIHNvdXJjZSA9IG1peGVyQ29udGV4dC5jcmVhdGVNZWRpYUVsZW1lbnRTb3VyY2UoYXVkaW8pO1xuICAgICAgICAgICAgICAgIHNvdXJjZS5jb25uZWN0KHByb2Nlc3Nvcik7XG4gICAgICAgICAgICAgICAgcHJvY2Vzc29yLmNvbm5lY3QobWl4ZXJDb250ZXh0LmRlc3RpbmF0aW9uKTtcbiAgICAgICAgICAgICAgICBhdWRpby5zdHRNaXhlciA9IHsgc291cmNlOiBzb3VyY2UsIHByb2Nlc3NvcjogcHJvY2Vzc29yIH07XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAobWl4ZXJDb250ZXh0LnN0YXRlID09PSAnc3VzcGVuZGVkJykge1xuICAgICAgICAgICAgICAgIG1peGVyQ29udGV4dC5yZXN1bWUoKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIC8vIFBsYWluIHN0ZXJlbyBwbGF5YmFjayB0aHJvdWdoIHRoZSBlbGVtZW50LlxuICAgICAgICB9XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gZGV0YWNoTWl4ZXIoYXVkaW8pIHtcbiAgICAgICAgdmFyIGdyYXBoID0gYXVkaW8gJiYgYXVkaW8uc3R0TWl4ZXI7XG4gICAgICAgIGlmICghZ3JhcGgpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuICAgICAgICBhdWRpby5zdHRNaXhlciA9IG51bGw7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICBncmFwaC5wcm9jZXNzb3Iub25hdWRpb3Byb2Nlc3MgPSBudWxsO1xuICAgICAgICAgICAgZ3JhcGguc291cmNlLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIGdyYXBoLnByb2Nlc3Nvci5kaXNjb25uZWN0KCk7XG4gICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICAvLyBBbHJlYWR5IGRpc2Nvbm5lY3RlZC5cbiAgICAgICAgfVxuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVQbGF5ZXIoJHJvb3QpIHtcbiAgICAgICAgdmFyIGF1ZGlvID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LWF1ZGlvJykuZ2V0KDApO1xuICAgICAgICB2YXIgJHBsYXllciA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1wbGF5ZXInKTtcbiAgICAgICAgdmFyICRwbGF5QnV0dG9uID0gJHBsYXllci5maW5kKCcuc3R0LXBsYXllci1wbGF5Jyk7XG4gICAgICAgIHZhciAkcGxheUljb24gPSAkcGxheUJ1dHRvbi5maW5kKCdpJyk7XG4gICAgICAgIHZhciAkZHVyYXRpb24gPSAkcGxheWVyLmZpbmQoJy5zdHQtcGxheWVyLWR1cmF0aW9uJyk7XG4gICAgICAgIHZhciAkc3RhdHVzID0gJHJvb3QuZmluZCgnLnN0dC1wbGF5ZXItc3RhdHVzJyk7XG4gICAgICAgIHZhciAkc2VlayA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1zZWVrLmlzLXBsYXlhYmxlJyk7XG4gICAgICAgIHZhciAkcmFuZ2UgPSAkc2Vlay5maW5kKCcuc3R0LXRyYW5zY3JpcHQtcmFuZ2UnKTtcbiAgICAgICAgdmFyICRwbGF5aGVhZCA9ICRzZWVrLmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1wbGF5aGVhZCcpO1xuICAgICAgICB2YXIgJHRvb2x0aXAgPSAkc2Vlay5maW5kKCcuc3R0LXRyYW5zY3JpcHQtc2Vlay10b29sdGlwJyk7XG4gICAgICAgIHZhciAkc2NhbGVFbmQgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtc2NhbGUgc3BhbicpLmxhc3QoKTtcbiAgICAgICAgdmFyICRzZWdtZW50cyA9ICRzZWVrLmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1sYW5lLXNlZ21lbnQnKTtcbiAgICAgICAgdmFyICR0dXJucyA9ICRyb290LmZpbmQoJy5zdHQtcGxheWFibGUtdHVybicpO1xuICAgICAgICB2YXIgc291cmNlID0gU3RyaW5nKChhdWRpbyAmJiBhdWRpby5nZXRBdHRyaWJ1dGUoJ2RhdGEtc3JjJykpIHx8ICcnKTtcbiAgICAgICAgdmFyIGxhYmVscyA9IHtcbiAgICAgICAgICAgIGxvYWRpbmc6IFN0cmluZygkcGxheWVyLmF0dHIoJ2RhdGEtbGFiZWwtbG9hZGluZycpIHx8ICcnKSxcbiAgICAgICAgICAgIHVuYXZhaWxhYmxlOiBTdHJpbmcoJHBsYXllci5hdHRyKCdkYXRhLWxhYmVsLXVuYXZhaWxhYmxlJykgfHwgJycpLFxuICAgICAgICAgICAgZmFpbGVkOiBTdHJpbmcoJHBsYXllci5hdHRyKCdkYXRhLWxhYmVsLWZhaWxlZCcpIHx8ICcnKVxuICAgICAgICB9O1xuICAgICAgICB2YXIgc3luY2hyb25pemVkRHVyYXRpb24gPSAwO1xuICAgICAgICAvLyBwaGFzZTogY2hlY2tpbmcsIGlkbGUsIGxvYWRpbmcsIHJlYWR5LCB1bmF2YWlsYWJsZSwgZmFpbGVkXG4gICAgICAgIHZhciBwaGFzZSA9ICdjaGVja2luZyc7XG4gICAgICAgIHZhciBpbnRlbnQgPSBudWxsO1xuICAgICAgICB2YXIgcG9zaXRpb24gPSAwO1xuICAgICAgICB2YXIgYnVmZmVyaW5nID0gZmFsc2U7XG4gICAgICAgIHZhciBwbGF5ZXI7XG4gICAgICAgIC8vIE9ubHkgc2VydmVyLXJlbmRlcmVkIHRyYW5zY3JpcHQgcGFnZXMgbmFtZSB0aGVpciBzdGFnZTsgdGhlIGNhbGwtaGlzdG9yeSBtb2RhbCBuZXZlciB0b3VjaGVzIHRoZSBhZGRyZXNzLlxuICAgICAgICB2YXIgc3RhZ2VJZCA9IFN0cmluZygkcm9vdC5hdHRyKCdkYXRhLXN0dC1zdGFnZScpIHx8ICcnKTtcbiAgICAgICAgdmFyIGxpbmthYmxlID0gL15bMS05XVswLTldKiQvLnRlc3Qoc3RhZ2VJZCk7XG5cbiAgICAgICAgaWYgKCFhdWRpbyB8fCAkcmFuZ2UubGVuZ3RoID09PSAwIHx8ICRyb290LmRhdGEoJ3N0dC1wbGF5ZXItaW5pdGlhbGl6ZWQnKSkge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG4gICAgICAgICRyb290LmRhdGEoJ3N0dC1wbGF5ZXItaW5pdGlhbGl6ZWQnLCB0cnVlKTtcblxuICAgICAgICBmdW5jdGlvbiBmYWxsYmFja0R1cmF0aW9uKCkge1xuICAgICAgICAgICAgcmV0dXJuIHNhZmVOdW1iZXIoJHBsYXllci5kYXRhKCdkdXJhdGlvbicpKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGN1cnJlbnREdXJhdGlvbigpIHtcbiAgICAgICAgICAgIHJldHVybiBOdW1iZXIuaXNGaW5pdGUoYXVkaW8uZHVyYXRpb24pICYmIGF1ZGlvLmR1cmF0aW9uID4gMCA/IGF1ZGlvLmR1cmF0aW9uIDogZmFsbGJhY2tEdXJhdGlvbigpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gYXR0YWNoZWQoKSB7XG4gICAgICAgICAgICByZXR1cm4gQm9vbGVhbihhdWRpby5nZXRBdHRyaWJ1dGUoJ3NyYycpKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGNsYW1wKHNlY29uZHMpIHtcbiAgICAgICAgICAgIHZhciB2YWx1ZSA9IHNhZmVOdW1iZXIoc2Vjb25kcyk7XG4gICAgICAgICAgICB2YXIgZHVyYXRpb24gPSBjdXJyZW50RHVyYXRpb24oKTtcblxuICAgICAgICAgICAgcmV0dXJuIGR1cmF0aW9uID4gMCA/IE1hdGgubWluKHZhbHVlLCBNYXRoLm1heCgwLCBkdXJhdGlvbiAtIDAuMDEpKSA6IHZhbHVlO1xuICAgICAgICB9XG5cbiAgICAgICAgLy8gVW50aWwgdGhlIHN0cmVhbSBjYW4gcGxheSB0aHJvdWdoLCB0aGUgY2xvY2sgYW5kIHBsYXloZWFkIGhvbGQgdGhlIHJlcXVlc3RlZCBwb3NpdGlvbi5cbiAgICAgICAgZnVuY3Rpb24gc2hvd25UaW1lKCkge1xuICAgICAgICAgICAgaWYgKGludGVudCAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBpbnRlbnQ7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHJldHVybiBhdHRhY2hlZCgpICYmIGF1ZGlvLnJlYWR5U3RhdGUgPj0gMSA/IGF1ZGlvLmN1cnJlbnRUaW1lIDogcG9zaXRpb247XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzaG93U3RhdHVzKHRleHQsIGVycm9yKSB7XG4gICAgICAgICAgICAkc3RhdHVzLnRleHQodGV4dCkudG9nZ2xlQ2xhc3MoJ2lzLWVycm9yJywgQm9vbGVhbihlcnJvcikpLnByb3AoJ2hpZGRlbicsIHRleHQgPT09ICcnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbmRlckJ1dHRvbigpIHtcbiAgICAgICAgICAgIHZhciBsb2FkaW5nID0gaW50ZW50ICE9PSBudWxsIHx8IGJ1ZmZlcmluZztcbiAgICAgICAgICAgIHZhciBwbGF5aW5nID0gIWF1ZGlvLnBhdXNlZCAmJiAhbG9hZGluZztcblxuICAgICAgICAgICAgJHBsYXlJY29uLnJlbW92ZUNsYXNzKCdwbGF5IHBhdXNlIHNwaW5uZXIgbG9hZGluZycpO1xuICAgICAgICAgICAgaWYgKGxvYWRpbmcpIHtcbiAgICAgICAgICAgICAgICAkcGxheUljb24uYWRkQ2xhc3MoJ3NwaW5uZXIgbG9hZGluZycpO1xuICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICAkcGxheUljb24uYWRkQ2xhc3MocGxheWluZyA/ICdwYXVzZScgOiAncGxheScpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgJHBsYXlCdXR0b24uYXR0cignYXJpYS1idXN5JywgbG9hZGluZyA/ICd0cnVlJyA6IG51bGwpO1xuICAgICAgICAgICAgaWYgKGxvYWRpbmcpIHtcbiAgICAgICAgICAgICAgICBzaG93U3RhdHVzKGxhYmVscy5sb2FkaW5nLCBmYWxzZSk7XG4gICAgICAgICAgICB9IGVsc2UgaWYgKCRzdGF0dXMudGV4dCgpID09PSBsYWJlbHMubG9hZGluZykge1xuICAgICAgICAgICAgICAgIHNob3dTdGF0dXMoJycsIGZhbHNlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNldEFjdGl2ZVR1cm5zKGN1cnJlbnQpIHtcbiAgICAgICAgICAgICR0dXJucy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICB2YXIgJHR1cm4gPSAkKHRoaXMpO1xuICAgICAgICAgICAgICAgIHZhciBzdGFydCA9IHNhZmVOdW1iZXIoJHR1cm4uZGF0YSgnc3RhcnQnKSk7XG4gICAgICAgICAgICAgICAgdmFyIGVuZCA9IHNhZmVOdW1iZXIoJHR1cm4uZGF0YSgnZW5kJykpO1xuICAgICAgICAgICAgICAgICR0dXJuLnRvZ2dsZUNsYXNzKCdzdHQtdHVybi1hY3RpdmUnLCBjdXJyZW50ID49IHN0YXJ0ICYmIGN1cnJlbnQgPD0gTWF0aC5tYXgoZW5kLCBzdGFydCArIDEpKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc3luY2hyb25pemVUaW1lbGluZShkdXJhdGlvbikge1xuICAgICAgICAgICAgaWYgKGR1cmF0aW9uIDw9IDAgfHwgTWF0aC5hYnMoZHVyYXRpb24gLSBzeW5jaHJvbml6ZWREdXJhdGlvbikgPCAwLjAxKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgc3luY2hyb25pemVkRHVyYXRpb24gPSBkdXJhdGlvbjtcbiAgICAgICAgICAgICRzY2FsZUVuZC50ZXh0KGZvcm1hdFRpbWUoZHVyYXRpb24pKTtcbiAgICAgICAgICAgICRzZWdtZW50cy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICB2YXIgJHNlZ21lbnQgPSAkKHRoaXMpO1xuICAgICAgICAgICAgICAgIHZhciBzdGFydCA9IE1hdGgubWluKHNhZmVOdW1iZXIoJHNlZ21lbnQuZGF0YSgnc3RhcnQnKSksIGR1cmF0aW9uKTtcbiAgICAgICAgICAgICAgICB2YXIgZW5kID0gTWF0aC5taW4oTWF0aC5tYXgoc2FmZU51bWJlcigkc2VnbWVudC5kYXRhKCdlbmQnKSksIHN0YXJ0KSwgZHVyYXRpb24pO1xuICAgICAgICAgICAgICAgICRzZWdtZW50LmNzcyh7XG4gICAgICAgICAgICAgICAgICAgIGxlZnQ6ICgoc3RhcnQgLyBkdXJhdGlvbikgKiAxMDApICsgJyUnLFxuICAgICAgICAgICAgICAgICAgICB3aWR0aDogKCgoZW5kIC0gc3RhcnQpIC8gZHVyYXRpb24pICogMTAwKSArICclJ1xuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGUoKSB7XG4gICAgICAgICAgICB2YXIgZHVyYXRpb24gPSBjdXJyZW50RHVyYXRpb24oKTtcbiAgICAgICAgICAgIHZhciBjdXJyZW50ID0gc2hvd25UaW1lKCk7XG4gICAgICAgICAgICB2YXIgdmFsdWUgPSBkdXJhdGlvbiA+IDAgPyBNYXRoLm1pbigxMDAwLCBNYXRoLnJvdW5kKChjdXJyZW50IC8gZHVyYXRpb24pICogMTAwMCkpIDogMDtcblxuICAgICAgICAgICAgaWYgKHBoYXNlID09PSAndW5hdmFpbGFibGUnKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgc3luY2hyb25pemVUaW1lbGluZShkdXJhdGlvbik7XG4gICAgICAgICAgICAkcmFuZ2UudmFsKHZhbHVlKS5hdHRyKCdhcmlhLXZhbHVldGV4dCcsIGZvcm1hdFRpbWUoY3VycmVudCkpO1xuICAgICAgICAgICAgJHBsYXloZWFkLmNzcygnbGVmdCcsICh2YWx1ZSAvIDEwKSArICclJyk7XG4gICAgICAgICAgICAkZHVyYXRpb24udGV4dChmb3JtYXRUaW1lKGN1cnJlbnQpICsgJyAvICcgKyAoZHVyYXRpb24gPiAwID8gZm9ybWF0VGltZShkdXJhdGlvbikgOiAnLS06LS0nKSk7XG4gICAgICAgICAgICBzZXRBY3RpdmVUdXJucyhjdXJyZW50KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGRpc2FibGUoKSB7XG4gICAgICAgICAgICBwaGFzZSA9ICd1bmF2YWlsYWJsZSc7XG4gICAgICAgICAgICAkcGxheUJ1dHRvbi5wcm9wKCdkaXNhYmxlZCcsIHRydWUpLmFkZENsYXNzKCdkaXNhYmxlZCcpO1xuICAgICAgICAgICAgJHBsYXllci5maW5kKCcuc3R0LXBsYXllci1zcGVlZCwgLnN0dC1wbGF5ZXItZG93bmxvYWQnKS5oaWRlKCk7XG4gICAgICAgICAgICAkcmFuZ2UucHJvcCgnZGlzYWJsZWQnLCB0cnVlKTtcbiAgICAgICAgICAgICR0dXJucy5yZW1vdmVDbGFzcygnc3R0LXBsYXlhYmxlLXR1cm4nKS5yZW1vdmVBdHRyKCd0YWJpbmRleCcpO1xuICAgICAgICAgICAgJGR1cmF0aW9uLnRleHQoJy0tOi0tIC8gLS06LS0nKTtcbiAgICAgICAgICAgIHNob3dTdGF0dXMobGFiZWxzLnVuYXZhaWxhYmxlLCBmYWxzZSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBmYWlsKCkge1xuICAgICAgICAgICAgaW50ZW50ID0gbnVsbDtcbiAgICAgICAgICAgIGJ1ZmZlcmluZyA9IGZhbHNlO1xuICAgICAgICAgICAgc2hvd1N0YXR1cyhsYWJlbHMuZmFpbGVkLCB0cnVlKTtcbiAgICAgICAgICAgIHJlbmRlckJ1dHRvbigpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gbW92ZVRvKHNlY29uZHMpIHtcbiAgICAgICAgICAgIGlmIChNYXRoLmFicygoYXVkaW8uY3VycmVudFRpbWUgfHwgMCkgLSBzZWNvbmRzKSA8IDAuMjUpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIGF1ZGlvLmN1cnJlbnRUaW1lID0gc2Vjb25kcztcbiAgICAgICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICAgICAgLy8gQXBwbGllZCBhZ2FpbiBvbmNlIG1ldGFkYXRhIGFycml2ZXMuXG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBhcHBseSgpIHtcbiAgICAgICAgICAgIHZhciBzZWNvbmRzO1xuICAgICAgICAgICAgdmFyIHJlc3VsdDtcblxuICAgICAgICAgICAgaWYgKHBoYXNlICE9PSAncmVhZHknIHx8IGludGVudCA9PT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHNlY29uZHMgPSBjbGFtcChpbnRlbnQpO1xuICAgICAgICAgICAgaW50ZW50ID0gbnVsbDtcbiAgICAgICAgICAgIHBvc2l0aW9uID0gc2Vjb25kcztcbiAgICAgICAgICAgIG1vdmVUbyhzZWNvbmRzKTtcbiAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgcmVzdWx0ID0gYXVkaW8ucGxheSgpO1xuICAgICAgICAgICAgICAgIGlmIChyZXN1bHQgJiYgdHlwZW9mIHJlc3VsdC5jYXRjaCA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgICAgICByZXN1bHQuY2F0Y2goZmFpbCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICBmYWlsKCk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmVuZGVyQnV0dG9uKCk7XG4gICAgICAgICAgICB1cGRhdGUoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHBsYXkoc2Vjb25kcykge1xuICAgICAgICAgICAgaWYgKHBoYXNlID09PSAndW5hdmFpbGFibGUnIHx8ICFzb3VyY2UpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpbnRlbnQgPSBjbGFtcChzZWNvbmRzKTtcbiAgICAgICAgICAgIHBvc2l0aW9uID0gaW50ZW50O1xuICAgICAgICAgICAgaWYgKCRzdGF0dXMuaGFzQ2xhc3MoJ2lzLWVycm9yJykpIHtcbiAgICAgICAgICAgICAgICBzaG93U3RhdHVzKCcnLCBmYWxzZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBzdG9wT3RoZXJzKHBsYXllcik7XG4gICAgICAgICAgICAvLyBJbnNpZGUgdGhlIGNsaWNrOiBicm93c2VycyBvbmx5IHN0YXJ0IGFuIEF1ZGlvQ29udGV4dCBvbiBhIGdlc3R1cmUuXG4gICAgICAgICAgICBhdHRhY2hNaXhlcihhdWRpbyk7XG4gICAgICAgICAgICBpZiAocGhhc2UgPT09ICdyZWFkeScgJiYgYXR0YWNoZWQoKSkge1xuICAgICAgICAgICAgICAgIGFwcGx5KCk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKCFhdHRhY2hlZCgpKSB7XG4gICAgICAgICAgICAgICAgcGhhc2UgPSAnbG9hZGluZyc7XG4gICAgICAgICAgICAgICAgYXVkaW8uc2V0QXR0cmlidXRlKCdzcmMnLCBzb3VyY2UpO1xuICAgICAgICAgICAgICAgIGF1ZGlvLmxvYWQoKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHJlbmRlckJ1dHRvbigpO1xuICAgICAgICAgICAgdXBkYXRlKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBwYXVzZSgpIHtcbiAgICAgICAgICAgIGludGVudCA9IG51bGw7XG4gICAgICAgICAgICBidWZmZXJpbmcgPSBmYWxzZTtcbiAgICAgICAgICAgIGlmICghYXVkaW8ucGF1c2VkKSB7XG4gICAgICAgICAgICAgICAgYXVkaW8ucGF1c2UoKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHJlbmRlckJ1dHRvbigpO1xuICAgICAgICB9XG5cbiAgICAgICAgLy8gTW92ZXMgdGhlIGhlbGQgb3IgbGl2ZSBwb3NpdGlvbiB3aXRob3V0IGNoYW5naW5nIHdoZXRoZXIgaXQgcGxheXMuXG4gICAgICAgIGZ1bmN0aW9uIHNlZWsoc2Vjb25kcykge1xuICAgICAgICAgICAgdmFyIHZhbHVlID0gY2xhbXAoc2Vjb25kcyk7XG5cbiAgICAgICAgICAgIHBvc2l0aW9uID0gdmFsdWU7XG4gICAgICAgICAgICBpZiAoaW50ZW50ICE9PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgaW50ZW50ID0gdmFsdWU7XG4gICAgICAgICAgICB9IGVsc2UgaWYgKGF0dGFjaGVkKCkgJiYgYXVkaW8ucmVhZHlTdGF0ZSA+PSAxKSB7XG4gICAgICAgICAgICAgICAgbW92ZVRvKHZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVtZW1iZXJNb21lbnQoc2Vjb25kcykge1xuICAgICAgICAgICAgaWYgKGxpbmthYmxlKSB7XG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdExvY2F0aW9uLndyaXRlKHsgc3RhZ2U6IHN0YWdlSWQsIHQ6IHNlY29uZHMgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICAvLyBBIHNoYXJlZCBtb21lbnQgaXMgaGVsZCB3aXRob3V0IHBsYXlpbmcgb3IgbG9hZGluZzogYnJvd3NlcnMgc3RhcnQgYXVkaW8gb25seSBvbiBhIHVzZXIgZ2VzdHVyZS5cbiAgICAgICAgZnVuY3Rpb24gaG9sZE1vbWVudChzZWNvbmRzKSB7XG4gICAgICAgICAgICB2YXIgdHVybjtcblxuICAgICAgICAgICAgc2VlayhzZWNvbmRzKTtcbiAgICAgICAgICAgIHR1cm4gPSAkcm9vdC5maW5kKCcuc3R0LXR1cm4tYWN0aXZlJykuZ2V0KDApIHx8ICRyb290LmdldCgwKTtcbiAgICAgICAgICAgIGlmICh0dXJuICYmIHR5cGVvZiB0dXJuLnNjcm9sbEludG9WaWV3ID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgdHVybi5zY3JvbGxJbnRvVmlldyh7IGJsb2NrOiAnY2VudGVyJyB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIHBsYXllciA9IHsgcGF1c2U6IHBhdXNlIH07XG4gICAgICAgICRyb290LmRhdGEoJ3N0dC1wbGF5ZXInLCB7XG4gICAgICAgICAgICBwYXVzZTogcGF1c2UsXG4gICAgICAgICAgICBkaXNwb3NlOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgcGF1c2UoKTtcbiAgICAgICAgICAgICAgICBpZiAoYWN0aXZlUGxheWVyID09PSBwbGF5ZXIpIHtcbiAgICAgICAgICAgICAgICAgICAgYWN0aXZlUGxheWVyID0gbnVsbDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgYXVkaW8ucmVtb3ZlQXR0cmlidXRlKCdzcmMnKTtcbiAgICAgICAgICAgICAgICBhdWRpby5sb2FkKCk7XG4gICAgICAgICAgICAgICAgZGV0YWNoTWl4ZXIoYXVkaW8pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcblxuICAgICAgICAvLyBkZWZhdWx0UGxheWJhY2tSYXRlIHN1cnZpdmVzIHRoZSBhdWRpby5sb2FkKCkgdGhhdCBhdHRhY2hlcyB0aGVcbiAgICAgICAgLy8gbGF6aWx5IGxvYWRlZCBzb3VyY2U7IHBsYXliYWNrUmF0ZSBhcHBsaWVzIHRvIHRoZSBydW5uaW5nIGF1ZGlvLlxuICAgICAgICAkcGxheWVyLmZpbmQoJy5zdHQtcGxheWVyLXNwZWVkLW9wdGlvbicpLm9uKCdjbGljay5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyIHJhdGUgPSBzYWZlTnVtYmVyKCQodGhpcykuZGF0YSgncmF0ZScpKSB8fCAxO1xuICAgICAgICAgICAgYXVkaW8uZGVmYXVsdFBsYXliYWNrUmF0ZSA9IHJhdGU7XG4gICAgICAgICAgICBhdWRpby5wbGF5YmFja1JhdGUgPSByYXRlO1xuICAgICAgICAgICAgJHBsYXllci5maW5kKCcuc3R0LXBsYXllci1zcGVlZC1vcHRpb24nKS5yZW1vdmVDbGFzcygnYWN0aXZlJykuYXR0cignYXJpYS1wcmVzc2VkJywgJ2ZhbHNlJyk7XG4gICAgICAgICAgICAkKHRoaXMpLmFkZENsYXNzKCdhY3RpdmUnKS5hdHRyKCdhcmlhLXByZXNzZWQnLCAndHJ1ZScpO1xuICAgICAgICB9KTtcbiAgICAgICAgJHBsYXlCdXR0b24ub24oJ2NsaWNrLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpZiAoIWF1ZGlvLnBhdXNlZCB8fCBpbnRlbnQgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICByZW1lbWJlck1vbWVudChzaG93blRpbWUoKSk7XG4gICAgICAgICAgICAgICAgcGF1c2UoKTtcbiAgICAgICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAgICAgcGxheShzaG93blRpbWUoKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICAkcmFuZ2Uub24oJ2lucHV0LnN0dFRyYW5zY3JpcHQgY2hhbmdlLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZWVrKChjdXJyZW50RHVyYXRpb24oKSAqIHNhZmVOdW1iZXIodGhpcy52YWx1ZSkpIC8gMTAwMCk7XG4gICAgICAgIH0pO1xuICAgICAgICAvLyBPbmx5IHRoZSBzZXR0bGVkIHBvc2l0aW9uIGlzIHdyaXR0ZW47IGJyb3dzZXJzIHRocm90dGxlIGFkZHJlc3MgdXBkYXRlcy5cbiAgICAgICAgJHJhbmdlLm9uKCdjaGFuZ2Uuc3R0VHJhbnNjcmlwdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHJlbWVtYmVyTW9tZW50KChjdXJyZW50RHVyYXRpb24oKSAqIHNhZmVOdW1iZXIodGhpcy52YWx1ZSkpIC8gMTAwMCk7XG4gICAgICAgIH0pO1xuICAgICAgICAkc2Vlay5vbignbW91c2Vtb3ZlLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIHZhciBvZmZzZXQgPSAkc2Vlay5vZmZzZXQoKTtcbiAgICAgICAgICAgIHZhciB3aWR0aCA9ICRzZWVrLndpZHRoKCk7XG4gICAgICAgICAgICB2YXIgcGVyY2VudCA9IHdpZHRoID4gMCA/IE1hdGgubWF4KDAsIE1hdGgubWluKDEwMCwgKChldmVudC5wYWdlWCAtIG9mZnNldC5sZWZ0KSAvIHdpZHRoKSAqIDEwMCkpIDogMDtcbiAgICAgICAgICAgICR0b29sdGlwLnRleHQoZm9ybWF0VGltZSgoY3VycmVudER1cmF0aW9uKCkgKiBwZXJjZW50KSAvIDEwMCkpLmNzcygnbGVmdCcsIHBlcmNlbnQgKyAnJScpO1xuICAgICAgICB9KTtcbiAgICAgICAgJHR1cm5zLm9uKCdjbGljay5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyIHNlY29uZHMgPSBzYWZlTnVtYmVyKCQodGhpcykuZGF0YSgnc3RhcnQnKSk7XG4gICAgICAgICAgICByZW1lbWJlck1vbWVudChzZWNvbmRzKTtcbiAgICAgICAgICAgIHBsYXkoc2Vjb25kcyk7XG4gICAgICAgIH0pLm9uKCdrZXlkb3duLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIGlmIChldmVudC5rZXkgPT09ICdFbnRlcicgfHwgZXZlbnQua2V5ID09PSAnICcpIHtcbiAgICAgICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgICAgICQodGhpcykudHJpZ2dlcignY2xpY2snKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG5cbiAgICAgICAgLy8gbG9hZGVkbWV0YWRhdGE6IGp1bXAgdG8gdGhlIGhlbGQgcG9zaXRpb24gYmVmb3JlIGJ1ZmZlcmluZyBzdGFydHMgdGhlcmUuXG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2xvYWRlZG1ldGFkYXRhJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaWYgKGludGVudCAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIG1vdmVUbyhjbGFtcChpbnRlbnQpKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICB9KTtcbiAgICAgICAgLy8gY2FucGxheXRocm91Z2g6IGVub3VnaCBpcyBidWZmZXJlZCB0byBwbGF5IHdpdGhvdXQgc3RhbGxpbmcuXG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2NhbnBsYXl0aHJvdWdoJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaWYgKCFhdHRhY2hlZCgpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcGhhc2UgPSAncmVhZHknO1xuICAgICAgICAgICAgYXBwbHkoKTtcbiAgICAgICAgfSk7XG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ3dhaXRpbmcnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpZiAoIWF1ZGlvLnBhdXNlZCAmJiAhYnVmZmVyaW5nKSB7XG4gICAgICAgICAgICAgICAgYnVmZmVyaW5nID0gdHJ1ZTtcbiAgICAgICAgICAgICAgICByZW5kZXJCdXR0b24oKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ3BsYXlpbmcnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBidWZmZXJpbmcgPSBmYWxzZTtcbiAgICAgICAgICAgIHJlbmRlckJ1dHRvbigpO1xuICAgICAgICB9KTtcbiAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcigndGltZXVwZGF0ZScsIHVwZGF0ZSk7XG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ3BsYXknLCByZW5kZXJCdXR0b24pO1xuICAgICAgICBhdWRpby5hZGRFdmVudExpc3RlbmVyKCdwYXVzZScsIHJlbmRlckJ1dHRvbik7XG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2VuZGVkJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgYXVkaW8uY3VycmVudFRpbWUgPSAwO1xuICAgICAgICAgICAgcG9zaXRpb24gPSAwO1xuICAgICAgICAgICAgaWYgKGFjdGl2ZVBsYXllciA9PT0gcGxheWVyKSB7XG4gICAgICAgICAgICAgICAgYWN0aXZlUGxheWVyID0gbnVsbDtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHJlbmRlckJ1dHRvbigpO1xuICAgICAgICAgICAgdXBkYXRlKCk7XG4gICAgICAgIH0pO1xuICAgICAgICAvLyBUaGUgc3RyZWFtIGlzIGRyb3BwZWQgc28gdGhlIG5leHQgUGxheSBhdHRhY2hlcyBpdCBhZ2Fpbi5cbiAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcignZXJyb3InLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpZiAoIWF0dGFjaGVkKCkpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBhdWRpby5yZW1vdmVBdHRyaWJ1dGUoJ3NyYycpO1xuICAgICAgICAgICAgYXVkaW8ubG9hZCgpO1xuICAgICAgICAgICAgcGhhc2UgPSAnZmFpbGVkJztcbiAgICAgICAgICAgIGZhaWwoKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgdXBkYXRlKCk7XG4gICAgICAgIGlmIChsaW5rYWJsZSkge1xuICAgICAgICAgICAgdmFyIG1vbWVudCA9IHRyYW5zY3JpcHRMb2NhdGlvbi5yZWFkKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpO1xuICAgICAgICAgICAgaWYgKG1vbWVudC5zdGFnZSA9PT0gc3RhZ2VJZCkge1xuICAgICAgICAgICAgICAgIGhvbGRNb21lbnQobW9tZW50LnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIGlmICghc291cmNlKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgcHJvYmVSZWNvcmRpbmcoc291cmNlLCBmdW5jdGlvbiAob2spIHtcbiAgICAgICAgICAgIGlmIChwaGFzZSAhPT0gJ2NoZWNraW5nJykge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChvaykge1xuICAgICAgICAgICAgICAgIHBoYXNlID0gJ2lkbGUnO1xuICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICBkaXNhYmxlKCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemUocm9vdCkge1xuICAgICAgICB2YXIgJHJvb3QgPSByb290ICYmIHJvb3QuanF1ZXJ5ID8gcm9vdCA6ICQocm9vdCk7XG4gICAgICAgIHZhciAkaW5wdXQgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtc2VhcmNoIGlucHV0Jyk7XG4gICAgICAgIHZhciAkZmlsdGVycyA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1maWx0ZXInKTtcbiAgICAgICAgdmFyICRyb3dzID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LXR1cm4nKTtcbiAgICAgICAgdmFyICRnYXBzID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LWdhcCcpO1xuICAgICAgICB2YXIgJGVtcHR5ID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LWVtcHR5Jyk7XG4gICAgICAgIHZhciBhY3RpdmVTcGVha2VyID0gJ2FsbCc7XG5cbiAgICAgICAgaWYgKCRyb290Lmxlbmd0aCA9PT0gMCB8fCAkcm9vdC5kYXRhKCdzdHQtdHJhbnNjcmlwdC1pbml0aWFsaXplZCcpKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgJHJvb3QuZGF0YSgnc3R0LXRyYW5zY3JpcHQtaW5pdGlhbGl6ZWQnLCB0cnVlKTtcblxuICAgICAgICBmdW5jdGlvbiBhcHBseSgpIHtcbiAgICAgICAgICAgIHZhciBxdWVyeSA9IFN0cmluZygkaW5wdXQudmFsKCkgfHwgJycpLnRyaW0oKS50b0xvY2FsZUxvd2VyQ2FzZSgpO1xuICAgICAgICAgICAgdmFyIHZpc2libGUgPSAwO1xuXG4gICAgICAgICAgICAkcm93cy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICB2YXIgJHJvdyA9ICQodGhpcyk7XG4gICAgICAgICAgICAgICAgdmFyIHNwZWFrZXJNYXRjaGVzID0gYWN0aXZlU3BlYWtlciA9PT0gJ2FsbCcgfHwgJHJvdy5kYXRhKCdzcGVha2VyJykgPT09IGFjdGl2ZVNwZWFrZXI7XG4gICAgICAgICAgICAgICAgdmFyIHRleHRNYXRjaGVzID0gcXVlcnkgPT09ICcnIHx8ICRyb3cuZmluZCgnLnN0dC10dXJuLXRleHQnKS50ZXh0KCkudG9Mb2NhbGVMb3dlckNhc2UoKS5pbmRleE9mKHF1ZXJ5KSAhPT0gLTE7XG4gICAgICAgICAgICAgICAgdmFyIHNob3duID0gc3BlYWtlck1hdGNoZXMgJiYgdGV4dE1hdGNoZXM7XG5cbiAgICAgICAgICAgICAgICAkcm93LnByb3AoJ2hpZGRlbicsICFzaG93bik7XG4gICAgICAgICAgICAgICAgaWYgKHNob3duKSB7XG4gICAgICAgICAgICAgICAgICAgIHZpc2libGUgKz0gMTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICRnYXBzLnByb3AoJ2hpZGRlbicsIHF1ZXJ5ICE9PSAnJyB8fCBhY3RpdmVTcGVha2VyICE9PSAnYWxsJyk7XG4gICAgICAgICAgICAkZW1wdHkucHJvcCgnaGlkZGVuJywgdmlzaWJsZSAhPT0gMCk7XG4gICAgICAgIH1cblxuICAgICAgICAkaW5wdXQub24oJ2lucHV0LnN0dFRyYW5zY3JpcHQnLCBhcHBseSk7XG4gICAgICAgICRmaWx0ZXJzLm9uKCdjbGljay5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgYWN0aXZlU3BlYWtlciA9IFN0cmluZygkKHRoaXMpLmRhdGEoJ3NwZWFrZXInKSB8fCAnYWxsJyk7XG4gICAgICAgICAgICAkZmlsdGVycy5yZW1vdmVDbGFzcygnYWN0aXZlJykuYXR0cignYXJpYS1wcmVzc2VkJywgJ2ZhbHNlJyk7XG4gICAgICAgICAgICAkKHRoaXMpLmFkZENsYXNzKCdhY3RpdmUnKS5hdHRyKCdhcmlhLXByZXNzZWQnLCAndHJ1ZScpO1xuICAgICAgICAgICAgYXBwbHkoKTtcbiAgICAgICAgfSk7XG4gICAgICAgIGFwcGx5KCk7XG4gICAgICAgIGluaXRpYWxpemVQbGF5ZXIoJHJvb3QpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGVhY2hQbGF5ZXIocm9vdCwgYWN0aW9uKSB7XG4gICAgICAgICQocm9vdCkuZmluZCgnLnN0dC10cmFuc2NyaXB0LXZpZXcnKS5hZGRCYWNrKCcuc3R0LXRyYW5zY3JpcHQtdmlldycpLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyIHBsYXllciA9ICQodGhpcykuZGF0YSgnc3R0LXBsYXllcicpO1xuICAgICAgICAgICAgaWYgKHBsYXllcikge1xuICAgICAgICAgICAgICAgIHBsYXllclthY3Rpb25dKCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIC8vIFBhdXNlcyBhbmQgY2FuY2VscyBhIHBlbmRpbmcgc3RhcnQgKGEgcmVjb3JkaW5nIHN0aWxsIGJ1ZmZlcmluZyB3b3VsZFxuICAgIC8vIG90aGVyd2lzZSBiZWdpbiBwbGF5aW5nIG9uY2UgaXQgY2FuKSwgZS5nLiB3aGVuIGEgdGFiIGhpZGVzIGl0LlxuICAgIGZ1bmN0aW9uIHBhdXNlKHJvb3QpIHtcbiAgICAgICAgZWFjaFBsYXllcihyb290LCAncGF1c2UnKTtcbiAgICB9XG5cbiAgICAvLyBTdG9wcyB0aGUgYXVkaW8sIGFib3J0cyBpdHMgc3RyZWFtIGFuZCByZWxlYXNlcyB0aGUgbWl4ZXIsIGUuZy4gd2hlblxuICAgIC8vIHRoZSBjYWxsLWhpc3RvcnkgbW9kYWwgY2xvc2VzLlxuICAgIGZ1bmN0aW9uIGRpc3Bvc2Uocm9vdCkge1xuICAgICAgICBlYWNoUGxheWVyKHJvb3QsICdkaXNwb3NlJyk7XG4gICAgfVxuXG4gICAgd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3ID0ge1xuICAgICAgICBjcmVhdGU6IGNyZWF0ZSxcbiAgICAgICAgaW5pdGlhbGl6ZTogaW5pdGlhbGl6ZSxcbiAgICAgICAgcGF1c2U6IHBhdXNlLFxuICAgICAgICBkaXNwb3NlOiBkaXNwb3NlLFxuICAgICAgICBsb2NhdGlvbjogdHJhbnNjcmlwdExvY2F0aW9uXG4gICAgfTtcblxuICAgICQoZnVuY3Rpb24gKCkge1xuICAgICAgICAkKCcuc3R0LXRyYW5zY3JpcHQtdmlldycpLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaW5pdGlhbGl6ZSh0aGlzKTtcbiAgICAgICAgfSk7XG4gICAgfSk7XG59KHdpbmRvdywgalF1ZXJ5KSk7XG4iXSwibWFwcGluZ3MiOiI7QUFBQyxXQUFVQSxNQUFNLEVBQUVDLENBQUMsRUFBRTtFQUNsQixZQUFZOztFQUVaLElBQUlDLGNBQWMsR0FBRyxDQUFDLEdBQUcsRUFBRSxDQUFDLEVBQUUsQ0FBQyxDQUFDO0VBRWhDLFNBQVNDLFVBQVVBLENBQUNDLEtBQUssRUFBRTtJQUN2QixJQUFJQyxNQUFNLEdBQUdDLE1BQU0sQ0FBQ0YsS0FBSyxDQUFDO0lBRTFCLE9BQU9F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