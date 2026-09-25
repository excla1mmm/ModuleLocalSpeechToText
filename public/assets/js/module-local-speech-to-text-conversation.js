function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
(function (window, $) {
  'use strict';

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
  function createPlayer(labels, durationLabel) {
    var $player = $('<div>', {
      class: 'stt-transcript-player',
      'data-duration': safeNumber(labels.durationSeconds)
    }).append($('<audio>', {
      class: 'stt-transcript-audio',
      preload: 'metadata',
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
    }).text('00:00 / ' + durationLabel));
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
    }).append($('<span>').text('0:00'), $('<span>').text(durationLabel)));
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
    })), $filters, $('<span>', {
      class: 'stt-transcript-count',
      'aria-live': 'polite'
    }));
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
          left: start / duration * 100 + '%',
          width: (end - start) / duration * 100 + '%'
        });
      });
    }
    function update() {
      var duration = currentDuration();
      var value = duration > 0 ? Math.min(1000, Math.round(audio.currentTime / duration * 1000)) : 0;
      synchronizeTimeline(duration);
      $range.val(value).attr('aria-valuetext', formatTime(audio.currentTime));
      $playhead.css('left', value / 10 + '%');
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
        audio.currentTime = duration * value / 1000;
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
        }, {
          once: true
        });
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
        audio.addEventListener('loadedmetadata', applySeek, {
          once: true
        });
      }
    }
    function synchronize() {
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

    // A shared moment is held without playing: browsers start audio only on a user gesture.
    function holdMoment(seconds) {
      function apply() {
        var duration = currentDuration();
        audio.currentTime = duration > 0 ? Math.min(seconds, Math.max(0, duration - 0.01)) : seconds;
        update();
        var turn = $root.find('.stt-turn-active').get(0) || $root.get(0);
        if (turn && typeof turn.scrollIntoView === 'function') {
          turn.scrollIntoView({
            block: 'center'
          });
        }
      }
      if (!ensureSource()) {
        return;
      }
      if (audio.readyState >= 1) {
        apply();
      } else {
        audio.addEventListener('loadedmetadata', apply, {
          once: true
        });
      }
    }
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
      rememberMoment(currentDuration() * safeNumber(this.value) / 1000);
    });
    $seek.on('mousemove.sttTranscript', function (event) {
      var offset = $seek.offset();
      var width = $seek.width();
      var percent = width > 0 ? Math.max(0, Math.min(100, (event.pageX - offset.left) / width * 100)) : 0;
      $tooltip.text(formatTime(currentDuration() * percent / 100)).css('left', percent + '%');
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
      $root.find('.stt-transcript-count').text(String(visible));
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
})(window, jQuery);

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LWNvbnZlcnNhdGlvbi5qcyIsIm5hbWVzIjpbIndpbmRvdyIsIiQiLCJzYWZlTnVtYmVyIiwidmFsdWUiLCJudW1iZXIiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsIk1hdGgiLCJtYXgiLCJzcGVha2VyQ2xhc3MiLCJrZXkiLCJTdHJpbmciLCJyZXBsYWNlIiwiY3JlYXRlTGVnZW5kIiwic3BlYWtlcnMiLCIkbGVnZW5kIiwiY2xhc3MiLCJmb3JFYWNoIiwic3BlYWtlciIsImFwcGVuZCIsImRvY3VtZW50IiwiY3JlYXRlVGV4dE5vZGUiLCJsYWJlbCIsImNyZWF0ZVBsYXllciIsImxhYmVscyIsImR1cmF0aW9uTGFiZWwiLCIkcGxheWVyIiwiZHVyYXRpb25TZWNvbmRzIiwicHJlbG9hZCIsImF1ZGlvVXJsIiwidHlwZSIsInBsYXkiLCJ0ZXh0IiwiZG93bmxvYWRVcmwiLCJocmVmIiwiZG93bmxvYWQiLCJ0aXRsZSIsImNyZWF0ZU92ZXJ2aWV3IiwiY29udmVyc2F0aW9uIiwiQXJyYXkiLCJpc0FycmF5IiwicGxheWFibGUiLCJCb29sZWFuIiwiJG92ZXJ2aWV3IiwiJG92ZXJ2aWV3SGVhZGVyIiwiJGxhbmVzIiwiJGxhbmUiLCJzZWdtZW50cyIsInR1cm4iLCJzdGFydCIsImVuZCIsImNzcyIsImxlZnQiLCJzdGFydFBlcmNlbnQiLCJ3aWR0aCIsIndpZHRoUGVyY2VudCIsIiRzZWVrIiwiYWRkQ2xhc3MiLCJtaW4iLCJjcmVhdGVUb29sYmFyIiwiJGZpbHRlcnMiLCJyb2xlIiwiYWxsIiwicGxhY2Vob2xkZXIiLCJzZWFyY2giLCJjcmVhdGVUdXJuIiwic3BlYWtlcktleSIsIiRyb3ciLCJ0YWJpbmRleCIsIiRtZXRhIiwic3BlYWtlckxhYmVsIiwic3RhcnRMYWJlbCIsImRpciIsImNyZWF0ZSIsImRhdGEiLCJfdHlwZW9mIiwidGltZWxpbmUiLCJPYmplY3QiLCJhc3NpZ24iLCJlbXB0eSIsIiRyb290IiwiJGxpc3QiLCJnYXBCZWZvcmVMYWJlbCIsImhpZGRlbiIsImluaXRpYWxpemUiLCJmb3JtYXRUaW1lIiwic2Vjb25kcyIsInRvdGFsIiwiZmxvb3IiLCJob3VycyIsIm1pbnV0ZXMiLCJyZXN0IiwicGFkU3RhcnQiLCJMT0NBVElPTl9LRVlTIiwic3RhZ2UiLCJ0IiwibGVnIiwiTEVHQUNZX0xPQ0FUSU9OX0tFWVMiLCJ0cmFuc2NyaXB0TG9jYXRpb24iLCJyZWFkIiwicXVlcnkiLCJVUkxTZWFyY2hQYXJhbXMiLCJlcnJvciIsImtleXMiLCJoYXMiLCJnZXQiLCJ2YWxpZCIsInRlc3QiLCJjaGFuZ2VzIiwiY3VycmVudCIsIm5leHQiLCJwcm90b3R5cGUiLCJoYXNPd25Qcm9wZXJ0eSIsImNhbGwiLCJkZWxldGUiLCJzZXQiLCJyZXN1bHQiLCJ0b1N0cmluZyIsIndyaXRlIiwibG9jYXRpb24iLCJoaXN0b3J5IiwicmVwbGFjZVN0YXRlIiwic3RhdGUiLCJwYXRobmFtZSIsImhhc2giLCJpbml0aWFsaXplUGxheWVyIiwiYXVkaW8iLCJmaW5kIiwiJHBsYXlCdXR0b24iLCIkcGxheUljb24iLCIkZHVyYXRpb24iLCIkcmFuZ2UiLCIkcGxheWhlYWQiLCIkdG9vbHRpcCIsIiRzY2FsZUVuZCIsImxhc3QiLCIkc2VnbWVudHMiLCIkdHVybnMiLCJwZW5kaW5nUmFuZ2VWYWx1ZSIsIndhaXRpbmdGb3JNZXRhZGF0YSIsInN5bmNocm9uaXplZER1cmF0aW9uIiwic3RhZ2VJZCIsImF0dHIiLCJsaW5rYWJsZSIsImxlbmd0aCIsImZhbGxiYWNrRHVyYXRpb24iLCJjdXJyZW50RHVyYXRpb24iLCJkdXJhdGlvbiIsImVuc3VyZVNvdXJjZSIsImdldEF0dHJpYnV0ZSIsInNvdXJjZSIsInNldEF0dHJpYnV0ZSIsImxvYWQiLCJzZXRQbGF5SWNvbiIsInBsYXlpbmciLCJ0b2dnbGVDbGFzcyIsInNldEFjdGl2ZVR1cm5zIiwiY3VycmVudFRpbWUiLCJlYWNoIiwiJHR1cm4iLCJzeW5jaHJvbml6ZVRpbWVsaW5lIiwiYWJzIiwiJHNlZ21lbnQiLCJ1cGRhdGUiLCJyb3VuZCIsInZhbCIsImNhdGNoIiwic2Vla0J5UmFuZ2UiLCJhcHBseVNlZWsiLCJyZWFkeVN0YXRlIiwiYWRkRXZlbnRMaXN0ZW5lciIsIm9uY2UiLCJzZWVrVHVybiIsInJlbWVtYmVyTW9tZW50Iiwic3luY2hyb25pemUiLCJob2xkTW9tZW50IiwiYXBwbHkiLCJzY3JvbGxJbnRvVmlldyIsImJsb2NrIiwib24iLCJwYXVzZWQiLCJwYXVzZSIsImV2ZW50Iiwib2Zmc2V0IiwicGVyY2VudCIsInBhZ2VYIiwicHJldmVudERlZmF1bHQiLCJtb21lbnQiLCJyb290IiwianF1ZXJ5IiwiJGlucHV0IiwiJHJvd3MiLCIkZ2FwcyIsIiRlbXB0eSIsImFjdGl2ZVNwZWFrZXIiLCJ0cmltIiwidG9Mb2NhbGVMb3dlckNhc2UiLCJ2aXNpYmxlIiwic3BlYWtlck1hdGNoZXMiLCJ0ZXh0TWF0Y2hlcyIsImluZGV4T2YiLCJzaG93biIsInByb3AiLCJyZW1vdmVDbGFzcyIsIlN0dFRyYW5zY3JpcHRWaWV3IiwialF1ZXJ5Il0sInNvdXJjZXMiOlsic3JjL21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1jb252ZXJzYXRpb24uanMiXSwic291cmNlc0NvbnRlbnQiOlsiKGZ1bmN0aW9uICh3aW5kb3csICQpIHtcbiAgICAndXNlIHN0cmljdCc7XG5cbiAgICBmdW5jdGlvbiBzYWZlTnVtYmVyKHZhbHVlKSB7XG4gICAgICAgIHZhciBudW1iZXIgPSBOdW1iZXIodmFsdWUpO1xuXG4gICAgICAgIHJldHVybiBOdW1iZXIuaXNGaW5pdGUobnVtYmVyKSA/IE1hdGgubWF4KDAsIG51bWJlcikgOiAwO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIHNwZWFrZXJDbGFzcyhrZXkpIHtcbiAgICAgICAgcmV0dXJuIFN0cmluZyhrZXkgfHwgJycpLnJlcGxhY2UoL1teYS16MC05Xy1dL2dpLCAnLScpIHx8ICdsZWZ0JztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjcmVhdGVMZWdlbmQoc3BlYWtlcnMpIHtcbiAgICAgICAgdmFyICRsZWdlbmQgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sZWdlbmQnIH0pO1xuXG4gICAgICAgIHNwZWFrZXJzLmZvckVhY2goZnVuY3Rpb24gKHNwZWFrZXIpIHtcbiAgICAgICAgICAgICRsZWdlbmQuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sZWdlbmQtaXRlbSBzdHQtc3BlYWtlci0nICsgc3BlYWtlckNsYXNzKHNwZWFrZXIua2V5KSB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtc3BlYWtlci1kb3QnIH0pLFxuICAgICAgICAgICAgICAgICAgICBkb2N1bWVudC5jcmVhdGVUZXh0Tm9kZShTdHJpbmcoc3BlYWtlci5sYWJlbCB8fCAnJykpXG4gICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgcmV0dXJuICRsZWdlbmQ7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlUGxheWVyKGxhYmVscywgZHVyYXRpb25MYWJlbCkge1xuICAgICAgICB2YXIgJHBsYXllciA9ICQoJzxkaXY+Jywge1xuICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1wbGF5ZXInLFxuICAgICAgICAgICAgJ2RhdGEtZHVyYXRpb24nOiBzYWZlTnVtYmVyKGxhYmVscy5kdXJhdGlvblNlY29uZHMpXG4gICAgICAgIH0pLmFwcGVuZChcbiAgICAgICAgICAgICQoJzxhdWRpbz4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1hdWRpbycsXG4gICAgICAgICAgICAgICAgcHJlbG9hZDogJ21ldGFkYXRhJyxcbiAgICAgICAgICAgICAgICAnZGF0YS1zcmMnOiBTdHJpbmcobGFiZWxzLmF1ZGlvVXJsIHx8ICcnKVxuICAgICAgICAgICAgfSksXG4gICAgICAgICAgICAkKCc8YnV0dG9uPicsIHtcbiAgICAgICAgICAgICAgICB0eXBlOiAnYnV0dG9uJyxcbiAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC1wbGF5ZXItcGxheScsXG4gICAgICAgICAgICAgICAgJ2FyaWEtbGFiZWwnOiBsYWJlbHMucGxheSxcbiAgICAgICAgICAgICAgICAnZGF0YS1jb250ZW50JzogbGFiZWxzLnBsYXlcbiAgICAgICAgICAgIH0pLmFwcGVuZCgkKCc8aT4nLCB7IGNsYXNzOiAncGxheSBpY29uJyB9KSksXG4gICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXBsYXllci1kdXJhdGlvbicgfSkudGV4dCgnMDA6MDAgLyAnICsgZHVyYXRpb25MYWJlbClcbiAgICAgICAgKTtcblxuICAgICAgICBpZiAobGFiZWxzLmRvd25sb2FkVXJsKSB7XG4gICAgICAgICAgICAkcGxheWVyLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8YT4nLCB7XG4gICAgICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXBsYXllci1kb3dubG9hZCcsXG4gICAgICAgICAgICAgICAgICAgIGhyZWY6IFN0cmluZyhsYWJlbHMuZG93bmxvYWRVcmwpLFxuICAgICAgICAgICAgICAgICAgICAnZGF0YS1jb250ZW50JzogbGFiZWxzLmRvd25sb2FkLFxuICAgICAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5kb3dubG9hZCxcbiAgICAgICAgICAgICAgICAgICAgdGl0bGU6IGxhYmVscy5kb3dubG9hZFxuICAgICAgICAgICAgICAgIH0pLmFwcGVuZCgkKCc8aT4nLCB7IGNsYXNzOiAnZG93bmxvYWQgaWNvbicgfSkpXG4gICAgICAgICAgICApO1xuICAgICAgICB9XG5cbiAgICAgICAgcmV0dXJuICRwbGF5ZXI7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlT3ZlcnZpZXcoY29udmVyc2F0aW9uLCBsYWJlbHMpIHtcbiAgICAgICAgdmFyIHNwZWFrZXJzID0gQXJyYXkuaXNBcnJheShjb252ZXJzYXRpb24uc3BlYWtlcnMpID8gY29udmVyc2F0aW9uLnNwZWFrZXJzIDogW107XG4gICAgICAgIHZhciBkdXJhdGlvbkxhYmVsID0gU3RyaW5nKGNvbnZlcnNhdGlvbi5kdXJhdGlvbkxhYmVsIHx8ICcwOjAwJyk7XG4gICAgICAgIHZhciBwbGF5YWJsZSA9IEJvb2xlYW4obGFiZWxzLmF1ZGlvVXJsKTtcbiAgICAgICAgdmFyICRvdmVydmlldyA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LW92ZXJ2aWV3JyB9KTtcbiAgICAgICAgdmFyICRvdmVydmlld0hlYWRlciA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LW92ZXJ2aWV3LWhlYWRlcicgfSkuYXBwZW5kKFxuICAgICAgICAgICAgcGxheWFibGVcbiAgICAgICAgICAgICAgICA/IGNyZWF0ZVBsYXllcihsYWJlbHMsIGR1cmF0aW9uTGFiZWwpXG4gICAgICAgICAgICAgICAgOiAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZHVyYXRpb24nIH0pLnRleHQoZHVyYXRpb25MYWJlbCksXG4gICAgICAgICAgICBjcmVhdGVMZWdlbmQoc3BlYWtlcnMpXG4gICAgICAgICk7XG4gICAgICAgIHZhciAkbGFuZXMgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sYW5lcycgfSk7XG5cbiAgICAgICAgc3BlYWtlcnMuZm9yRWFjaChmdW5jdGlvbiAoc3BlYWtlcikge1xuICAgICAgICAgICAgdmFyIGtleSA9IHNwZWFrZXJDbGFzcyhzcGVha2VyLmtleSk7XG4gICAgICAgICAgICB2YXIgJGxhbmUgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sYW5lIHN0dC1zcGVha2VyLScgKyBrZXkgfSk7XG4gICAgICAgICAgICB2YXIgc2VnbWVudHMgPSBBcnJheS5pc0FycmF5KHNwZWFrZXIuc2VnbWVudHMpID8gc3BlYWtlci5zZWdtZW50cyA6IFtdO1xuXG4gICAgICAgICAgICBzZWdtZW50cy5mb3JFYWNoKGZ1bmN0aW9uICh0dXJuKSB7XG4gICAgICAgICAgICAgICAgJGxhbmUuYXBwZW5kKCQoJzxzcGFuPicsIHtcbiAgICAgICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sYW5lLXNlZ21lbnQnLFxuICAgICAgICAgICAgICAgICAgICAnZGF0YS1zdGFydCc6IHNhZmVOdW1iZXIodHVybi5zdGFydCksXG4gICAgICAgICAgICAgICAgICAgICdkYXRhLWVuZCc6IHNhZmVOdW1iZXIodHVybi5lbmQpXG4gICAgICAgICAgICAgICAgfSkuY3NzKHtcbiAgICAgICAgICAgICAgICAgICAgbGVmdDogc2FmZU51bWJlcih0dXJuLnN0YXJ0UGVyY2VudCkgKyAnJScsXG4gICAgICAgICAgICAgICAgICAgIHdpZHRoOiBzYWZlTnVtYmVyKHR1cm4ud2lkdGhQZXJjZW50KSArICclJ1xuICAgICAgICAgICAgICAgIH0pKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJGxhbmVzLmFwcGVuZCgkbGFuZSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHZhciAkc2VlayA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXNlZWsnIH0pLmFwcGVuZCgkbGFuZXMpO1xuICAgICAgICBpZiAocGxheWFibGUpIHtcbiAgICAgICAgICAgICRzZWVrLmFkZENsYXNzKCdpcy1wbGF5YWJsZScpLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtcGxheWhlYWQnIH0pLFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1zZWVrLXRvb2x0aXAnIH0pLnRleHQoJzA6MDAnKSxcbiAgICAgICAgICAgICAgICAkKCc8aW5wdXQ+Jywge1xuICAgICAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXJhbmdlJyxcbiAgICAgICAgICAgICAgICAgICAgdHlwZTogJ3JhbmdlJyxcbiAgICAgICAgICAgICAgICAgICAgbWluOiAwLFxuICAgICAgICAgICAgICAgICAgICBtYXg6IDEwMDAsXG4gICAgICAgICAgICAgICAgICAgIHZhbHVlOiAwLFxuICAgICAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5wbGF5XG4gICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICk7XG4gICAgICAgIH1cblxuICAgICAgICByZXR1cm4gJG92ZXJ2aWV3LmFwcGVuZChcbiAgICAgICAgICAgICRvdmVydmlld0hlYWRlcixcbiAgICAgICAgICAgICRzZWVrLFxuICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtc2NhbGUnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nKS50ZXh0KCcwOjAwJyksXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JykudGV4dChkdXJhdGlvbkxhYmVsKVxuICAgICAgICAgICAgKVxuICAgICAgICApO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZVRvb2xiYXIoc3BlYWtlcnMsIGxhYmVscykge1xuICAgICAgICB2YXIgJGZpbHRlcnMgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1maWx0ZXJzJywgcm9sZTogJ2dyb3VwJyB9KTtcblxuICAgICAgICAkZmlsdGVycy5hcHBlbmQoJCgnPGJ1dHRvbj4nLCB7XG4gICAgICAgICAgICB0eXBlOiAnYnV0dG9uJyxcbiAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZmlsdGVyIGFjdGl2ZScsXG4gICAgICAgICAgICAnZGF0YS1zcGVha2VyJzogJ2FsbCcsXG4gICAgICAgICAgICAnYXJpYS1wcmVzc2VkJzogJ3RydWUnXG4gICAgICAgIH0pLnRleHQobGFiZWxzLmFsbCkpO1xuICAgICAgICBzcGVha2Vycy5mb3JFYWNoKGZ1bmN0aW9uIChzcGVha2VyKSB7XG4gICAgICAgICAgICAkZmlsdGVycy5hcHBlbmQoJCgnPGJ1dHRvbj4nLCB7XG4gICAgICAgICAgICAgICAgdHlwZTogJ2J1dHRvbicsXG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1maWx0ZXInLFxuICAgICAgICAgICAgICAgICdkYXRhLXNwZWFrZXInOiBzcGVha2VyQ2xhc3Moc3BlYWtlci5rZXkpLFxuICAgICAgICAgICAgICAgICdhcmlhLXByZXNzZWQnOiAnZmFsc2UnXG4gICAgICAgICAgICB9KS50ZXh0KFN0cmluZyhzcGVha2VyLmxhYmVsIHx8ICcnKSkpO1xuICAgICAgICB9KTtcblxuICAgICAgICByZXR1cm4gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtdG9vbGJhcicgfSkuYXBwZW5kKFxuICAgICAgICAgICAgJCgnPGxhYmVsPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1zZWFyY2gnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8aT4nLCB7IGNsYXNzOiAnc2VhcmNoIGljb24nIH0pLFxuICAgICAgICAgICAgICAgICQoJzxpbnB1dD4nLCB7XG4gICAgICAgICAgICAgICAgICAgIHR5cGU6ICdzZWFyY2gnLFxuICAgICAgICAgICAgICAgICAgICBwbGFjZWhvbGRlcjogbGFiZWxzLnNlYXJjaCxcbiAgICAgICAgICAgICAgICAgICAgJ2FyaWEtbGFiZWwnOiBsYWJlbHMuc2VhcmNoXG4gICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICksXG4gICAgICAgICAgICAkZmlsdGVycyxcbiAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1jb3VudCcsICdhcmlhLWxpdmUnOiAncG9saXRlJyB9KVxuICAgICAgICApO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZVR1cm4odHVybiwgcGxheWFibGUpIHtcbiAgICAgICAgdmFyIGtleSA9IHNwZWFrZXJDbGFzcyh0dXJuLnNwZWFrZXJLZXkpO1xuICAgICAgICB2YXIgJHJvdyA9ICQoJzxhcnRpY2xlPicsIHtcbiAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtdHVybiBzdHQtdHVybi0nICsga2V5ICsgKHBsYXlhYmxlID8gJyBzdHQtcGxheWFibGUtdHVybicgOiAnJyksXG4gICAgICAgICAgICAnZGF0YS1zcGVha2VyJzoga2V5LFxuICAgICAgICAgICAgJ2RhdGEtc3RhcnQnOiBzYWZlTnVtYmVyKHR1cm4uc3RhcnQpLFxuICAgICAgICAgICAgJ2RhdGEtZW5kJzogc2FmZU51bWJlcih0dXJuLmVuZCksXG4gICAgICAgICAgICB0YWJpbmRleDogcGxheWFibGUgPyAwIDogbnVsbFxuICAgICAgICB9KTtcbiAgICAgICAgdmFyICRtZXRhID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXR1cm4tbWV0YScgfSkuYXBwZW5kKFxuICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC1zcGVha2VyLWRvdCcgfSksXG4gICAgICAgICAgICAkKCc8c3Ryb25nPicsIHsgY2xhc3M6ICdzdHQtdHVybi1zcGVha2VyJyB9KS50ZXh0KFN0cmluZyh0dXJuLnNwZWFrZXJMYWJlbCB8fCAnJykpXG4gICAgICAgICk7XG5cbiAgICAgICAgcmV0dXJuICRyb3cuYXBwZW5kKFxuICAgICAgICAgICAgJCgnPHRpbWU+JywgeyBjbGFzczogJ3N0dC10dXJuLXRpbWUnIH0pLnRleHQoU3RyaW5nKHR1cm4uc3RhcnRMYWJlbCB8fCAnJykpLFxuICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXR1cm4tY29udGVudCcgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICRtZXRhLFxuICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10dXJuLXRleHQnLCBkaXI6ICdhdXRvJyB9KS50ZXh0KFN0cmluZyh0dXJuLnRleHQgfHwgJycpKVxuICAgICAgICAgICAgKVxuICAgICAgICApO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZShjb252ZXJzYXRpb24sIGxhYmVscykge1xuICAgICAgICB2YXIgZGF0YSA9IGNvbnZlcnNhdGlvbiAmJiB0eXBlb2YgY29udmVyc2F0aW9uID09PSAnb2JqZWN0JyA/IGNvbnZlcnNhdGlvbiA6IHt9O1xuICAgICAgICB2YXIgc3BlYWtlcnMgPSBBcnJheS5pc0FycmF5KGRhdGEuc3BlYWtlcnMpID8gZGF0YS5zcGVha2VycyA6IFtdO1xuICAgICAgICB2YXIgdGltZWxpbmUgPSBBcnJheS5pc0FycmF5KGRhdGEudGltZWxpbmUpID8gZGF0YS50aW1lbGluZSA6IFtdO1xuICAgICAgICB2YXIgdGV4dCA9IE9iamVjdC5hc3NpZ24oeyBhbGw6ICdBbGwnLCBzZWFyY2g6ICdTZWFyY2gnLCBlbXB0eTogJycsIHBsYXk6ICcnLCBkb3dubG9hZDogJycgfSwgbGFiZWxzIHx8IHt9KTtcbiAgICAgICAgdmFyIHBsYXlhYmxlID0gQm9vbGVhbih0ZXh0LmF1ZGlvVXJsKTtcbiAgICAgICAgdmFyICRyb290ID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtdmlldycgfSk7XG4gICAgICAgIHZhciAkbGlzdCA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWxpc3QnIH0pO1xuXG4gICAgICAgIHRpbWVsaW5lLmZvckVhY2goZnVuY3Rpb24gKHR1cm4pIHtcbiAgICAgICAgICAgIGlmICghdHVybiB8fCB0eXBlb2YgdHVybiAhPT0gJ29iamVjdCcpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAodHVybi5nYXBCZWZvcmVMYWJlbCkge1xuICAgICAgICAgICAgICAgICRsaXN0LmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZ2FwJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nKS50ZXh0KCfCt8K3wrcgJyArIFN0cmluZyh0dXJuLmdhcEJlZm9yZUxhYmVsKSlcbiAgICAgICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgICAgICk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICAkbGlzdC5hcHBlbmQoY3JlYXRlVHVybih0dXJuLCBwbGF5YWJsZSkpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkcm9vdC5hcHBlbmQoXG4gICAgICAgICAgICBjcmVhdGVPdmVydmlldyhkYXRhLCB0ZXh0KSxcbiAgICAgICAgICAgIGNyZWF0ZVRvb2xiYXIoc3BlYWtlcnMsIHRleHQpLFxuICAgICAgICAgICAgJGxpc3QsXG4gICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1lbXB0eSB1aSBtZXNzYWdlJywgaGlkZGVuOiB0cnVlIH0pLnRleHQodGV4dC5lbXB0eSlcbiAgICAgICAgKTtcbiAgICAgICAgaW5pdGlhbGl6ZSgkcm9vdCk7XG5cbiAgICAgICAgcmV0dXJuICRyb290O1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGZvcm1hdFRpbWUoc2Vjb25kcykge1xuICAgICAgICB2YXIgdG90YWwgPSBNYXRoLmZsb29yKE1hdGgubWF4KDAsIHNlY29uZHMgfHwgMCkpO1xuICAgICAgICB2YXIgaG91cnMgPSBNYXRoLmZsb29yKHRvdGFsIC8gMzYwMCk7XG4gICAgICAgIHZhciBtaW51dGVzID0gTWF0aC5mbG9vcigodG90YWwgJSAzNjAwKSAvIDYwKTtcbiAgICAgICAgdmFyIHJlc3QgPSB0b3RhbCAlIDYwO1xuXG4gICAgICAgIGlmIChob3VycyA+IDApIHtcbiAgICAgICAgICAgIHJldHVybiBob3VycyArICc6JyArIFN0cmluZyhtaW51dGVzKS5wYWRTdGFydCgyLCAnMCcpICsgJzonICsgU3RyaW5nKHJlc3QpLnBhZFN0YXJ0KDIsICcwJyk7XG4gICAgICAgIH1cblxuICAgICAgICByZXR1cm4gTWF0aC5mbG9vcih0b3RhbCAvIDYwKSArICc6JyArIFN0cmluZyhyZXN0KS5wYWRTdGFydCgyLCAnMCcpO1xuICAgIH1cblxuICAgIC8vIEEgdHJhbnNjcmlwdCBwYWdlIGFkZHJlc3MgbWF5IGNhcnJ5IHRoZSByZWNvcmRpbmcgKHN0YWdlIHJlc3VsdCBpZCkgYW5kXG4gICAgLy8gc2Vjb25kIHRoZSByZWFkZXIgc3RvcHBlZCBhdCwgYW5kIHRoZSBvcGVuIGNhbGwgbGVnOlxuICAgIC8vID9pZD08cmVzdWx0PiZsc3R0X3N0YWdlPTxyZXN1bHQ+JmxzdHRfdD08c2Vjb25kcz4mbHN0dF9sZWc9aW50ZXJuYWwuXG4gICAgLy8gVGhlIG1vZHVsZSBwcmVmaXgga2VlcHMgdGhlc2UgbmFtZXMgY2xlYXIgb2YgQ29yZSBhbmQgb3RoZXIgbW9kdWxlcztcbiAgICAvLyBsaW5rcyBzaGFyZWQgYmVmb3JlIGl0IChzdGFnZS90L2xlZykgc3RpbGwgb3BlbiBhbmQgYXJlIHJld3JpdHRlbiBvblxuICAgIC8vIHRoZSBuZXh0IGFkZHJlc3MgdXBkYXRlLlxuICAgIHZhciBMT0NBVElPTl9LRVlTID0geyBzdGFnZTogJ2xzdHRfc3RhZ2UnLCB0OiAnbHN0dF90JywgbGVnOiAnbHN0dF9sZWcnIH07XG4gICAgdmFyIExFR0FDWV9MT0NBVElPTl9LRVlTID0geyBzdGFnZTogJ3N0YWdlJywgdDogJ3QnLCBsZWc6ICdsZWcnIH07XG5cbiAgICB2YXIgdHJhbnNjcmlwdExvY2F0aW9uID0ge1xuICAgICAgICByZWFkOiBmdW5jdGlvbiAoc2VhcmNoKSB7XG4gICAgICAgICAgICB2YXIgcXVlcnk7XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIHF1ZXJ5ID0gbmV3IFVSTFNlYXJjaFBhcmFtcyhzZWFyY2ggfHwgJycpO1xuICAgICAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4geyBzdGFnZTogJycsIHQ6IDAsIGxlZzogJycgfTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHZhciBrZXlzID0gcXVlcnkuaGFzKExPQ0FUSU9OX0tFWVMuc3RhZ2UpIHx8IHF1ZXJ5LmhhcyhMT0NBVElPTl9LRVlTLmxlZykgPyBMT0NBVElPTl9LRVlTIDogTEVHQUNZX0xPQ0FUSU9OX0tFWVM7XG4gICAgICAgICAgICB2YXIgc3RhZ2UgPSBTdHJpbmcocXVlcnkuZ2V0KGtleXMuc3RhZ2UpIHx8ICcnKTtcbiAgICAgICAgICAgIHZhciBzZWNvbmRzID0gTnVtYmVyKHF1ZXJ5LmdldChrZXlzLnQpKTtcbiAgICAgICAgICAgIHZhciB2YWxpZCA9IC9eWzEtOV1bMC05XSokLy50ZXN0KHN0YWdlKSAmJiBxdWVyeS5nZXQoa2V5cy50KSAhPT0gbnVsbCAmJiBOdW1iZXIuaXNGaW5pdGUoc2Vjb25kcykgJiYgc2Vjb25kcyA+PSAwICYmIHNlY29uZHMgPD0gODY0MDA7XG5cbiAgICAgICAgICAgIHJldHVybiB7XG4gICAgICAgICAgICAgICAgc3RhZ2U6IHZhbGlkID8gc3RhZ2UgOiAnJyxcbiAgICAgICAgICAgICAgICB0OiB2YWxpZCA/IE1hdGguZmxvb3Ioc2Vjb25kcykgOiAwLFxuICAgICAgICAgICAgICAgIGxlZzogcXVlcnkuZ2V0KGtleXMubGVnKSA9PT0gJ2ludGVybmFsJyA/ICdpbnRlcm5hbCcgOiAnJ1xuICAgICAgICAgICAgfTtcbiAgICAgICAgfSxcbiAgICAgICAgc2VhcmNoOiBmdW5jdGlvbiAoc2VhcmNoLCBjaGFuZ2VzKSB7XG4gICAgICAgICAgICB2YXIgcXVlcnk7XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIHF1ZXJ5ID0gbmV3IFVSTFNlYXJjaFBhcmFtcyhzZWFyY2ggfHwgJycpO1xuICAgICAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gJyc7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB2YXIgY3VycmVudCA9IHRyYW5zY3JpcHRMb2NhdGlvbi5yZWFkKHNlYXJjaCk7XG4gICAgICAgICAgICB2YXIgbmV4dCA9IHtcbiAgICAgICAgICAgICAgICBzdGFnZTogY3VycmVudC5zdGFnZSxcbiAgICAgICAgICAgICAgICB0OiBjdXJyZW50LnQsXG4gICAgICAgICAgICAgICAgbGVnOiBjdXJyZW50LmxlZ1xuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgIGlmIChPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoY2hhbmdlcywgJ3N0YWdlJykpIHtcbiAgICAgICAgICAgICAgICBuZXh0LnN0YWdlID0gY2hhbmdlcy5zdGFnZSA/IFN0cmluZyhjaGFuZ2VzLnN0YWdlKSA6ICcnO1xuICAgICAgICAgICAgICAgIG5leHQudCA9IE1hdGguZmxvb3IoTnVtYmVyKGNoYW5nZXMudCkgfHwgMCk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKGNoYW5nZXMsICdsZWcnKSkge1xuICAgICAgICAgICAgICAgIG5leHQubGVnID0gY2hhbmdlcy5sZWcgPT09ICdpbnRlcm5hbCcgPyAnaW50ZXJuYWwnIDogJyc7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBbTE9DQVRJT05fS0VZUywgTEVHQUNZX0xPQ0FUSU9OX0tFWVNdLmZvckVhY2goZnVuY3Rpb24gKGtleXMpIHtcbiAgICAgICAgICAgICAgICBxdWVyeS5kZWxldGUoa2V5cy5zdGFnZSk7XG4gICAgICAgICAgICAgICAgcXVlcnkuZGVsZXRlKGtleXMudCk7XG4gICAgICAgICAgICAgICAgcXVlcnkuZGVsZXRlKGtleXMubGVnKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgaWYgKG5leHQuc3RhZ2UgJiYgbmV4dC50ID4gMCkge1xuICAgICAgICAgICAgICAgIHF1ZXJ5LnNldChMT0NBVElPTl9LRVlTLnN0YWdlLCBuZXh0LnN0YWdlKTtcbiAgICAgICAgICAgICAgICBxdWVyeS5zZXQoTE9DQVRJT05fS0VZUy50LCBTdHJpbmcobmV4dC50KSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAobmV4dC5sZWcgPT09ICdpbnRlcm5hbCcpIHtcbiAgICAgICAgICAgICAgICBxdWVyeS5zZXQoTE9DQVRJT05fS0VZUy5sZWcsICdpbnRlcm5hbCcpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIHJlc3VsdCA9IHF1ZXJ5LnRvU3RyaW5nKCk7XG5cbiAgICAgICAgICAgIHJldHVybiByZXN1bHQgPT09ICcnID8gJycgOiAnPycgKyByZXN1bHQ7XG4gICAgICAgIH0sXG4gICAgICAgIHdyaXRlOiBmdW5jdGlvbiAoY2hhbmdlcykge1xuICAgICAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICAgICB2YXIgbG9jYXRpb24gPSB3aW5kb3cubG9jYXRpb247XG4gICAgICAgICAgICAgICAgd2luZG93Lmhpc3RvcnkucmVwbGFjZVN0YXRlKHdpbmRvdy5oaXN0b3J5LnN0YXRlLCAnJywgbG9jYXRpb24ucGF0aG5hbWUgKyB0cmFuc2NyaXB0TG9jYXRpb24uc2VhcmNoKGxvY2F0aW9uLnNlYXJjaCwgY2hhbmdlcykgKyBsb2NhdGlvbi5oYXNoKTtcbiAgICAgICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICAgICAgLy8gVGhlIGFkZHJlc3MgaXMgYSBjb252ZW5pZW5jZTsgcGxheWJhY2sgbmV2ZXIgZGVwZW5kcyBvbiBpdC5cbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH07XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplUGxheWVyKCRyb290KSB7XG4gICAgICAgIHZhciBhdWRpbyA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1hdWRpbycpLmdldCgwKTtcbiAgICAgICAgdmFyICRwbGF5ZXIgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtcGxheWVyJyk7XG4gICAgICAgIHZhciAkcGxheUJ1dHRvbiA9ICRwbGF5ZXIuZmluZCgnLnN0dC1wbGF5ZXItcGxheScpO1xuICAgICAgICB2YXIgJHBsYXlJY29uID0gJHBsYXlCdXR0b24uZmluZCgnaScpO1xuICAgICAgICB2YXIgJGR1cmF0aW9uID0gJHBsYXllci5maW5kKCcuc3R0LXBsYXllci1kdXJhdGlvbicpO1xuICAgICAgICB2YXIgJHNlZWsgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtc2Vlay5pcy1wbGF5YWJsZScpO1xuICAgICAgICB2YXIgJHJhbmdlID0gJHNlZWsuZmluZCgnLnN0dC10cmFuc2NyaXB0LXJhbmdlJyk7XG4gICAgICAgIHZhciAkcGxheWhlYWQgPSAkc2Vlay5maW5kKCcuc3R0LXRyYW5zY3JpcHQtcGxheWhlYWQnKTtcbiAgICAgICAgdmFyICR0b29sdGlwID0gJHNlZWsuZmluZCgnLnN0dC10cmFuc2NyaXB0LXNlZWstdG9vbHRpcCcpO1xuICAgICAgICB2YXIgJHNjYWxlRW5kID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LXNjYWxlIHNwYW4nKS5sYXN0KCk7XG4gICAgICAgIHZhciAkc2VnbWVudHMgPSAkc2Vlay5maW5kKCcuc3R0LXRyYW5zY3JpcHQtbGFuZS1zZWdtZW50Jyk7XG4gICAgICAgIHZhciAkdHVybnMgPSAkcm9vdC5maW5kKCcuc3R0LXBsYXlhYmxlLXR1cm4nKTtcbiAgICAgICAgdmFyIHBlbmRpbmdSYW5nZVZhbHVlID0gbnVsbDtcbiAgICAgICAgdmFyIHdhaXRpbmdGb3JNZXRhZGF0YSA9IGZhbHNlO1xuICAgICAgICB2YXIgc3luY2hyb25pemVkRHVyYXRpb24gPSAwO1xuICAgICAgICAvLyBPbmx5IHNlcnZlci1yZW5kZXJlZCB0cmFuc2NyaXB0IHBhZ2VzIG5hbWUgdGhlaXIgc3RhZ2U7IHRoZSBjYWxsLWhpc3RvcnkgbW9kYWwgbmV2ZXIgdG91Y2hlcyB0aGUgYWRkcmVzcy5cbiAgICAgICAgdmFyIHN0YWdlSWQgPSBTdHJpbmcoJHJvb3QuYXR0cignZGF0YS1zdHQtc3RhZ2UnKSB8fCAnJyk7XG4gICAgICAgIHZhciBsaW5rYWJsZSA9IC9eWzEtOV1bMC05XSokLy50ZXN0KHN0YWdlSWQpO1xuXG4gICAgICAgIGlmICghYXVkaW8gfHwgJHJhbmdlLmxlbmd0aCA9PT0gMCB8fCAkcm9vdC5kYXRhKCdzdHQtcGxheWVyLWluaXRpYWxpemVkJykpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuICAgICAgICAkcm9vdC5kYXRhKCdzdHQtcGxheWVyLWluaXRpYWxpemVkJywgdHJ1ZSk7XG5cbiAgICAgICAgZnVuY3Rpb24gZmFsbGJhY2tEdXJhdGlvbigpIHtcbiAgICAgICAgICAgIHJldHVybiBzYWZlTnVtYmVyKCRwbGF5ZXIuZGF0YSgnZHVyYXRpb24nKSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjdXJyZW50RHVyYXRpb24oKSB7XG4gICAgICAgICAgICByZXR1cm4gTnVtYmVyLmlzRmluaXRlKGF1ZGlvLmR1cmF0aW9uKSAmJiBhdWRpby5kdXJhdGlvbiA+IDAgPyBhdWRpby5kdXJhdGlvbiA6IGZhbGxiYWNrRHVyYXRpb24oKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGVuc3VyZVNvdXJjZSgpIHtcbiAgICAgICAgICAgIGlmIChhdWRpby5nZXRBdHRyaWJ1dGUoJ3NyYycpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB2YXIgc291cmNlID0gU3RyaW5nKGF1ZGlvLmdldEF0dHJpYnV0ZSgnZGF0YS1zcmMnKSB8fCAnJyk7XG4gICAgICAgICAgICBpZiAoIXNvdXJjZSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGF1ZGlvLnNldEF0dHJpYnV0ZSgnc3JjJywgc291cmNlKTtcbiAgICAgICAgICAgIGF1ZGlvLmxvYWQoKTtcblxuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZXRQbGF5SWNvbihwbGF5aW5nKSB7XG4gICAgICAgICAgICAkcGxheUljb24udG9nZ2xlQ2xhc3MoJ3BsYXknLCAhcGxheWluZykudG9nZ2xlQ2xhc3MoJ3BhdXNlJywgcGxheWluZyk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZXRBY3RpdmVUdXJucygpIHtcbiAgICAgICAgICAgIHZhciBjdXJyZW50ID0gYXVkaW8uY3VycmVudFRpbWU7XG4gICAgICAgICAgICAkdHVybnMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyICR0dXJuID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICB2YXIgc3RhcnQgPSBzYWZlTnVtYmVyKCR0dXJuLmRhdGEoJ3N0YXJ0JykpO1xuICAgICAgICAgICAgICAgIHZhciBlbmQgPSBzYWZlTnVtYmVyKCR0dXJuLmRhdGEoJ2VuZCcpKTtcbiAgICAgICAgICAgICAgICAkdHVybi50b2dnbGVDbGFzcygnc3R0LXR1cm4tYWN0aXZlJywgY3VycmVudCA+PSBzdGFydCAmJiBjdXJyZW50IDw9IE1hdGgubWF4KGVuZCwgc3RhcnQgKyAxKSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHN5bmNocm9uaXplVGltZWxpbmUoZHVyYXRpb24pIHtcbiAgICAgICAgICAgIGlmIChkdXJhdGlvbiA8PSAwIHx8IE1hdGguYWJzKGR1cmF0aW9uIC0gc3luY2hyb25pemVkRHVyYXRpb24pIDwgMC4wMSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHN5bmNocm9uaXplZER1cmF0aW9uID0gZHVyYXRpb247XG4gICAgICAgICAgICAkc2NhbGVFbmQudGV4dChmb3JtYXRUaW1lKGR1cmF0aW9uKSk7XG4gICAgICAgICAgICAkc2VnbWVudHMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyICRzZWdtZW50ID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICB2YXIgc3RhcnQgPSBNYXRoLm1pbihzYWZlTnVtYmVyKCRzZWdtZW50LmRhdGEoJ3N0YXJ0JykpLCBkdXJhdGlvbik7XG4gICAgICAgICAgICAgICAgdmFyIGVuZCA9IE1hdGgubWluKE1hdGgubWF4KHNhZmVOdW1iZXIoJHNlZ21lbnQuZGF0YSgnZW5kJykpLCBzdGFydCksIGR1cmF0aW9uKTtcbiAgICAgICAgICAgICAgICAkc2VnbWVudC5jc3Moe1xuICAgICAgICAgICAgICAgICAgICBsZWZ0OiAoKHN0YXJ0IC8gZHVyYXRpb24pICogMTAwKSArICclJyxcbiAgICAgICAgICAgICAgICAgICAgd2lkdGg6ICgoKGVuZCAtIHN0YXJ0KSAvIGR1cmF0aW9uKSAqIDEwMCkgKyAnJSdcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlKCkge1xuICAgICAgICAgICAgdmFyIGR1cmF0aW9uID0gY3VycmVudER1cmF0aW9uKCk7XG4gICAgICAgICAgICB2YXIgdmFsdWUgPSBkdXJhdGlvbiA+IDAgPyBNYXRoLm1pbigxMDAwLCBNYXRoLnJvdW5kKChhdWRpby5jdXJyZW50VGltZSAvIGR1cmF0aW9uKSAqIDEwMDApKSA6IDA7XG4gICAgICAgICAgICBzeW5jaHJvbml6ZVRpbWVsaW5lKGR1cmF0aW9uKTtcbiAgICAgICAgICAgICRyYW5nZS52YWwodmFsdWUpLmF0dHIoJ2FyaWEtdmFsdWV0ZXh0JywgZm9ybWF0VGltZShhdWRpby5jdXJyZW50VGltZSkpO1xuICAgICAgICAgICAgJHBsYXloZWFkLmNzcygnbGVmdCcsICh2YWx1ZSAvIDEwKSArICclJyk7XG4gICAgICAgICAgICAkZHVyYXRpb24udGV4dChmb3JtYXRUaW1lKGF1ZGlvLmN1cnJlbnRUaW1lKSArICcgLyAnICsgKGR1cmF0aW9uID4gMCA/IGZvcm1hdFRpbWUoZHVyYXRpb24pIDogJy0tOi0tJykpO1xuICAgICAgICAgICAgc2V0QWN0aXZlVHVybnMoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHBsYXkoKSB7XG4gICAgICAgICAgICBpZiAoIWVuc3VyZVNvdXJjZSgpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIHJlc3VsdCA9IGF1ZGlvLnBsYXkoKTtcbiAgICAgICAgICAgIGlmIChyZXN1bHQgJiYgdHlwZW9mIHJlc3VsdC5jYXRjaCA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgIHJlc3VsdC5jYXRjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHNldFBsYXlJY29uKGZhbHNlKTtcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNlZWtCeVJhbmdlKHZhbHVlKSB7XG4gICAgICAgICAgICBmdW5jdGlvbiBhcHBseVNlZWsoKSB7XG4gICAgICAgICAgICAgICAgdmFyIGR1cmF0aW9uID0gY3VycmVudER1cmF0aW9uKCk7XG4gICAgICAgICAgICAgICAgaWYgKGR1cmF0aW9uIDw9IDApIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB2YXIgdmFsdWUgPSBwZW5kaW5nUmFuZ2VWYWx1ZTtcbiAgICAgICAgICAgICAgICBwZW5kaW5nUmFuZ2VWYWx1ZSA9IG51bGw7XG4gICAgICAgICAgICAgICAgYXVkaW8uY3VycmVudFRpbWUgPSAoZHVyYXRpb24gKiB2YWx1ZSkgLyAxMDAwO1xuICAgICAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBwZW5kaW5nUmFuZ2VWYWx1ZSA9IHZhbHVlO1xuICAgICAgICAgICAgaWYgKCFlbnN1cmVTb3VyY2UoKSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChhdWRpby5yZWFkeVN0YXRlID49IDEpIHtcbiAgICAgICAgICAgICAgICBhcHBseVNlZWsoKTtcbiAgICAgICAgICAgIH0gZWxzZSBpZiAoIXdhaXRpbmdGb3JNZXRhZGF0YSkge1xuICAgICAgICAgICAgICAgIHdhaXRpbmdGb3JNZXRhZGF0YSA9IHRydWU7XG4gICAgICAgICAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcignbG9hZGVkbWV0YWRhdGEnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHdhaXRpbmdGb3JNZXRhZGF0YSA9IGZhbHNlO1xuICAgICAgICAgICAgICAgICAgICBhcHBseVNlZWsoKTtcbiAgICAgICAgICAgICAgICB9LCB7IG9uY2U6IHRydWUgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZWVrVHVybigkdHVybikge1xuICAgICAgICAgICAgdmFyIHNlY29uZHMgPSBzYWZlTnVtYmVyKCR0dXJuLmRhdGEoJ3N0YXJ0JykpO1xuICAgICAgICAgICAgcmVtZW1iZXJNb21lbnQoc2Vjb25kcyk7XG5cbiAgICAgICAgICAgIGZ1bmN0aW9uIGFwcGx5U2VlaygpIHtcbiAgICAgICAgICAgICAgICBhdWRpby5jdXJyZW50VGltZSA9IE1hdGgubWluKHNlY29uZHMsIGN1cnJlbnREdXJhdGlvbigpIHx8IHNlY29uZHMpO1xuICAgICAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICAgICAgICAgIHBsYXkoKTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgaWYgKCFlbnN1cmVTb3VyY2UoKSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChhdWRpby5yZWFkeVN0YXRlID49IDEpIHtcbiAgICAgICAgICAgICAgICBhcHBseVNlZWsoKTtcbiAgICAgICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcignbG9hZGVkbWV0YWRhdGEnLCBhcHBseVNlZWssIHsgb25jZTogdHJ1ZSB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHN5bmNocm9uaXplKCkge1xuICAgICAgICAgICAgdXBkYXRlKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW1lbWJlck1vbWVudChzZWNvbmRzKSB7XG4gICAgICAgICAgICBpZiAobGlua2FibGUpIHtcbiAgICAgICAgICAgICAgICB0cmFuc2NyaXB0TG9jYXRpb24ud3JpdGUoeyBzdGFnZTogc3RhZ2VJZCwgdDogc2Vjb25kcyB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIC8vIEEgc2hhcmVkIG1vbWVudCBpcyBoZWxkIHdpdGhvdXQgcGxheWluZzogYnJvd3NlcnMgc3RhcnQgYXVkaW8gb25seSBvbiBhIHVzZXIgZ2VzdHVyZS5cbiAgICAgICAgZnVuY3Rpb24gaG9sZE1vbWVudChzZWNvbmRzKSB7XG4gICAgICAgICAgICBmdW5jdGlvbiBhcHBseSgpIHtcbiAgICAgICAgICAgICAgICB2YXIgZHVyYXRpb24gPSBjdXJyZW50RHVyYXRpb24oKTtcbiAgICAgICAgICAgICAgICBhdWRpby5jdXJyZW50VGltZSA9IGR1cmF0aW9uID4gMCA/IE1hdGgubWluKHNlY29uZHMsIE1hdGgubWF4KDAsIGR1cmF0aW9uIC0gMC4wMSkpIDogc2Vjb25kcztcbiAgICAgICAgICAgICAgICB1cGRhdGUoKTtcbiAgICAgICAgICAgICAgICB2YXIgdHVybiA9ICRyb290LmZpbmQoJy5zdHQtdHVybi1hY3RpdmUnKS5nZXQoMCkgfHwgJHJvb3QuZ2V0KDApO1xuICAgICAgICAgICAgICAgIGlmICh0dXJuICYmIHR5cGVvZiB0dXJuLnNjcm9sbEludG9WaWV3ID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgICAgIHR1cm4uc2Nyb2xsSW50b1ZpZXcoeyBibG9jazogJ2NlbnRlcicgfSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBpZiAoIWVuc3VyZVNvdXJjZSgpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGF1ZGlvLnJlYWR5U3RhdGUgPj0gMSkge1xuICAgICAgICAgICAgICAgIGFwcGx5KCk7XG4gICAgICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2xvYWRlZG1ldGFkYXRhJywgYXBwbHksIHsgb25jZTogdHJ1ZSB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgICRwbGF5QnV0dG9uLm9uKCdjbGljay5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaWYgKGF1ZGlvLnBhdXNlZCkge1xuICAgICAgICAgICAgICAgIHBsYXkoKTtcbiAgICAgICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAgICAgYXVkaW8ucGF1c2UoKTtcbiAgICAgICAgICAgICAgICByZW1lbWJlck1vbWVudChhdWRpby5jdXJyZW50VGltZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICAkcmFuZ2Uub24oJ2lucHV0LnN0dFRyYW5zY3JpcHQgY2hhbmdlLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZWVrQnlSYW5nZShzYWZlTnVtYmVyKHRoaXMudmFsdWUpKTtcbiAgICAgICAgfSk7XG4gICAgICAgIC8vIE9ubHkgdGhlIHNldHRsZWQgcG9zaXRpb24gaXMgd3JpdHRlbjsgYnJvd3NlcnMgdGhyb3R0bGUgYWRkcmVzcyB1cGRhdGVzLlxuICAgICAgICAkcmFuZ2Uub24oJ2NoYW5nZS5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgcmVtZW1iZXJNb21lbnQoKGN1cnJlbnREdXJhdGlvbigpICogc2FmZU51bWJlcih0aGlzLnZhbHVlKSkgLyAxMDAwKTtcbiAgICAgICAgfSk7XG4gICAgICAgICRzZWVrLm9uKCdtb3VzZW1vdmUuc3R0VHJhbnNjcmlwdCcsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgdmFyIG9mZnNldCA9ICRzZWVrLm9mZnNldCgpO1xuICAgICAgICAgICAgdmFyIHdpZHRoID0gJHNlZWsud2lkdGgoKTtcbiAgICAgICAgICAgIHZhciBwZXJjZW50ID0gd2lkdGggPiAwID8gTWF0aC5tYXgoMCwgTWF0aC5taW4oMTAwLCAoKGV2ZW50LnBhZ2VYIC0gb2Zmc2V0LmxlZnQpIC8gd2lkdGgpICogMTAwKSkgOiAwO1xuICAgICAgICAgICAgJHRvb2x0aXAudGV4dChmb3JtYXRUaW1lKChjdXJyZW50RHVyYXRpb24oKSAqIHBlcmNlbnQpIC8gMTAwKSkuY3NzKCdsZWZ0JywgcGVyY2VudCArICclJyk7XG4gICAgICAgIH0pO1xuICAgICAgICAkdHVybnMub24oJ2NsaWNrLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZWVrVHVybigkKHRoaXMpKTtcbiAgICAgICAgfSkub24oJ2tleWRvd24uc3R0VHJhbnNjcmlwdCcsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKGV2ZW50LmtleSA9PT0gJ0VudGVyJyB8fCBldmVudC5rZXkgPT09ICcgJykge1xuICAgICAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICAgICAgc2Vla1R1cm4oJCh0aGlzKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuXG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2xvYWRlZG1ldGFkYXRhJywgc3luY2hyb25pemUpO1xuICAgICAgICBhdWRpby5hZGRFdmVudExpc3RlbmVyKCd0aW1ldXBkYXRlJywgdXBkYXRlKTtcbiAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcigncGxheScsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHNldFBsYXlJY29uKHRydWUpO1xuICAgICAgICB9KTtcbiAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcigncGF1c2UnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZXRQbGF5SWNvbihmYWxzZSk7XG4gICAgICAgIH0pO1xuICAgICAgICBhdWRpby5hZGRFdmVudExpc3RlbmVyKCdlbmRlZCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGF1ZGlvLmN1cnJlbnRUaW1lID0gMDtcbiAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICB9KTtcbiAgICAgICAgdXBkYXRlKCk7XG4gICAgICAgIGVuc3VyZVNvdXJjZSgpO1xuICAgICAgICBpZiAobGlua2FibGUpIHtcbiAgICAgICAgICAgIHZhciBtb21lbnQgPSB0cmFuc2NyaXB0TG9jYXRpb24ucmVhZCh3aW5kb3cubG9jYXRpb24uc2VhcmNoKTtcbiAgICAgICAgICAgIGlmIChtb21lbnQuc3RhZ2UgPT09IHN0YWdlSWQpIHtcbiAgICAgICAgICAgICAgICBob2xkTW9tZW50KG1vbWVudC50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemUocm9vdCkge1xuICAgICAgICB2YXIgJHJvb3QgPSByb290ICYmIHJvb3QuanF1ZXJ5ID8gcm9vdCA6ICQocm9vdCk7XG4gICAgICAgIHZhciAkaW5wdXQgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtc2VhcmNoIGlucHV0Jyk7XG4gICAgICAgIHZhciAkZmlsdGVycyA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1maWx0ZXInKTtcbiAgICAgICAgdmFyICRyb3dzID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LXR1cm4nKTtcbiAgICAgICAgdmFyICRnYXBzID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LWdhcCcpO1xuICAgICAgICB2YXIgJGVtcHR5ID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LWVtcHR5Jyk7XG4gICAgICAgIHZhciBhY3RpdmVTcGVha2VyID0gJ2FsbCc7XG5cbiAgICAgICAgaWYgKCRyb290Lmxlbmd0aCA9PT0gMCB8fCAkcm9vdC5kYXRhKCdzdHQtdHJhbnNjcmlwdC1pbml0aWFsaXplZCcpKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgJHJvb3QuZGF0YSgnc3R0LXRyYW5zY3JpcHQtaW5pdGlhbGl6ZWQnLCB0cnVlKTtcblxuICAgICAgICBmdW5jdGlvbiBhcHBseSgpIHtcbiAgICAgICAgICAgIHZhciBxdWVyeSA9IFN0cmluZygkaW5wdXQudmFsKCkgfHwgJycpLnRyaW0oKS50b0xvY2FsZUxvd2VyQ2FzZSgpO1xuICAgICAgICAgICAgdmFyIHZpc2libGUgPSAwO1xuXG4gICAgICAgICAgICAkcm93cy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICB2YXIgJHJvdyA9ICQodGhpcyk7XG4gICAgICAgICAgICAgICAgdmFyIHNwZWFrZXJNYXRjaGVzID0gYWN0aXZlU3BlYWtlciA9PT0gJ2FsbCcgfHwgJHJvdy5kYXRhKCdzcGVha2VyJykgPT09IGFjdGl2ZVNwZWFrZXI7XG4gICAgICAgICAgICAgICAgdmFyIHRleHRNYXRjaGVzID0gcXVlcnkgPT09ICcnIHx8ICRyb3cuZmluZCgnLnN0dC10dXJuLXRleHQnKS50ZXh0KCkudG9Mb2NhbGVMb3dlckNhc2UoKS5pbmRleE9mKHF1ZXJ5KSAhPT0gLTE7XG4gICAgICAgICAgICAgICAgdmFyIHNob3duID0gc3BlYWtlck1hdGNoZXMgJiYgdGV4dE1hdGNoZXM7XG5cbiAgICAgICAgICAgICAgICAkcm93LnByb3AoJ2hpZGRlbicsICFzaG93bik7XG4gICAgICAgICAgICAgICAgaWYgKHNob3duKSB7XG4gICAgICAgICAgICAgICAgICAgIHZpc2libGUgKz0gMTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICRnYXBzLnByb3AoJ2hpZGRlbicsIHF1ZXJ5ICE9PSAnJyB8fCBhY3RpdmVTcGVha2VyICE9PSAnYWxsJyk7XG4gICAgICAgICAgICAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtY291bnQnKS50ZXh0KFN0cmluZyh2aXNpYmxlKSk7XG4gICAgICAgICAgICAkZW1wdHkucHJvcCgnaGlkZGVuJywgdmlzaWJsZSAhPT0gMCk7XG4gICAgICAgIH1cblxuICAgICAgICAkaW5wdXQub24oJ2lucHV0LnN0dFRyYW5zY3JpcHQnLCBhcHBseSk7XG4gICAgICAgICRmaWx0ZXJzLm9uKCdjbGljay5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgYWN0aXZlU3BlYWtlciA9IFN0cmluZygkKHRoaXMpLmRhdGEoJ3NwZWFrZXInKSB8fCAnYWxsJyk7XG4gICAgICAgICAgICAkZmlsdGVycy5yZW1vdmVDbGFzcygnYWN0aXZlJykuYXR0cignYXJpYS1wcmVzc2VkJywgJ2ZhbHNlJyk7XG4gICAgICAgICAgICAkKHRoaXMpLmFkZENsYXNzKCdhY3RpdmUnKS5hdHRyKCdhcmlhLXByZXNzZWQnLCAndHJ1ZScpO1xuICAgICAgICAgICAgYXBwbHkoKTtcbiAgICAgICAgfSk7XG4gICAgICAgIGFwcGx5KCk7XG4gICAgICAgIGluaXRpYWxpemVQbGF5ZXIoJHJvb3QpO1xuICAgIH1cblxuICAgIHdpbmRvdy5TdHRUcmFuc2NyaXB0VmlldyA9IHtcbiAgICAgICAgY3JlYXRlOiBjcmVhdGUsXG4gICAgICAgIGluaXRpYWxpemU6IGluaXRpYWxpemUsXG4gICAgICAgIGxvY2F0aW9uOiB0cmFuc2NyaXB0TG9jYXRpb25cbiAgICB9O1xuXG4gICAgJChmdW5jdGlvbiAoKSB7XG4gICAgICAgICQoJy5zdHQtdHJhbnNjcmlwdC12aWV3JykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpbml0aWFsaXplKHRoaXMpO1xuICAgICAgICB9KTtcbiAgICB9KTtcbn0od2luZG93LCBqUXVlcnkpKTtcbiJdLCJtYXBwaW5ncyI6IjtBQUFDLFdBQVVBLE1BQU0sRUFBRUMsQ0FBQyxFQUFFO0VBQ2xCLFlBQVk7O0VBRVosU0FBU0MsVUFBVUEsQ0FBQ0MsS0FBSyxFQUFFO0lBQ3ZCLElBQUlDLE1BQU0sR0FBR0MsTUFBTSxDQUFDRixLQUFLLENBQUM7SUFFMUIsT0FBT0UsTUFBTSxDQUFDQyxRQUFRLENBQUNGLE1BQU0sQ0FBQyxHQUFHRyxJQUFJLENBQUNDLEdBQUcsQ0FBQyxDQUFDLEVBQUVKLE1BQU0sQ0FBQyxHQUFHLENBQUM7RUFDNUQ7RUFFQSxTQUFTSyxZQUFZQSxDQUFDQyxHQUFHLEVBQUU7SUFDdkIsT0FBT0MsTUFBTSxDQUFDRCxHQUFHLElBQUksRUFBRSxDQUFDLENBQUNFLE9BQU8sQ0FBQyxlQUFlLEVBQUUsR0FBRyxDQUFDLElBQUksTUFBTTtFQUNwRTtFQUVBLFNBQVNDLFlBQVlBLENBQUNDLFFBQVEsRUFBRTtJQUM1QixJQUFJQyxPQUFPLEdBQUdkLENBQUMsQ0FBQyxPQUFPLEVBQUU7TUFBRWUsS0FBSyxFQUFFO0lBQXdCLENBQUMsQ0FBQztJQUU1REYsUUFBUSxDQUFDRyxPQUFPLENBQUMsVUFBVUMsT0FBTyxFQUFFO01BQ2hDSCxPQUFPLENBQUNJLE1BQU0sQ0FDVmxCLENBQUMsQ0FBQyxRQUFRLEVBQUU7UUFBRWUsS0FBSyxFQUFFLHlDQUF5QyxHQUFHUCxZQUFZLENBQUNTLE9BQU8sQ0FBQ1IsR0FBRztNQUFFLENBQUMsQ0FBQyxDQUFDUyxNQUFNLENBQ2hHbEIsQ0FBQyxDQUFDLFFBQVEsRUFBRTtRQUFFZSxLQUFLLEVBQUU7TUFBa0IsQ0FBQyxDQUFDLEVBQ3pDSSxRQUFRLENBQUNDLGNBQWMsQ0FBQ1YsTUFBTSxDQUFDTyxPQUFPLENBQUNJLEtBQUssSUFBSSxFQUFFLENBQUMsQ0FDdkQsQ0FDSixDQUFDO0lBQ0wsQ0FBQyxD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