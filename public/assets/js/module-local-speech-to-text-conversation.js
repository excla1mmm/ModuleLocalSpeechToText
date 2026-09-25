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
  // second the reader stopped at: ?id=<result>&stage=<result>&t=<seconds>.
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
      var stage = String(query.get('stage') || '');
      var seconds = Number(query.get('t'));
      var valid = /^[1-9][0-9]*$/.test(stage) && query.get('t') !== null && Number.isFinite(seconds) && seconds >= 0 && seconds <= 86400;
      return {
        stage: valid ? stage : '',
        t: valid ? Math.floor(seconds) : 0,
        leg: query.get('leg') === 'internal' ? 'internal' : ''
      };
    },
    search: function (search, changes) {
      var query;
      try {
        query = new URLSearchParams(search || '');
      } catch (error) {
        return '';
      }
      if (Object.prototype.hasOwnProperty.call(changes, 'stage')) {
        var seconds = Math.floor(Number(changes.t) || 0);
        if (changes.stage && seconds > 0) {
          query.set('stage', String(changes.stage));
          query.set('t', String(seconds));
        } else {
          query.delete('stage');
          query.delete('t');
        }
      }
      if (Object.prototype.hasOwnProperty.call(changes, 'leg')) {
        if (changes.leg === 'internal') {
          query.set('leg', 'internal');
        } else {
          query.delete('leg');
        }
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

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LWNvbnZlcnNhdGlvbi5qcyIsIm5hbWVzIjpbIndpbmRvdyIsIiQiLCJzYWZlTnVtYmVyIiwidmFsdWUiLCJudW1iZXIiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsIk1hdGgiLCJtYXgiLCJzcGVha2VyQ2xhc3MiLCJrZXkiLCJTdHJpbmciLCJyZXBsYWNlIiwiY3JlYXRlTGVnZW5kIiwic3BlYWtlcnMiLCIkbGVnZW5kIiwiY2xhc3MiLCJmb3JFYWNoIiwic3BlYWtlciIsImFwcGVuZCIsImRvY3VtZW50IiwiY3JlYXRlVGV4dE5vZGUiLCJsYWJlbCIsImNyZWF0ZVBsYXllciIsImxhYmVscyIsImR1cmF0aW9uTGFiZWwiLCIkcGxheWVyIiwiZHVyYXRpb25TZWNvbmRzIiwicHJlbG9hZCIsImF1ZGlvVXJsIiwidHlwZSIsInBsYXkiLCJ0ZXh0IiwiZG93bmxvYWRVcmwiLCJocmVmIiwiZG93bmxvYWQiLCJ0aXRsZSIsImNyZWF0ZU92ZXJ2aWV3IiwiY29udmVyc2F0aW9uIiwiQXJyYXkiLCJpc0FycmF5IiwicGxheWFibGUiLCJCb29sZWFuIiwiJG92ZXJ2aWV3IiwiJG92ZXJ2aWV3SGVhZGVyIiwiJGxhbmVzIiwiJGxhbmUiLCJzZWdtZW50cyIsInR1cm4iLCJzdGFydCIsImVuZCIsImNzcyIsImxlZnQiLCJzdGFydFBlcmNlbnQiLCJ3aWR0aCIsIndpZHRoUGVyY2VudCIsIiRzZWVrIiwiYWRkQ2xhc3MiLCJtaW4iLCJjcmVhdGVUb29sYmFyIiwiJGZpbHRlcnMiLCJyb2xlIiwiYWxsIiwicGxhY2Vob2xkZXIiLCJzZWFyY2giLCJjcmVhdGVUdXJuIiwic3BlYWtlcktleSIsIiRyb3ciLCJ0YWJpbmRleCIsIiRtZXRhIiwic3BlYWtlckxhYmVsIiwic3RhcnRMYWJlbCIsImRpciIsImNyZWF0ZSIsImRhdGEiLCJfdHlwZW9mIiwidGltZWxpbmUiLCJPYmplY3QiLCJhc3NpZ24iLCJlbXB0eSIsIiRyb290IiwiJGxpc3QiLCJnYXBCZWZvcmVMYWJlbCIsImhpZGRlbiIsImluaXRpYWxpemUiLCJmb3JtYXRUaW1lIiwic2Vjb25kcyIsInRvdGFsIiwiZmxvb3IiLCJob3VycyIsIm1pbnV0ZXMiLCJyZXN0IiwicGFkU3RhcnQiLCJ0cmFuc2NyaXB0TG9jYXRpb24iLCJyZWFkIiwicXVlcnkiLCJVUkxTZWFyY2hQYXJhbXMiLCJlcnJvciIsInN0YWdlIiwidCIsImxlZyIsImdldCIsInZhbGlkIiwidGVzdCIsImNoYW5nZXMiLCJwcm90b3R5cGUiLCJoYXNPd25Qcm9wZXJ0eSIsImNhbGwiLCJzZXQiLCJkZWxldGUiLCJyZXN1bHQiLCJ0b1N0cmluZyIsIndyaXRlIiwibG9jYXRpb24iLCJoaXN0b3J5IiwicmVwbGFjZVN0YXRlIiwic3RhdGUiLCJwYXRobmFtZSIsImhhc2giLCJpbml0aWFsaXplUGxheWVyIiwiYXVkaW8iLCJmaW5kIiwiJHBsYXlCdXR0b24iLCIkcGxheUljb24iLCIkZHVyYXRpb24iLCIkcmFuZ2UiLCIkcGxheWhlYWQiLCIkdG9vbHRpcCIsIiRzY2FsZUVuZCIsImxhc3QiLCIkc2VnbWVudHMiLCIkdHVybnMiLCJwZW5kaW5nUmFuZ2VWYWx1ZSIsIndhaXRpbmdGb3JNZXRhZGF0YSIsInN5bmNocm9uaXplZER1cmF0aW9uIiwic3RhZ2VJZCIsImF0dHIiLCJsaW5rYWJsZSIsImxlbmd0aCIsImZhbGxiYWNrRHVyYXRpb24iLCJjdXJyZW50RHVyYXRpb24iLCJkdXJhdGlvbiIsImVuc3VyZVNvdXJjZSIsImdldEF0dHJpYnV0ZSIsInNvdXJjZSIsInNldEF0dHJpYnV0ZSIsImxvYWQiLCJzZXRQbGF5SWNvbiIsInBsYXlpbmciLCJ0b2dnbGVDbGFzcyIsInNldEFjdGl2ZVR1cm5zIiwiY3VycmVudCIsImN1cnJlbnRUaW1lIiwiZWFjaCIsIiR0dXJuIiwic3luY2hyb25pemVUaW1lbGluZSIsImFicyIsIiRzZWdtZW50IiwidXBkYXRlIiwicm91bmQiLCJ2YWwiLCJjYXRjaCIsInNlZWtCeVJhbmdlIiwiYXBwbHlTZWVrIiwicmVhZHlTdGF0ZSIsImFkZEV2ZW50TGlzdGVuZXIiLCJvbmNlIiwic2Vla1R1cm4iLCJyZW1lbWJlck1vbWVudCIsInN5bmNocm9uaXplIiwiaG9sZE1vbWVudCIsImFwcGx5Iiwic2Nyb2xsSW50b1ZpZXciLCJibG9jayIsIm9uIiwicGF1c2VkIiwicGF1c2UiLCJldmVudCIsIm9mZnNldCIsInBlcmNlbnQiLCJwYWdlWCIsInByZXZlbnREZWZhdWx0IiwibW9tZW50Iiwicm9vdCIsImpxdWVyeSIsIiRpbnB1dCIsIiRyb3dzIiwiJGdhcHMiLCIkZW1wdHkiLCJhY3RpdmVTcGVha2VyIiwidHJpbSIsInRvTG9jYWxlTG93ZXJDYXNlIiwidmlzaWJsZSIsInNwZWFrZXJNYXRjaGVzIiwidGV4dE1hdGNoZXMiLCJpbmRleE9mIiwic2hvd24iLCJwcm9wIiwicmVtb3ZlQ2xhc3MiLCJTdHRUcmFuc2NyaXB0VmlldyIsImpRdWVyeSJdLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtY29udmVyc2F0aW9uLmpzIl0sInNvdXJjZXNDb250ZW50IjpbIihmdW5jdGlvbiAod2luZG93LCAkKSB7XG4gICAgJ3VzZSBzdHJpY3QnO1xuXG4gICAgZnVuY3Rpb24gc2FmZU51bWJlcih2YWx1ZSkge1xuICAgICAgICB2YXIgbnVtYmVyID0gTnVtYmVyKHZhbHVlKTtcblxuICAgICAgICByZXR1cm4gTnVtYmVyLmlzRmluaXRlKG51bWJlcikgPyBNYXRoLm1heCgwLCBudW1iZXIpIDogMDtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBzcGVha2VyQ2xhc3Moa2V5KSB7XG4gICAgICAgIHJldHVybiBTdHJpbmcoa2V5IHx8ICcnKS5yZXBsYWNlKC9bXmEtejAtOV8tXS9naSwgJy0nKSB8fCAnbGVmdCc7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlTGVnZW5kKHNwZWFrZXJzKSB7XG4gICAgICAgIHZhciAkbGVnZW5kID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtbGVnZW5kJyB9KTtcblxuICAgICAgICBzcGVha2Vycy5mb3JFYWNoKGZ1bmN0aW9uIChzcGVha2VyKSB7XG4gICAgICAgICAgICAkbGVnZW5kLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtbGVnZW5kLWl0ZW0gc3R0LXNwZWFrZXItJyArIHNwZWFrZXJDbGFzcyhzcGVha2VyLmtleSkgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXNwZWFrZXItZG90JyB9KSxcbiAgICAgICAgICAgICAgICAgICAgZG9jdW1lbnQuY3JlYXRlVGV4dE5vZGUoU3RyaW5nKHNwZWFrZXIubGFiZWwgfHwgJycpKVxuICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHJldHVybiAkbGVnZW5kO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZVBsYXllcihsYWJlbHMsIGR1cmF0aW9uTGFiZWwpIHtcbiAgICAgICAgdmFyICRwbGF5ZXIgPSAkKCc8ZGl2PicsIHtcbiAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtcGxheWVyJyxcbiAgICAgICAgICAgICdkYXRhLWR1cmF0aW9uJzogc2FmZU51bWJlcihsYWJlbHMuZHVyYXRpb25TZWNvbmRzKVxuICAgICAgICB9KS5hcHBlbmQoXG4gICAgICAgICAgICAkKCc8YXVkaW8+Jywge1xuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtYXVkaW8nLFxuICAgICAgICAgICAgICAgIHByZWxvYWQ6ICdtZXRhZGF0YScsXG4gICAgICAgICAgICAgICAgJ2RhdGEtc3JjJzogU3RyaW5nKGxhYmVscy5hdWRpb1VybCB8fCAnJylcbiAgICAgICAgICAgIH0pLFxuICAgICAgICAgICAgJCgnPGJ1dHRvbj4nLCB7XG4gICAgICAgICAgICAgICAgdHlwZTogJ2J1dHRvbicsXG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtcGxheWVyLXBsYXknLFxuICAgICAgICAgICAgICAgICdhcmlhLWxhYmVsJzogbGFiZWxzLnBsYXksXG4gICAgICAgICAgICAgICAgJ2RhdGEtY29udGVudCc6IGxhYmVscy5wbGF5XG4gICAgICAgICAgICB9KS5hcHBlbmQoJCgnPGk+JywgeyBjbGFzczogJ3BsYXkgaWNvbicgfSkpLFxuICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC1wbGF5ZXItZHVyYXRpb24nIH0pLnRleHQoJzAwOjAwIC8gJyArIGR1cmF0aW9uTGFiZWwpXG4gICAgICAgICk7XG5cbiAgICAgICAgaWYgKGxhYmVscy5kb3dubG9hZFVybCkge1xuICAgICAgICAgICAgJHBsYXllci5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGE+Jywge1xuICAgICAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC1wbGF5ZXItZG93bmxvYWQnLFxuICAgICAgICAgICAgICAgICAgICBocmVmOiBTdHJpbmcobGFiZWxzLmRvd25sb2FkVXJsKSxcbiAgICAgICAgICAgICAgICAgICAgJ2RhdGEtY29udGVudCc6IGxhYmVscy5kb3dubG9hZCxcbiAgICAgICAgICAgICAgICAgICAgJ2FyaWEtbGFiZWwnOiBsYWJlbHMuZG93bmxvYWQsXG4gICAgICAgICAgICAgICAgICAgIHRpdGxlOiBsYWJlbHMuZG93bmxvYWRcbiAgICAgICAgICAgICAgICB9KS5hcHBlbmQoJCgnPGk+JywgeyBjbGFzczogJ2Rvd25sb2FkIGljb24nIH0pKVxuICAgICAgICAgICAgKTtcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiAkcGxheWVyO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZU92ZXJ2aWV3KGNvbnZlcnNhdGlvbiwgbGFiZWxzKSB7XG4gICAgICAgIHZhciBzcGVha2VycyA9IEFycmF5LmlzQXJyYXkoY29udmVyc2F0aW9uLnNwZWFrZXJzKSA/IGNvbnZlcnNhdGlvbi5zcGVha2VycyA6IFtdO1xuICAgICAgICB2YXIgZHVyYXRpb25MYWJlbCA9IFN0cmluZyhjb252ZXJzYXRpb24uZHVyYXRpb25MYWJlbCB8fCAnMDowMCcpO1xuICAgICAgICB2YXIgcGxheWFibGUgPSBCb29sZWFuKGxhYmVscy5hdWRpb1VybCk7XG4gICAgICAgIHZhciAkb3ZlcnZpZXcgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1vdmVydmlldycgfSk7XG4gICAgICAgIHZhciAkb3ZlcnZpZXdIZWFkZXIgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1vdmVydmlldy1oZWFkZXInIH0pLmFwcGVuZChcbiAgICAgICAgICAgIHBsYXlhYmxlXG4gICAgICAgICAgICAgICAgPyBjcmVhdGVQbGF5ZXIobGFiZWxzLCBkdXJhdGlvbkxhYmVsKVxuICAgICAgICAgICAgICAgIDogJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWR1cmF0aW9uJyB9KS50ZXh0KGR1cmF0aW9uTGFiZWwpLFxuICAgICAgICAgICAgY3JlYXRlTGVnZW5kKHNwZWFrZXJzKVxuICAgICAgICApO1xuICAgICAgICB2YXIgJGxhbmVzID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtbGFuZXMnIH0pO1xuXG4gICAgICAgIHNwZWFrZXJzLmZvckVhY2goZnVuY3Rpb24gKHNwZWFrZXIpIHtcbiAgICAgICAgICAgIHZhciBrZXkgPSBzcGVha2VyQ2xhc3Moc3BlYWtlci5rZXkpO1xuICAgICAgICAgICAgdmFyICRsYW5lID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtbGFuZSBzdHQtc3BlYWtlci0nICsga2V5IH0pO1xuICAgICAgICAgICAgdmFyIHNlZ21lbnRzID0gQXJyYXkuaXNBcnJheShzcGVha2VyLnNlZ21lbnRzKSA/IHNwZWFrZXIuc2VnbWVudHMgOiBbXTtcblxuICAgICAgICAgICAgc2VnbWVudHMuZm9yRWFjaChmdW5jdGlvbiAodHVybikge1xuICAgICAgICAgICAgICAgICRsYW5lLmFwcGVuZCgkKCc8c3Bhbj4nLCB7XG4gICAgICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtbGFuZS1zZWdtZW50JyxcbiAgICAgICAgICAgICAgICAgICAgJ2RhdGEtc3RhcnQnOiBzYWZlTnVtYmVyKHR1cm4uc3RhcnQpLFxuICAgICAgICAgICAgICAgICAgICAnZGF0YS1lbmQnOiBzYWZlTnVtYmVyKHR1cm4uZW5kKVxuICAgICAgICAgICAgICAgIH0pLmNzcyh7XG4gICAgICAgICAgICAgICAgICAgIGxlZnQ6IHNhZmVOdW1iZXIodHVybi5zdGFydFBlcmNlbnQpICsgJyUnLFxuICAgICAgICAgICAgICAgICAgICB3aWR0aDogc2FmZU51bWJlcih0dXJuLndpZHRoUGVyY2VudCkgKyAnJSdcbiAgICAgICAgICAgICAgICB9KSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICRsYW5lcy5hcHBlbmQoJGxhbmUpO1xuICAgICAgICB9KTtcblxuICAgICAgICB2YXIgJHNlZWsgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1zZWVrJyB9KS5hcHBlbmQoJGxhbmVzKTtcbiAgICAgICAgaWYgKHBsYXlhYmxlKSB7XG4gICAgICAgICAgICAkc2Vlay5hZGRDbGFzcygnaXMtcGxheWFibGUnKS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXBsYXloZWFkJyB9KSxcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtc2Vlay10b29sdGlwJyB9KS50ZXh0KCcwOjAwJyksXG4gICAgICAgICAgICAgICAgJCgnPGlucHV0PicsIHtcbiAgICAgICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1yYW5nZScsXG4gICAgICAgICAgICAgICAgICAgIHR5cGU6ICdyYW5nZScsXG4gICAgICAgICAgICAgICAgICAgIG1pbjogMCxcbiAgICAgICAgICAgICAgICAgICAgbWF4OiAxMDAwLFxuICAgICAgICAgICAgICAgICAgICB2YWx1ZTogMCxcbiAgICAgICAgICAgICAgICAgICAgJ2FyaWEtbGFiZWwnOiBsYWJlbHMucGxheVxuICAgICAgICAgICAgICAgIH0pXG4gICAgICAgICAgICApO1xuICAgICAgICB9XG5cbiAgICAgICAgcmV0dXJuICRvdmVydmlldy5hcHBlbmQoXG4gICAgICAgICAgICAkb3ZlcnZpZXdIZWFkZXIsXG4gICAgICAgICAgICAkc2VlayxcbiAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXNjYWxlJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JykudGV4dCgnMDowMCcpLFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicpLnRleHQoZHVyYXRpb25MYWJlbClcbiAgICAgICAgICAgIClcbiAgICAgICAgKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjcmVhdGVUb29sYmFyKHNwZWFrZXJzLCBsYWJlbHMpIHtcbiAgICAgICAgdmFyICRmaWx0ZXJzID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZmlsdGVycycsIHJvbGU6ICdncm91cCcgfSk7XG5cbiAgICAgICAgJGZpbHRlcnMuYXBwZW5kKCQoJzxidXR0b24+Jywge1xuICAgICAgICAgICAgdHlwZTogJ2J1dHRvbicsXG4gICAgICAgICAgICBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWZpbHRlciBhY3RpdmUnLFxuICAgICAgICAgICAgJ2RhdGEtc3BlYWtlcic6ICdhbGwnLFxuICAgICAgICAgICAgJ2FyaWEtcHJlc3NlZCc6ICd0cnVlJ1xuICAgICAgICB9KS50ZXh0KGxhYmVscy5hbGwpKTtcbiAgICAgICAgc3BlYWtlcnMuZm9yRWFjaChmdW5jdGlvbiAoc3BlYWtlcikge1xuICAgICAgICAgICAgJGZpbHRlcnMuYXBwZW5kKCQoJzxidXR0b24+Jywge1xuICAgICAgICAgICAgICAgIHR5cGU6ICdidXR0b24nLFxuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZmlsdGVyJyxcbiAgICAgICAgICAgICAgICAnZGF0YS1zcGVha2VyJzogc3BlYWtlckNsYXNzKHNwZWFrZXIua2V5KSxcbiAgICAgICAgICAgICAgICAnYXJpYS1wcmVzc2VkJzogJ2ZhbHNlJ1xuICAgICAgICAgICAgfSkudGV4dChTdHJpbmcoc3BlYWtlci5sYWJlbCB8fCAnJykpKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgcmV0dXJuICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXRvb2xiYXInIH0pLmFwcGVuZChcbiAgICAgICAgICAgICQoJzxsYWJlbD4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtc2VhcmNoJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGk+JywgeyBjbGFzczogJ3NlYXJjaCBpY29uJyB9KSxcbiAgICAgICAgICAgICAgICAkKCc8aW5wdXQ+Jywge1xuICAgICAgICAgICAgICAgICAgICB0eXBlOiAnc2VhcmNoJyxcbiAgICAgICAgICAgICAgICAgICAgcGxhY2Vob2xkZXI6IGxhYmVscy5zZWFyY2gsXG4gICAgICAgICAgICAgICAgICAgICdhcmlhLWxhYmVsJzogbGFiZWxzLnNlYXJjaFxuICAgICAgICAgICAgICAgIH0pXG4gICAgICAgICAgICApLFxuICAgICAgICAgICAgJGZpbHRlcnMsXG4gICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtY291bnQnLCAnYXJpYS1saXZlJzogJ3BvbGl0ZScgfSlcbiAgICAgICAgKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjcmVhdGVUdXJuKHR1cm4sIHBsYXlhYmxlKSB7XG4gICAgICAgIHZhciBrZXkgPSBzcGVha2VyQ2xhc3ModHVybi5zcGVha2VyS2V5KTtcbiAgICAgICAgdmFyICRyb3cgPSAkKCc8YXJ0aWNsZT4nLCB7XG4gICAgICAgICAgICBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXR1cm4gc3R0LXR1cm4tJyArIGtleSArIChwbGF5YWJsZSA/ICcgc3R0LXBsYXlhYmxlLXR1cm4nIDogJycpLFxuICAgICAgICAgICAgJ2RhdGEtc3BlYWtlcic6IGtleSxcbiAgICAgICAgICAgICdkYXRhLXN0YXJ0Jzogc2FmZU51bWJlcih0dXJuLnN0YXJ0KSxcbiAgICAgICAgICAgICdkYXRhLWVuZCc6IHNhZmVOdW1iZXIodHVybi5lbmQpLFxuICAgICAgICAgICAgdGFiaW5kZXg6IHBsYXlhYmxlID8gMCA6IG51bGxcbiAgICAgICAgfSk7XG4gICAgICAgIHZhciAkbWV0YSA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10dXJuLW1ldGEnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtc3BlYWtlci1kb3QnIH0pLFxuICAgICAgICAgICAgJCgnPHN0cm9uZz4nLCB7IGNsYXNzOiAnc3R0LXR1cm4tc3BlYWtlcicgfSkudGV4dChTdHJpbmcodHVybi5zcGVha2VyTGFiZWwgfHwgJycpKVxuICAgICAgICApO1xuXG4gICAgICAgIHJldHVybiAkcm93LmFwcGVuZChcbiAgICAgICAgICAgICQoJzx0aW1lPicsIHsgY2xhc3M6ICdzdHQtdHVybi10aW1lJyB9KS50ZXh0KFN0cmluZyh0dXJuLnN0YXJ0TGFiZWwgfHwgJycpKSxcbiAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10dXJuLWNvbnRlbnQnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkbWV0YSxcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHVybi10ZXh0JywgZGlyOiAnYXV0bycgfSkudGV4dChTdHJpbmcodHVybi50ZXh0IHx8ICcnKSlcbiAgICAgICAgICAgIClcbiAgICAgICAgKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjcmVhdGUoY29udmVyc2F0aW9uLCBsYWJlbHMpIHtcbiAgICAgICAgdmFyIGRhdGEgPSBjb252ZXJzYXRpb24gJiYgdHlwZW9mIGNvbnZlcnNhdGlvbiA9PT0gJ29iamVjdCcgPyBjb252ZXJzYXRpb24gOiB7fTtcbiAgICAgICAgdmFyIHNwZWFrZXJzID0gQXJyYXkuaXNBcnJheShkYXRhLnNwZWFrZXJzKSA/IGRhdGEuc3BlYWtlcnMgOiBbXTtcbiAgICAgICAgdmFyIHRpbWVsaW5lID0gQXJyYXkuaXNBcnJheShkYXRhLnRpbWVsaW5lKSA/IGRhdGEudGltZWxpbmUgOiBbXTtcbiAgICAgICAgdmFyIHRleHQgPSBPYmplY3QuYXNzaWduKHsgYWxsOiAnQWxsJywgc2VhcmNoOiAnU2VhcmNoJywgZW1wdHk6ICcnLCBwbGF5OiAnJywgZG93bmxvYWQ6ICcnIH0sIGxhYmVscyB8fCB7fSk7XG4gICAgICAgIHZhciBwbGF5YWJsZSA9IEJvb2xlYW4odGV4dC5hdWRpb1VybCk7XG4gICAgICAgIHZhciAkcm9vdCA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXZpZXcnIH0pO1xuICAgICAgICB2YXIgJGxpc3QgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1saXN0JyB9KTtcblxuICAgICAgICB0aW1lbGluZS5mb3JFYWNoKGZ1bmN0aW9uICh0dXJuKSB7XG4gICAgICAgICAgICBpZiAoIXR1cm4gfHwgdHlwZW9mIHR1cm4gIT09ICdvYmplY3QnKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKHR1cm4uZ2FwQmVmb3JlTGFiZWwpIHtcbiAgICAgICAgICAgICAgICAkbGlzdC5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWdhcCcgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAgICAgJCgnPHNwYW4+JykudGV4dCgnwrfCt8K3ICcgKyBTdHJpbmcodHVybi5nYXBCZWZvcmVMYWJlbCkpXG4gICAgICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICAgICApO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgJGxpc3QuYXBwZW5kKGNyZWF0ZVR1cm4odHVybiwgcGxheWFibGUpKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJHJvb3QuYXBwZW5kKFxuICAgICAgICAgICAgY3JlYXRlT3ZlcnZpZXcoZGF0YSwgdGV4dCksXG4gICAgICAgICAgICBjcmVhdGVUb29sYmFyKHNwZWFrZXJzLCB0ZXh0KSxcbiAgICAgICAgICAgICRsaXN0LFxuICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZW1wdHkgdWkgbWVzc2FnZScsIGhpZGRlbjogdHJ1ZSB9KS50ZXh0KHRleHQuZW1wdHkpXG4gICAgICAgICk7XG4gICAgICAgIGluaXRpYWxpemUoJHJvb3QpO1xuXG4gICAgICAgIHJldHVybiAkcm9vdDtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBmb3JtYXRUaW1lKHNlY29uZHMpIHtcbiAgICAgICAgdmFyIHRvdGFsID0gTWF0aC5mbG9vcihNYXRoLm1heCgwLCBzZWNvbmRzIHx8IDApKTtcbiAgICAgICAgdmFyIGhvdXJzID0gTWF0aC5mbG9vcih0b3RhbCAvIDM2MDApO1xuICAgICAgICB2YXIgbWludXRlcyA9IE1hdGguZmxvb3IoKHRvdGFsICUgMzYwMCkgLyA2MCk7XG4gICAgICAgIHZhciByZXN0ID0gdG90YWwgJSA2MDtcblxuICAgICAgICBpZiAoaG91cnMgPiAwKSB7XG4gICAgICAgICAgICByZXR1cm4gaG91cnMgKyAnOicgKyBTdHJpbmcobWludXRlcykucGFkU3RhcnQoMiwgJzAnKSArICc6JyArIFN0cmluZyhyZXN0KS5wYWRTdGFydCgyLCAnMCcpO1xuICAgICAgICB9XG5cbiAgICAgICAgcmV0dXJuIE1hdGguZmxvb3IodG90YWwgLyA2MCkgKyAnOicgKyBTdHJpbmcocmVzdCkucGFkU3RhcnQoMiwgJzAnKTtcbiAgICB9XG5cbiAgICAvLyBBIHRyYW5zY3JpcHQgcGFnZSBhZGRyZXNzIG1heSBjYXJyeSB0aGUgcmVjb3JkaW5nIChzdGFnZSByZXN1bHQgaWQpIGFuZFxuICAgIC8vIHNlY29uZCB0aGUgcmVhZGVyIHN0b3BwZWQgYXQ6ID9pZD08cmVzdWx0PiZzdGFnZT08cmVzdWx0PiZ0PTxzZWNvbmRzPi5cbiAgICB2YXIgdHJhbnNjcmlwdExvY2F0aW9uID0ge1xuICAgICAgICByZWFkOiBmdW5jdGlvbiAoc2VhcmNoKSB7XG4gICAgICAgICAgICB2YXIgcXVlcnk7XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIHF1ZXJ5ID0gbmV3IFVSTFNlYXJjaFBhcmFtcyhzZWFyY2ggfHwgJycpO1xuICAgICAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4geyBzdGFnZTogJycsIHQ6IDAsIGxlZzogJycgfTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHZhciBzdGFnZSA9IFN0cmluZyhxdWVyeS5nZXQoJ3N0YWdlJykgfHwgJycpO1xuICAgICAgICAgICAgdmFyIHNlY29uZHMgPSBOdW1iZXIocXVlcnkuZ2V0KCd0JykpO1xuICAgICAgICAgICAgdmFyIHZhbGlkID0gL15bMS05XVswLTldKiQvLnRlc3Qoc3RhZ2UpICYmIHF1ZXJ5LmdldCgndCcpICE9PSBudWxsICYmIE51bWJlci5pc0Zpbml0ZShzZWNvbmRzKSAmJiBzZWNvbmRzID49IDAgJiYgc2Vjb25kcyA8PSA4NjQwMDtcblxuICAgICAgICAgICAgcmV0dXJuIHtcbiAgICAgICAgICAgICAgICBzdGFnZTogdmFsaWQgPyBzdGFnZSA6ICcnLFxuICAgICAgICAgICAgICAgIHQ6IHZhbGlkID8gTWF0aC5mbG9vcihzZWNvbmRzKSA6IDAsXG4gICAgICAgICAgICAgICAgbGVnOiBxdWVyeS5nZXQoJ2xlZycpID09PSAnaW50ZXJuYWwnID8gJ2ludGVybmFsJyA6ICcnXG4gICAgICAgICAgICB9O1xuICAgICAgICB9LFxuICAgICAgICBzZWFyY2g6IGZ1bmN0aW9uIChzZWFyY2gsIGNoYW5nZXMpIHtcbiAgICAgICAgICAgIHZhciBxdWVyeTtcbiAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgcXVlcnkgPSBuZXcgVVJMU2VhcmNoUGFyYW1zKHNlYXJjaCB8fCAnJyk7XG4gICAgICAgICAgICB9IGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgICAgIHJldHVybiAnJztcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoY2hhbmdlcywgJ3N0YWdlJykpIHtcbiAgICAgICAgICAgICAgICB2YXIgc2Vjb25kcyA9IE1hdGguZmxvb3IoTnVtYmVyKGNoYW5nZXMudCkgfHwgMCk7XG4gICAgICAgICAgICAgICAgaWYgKGNoYW5nZXMuc3RhZ2UgJiYgc2Vjb25kcyA+IDApIHtcbiAgICAgICAgICAgICAgICAgICAgcXVlcnkuc2V0KCdzdGFnZScsIFN0cmluZyhjaGFuZ2VzLnN0YWdlKSk7XG4gICAgICAgICAgICAgICAgICAgIHF1ZXJ5LnNldCgndCcsIFN0cmluZyhzZWNvbmRzKSk7XG4gICAgICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgcXVlcnkuZGVsZXRlKCdzdGFnZScpO1xuICAgICAgICAgICAgICAgICAgICBxdWVyeS5kZWxldGUoJ3QnKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKGNoYW5nZXMsICdsZWcnKSkge1xuICAgICAgICAgICAgICAgIGlmIChjaGFuZ2VzLmxlZyA9PT0gJ2ludGVybmFsJykge1xuICAgICAgICAgICAgICAgICAgICBxdWVyeS5zZXQoJ2xlZycsICdpbnRlcm5hbCcpO1xuICAgICAgICAgICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIHF1ZXJ5LmRlbGV0ZSgnbGVnJyk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIHJlc3VsdCA9IHF1ZXJ5LnRvU3RyaW5nKCk7XG5cbiAgICAgICAgICAgIHJldHVybiByZXN1bHQgPT09ICcnID8gJycgOiAnPycgKyByZXN1bHQ7XG4gICAgICAgIH0sXG4gICAgICAgIHdyaXRlOiBmdW5jdGlvbiAoY2hhbmdlcykge1xuICAgICAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICAgICB2YXIgbG9jYXRpb24gPSB3aW5kb3cubG9jYXRpb247XG4gICAgICAgICAgICAgICAgd2luZG93Lmhpc3RvcnkucmVwbGFjZVN0YXRlKHdpbmRvdy5oaXN0b3J5LnN0YXRlLCAnJywgbG9jYXRpb24ucGF0aG5hbWUgKyB0cmFuc2NyaXB0TG9jYXRpb24uc2VhcmNoKGxvY2F0aW9uLnNlYXJjaCwgY2hhbmdlcykgKyBsb2NhdGlvbi5oYXNoKTtcbiAgICAgICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICAgICAgLy8gVGhlIGFkZHJlc3MgaXMgYSBjb252ZW5pZW5jZTsgcGxheWJhY2sgbmV2ZXIgZGVwZW5kcyBvbiBpdC5cbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH07XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplUGxheWVyKCRyb290KSB7XG4gICAgICAgIHZhciBhdWRpbyA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1hdWRpbycpLmdldCgwKTtcbiAgICAgICAgdmFyICRwbGF5ZXIgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtcGxheWVyJyk7XG4gICAgICAgIHZhciAkcGxheUJ1dHRvbiA9ICRwbGF5ZXIuZmluZCgnLnN0dC1wbGF5ZXItcGxheScpO1xuICAgICAgICB2YXIgJHBsYXlJY29uID0gJHBsYXlCdXR0b24uZmluZCgnaScpO1xuICAgICAgICB2YXIgJGR1cmF0aW9uID0gJHBsYXllci5maW5kKCcuc3R0LXBsYXllci1kdXJhdGlvbicpO1xuICAgICAgICB2YXIgJHNlZWsgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtc2Vlay5pcy1wbGF5YWJsZScpO1xuICAgICAgICB2YXIgJHJhbmdlID0gJHNlZWsuZmluZCgnLnN0dC10cmFuc2NyaXB0LXJhbmdlJyk7XG4gICAgICAgIHZhciAkcGxheWhlYWQgPSAkc2Vlay5maW5kKCcuc3R0LXRyYW5zY3JpcHQtcGxheWhlYWQnKTtcbiAgICAgICAgdmFyICR0b29sdGlwID0gJHNlZWsuZmluZCgnLnN0dC10cmFuc2NyaXB0LXNlZWstdG9vbHRpcCcpO1xuICAgICAgICB2YXIgJHNjYWxlRW5kID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LXNjYWxlIHNwYW4nKS5sYXN0KCk7XG4gICAgICAgIHZhciAkc2VnbWVudHMgPSAkc2Vlay5maW5kKCcuc3R0LXRyYW5zY3JpcHQtbGFuZS1zZWdtZW50Jyk7XG4gICAgICAgIHZhciAkdHVybnMgPSAkcm9vdC5maW5kKCcuc3R0LXBsYXlhYmxlLXR1cm4nKTtcbiAgICAgICAgdmFyIHBlbmRpbmdSYW5nZVZhbHVlID0gbnVsbDtcbiAgICAgICAgdmFyIHdhaXRpbmdGb3JNZXRhZGF0YSA9IGZhbHNlO1xuICAgICAgICB2YXIgc3luY2hyb25pemVkRHVyYXRpb24gPSAwO1xuICAgICAgICAvLyBPbmx5IHNlcnZlci1yZW5kZXJlZCB0cmFuc2NyaXB0IHBhZ2VzIG5hbWUgdGhlaXIgc3RhZ2U7IHRoZSBjYWxsLWhpc3RvcnkgbW9kYWwgbmV2ZXIgdG91Y2hlcyB0aGUgYWRkcmVzcy5cbiAgICAgICAgdmFyIHN0YWdlSWQgPSBTdHJpbmcoJHJvb3QuYXR0cignZGF0YS1zdHQtc3RhZ2UnKSB8fCAnJyk7XG4gICAgICAgIHZhciBsaW5rYWJsZSA9IC9eWzEtOV1bMC05XSokLy50ZXN0KHN0YWdlSWQpO1xuXG4gICAgICAgIGlmICghYXVkaW8gfHwgJHJhbmdlLmxlbmd0aCA9PT0gMCB8fCAkcm9vdC5kYXRhKCdzdHQtcGxheWVyLWluaXRpYWxpemVkJykpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuICAgICAgICAkcm9vdC5kYXRhKCdzdHQtcGxheWVyLWluaXRpYWxpemVkJywgdHJ1ZSk7XG5cbiAgICAgICAgZnVuY3Rpb24gZmFsbGJhY2tEdXJhdGlvbigpIHtcbiAgICAgICAgICAgIHJldHVybiBzYWZlTnVtYmVyKCRwbGF5ZXIuZGF0YSgnZHVyYXRpb24nKSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjdXJyZW50RHVyYXRpb24oKSB7XG4gICAgICAgICAgICByZXR1cm4gTnVtYmVyLmlzRmluaXRlKGF1ZGlvLmR1cmF0aW9uKSAmJiBhdWRpby5kdXJhdGlvbiA+IDAgPyBhdWRpby5kdXJhdGlvbiA6IGZhbGxiYWNrRHVyYXRpb24oKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGVuc3VyZVNvdXJjZSgpIHtcbiAgICAgICAgICAgIGlmIChhdWRpby5nZXRBdHRyaWJ1dGUoJ3NyYycpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB2YXIgc291cmNlID0gU3RyaW5nKGF1ZGlvLmdldEF0dHJpYnV0ZSgnZGF0YS1zcmMnKSB8fCAnJyk7XG4gICAgICAgICAgICBpZiAoIXNvdXJjZSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGF1ZGlvLnNldEF0dHJpYnV0ZSgnc3JjJywgc291cmNlKTtcbiAgICAgICAgICAgIGF1ZGlvLmxvYWQoKTtcblxuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZXRQbGF5SWNvbihwbGF5aW5nKSB7XG4gICAgICAgICAgICAkcGxheUljb24udG9nZ2xlQ2xhc3MoJ3BsYXknLCAhcGxheWluZykudG9nZ2xlQ2xhc3MoJ3BhdXNlJywgcGxheWluZyk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZXRBY3RpdmVUdXJucygpIHtcbiAgICAgICAgICAgIHZhciBjdXJyZW50ID0gYXVkaW8uY3VycmVudFRpbWU7XG4gICAgICAgICAgICAkdHVybnMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyICR0dXJuID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICB2YXIgc3RhcnQgPSBzYWZlTnVtYmVyKCR0dXJuLmRhdGEoJ3N0YXJ0JykpO1xuICAgICAgICAgICAgICAgIHZhciBlbmQgPSBzYWZlTnVtYmVyKCR0dXJuLmRhdGEoJ2VuZCcpKTtcbiAgICAgICAgICAgICAgICAkdHVybi50b2dnbGVDbGFzcygnc3R0LXR1cm4tYWN0aXZlJywgY3VycmVudCA+PSBzdGFydCAmJiBjdXJyZW50IDw9IE1hdGgubWF4KGVuZCwgc3RhcnQgKyAxKSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHN5bmNocm9uaXplVGltZWxpbmUoZHVyYXRpb24pIHtcbiAgICAgICAgICAgIGlmIChkdXJhdGlvbiA8PSAwIHx8IE1hdGguYWJzKGR1cmF0aW9uIC0gc3luY2hyb25pemVkRHVyYXRpb24pIDwgMC4wMSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHN5bmNocm9uaXplZER1cmF0aW9uID0gZHVyYXRpb247XG4gICAgICAgICAgICAkc2NhbGVFbmQudGV4dChmb3JtYXRUaW1lKGR1cmF0aW9uKSk7XG4gICAgICAgICAgICAkc2VnbWVudHMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyICRzZWdtZW50ID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICB2YXIgc3RhcnQgPSBNYXRoLm1pbihzYWZlTnVtYmVyKCRzZWdtZW50LmRhdGEoJ3N0YXJ0JykpLCBkdXJhdGlvbik7XG4gICAgICAgICAgICAgICAgdmFyIGVuZCA9IE1hdGgubWluKE1hdGgubWF4KHNhZmVOdW1iZXIoJHNlZ21lbnQuZGF0YSgnZW5kJykpLCBzdGFydCksIGR1cmF0aW9uKTtcbiAgICAgICAgICAgICAgICAkc2VnbWVudC5jc3Moe1xuICAgICAgICAgICAgICAgICAgICBsZWZ0OiAoKHN0YXJ0IC8gZHVyYXRpb24pICogMTAwKSArICclJyxcbiAgICAgICAgICAgICAgICAgICAgd2lkdGg6ICgoKGVuZCAtIHN0YXJ0KSAvIGR1cmF0aW9uKSAqIDEwMCkgKyAnJSdcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlKCkge1xuICAgICAgICAgICAgdmFyIGR1cmF0aW9uID0gY3VycmVudER1cmF0aW9uKCk7XG4gICAgICAgICAgICB2YXIgdmFsdWUgPSBkdXJhdGlvbiA+IDAgPyBNYXRoLm1pbigxMDAwLCBNYXRoLnJvdW5kKChhdWRpby5jdXJyZW50VGltZSAvIGR1cmF0aW9uKSAqIDEwMDApKSA6IDA7XG4gICAgICAgICAgICBzeW5jaHJvbml6ZVRpbWVsaW5lKGR1cmF0aW9uKTtcbiAgICAgICAgICAgICRyYW5nZS52YWwodmFsdWUpLmF0dHIoJ2FyaWEtdmFsdWV0ZXh0JywgZm9ybWF0VGltZShhdWRpby5jdXJyZW50VGltZSkpO1xuICAgICAgICAgICAgJHBsYXloZWFkLmNzcygnbGVmdCcsICh2YWx1ZSAvIDEwKSArICclJyk7XG4gICAgICAgICAgICAkZHVyYXRpb24udGV4dChmb3JtYXRUaW1lKGF1ZGlvLmN1cnJlbnRUaW1lKSArICcgLyAnICsgKGR1cmF0aW9uID4gMCA/IGZvcm1hdFRpbWUoZHVyYXRpb24pIDogJy0tOi0tJykpO1xuICAgICAgICAgICAgc2V0QWN0aXZlVHVybnMoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHBsYXkoKSB7XG4gICAgICAgICAgICBpZiAoIWVuc3VyZVNvdXJjZSgpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIHJlc3VsdCA9IGF1ZGlvLnBsYXkoKTtcbiAgICAgICAgICAgIGlmIChyZXN1bHQgJiYgdHlwZW9mIHJlc3VsdC5jYXRjaCA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgIHJlc3VsdC5jYXRjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHNldFBsYXlJY29uKGZhbHNlKTtcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNlZWtCeVJhbmdlKHZhbHVlKSB7XG4gICAgICAgICAgICBmdW5jdGlvbiBhcHBseVNlZWsoKSB7XG4gICAgICAgICAgICAgICAgdmFyIGR1cmF0aW9uID0gY3VycmVudER1cmF0aW9uKCk7XG4gICAgICAgICAgICAgICAgaWYgKGR1cmF0aW9uIDw9IDApIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB2YXIgdmFsdWUgPSBwZW5kaW5nUmFuZ2VWYWx1ZTtcbiAgICAgICAgICAgICAgICBwZW5kaW5nUmFuZ2VWYWx1ZSA9IG51bGw7XG4gICAgICAgICAgICAgICAgYXVkaW8uY3VycmVudFRpbWUgPSAoZHVyYXRpb24gKiB2YWx1ZSkgLyAxMDAwO1xuICAgICAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBwZW5kaW5nUmFuZ2VWYWx1ZSA9IHZhbHVlO1xuICAgICAgICAgICAgaWYgKCFlbnN1cmVTb3VyY2UoKSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChhdWRpby5yZWFkeVN0YXRlID49IDEpIHtcbiAgICAgICAgICAgICAgICBhcHBseVNlZWsoKTtcbiAgICAgICAgICAgIH0gZWxzZSBpZiAoIXdhaXRpbmdGb3JNZXRhZGF0YSkge1xuICAgICAgICAgICAgICAgIHdhaXRpbmdGb3JNZXRhZGF0YSA9IHRydWU7XG4gICAgICAgICAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcignbG9hZGVkbWV0YWRhdGEnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHdhaXRpbmdGb3JNZXRhZGF0YSA9IGZhbHNlO1xuICAgICAgICAgICAgICAgICAgICBhcHBseVNlZWsoKTtcbiAgICAgICAgICAgICAgICB9LCB7IG9uY2U6IHRydWUgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZWVrVHVybigkdHVybikge1xuICAgICAgICAgICAgdmFyIHNlY29uZHMgPSBzYWZlTnVtYmVyKCR0dXJuLmRhdGEoJ3N0YXJ0JykpO1xuICAgICAgICAgICAgcmVtZW1iZXJNb21lbnQoc2Vjb25kcyk7XG5cbiAgICAgICAgICAgIGZ1bmN0aW9uIGFwcGx5U2VlaygpIHtcbiAgICAgICAgICAgICAgICBhdWRpby5jdXJyZW50VGltZSA9IE1hdGgubWluKHNlY29uZHMsIGN1cnJlbnREdXJhdGlvbigpIHx8IHNlY29uZHMpO1xuICAgICAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICAgICAgICAgIHBsYXkoKTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgaWYgKCFlbnN1cmVTb3VyY2UoKSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChhdWRpby5yZWFkeVN0YXRlID49IDEpIHtcbiAgICAgICAgICAgICAgICBhcHBseVNlZWsoKTtcbiAgICAgICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcignbG9hZGVkbWV0YWRhdGEnLCBhcHBseVNlZWssIHsgb25jZTogdHJ1ZSB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHN5bmNocm9uaXplKCkge1xuICAgICAgICAgICAgdXBkYXRlKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW1lbWJlck1vbWVudChzZWNvbmRzKSB7XG4gICAgICAgICAgICBpZiAobGlua2FibGUpIHtcbiAgICAgICAgICAgICAgICB0cmFuc2NyaXB0TG9jYXRpb24ud3JpdGUoeyBzdGFnZTogc3RhZ2VJZCwgdDogc2Vjb25kcyB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIC8vIEEgc2hhcmVkIG1vbWVudCBpcyBoZWxkIHdpdGhvdXQgcGxheWluZzogYnJvd3NlcnMgc3RhcnQgYXVkaW8gb25seSBvbiBhIHVzZXIgZ2VzdHVyZS5cbiAgICAgICAgZnVuY3Rpb24gaG9sZE1vbWVudChzZWNvbmRzKSB7XG4gICAgICAgICAgICBmdW5jdGlvbiBhcHBseSgpIHtcbiAgICAgICAgICAgICAgICB2YXIgZHVyYXRpb24gPSBjdXJyZW50RHVyYXRpb24oKTtcbiAgICAgICAgICAgICAgICBhdWRpby5jdXJyZW50VGltZSA9IGR1cmF0aW9uID4gMCA/IE1hdGgubWluKHNlY29uZHMsIE1hdGgubWF4KDAsIGR1cmF0aW9uIC0gMC4wMSkpIDogc2Vjb25kcztcbiAgICAgICAgICAgICAgICB1cGRhdGUoKTtcbiAgICAgICAgICAgICAgICB2YXIgdHVybiA9ICRyb290LmZpbmQoJy5zdHQtdHVybi1hY3RpdmUnKS5nZXQoMCkgfHwgJHJvb3QuZ2V0KDApO1xuICAgICAgICAgICAgICAgIGlmICh0dXJuICYmIHR5cGVvZiB0dXJuLnNjcm9sbEludG9WaWV3ID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgICAgIHR1cm4uc2Nyb2xsSW50b1ZpZXcoeyBibG9jazogJ2NlbnRlcicgfSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBpZiAoIWVuc3VyZVNvdXJjZSgpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGF1ZGlvLnJlYWR5U3RhdGUgPj0gMSkge1xuICAgICAgICAgICAgICAgIGFwcGx5KCk7XG4gICAgICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2xvYWRlZG1ldGFkYXRhJywgYXBwbHksIHsgb25jZTogdHJ1ZSB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgICRwbGF5QnV0dG9uLm9uKCdjbGljay5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaWYgKGF1ZGlvLnBhdXNlZCkge1xuICAgICAgICAgICAgICAgIHBsYXkoKTtcbiAgICAgICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAgICAgYXVkaW8ucGF1c2UoKTtcbiAgICAgICAgICAgICAgICByZW1lbWJlck1vbWVudChhdWRpby5jdXJyZW50VGltZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICAkcmFuZ2Uub24oJ2lucHV0LnN0dFRyYW5zY3JpcHQgY2hhbmdlLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZWVrQnlSYW5nZShzYWZlTnVtYmVyKHRoaXMudmFsdWUpKTtcbiAgICAgICAgfSk7XG4gICAgICAgIC8vIE9ubHkgdGhlIHNldHRsZWQgcG9zaXRpb24gaXMgd3JpdHRlbjsgYnJvd3NlcnMgdGhyb3R0bGUgYWRkcmVzcyB1cGRhdGVzLlxuICAgICAgICAkcmFuZ2Uub24oJ2NoYW5nZS5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgcmVtZW1iZXJNb21lbnQoKGN1cnJlbnREdXJhdGlvbigpICogc2FmZU51bWJlcih0aGlzLnZhbHVlKSkgLyAxMDAwKTtcbiAgICAgICAgfSk7XG4gICAgICAgICRzZWVrLm9uKCdtb3VzZW1vdmUuc3R0VHJhbnNjcmlwdCcsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgdmFyIG9mZnNldCA9ICRzZWVrLm9mZnNldCgpO1xuICAgICAgICAgICAgdmFyIHdpZHRoID0gJHNlZWsud2lkdGgoKTtcbiAgICAgICAgICAgIHZhciBwZXJjZW50ID0gd2lkdGggPiAwID8gTWF0aC5tYXgoMCwgTWF0aC5taW4oMTAwLCAoKGV2ZW50LnBhZ2VYIC0gb2Zmc2V0LmxlZnQpIC8gd2lkdGgpICogMTAwKSkgOiAwO1xuICAgICAgICAgICAgJHRvb2x0aXAudGV4dChmb3JtYXRUaW1lKChjdXJyZW50RHVyYXRpb24oKSAqIHBlcmNlbnQpIC8gMTAwKSkuY3NzKCdsZWZ0JywgcGVyY2VudCArICclJyk7XG4gICAgICAgIH0pO1xuICAgICAgICAkdHVybnMub24oJ2NsaWNrLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZWVrVHVybigkKHRoaXMpKTtcbiAgICAgICAgfSkub24oJ2tleWRvd24uc3R0VHJhbnNjcmlwdCcsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKGV2ZW50LmtleSA9PT0gJ0VudGVyJyB8fCBldmVudC5rZXkgPT09ICcgJykge1xuICAgICAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICAgICAgc2Vla1R1cm4oJCh0aGlzKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuXG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2xvYWRlZG1ldGFkYXRhJywgc3luY2hyb25pemUpO1xuICAgICAgICBhdWRpby5hZGRFdmVudExpc3RlbmVyKCd0aW1ldXBkYXRlJywgdXBkYXRlKTtcbiAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcigncGxheScsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHNldFBsYXlJY29uKHRydWUpO1xuICAgICAgICB9KTtcbiAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcigncGF1c2UnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZXRQbGF5SWNvbihmYWxzZSk7XG4gICAgICAgIH0pO1xuICAgICAgICBhdWRpby5hZGRFdmVudExpc3RlbmVyKCdlbmRlZCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGF1ZGlvLmN1cnJlbnRUaW1lID0gMDtcbiAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICB9KTtcbiAgICAgICAgdXBkYXRlKCk7XG4gICAgICAgIGVuc3VyZVNvdXJjZSgpO1xuICAgICAgICBpZiAobGlua2FibGUpIHtcbiAgICAgICAgICAgIHZhciBtb21lbnQgPSB0cmFuc2NyaXB0TG9jYXRpb24ucmVhZCh3aW5kb3cubG9jYXRpb24uc2VhcmNoKTtcbiAgICAgICAgICAgIGlmIChtb21lbnQuc3RhZ2UgPT09IHN0YWdlSWQpIHtcbiAgICAgICAgICAgICAgICBob2xkTW9tZW50KG1vbWVudC50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemUocm9vdCkge1xuICAgICAgICB2YXIgJHJvb3QgPSByb290ICYmIHJvb3QuanF1ZXJ5ID8gcm9vdCA6ICQocm9vdCk7XG4gICAgICAgIHZhciAkaW5wdXQgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtc2VhcmNoIGlucHV0Jyk7XG4gICAgICAgIHZhciAkZmlsdGVycyA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1maWx0ZXInKTtcbiAgICAgICAgdmFyICRyb3dzID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LXR1cm4nKTtcbiAgICAgICAgdmFyICRnYXBzID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LWdhcCcpO1xuICAgICAgICB2YXIgJGVtcHR5ID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LWVtcHR5Jyk7XG4gICAgICAgIHZhciBhY3RpdmVTcGVha2VyID0gJ2FsbCc7XG5cbiAgICAgICAgaWYgKCRyb290Lmxlbmd0aCA9PT0gMCB8fCAkcm9vdC5kYXRhKCdzdHQtdHJhbnNjcmlwdC1pbml0aWFsaXplZCcpKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgJHJvb3QuZGF0YSgnc3R0LXRyYW5zY3JpcHQtaW5pdGlhbGl6ZWQnLCB0cnVlKTtcblxuICAgICAgICBmdW5jdGlvbiBhcHBseSgpIHtcbiAgICAgICAgICAgIHZhciBxdWVyeSA9IFN0cmluZygkaW5wdXQudmFsKCkgfHwgJycpLnRyaW0oKS50b0xvY2FsZUxvd2VyQ2FzZSgpO1xuICAgICAgICAgICAgdmFyIHZpc2libGUgPSAwO1xuXG4gICAgICAgICAgICAkcm93cy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICB2YXIgJHJvdyA9ICQodGhpcyk7XG4gICAgICAgICAgICAgICAgdmFyIHNwZWFrZXJNYXRjaGVzID0gYWN0aXZlU3BlYWtlciA9PT0gJ2FsbCcgfHwgJHJvdy5kYXRhKCdzcGVha2VyJykgPT09IGFjdGl2ZVNwZWFrZXI7XG4gICAgICAgICAgICAgICAgdmFyIHRleHRNYXRjaGVzID0gcXVlcnkgPT09ICcnIHx8ICRyb3cuZmluZCgnLnN0dC10dXJuLXRleHQnKS50ZXh0KCkudG9Mb2NhbGVMb3dlckNhc2UoKS5pbmRleE9mKHF1ZXJ5KSAhPT0gLTE7XG4gICAgICAgICAgICAgICAgdmFyIHNob3duID0gc3BlYWtlck1hdGNoZXMgJiYgdGV4dE1hdGNoZXM7XG5cbiAgICAgICAgICAgICAgICAkcm93LnByb3AoJ2hpZGRlbicsICFzaG93bik7XG4gICAgICAgICAgICAgICAgaWYgKHNob3duKSB7XG4gICAgICAgICAgICAgICAgICAgIHZpc2libGUgKz0gMTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICRnYXBzLnByb3AoJ2hpZGRlbicsIHF1ZXJ5ICE9PSAnJyB8fCBhY3RpdmVTcGVha2VyICE9PSAnYWxsJyk7XG4gICAgICAgICAgICAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtY291bnQnKS50ZXh0KFN0cmluZyh2aXNpYmxlKSk7XG4gICAgICAgICAgICAkZW1wdHkucHJvcCgnaGlkZGVuJywgdmlzaWJsZSAhPT0gMCk7XG4gICAgICAgIH1cblxuICAgICAgICAkaW5wdXQub24oJ2lucHV0LnN0dFRyYW5zY3JpcHQnLCBhcHBseSk7XG4gICAgICAgICRmaWx0ZXJzLm9uKCdjbGljay5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgYWN0aXZlU3BlYWtlciA9IFN0cmluZygkKHRoaXMpLmRhdGEoJ3NwZWFrZXInKSB8fCAnYWxsJyk7XG4gICAgICAgICAgICAkZmlsdGVycy5yZW1vdmVDbGFzcygnYWN0aXZlJykuYXR0cignYXJpYS1wcmVzc2VkJywgJ2ZhbHNlJyk7XG4gICAgICAgICAgICAkKHRoaXMpLmFkZENsYXNzKCdhY3RpdmUnKS5hdHRyKCdhcmlhLXByZXNzZWQnLCAndHJ1ZScpO1xuICAgICAgICAgICAgYXBwbHkoKTtcbiAgICAgICAgfSk7XG4gICAgICAgIGFwcGx5KCk7XG4gICAgICAgIGluaXRpYWxpemVQbGF5ZXIoJHJvb3QpO1xuICAgIH1cblxuICAgIHdpbmRvdy5TdHRUcmFuc2NyaXB0VmlldyA9IHtcbiAgICAgICAgY3JlYXRlOiBjcmVhdGUsXG4gICAgICAgIGluaXRpYWxpemU6IGluaXRpYWxpemUsXG4gICAgICAgIGxvY2F0aW9uOiB0cmFuc2NyaXB0TG9jYXRpb25cbiAgICB9O1xuXG4gICAgJChmdW5jdGlvbiAoKSB7XG4gICAgICAgICQoJy5zdHQtdHJhbnNjcmlwdC12aWV3JykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpbml0aWFsaXplKHRoaXMpO1xuICAgICAgICB9KTtcbiAgICB9KTtcbn0od2luZG93LCBq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