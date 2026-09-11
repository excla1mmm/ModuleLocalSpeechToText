"use strict";

function _typeof(obj) { "@babel/helpers - typeof"; if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof = function _typeof(obj) { return typeof obj; }; } else { _typeof = function _typeof(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof(obj); }

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
      "class": 'stt-transcript-legend'
    });
    speakers.forEach(function (speaker) {
      $legend.append($('<span>', {
        "class": 'stt-transcript-legend-item stt-speaker-' + speakerClass(speaker.key)
      }).append($('<span>', {
        "class": 'stt-speaker-dot'
      }), document.createTextNode(String(speaker.label || ''))));
    });
    return $legend;
  }

  function createPlayer(labels, durationLabel) {
    var $player = $('<div>', {
      "class": 'stt-transcript-player',
      'data-duration': safeNumber(labels.durationSeconds)
    }).append($('<audio>', {
      "class": 'stt-transcript-audio',
      preload: 'metadata',
      'data-src': String(labels.audioUrl || '')
    }), $('<button>', {
      type: 'button',
      "class": 'stt-player-play',
      'aria-label': labels.play,
      'data-content': labels.play
    }).append($('<i>', {
      "class": 'play icon'
    })), $('<span>', {
      "class": 'stt-player-duration'
    }).text('00:00 / ' + durationLabel));

    if (labels.downloadUrl) {
      $player.append($('<a>', {
        "class": 'stt-player-download',
        href: String(labels.downloadUrl),
        'data-content': labels.download
      }).append($('<i>', {
        "class": 'download icon'
      })));
    }

    return $player;
  }

  function createOverview(conversation, labels) {
    var speakers = Array.isArray(conversation.speakers) ? conversation.speakers : [];
    var durationLabel = String(conversation.durationLabel || '0:00');
    var playable = Boolean(labels.audioUrl);
    var $overview = $('<div>', {
      "class": 'stt-transcript-overview'
    });
    var $overviewHeader = $('<div>', {
      "class": 'stt-transcript-overview-header'
    }).append(playable ? createPlayer(labels, durationLabel) : $('<span>', {
      "class": 'stt-transcript-duration'
    }).text(durationLabel), createLegend(speakers));
    var $lanes = $('<div>', {
      "class": 'stt-transcript-lanes'
    });
    speakers.forEach(function (speaker) {
      var key = speakerClass(speaker.key);
      var $lane = $('<div>', {
        "class": 'stt-transcript-lane stt-speaker-' + key
      });
      var segments = Array.isArray(speaker.segments) ? speaker.segments : [];
      segments.forEach(function (turn) {
        $lane.append($('<span>', {
          "class": 'stt-transcript-lane-segment',
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
      "class": 'stt-transcript-seek'
    }).append($lanes);

    if (playable) {
      $seek.addClass('is-playable').append($('<span>', {
        "class": 'stt-transcript-playhead'
      }), $('<span>', {
        "class": 'stt-transcript-seek-tooltip'
      }).text('0:00'), $('<input>', {
        "class": 'stt-transcript-range',
        type: 'range',
        min: 0,
        max: 1000,
        value: 0,
        'aria-label': labels.play
      }));
    }

    return $overview.append($overviewHeader, $seek, $('<div>', {
      "class": 'stt-transcript-scale'
    }).append($('<span>').text('0:00'), $('<span>').text(durationLabel)));
  }

  function createToolbar(speakers, labels) {
    var $filters = $('<div>', {
      "class": 'stt-transcript-filters',
      role: 'group'
    });
    $filters.append($('<button>', {
      type: 'button',
      "class": 'stt-transcript-filter active',
      'data-speaker': 'all',
      'aria-pressed': 'true'
    }).text(labels.all));
    speakers.forEach(function (speaker) {
      $filters.append($('<button>', {
        type: 'button',
        "class": 'stt-transcript-filter',
        'data-speaker': speakerClass(speaker.key),
        'aria-pressed': 'false'
      }).text(String(speaker.label || '')));
    });
    return $('<div>', {
      "class": 'stt-transcript-toolbar'
    }).append($('<label>', {
      "class": 'stt-transcript-search'
    }).append($('<i>', {
      "class": 'search icon'
    }), $('<input>', {
      type: 'search',
      placeholder: labels.search,
      'aria-label': labels.search
    })), $filters, $('<span>', {
      "class": 'stt-transcript-count',
      'aria-live': 'polite'
    }));
  }

  function createTurn(turn, playable) {
    var key = speakerClass(turn.speakerKey);
    var $row = $('<article>', {
      "class": 'stt-transcript-turn stt-turn-' + key + (playable ? ' stt-playable-turn' : ''),
      'data-speaker': key,
      'data-start': safeNumber(turn.start),
      'data-end': safeNumber(turn.end),
      tabindex: playable ? 0 : null
    });
    var $meta = $('<div>', {
      "class": 'stt-turn-meta'
    }).append($('<span>', {
      "class": 'stt-speaker-dot'
    }), $('<strong>', {
      "class": 'stt-turn-speaker'
    }).text(String(turn.speakerLabel || '')), $('<span>', {
      "class": 'stt-turn-duration'
    }).text(String(turn.durationLabel || '')));
    return $row.append($('<time>', {
      "class": 'stt-turn-time'
    }).text(String(turn.startLabel || '')), $('<div>', {
      "class": 'stt-turn-content'
    }).append($meta, $('<div>', {
      "class": 'stt-turn-text',
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
      "class": 'stt-transcript-view'
    });
    var $list = $('<div>', {
      "class": 'stt-transcript-list'
    });
    timeline.forEach(function (turn) {
      if (!turn || _typeof(turn) !== 'object') {
        return;
      }

      if (turn.gapBeforeLabel) {
        $list.append($('<div>', {
          "class": 'stt-transcript-gap'
        }).append($('<span>').text('··· ' + String(turn.gapBeforeLabel))));
      }

      $list.append(createTurn(turn, playable));
    });
    $root.append(createOverview(data, text), createToolbar(speakers, text), $list, $('<div>', {
      "class": 'stt-transcript-empty ui message',
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

      if (result && typeof result["catch"] === 'function') {
        result["catch"](function () {
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

    $playButton.on('click.sttTranscript', function () {
      if (audio.paused) {
        play();
      } else {
        audio.pause();
      }
    });
    $range.on('input.sttTranscript change.sttTranscript', function () {
      seekByRange(safeNumber(this.value));
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
    initialize: initialize
  };
  $(function () {
    $('.stt-transcript-view').each(function () {
      initialize(this);
    });
  });
})(window, jQuery);
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtY29udmVyc2F0aW9uLmpzIl0sIm5hbWVzIjpbIndpbmRvdyIsIiQiLCJzYWZlTnVtYmVyIiwidmFsdWUiLCJudW1iZXIiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsIk1hdGgiLCJtYXgiLCJzcGVha2VyQ2xhc3MiLCJrZXkiLCJTdHJpbmciLCJyZXBsYWNlIiwiY3JlYXRlTGVnZW5kIiwic3BlYWtlcnMiLCIkbGVnZW5kIiwiZm9yRWFjaCIsInNwZWFrZXIiLCJhcHBlbmQiLCJkb2N1bWVudCIsImNyZWF0ZVRleHROb2RlIiwibGFiZWwiLCJjcmVhdGVQbGF5ZXIiLCJsYWJlbHMiLCJkdXJhdGlvbkxhYmVsIiwiJHBsYXllciIsImR1cmF0aW9uU2Vjb25kcyIsInByZWxvYWQiLCJhdWRpb1VybCIsInR5cGUiLCJwbGF5IiwidGV4dCIsImRvd25sb2FkVXJsIiwiaHJlZiIsImRvd25sb2FkIiwiY3JlYXRlT3ZlcnZpZXciLCJjb252ZXJzYXRpb24iLCJBcnJheSIsImlzQXJyYXkiLCJwbGF5YWJsZSIsIkJvb2xlYW4iLCIkb3ZlcnZpZXciLCIkb3ZlcnZpZXdIZWFkZXIiLCIkbGFuZXMiLCIkbGFuZSIsInNlZ21lbnRzIiwidHVybiIsInN0YXJ0IiwiZW5kIiwiY3NzIiwibGVmdCIsInN0YXJ0UGVyY2VudCIsIndpZHRoIiwid2lkdGhQZXJjZW50IiwiJHNlZWsiLCJhZGRDbGFzcyIsIm1pbiIsImNyZWF0ZVRvb2xiYXIiLCIkZmlsdGVycyIsInJvbGUiLCJhbGwiLCJwbGFjZWhvbGRlciIsInNlYXJjaCIsImNyZWF0ZVR1cm4iLCJzcGVha2VyS2V5IiwiJHJvdyIsInRhYmluZGV4IiwiJG1ldGEiLCJzcGVha2VyTGFiZWwiLCJzdGFydExhYmVsIiwiZGlyIiwiY3JlYXRlIiwiZGF0YSIsInRpbWVsaW5lIiwiT2JqZWN0IiwiYXNzaWduIiwiZW1wdHkiLCIkcm9vdCIsIiRsaXN0IiwiZ2FwQmVmb3JlTGFiZWwiLCJoaWRkZW4iLCJpbml0aWFsaXplIiwiZm9ybWF0VGltZSIsInNlY29uZHMiLCJ0b3RhbCIsImZsb29yIiwiaG91cnMiLCJtaW51dGVzIiwicmVzdCIsInBhZFN0YXJ0IiwiaW5pdGlhbGl6ZVBsYXllciIsImF1ZGlvIiwiZmluZCIsImdldCIsIiRwbGF5QnV0dG9uIiwiJHBsYXlJY29uIiwiJGR1cmF0aW9uIiwiJHJhbmdlIiwiJHBsYXloZWFkIiwiJHRvb2x0aXAiLCIkc2NhbGVFbmQiLCJsYXN0IiwiJHNlZ21lbnRzIiwiJHR1cm5zIiwicGVuZGluZ1JhbmdlVmFsdWUiLCJ3YWl0aW5nRm9yTWV0YWRhdGEiLCJzeW5jaHJvbml6ZWREdXJhdGlvbiIsImxlbmd0aCIsImZhbGxiYWNrRHVyYXRpb24iLCJjdXJyZW50RHVyYXRpb24iLCJkdXJhdGlvbiIsImVuc3VyZVNvdXJjZSIsImdldEF0dHJpYnV0ZSIsInNvdXJjZSIsInNldEF0dHJpYnV0ZSIsImxvYWQiLCJzZXRQbGF5SWNvbiIsInBsYXlpbmciLCJ0b2dnbGVDbGFzcyIsInNldEFjdGl2ZVR1cm5zIiwiY3VycmVudCIsImN1cnJlbnRUaW1lIiwiZWFjaCIsIiR0dXJuIiwic3luY2hyb25pemVUaW1lbGluZSIsImFicyIsIiRzZWdtZW50IiwidXBkYXRlIiwicm91bmQiLCJ2YWwiLCJhdHRyIiwicmVzdWx0Iiwic2Vla0J5UmFuZ2UiLCJhcHBseVNlZWsiLCJyZWFkeVN0YXRlIiwiYWRkRXZlbnRMaXN0ZW5lciIsIm9uY2UiLCJzZWVrVHVybiIsInN5bmNocm9uaXplIiwib24iLCJwYXVzZWQiLCJwYXVzZSIsImV2ZW50Iiwib2Zmc2V0IiwicGVyY2VudCIsInBhZ2VYIiwicHJldmVudERlZmF1bHQiLCJyb290IiwianF1ZXJ5IiwiJGlucHV0IiwiJHJvd3MiLCIkZ2FwcyIsIiRlbXB0eSIsImFjdGl2ZVNwZWFrZXIiLCJhcHBseSIsInF1ZXJ5IiwidHJpbSIsInRvTG9jYWxlTG93ZXJDYXNlIiwidmlzaWJsZSIsInNwZWFrZXJNYXRjaGVzIiwidGV4dE1hdGNoZXMiLCJpbmRleE9mIiwic2hvd24iLCJwcm9wIiwicmVtb3ZlQ2xhc3MiLCJTdHRUcmFuc2NyaXB0VmlldyIsImpRdWVyeSJdLCJtYXBwaW5ncyI6Ijs7OztBQUFDLFdBQVVBLE1BQVYsRUFBa0JDLENBQWxCLEVBQXFCO0FBQ2xCOztBQUVBLFdBQVNDLFVBQVQsQ0FBb0JDLEtBQXBCLEVBQTJCO0FBQ3ZCLFFBQUlDLE1BQU0sR0FBR0MsTUFBTSxDQUFDRixLQUFELENBQW5CO0FBRUEsV0FBT0UsTUFBTSxDQUFDQyxRQUFQLENBQWdCRixNQUFoQixJQUEwQkcsSUFBSSxDQUFDQyxHQUFMLENBQVMsQ0FBVCxFQUFZSixNQUFaLENBQTFCLEdBQWdELENBQXZEO0FBQ0g7O0FBRUQsV0FBU0ssWUFBVCxDQUFzQkMsR0FBdEIsRUFBMkI7QUFDdkIsV0FBT0MsTUFBTSxDQUFDRCxHQUFHLElBQUksRUFBUixDQUFOLENBQWtCRSxPQUFsQixDQUEwQixlQUExQixFQUEyQyxHQUEzQyxLQUFtRCxNQUExRDtBQUNIOztBQUVELFdBQVNDLFlBQVQsQ0FBc0JDLFFBQXRCLEVBQWdDO0FBQzVCLFFBQUlDLE9BQU8sR0FBR2QsQ0FBQyxDQUFDLE9BQUQsRUFBVTtBQUFFLGVBQU87QUFBVCxLQUFWLENBQWY7QUFFQWEsSUFBQUEsUUFBUSxDQUFDRSxPQUFULENBQWlCLFVBQVVDLE9BQVYsRUFBbUI7QUFDaENGLE1BQUFBLE9BQU8sQ0FBQ0csTUFBUixDQUNJakIsQ0FBQyxDQUFDLFFBQUQsRUFBVztBQUFFLGlCQUFPLDRDQUE0Q1EsWUFBWSxDQUFDUSxPQUFPLENBQUNQLEdBQVQ7QUFBakUsT0FBWCxDQUFELENBQThGUSxNQUE5RixDQUNJakIsQ0FBQyxDQUFDLFFBQUQsRUFBVztBQUFFLGlCQUFPO0FBQVQsT0FBWCxDQURMLEVBRUlrQixRQUFRLENBQUNDLGNBQVQsQ0FBd0JULE1BQU0sQ0FBQ00sT0FBTyxDQUFDSSxLQUFSLElBQWlCLEVBQWxCLENBQTlCLENBRkosQ0FESjtBQU1ILEtBUEQ7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QUFNLEVBQUVBLE1BRGU7QUFFdkJVLElBQUFBLFVBQVUsRUFBRUE7QUFGVyxHQUEzQjtBQUtBaEYsRUFBQUEsQ0FBQyxDQUFDLFlBQVk7QUFDVkEsSUFBQUEsQ0FBQyxDQUFDLHNCQUFELENBQUQsQ0FBMEJ5SCxJQUExQixDQUErQixZQUFZO0FBQ3ZDekMsTUFBQUEsVUFBVSxDQUFDLElBQUQsQ0FBVjtBQUNILEtBRkQ7QUFHSCxHQUpBLENBQUQ7QUFLSCxDQS9jQSxFQStjQ2pGLE1BL2NELEVBK2NTc0ssTUEvY1QsQ0FBRCIsInNvdXJjZXNDb250ZW50IjpbIihmdW5jdGlvbiAod2luZG93LCAkKSB7XG4gICAgJ3VzZSBzdHJpY3QnO1xuXG4gICAgZnVuY3Rpb24gc2FmZU51bWJlcih2YWx1ZSkge1xuICAgICAgICB2YXIgbnVtYmVyID0gTnVtYmVyKHZhbHVlKTtcblxuICAgICAgICByZXR1cm4gTnVtYmVyLmlzRmluaXRlKG51bWJlcikgPyBNYXRoLm1heCgwLCBudW1iZXIpIDogMDtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBzcGVha2VyQ2xhc3Moa2V5KSB7XG4gICAgICAgIHJldHVybiBTdHJpbmcoa2V5IHx8ICcnKS5yZXBsYWNlKC9bXmEtejAtOV8tXS9naSwgJy0nKSB8fCAnbGVmdCc7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlTGVnZW5kKHNwZWFrZXJzKSB7XG4gICAgICAgIHZhciAkbGVnZW5kID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtbGVnZW5kJyB9KTtcblxuICAgICAgICBzcGVha2Vycy5mb3JFYWNoKGZ1bmN0aW9uIChzcGVha2VyKSB7XG4gICAgICAgICAgICAkbGVnZW5kLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtbGVnZW5kLWl0ZW0gc3R0LXNwZWFrZXItJyArIHNwZWFrZXJDbGFzcyhzcGVha2VyLmtleSkgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXNwZWFrZXItZG90JyB9KSxcbiAgICAgICAgICAgICAgICAgICAgZG9jdW1lbnQuY3JlYXRlVGV4dE5vZGUoU3RyaW5nKHNwZWFrZXIubGFiZWwgfHwgJycpKVxuICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHJldHVybiAkbGVnZW5kO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZVBsYXllcihsYWJlbHMsIGR1cmF0aW9uTGFiZWwpIHtcbiAgICAgICAgdmFyICRwbGF5ZXIgPSAkKCc8ZGl2PicsIHtcbiAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtcGxheWVyJyxcbiAgICAgICAgICAgICdkYXRhLWR1cmF0aW9uJzogc2FmZU51bWJlcihsYWJlbHMuZHVyYXRpb25TZWNvbmRzKVxuICAgICAgICB9KS5hcHBlbmQoXG4gICAgICAgICAgICAkKCc8YXVkaW8+Jywge1xuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtYXVkaW8nLFxuICAgICAgICAgICAgICAgIHByZWxvYWQ6ICdtZXRhZGF0YScsXG4gICAgICAgICAgICAgICAgJ2RhdGEtc3JjJzogU3RyaW5nKGxhYmVscy5hdWRpb1VybCB8fCAnJylcbiAgICAgICAgICAgIH0pLFxuICAgICAgICAgICAgJCgnPGJ1dHRvbj4nLCB7XG4gICAgICAgICAgICAgICAgdHlwZTogJ2J1dHRvbicsXG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtcGxheWVyLXBsYXknLFxuICAgICAgICAgICAgICAgICdhcmlhLWxhYmVsJzogbGFiZWxzLnBsYXksXG4gICAgICAgICAgICAgICAgJ2RhdGEtY29udGVudCc6IGxhYmVscy5wbGF5XG4gICAgICAgICAgICB9KS5hcHBlbmQoJCgnPGk+JywgeyBjbGFzczogJ3BsYXkgaWNvbicgfSkpLFxuICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC1wbGF5ZXItZHVyYXRpb24nIH0pLnRleHQoJzAwOjAwIC8gJyArIGR1cmF0aW9uTGFiZWwpXG4gICAgICAgICk7XG5cbiAgICAgICAgaWYgKGxhYmVscy5kb3dubG9hZFVybCkge1xuICAgICAgICAgICAgJHBsYXllci5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGE+Jywge1xuICAgICAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC1wbGF5ZXItZG93bmxvYWQnLFxuICAgICAgICAgICAgICAgICAgICBocmVmOiBTdHJpbmcobGFiZWxzLmRvd25sb2FkVXJsKSxcbiAgICAgICAgICAgICAgICAgICAgJ2RhdGEtY29udGVudCc6IGxhYmVscy5kb3dubG9hZFxuICAgICAgICAgICAgICAgIH0pLmFwcGVuZCgkKCc8aT4nLCB7IGNsYXNzOiAnZG93bmxvYWQgaWNvbicgfSkpXG4gICAgICAgICAgICApO1xuICAgICAgICB9XG5cbiAgICAgICAgcmV0dXJuICRwbGF5ZXI7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlT3ZlcnZpZXcoY29udmVyc2F0aW9uLCBsYWJlbHMpIHtcbiAgICAgICAgdmFyIHNwZWFrZXJzID0gQXJyYXkuaXNBcnJheShjb252ZXJzYXRpb24uc3BlYWtlcnMpID8gY29udmVyc2F0aW9uLnNwZWFrZXJzIDogW107XG4gICAgICAgIHZhciBkdXJhdGlvbkxhYmVsID0gU3RyaW5nKGNvbnZlcnNhdGlvbi5kdXJhdGlvbkxhYmVsIHx8ICcwOjAwJyk7XG4gICAgICAgIHZhciBwbGF5YWJsZSA9IEJvb2xlYW4obGFiZWxzLmF1ZGlvVXJsKTtcbiAgICAgICAgdmFyICRvdmVydmlldyA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LW92ZXJ2aWV3JyB9KTtcbiAgICAgICAgdmFyICRvdmVydmlld0hlYWRlciA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LW92ZXJ2aWV3LWhlYWRlcicgfSkuYXBwZW5kKFxuICAgICAgICAgICAgcGxheWFibGVcbiAgICAgICAgICAgICAgICA/IGNyZWF0ZVBsYXllcihsYWJlbHMsIGR1cmF0aW9uTGFiZWwpXG4gICAgICAgICAgICAgICAgOiAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZHVyYXRpb24nIH0pLnRleHQoZHVyYXRpb25MYWJlbCksXG4gICAgICAgICAgICBjcmVhdGVMZWdlbmQoc3BlYWtlcnMpXG4gICAgICAgICk7XG4gICAgICAgIHZhciAkbGFuZXMgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sYW5lcycgfSk7XG5cbiAgICAgICAgc3BlYWtlcnMuZm9yRWFjaChmdW5jdGlvbiAoc3BlYWtlcikge1xuICAgICAgICAgICAgdmFyIGtleSA9IHNwZWFrZXJDbGFzcyhzcGVha2VyLmtleSk7XG4gICAgICAgICAgICB2YXIgJGxhbmUgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sYW5lIHN0dC1zcGVha2VyLScgKyBrZXkgfSk7XG4gICAgICAgICAgICB2YXIgc2VnbWVudHMgPSBBcnJheS5pc0FycmF5KHNwZWFrZXIuc2VnbWVudHMpID8gc3BlYWtlci5zZWdtZW50cyA6IFtdO1xuXG4gICAgICAgICAgICBzZWdtZW50cy5mb3JFYWNoKGZ1bmN0aW9uICh0dXJuKSB7XG4gICAgICAgICAgICAgICAgJGxhbmUuYXBwZW5kKCQoJzxzcGFuPicsIHtcbiAgICAgICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sYW5lLXNlZ21lbnQnLFxuICAgICAgICAgICAgICAgICAgICAnZGF0YS1zdGFydCc6IHNhZmVOdW1iZXIodHVybi5zdGFydCksXG4gICAgICAgICAgICAgICAgICAgICdkYXRhLWVuZCc6IHNhZmVOdW1iZXIodHVybi5lbmQpXG4gICAgICAgICAgICAgICAgfSkuY3NzKHtcbiAgICAgICAgICAgICAgICAgICAgbGVmdDogc2FmZU51bWJlcih0dXJuLnN0YXJ0UGVyY2VudCkgKyAnJScsXG4gICAgICAgICAgICAgICAgICAgIHdpZHRoOiBzYWZlTnVtYmVyKHR1cm4ud2lkdGhQZXJjZW50KSArICclJ1xuICAgICAgICAgICAgICAgIH0pKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJGxhbmVzLmFwcGVuZCgkbGFuZSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHZhciAkc2VlayA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXNlZWsnIH0pLmFwcGVuZCgkbGFuZXMpO1xuICAgICAgICBpZiAocGxheWFibGUpIHtcbiAgICAgICAgICAgICRzZWVrLmFkZENsYXNzKCdpcy1wbGF5YWJsZScpLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtcGxheWhlYWQnIH0pLFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1zZWVrLXRvb2x0aXAnIH0pLnRleHQoJzA6MDAnKSxcbiAgICAgICAgICAgICAgICAkKCc8aW5wdXQ+Jywge1xuICAgICAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXJhbmdlJyxcbiAgICAgICAgICAgICAgICAgICAgdHlwZTogJ3JhbmdlJyxcbiAgICAgICAgICAgICAgICAgICAgbWluOiAwLFxuICAgICAgICAgICAgICAgICAgICBtYXg6IDEwMDAsXG4gICAgICAgICAgICAgICAgICAgIHZhbHVlOiAwLFxuICAgICAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5wbGF5XG4gICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICk7XG4gICAgICAgIH1cblxuICAgICAgICByZXR1cm4gJG92ZXJ2aWV3LmFwcGVuZChcbiAgICAgICAgICAgICRvdmVydmlld0hlYWRlcixcbiAgICAgICAgICAgICRzZWVrLFxuICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtc2NhbGUnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nKS50ZXh0KCcwOjAwJyksXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JykudGV4dChkdXJhdGlvbkxhYmVsKVxuICAgICAgICAgICAgKVxuICAgICAgICApO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZVRvb2xiYXIoc3BlYWtlcnMsIGxhYmVscykge1xuICAgICAgICB2YXIgJGZpbHRlcnMgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1maWx0ZXJzJywgcm9sZTogJ2dyb3VwJyB9KTtcblxuICAgICAgICAkZmlsdGVycy5hcHBlbmQoJCgnPGJ1dHRvbj4nLCB7XG4gICAgICAgICAgICB0eXBlOiAnYnV0dG9uJyxcbiAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZmlsdGVyIGFjdGl2ZScsXG4gICAgICAgICAgICAnZGF0YS1zcGVha2VyJzogJ2FsbCcsXG4gICAgICAgICAgICAnYXJpYS1wcmVzc2VkJzogJ3RydWUnXG4gICAgICAgIH0pLnRleHQobGFiZWxzLmFsbCkpO1xuICAgICAgICBzcGVha2Vycy5mb3JFYWNoKGZ1bmN0aW9uIChzcGVha2VyKSB7XG4gICAgICAgICAgICAkZmlsdGVycy5hcHBlbmQoJCgnPGJ1dHRvbj4nLCB7XG4gICAgICAgICAgICAgICAgdHlwZTogJ2J1dHRvbicsXG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1maWx0ZXInLFxuICAgICAgICAgICAgICAgICdkYXRhLXNwZWFrZXInOiBzcGVha2VyQ2xhc3Moc3BlYWtlci5rZXkpLFxuICAgICAgICAgICAgICAgICdhcmlhLXByZXNzZWQnOiAnZmFsc2UnXG4gICAgICAgICAgICB9KS50ZXh0KFN0cmluZyhzcGVha2VyLmxhYmVsIHx8ICcnKSkpO1xuICAgICAgICB9KTtcblxuICAgICAgICByZXR1cm4gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtdG9vbGJhcicgfSkuYXBwZW5kKFxuICAgICAgICAgICAgJCgnPGxhYmVsPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1zZWFyY2gnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8aT4nLCB7IGNsYXNzOiAnc2VhcmNoIGljb24nIH0pLFxuICAgICAgICAgICAgICAgICQoJzxpbnB1dD4nLCB7XG4gICAgICAgICAgICAgICAgICAgIHR5cGU6ICdzZWFyY2gnLFxuICAgICAgICAgICAgICAgICAgICBwbGFjZWhvbGRlcjogbGFiZWxzLnNlYXJjaCxcbiAgICAgICAgICAgICAgICAgICAgJ2FyaWEtbGFiZWwnOiBsYWJlbHMuc2VhcmNoXG4gICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICksXG4gICAgICAgICAgICAkZmlsdGVycyxcbiAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1jb3VudCcsICdhcmlhLWxpdmUnOiAncG9saXRlJyB9KVxuICAgICAgICApO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNyZWF0ZVR1cm4odHVybiwgcGxheWFibGUpIHtcbiAgICAgICAgdmFyIGtleSA9IHNwZWFrZXJDbGFzcyh0dXJuLnNwZWFrZXJLZXkpO1xuICAgICAgICB2YXIgJHJvdyA9ICQoJzxhcnRpY2xlPicsIHtcbiAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtdHVybiBzdHQtdHVybi0nICsga2V5ICsgKHBsYXlhYmxlID8gJyBzdHQtcGxheWFibGUtdHVybicgOiAnJyksXG4gICAgICAgICAgICAnZGF0YS1zcGVha2VyJzoga2V5LFxuICAgICAgICAgICAgJ2RhdGEtc3RhcnQnOiBzYWZlTnVtYmVyKHR1cm4uc3RhcnQpLFxuICAgICAgICAgICAgJ2RhdGEtZW5kJzogc2FmZU51bWJlcih0dXJuLmVuZCksXG4gICAgICAgICAgICB0YWJpbmRleDogcGxheWFibGUgPyAwIDogbnVsbFxuICAgICAgICB9KTtcbiAgICAgICAgdmFyICRtZXRhID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXR1cm4tbWV0YScgfSkuYXBwZW5kKFxuICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC1zcGVha2VyLWRvdCcgfSksXG4gICAgICAgICAgICAkKCc8c3Ryb25nPicsIHsgY2xhc3M6ICdzdHQtdHVybi1zcGVha2VyJyB9KS50ZXh0KFN0cmluZyh0dXJuLnNwZWFrZXJMYWJlbCB8fCAnJykpLFxuICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC10dXJuLWR1cmF0aW9uJyB9KS50ZXh0KFN0cmluZyh0dXJuLmR1cmF0aW9uTGFiZWwgfHwgJycpKVxuICAgICAgICApO1xuXG4gICAgICAgIHJldHVybiAkcm93LmFwcGVuZChcbiAgICAgICAgICAgICQoJzx0aW1lPicsIHsgY2xhc3M6ICdzdHQtdHVybi10aW1lJyB9KS50ZXh0KFN0cmluZyh0dXJuLnN0YXJ0TGFiZWwgfHwgJycpKSxcbiAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10dXJuLWNvbnRlbnQnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkbWV0YSxcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHVybi10ZXh0JywgZGlyOiAnYXV0bycgfSkudGV4dChTdHJpbmcodHVybi50ZXh0IHx8ICcnKSlcbiAgICAgICAgICAgIClcbiAgICAgICAgKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjcmVhdGUoY29udmVyc2F0aW9uLCBsYWJlbHMpIHtcbiAgICAgICAgdmFyIGRhdGEgPSBjb252ZXJzYXRpb24gJiYgdHlwZW9mIGNvbnZlcnNhdGlvbiA9PT0gJ29iamVjdCcgPyBjb252ZXJzYXRpb24gOiB7fTtcbiAgICAgICAgdmFyIHNwZWFrZXJzID0gQXJyYXkuaXNBcnJheShkYXRhLnNwZWFrZXJzKSA/IGRhdGEuc3BlYWtlcnMgOiBbXTtcbiAgICAgICAgdmFyIHRpbWVsaW5lID0gQXJyYXkuaXNBcnJheShkYXRhLnRpbWVsaW5lKSA/IGRhdGEudGltZWxpbmUgOiBbXTtcbiAgICAgICAgdmFyIHRleHQgPSBPYmplY3QuYXNzaWduKHsgYWxsOiAnQWxsJywgc2VhcmNoOiAnU2VhcmNoJywgZW1wdHk6ICcnLCBwbGF5OiAnJywgZG93bmxvYWQ6ICcnIH0sIGxhYmVscyB8fCB7fSk7XG4gICAgICAgIHZhciBwbGF5YWJsZSA9IEJvb2xlYW4odGV4dC5hdWRpb1VybCk7XG4gICAgICAgIHZhciAkcm9vdCA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXZpZXcnIH0pO1xuICAgICAgICB2YXIgJGxpc3QgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1saXN0JyB9KTtcblxuICAgICAgICB0aW1lbGluZS5mb3JFYWNoKGZ1bmN0aW9uICh0dXJuKSB7XG4gICAgICAgICAgICBpZiAoIXR1cm4gfHwgdHlwZW9mIHR1cm4gIT09ICdvYmplY3QnKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKHR1cm4uZ2FwQmVmb3JlTGFiZWwpIHtcbiAgICAgICAgICAgICAgICAkbGlzdC5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWdhcCcgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAgICAgJCgnPHNwYW4+JykudGV4dCgnwrfCt8K3ICcgKyBTdHJpbmcodHVybi5nYXBCZWZvcmVMYWJlbCkpXG4gICAgICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICAgICApO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgJGxpc3QuYXBwZW5kKGNyZWF0ZVR1cm4odHVybiwgcGxheWFibGUpKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJHJvb3QuYXBwZW5kKFxuICAgICAgICAgICAgY3JlYXRlT3ZlcnZpZXcoZGF0YSwgdGV4dCksXG4gICAgICAgICAgICBjcmVhdGVUb29sYmFyKHNwZWFrZXJzLCB0ZXh0KSxcbiAgICAgICAgICAgICRsaXN0LFxuICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtZW1wdHkgdWkgbWVzc2FnZScsIGhpZGRlbjogdHJ1ZSB9KS50ZXh0KHRleHQuZW1wdHkpXG4gICAgICAgICk7XG4gICAgICAgIGluaXRpYWxpemUoJHJvb3QpO1xuXG4gICAgICAgIHJldHVybiAkcm9vdDtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBmb3JtYXRUaW1lKHNlY29uZHMpIHtcbiAgICAgICAgdmFyIHRvdGFsID0gTWF0aC5mbG9vcihNYXRoLm1heCgwLCBzZWNvbmRzIHx8IDApKTtcbiAgICAgICAgdmFyIGhvdXJzID0gTWF0aC5mbG9vcih0b3RhbCAvIDM2MDApO1xuICAgICAgICB2YXIgbWludXRlcyA9IE1hdGguZmxvb3IoKHRvdGFsICUgMzYwMCkgLyA2MCk7XG4gICAgICAgIHZhciByZXN0ID0gdG90YWwgJSA2MDtcblxuICAgICAgICBpZiAoaG91cnMgPiAwKSB7XG4gICAgICAgICAgICByZXR1cm4gaG91cnMgKyAnOicgKyBTdHJpbmcobWludXRlcykucGFkU3RhcnQoMiwgJzAnKSArICc6JyArIFN0cmluZyhyZXN0KS5wYWRTdGFydCgyLCAnMCcpO1xuICAgICAgICB9XG5cbiAgICAgICAgcmV0dXJuIE1hdGguZmxvb3IodG90YWwgLyA2MCkgKyAnOicgKyBTdHJpbmcocmVzdCkucGFkU3RhcnQoMiwgJzAnKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplUGxheWVyKCRyb290KSB7XG4gICAgICAgIHZhciBhdWRpbyA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1hdWRpbycpLmdldCgwKTtcbiAgICAgICAgdmFyICRwbGF5ZXIgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtcGxheWVyJyk7XG4gICAgICAgIHZhciAkcGxheUJ1dHRvbiA9ICRwbGF5ZXIuZmluZCgnLnN0dC1wbGF5ZXItcGxheScpO1xuICAgICAgICB2YXIgJHBsYXlJY29uID0gJHBsYXlCdXR0b24uZmluZCgnaScpO1xuICAgICAgICB2YXIgJGR1cmF0aW9uID0gJHBsYXllci5maW5kKCcuc3R0LXBsYXllci1kdXJhdGlvbicpO1xuICAgICAgICB2YXIgJHNlZWsgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtc2Vlay5pcy1wbGF5YWJsZScpO1xuICAgICAgICB2YXIgJHJhbmdlID0gJHNlZWsuZmluZCgnLnN0dC10cmFuc2NyaXB0LXJhbmdlJyk7XG4gICAgICAgIHZhciAkcGxheWhlYWQgPSAkc2Vlay5maW5kKCcuc3R0LXRyYW5zY3JpcHQtcGxheWhlYWQnKTtcbiAgICAgICAgdmFyICR0b29sdGlwID0gJHNlZWsuZmluZCgnLnN0dC10cmFuc2NyaXB0LXNlZWstdG9vbHRpcCcpO1xuICAgICAgICB2YXIgJHNjYWxlRW5kID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LXNjYWxlIHNwYW4nKS5sYXN0KCk7XG4gICAgICAgIHZhciAkc2VnbWVudHMgPSAkc2Vlay5maW5kKCcuc3R0LXRyYW5zY3JpcHQtbGFuZS1zZWdtZW50Jyk7XG4gICAgICAgIHZhciAkdHVybnMgPSAkcm9vdC5maW5kKCcuc3R0LXBsYXlhYmxlLXR1cm4nKTtcbiAgICAgICAgdmFyIHBlbmRpbmdSYW5nZVZhbHVlID0gbnVsbDtcbiAgICAgICAgdmFyIHdhaXRpbmdGb3JNZXRhZGF0YSA9IGZhbHNlO1xuICAgICAgICB2YXIgc3luY2hyb25pemVkRHVyYXRpb24gPSAwO1xuXG4gICAgICAgIGlmICghYXVkaW8gfHwgJHJhbmdlLmxlbmd0aCA9PT0gMCB8fCAkcm9vdC5kYXRhKCdzdHQtcGxheWVyLWluaXRpYWxpemVkJykpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuICAgICAgICAkcm9vdC5kYXRhKCdzdHQtcGxheWVyLWluaXRpYWxpemVkJywgdHJ1ZSk7XG5cbiAgICAgICAgZnVuY3Rpb24gZmFsbGJhY2tEdXJhdGlvbigpIHtcbiAgICAgICAgICAgIHJldHVybiBzYWZlTnVtYmVyKCRwbGF5ZXIuZGF0YSgnZHVyYXRpb24nKSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjdXJyZW50RHVyYXRpb24oKSB7XG4gICAgICAgICAgICByZXR1cm4gTnVtYmVyLmlzRmluaXRlKGF1ZGlvLmR1cmF0aW9uKSAmJiBhdWRpby5kdXJhdGlvbiA+IDAgPyBhdWRpby5kdXJhdGlvbiA6IGZhbGxiYWNrRHVyYXRpb24oKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGVuc3VyZVNvdXJjZSgpIHtcbiAgICAgICAgICAgIGlmIChhdWRpby5nZXRBdHRyaWJ1dGUoJ3NyYycpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB2YXIgc291cmNlID0gU3RyaW5nKGF1ZGlvLmdldEF0dHJpYnV0ZSgnZGF0YS1zcmMnKSB8fCAnJyk7XG4gICAgICAgICAgICBpZiAoIXNvdXJjZSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGF1ZGlvLnNldEF0dHJpYnV0ZSgnc3JjJywgc291cmNlKTtcbiAgICAgICAgICAgIGF1ZGlvLmxvYWQoKTtcblxuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZXRQbGF5SWNvbihwbGF5aW5nKSB7XG4gICAgICAgICAgICAkcGxheUljb24udG9nZ2xlQ2xhc3MoJ3BsYXknLCAhcGxheWluZykudG9nZ2xlQ2xhc3MoJ3BhdXNlJywgcGxheWluZyk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZXRBY3RpdmVUdXJucygpIHtcbiAgICAgICAgICAgIHZhciBjdXJyZW50ID0gYXVkaW8uY3VycmVudFRpbWU7XG4gICAgICAgICAgICAkdHVybnMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyICR0dXJuID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICB2YXIgc3RhcnQgPSBzYWZlTnVtYmVyKCR0dXJuLmRhdGEoJ3N0YXJ0JykpO1xuICAgICAgICAgICAgICAgIHZhciBlbmQgPSBzYWZlTnVtYmVyKCR0dXJuLmRhdGEoJ2VuZCcpKTtcbiAgICAgICAgICAgICAgICAkdHVybi50b2dnbGVDbGFzcygnc3R0LXR1cm4tYWN0aXZlJywgY3VycmVudCA+PSBzdGFydCAmJiBjdXJyZW50IDw9IE1hdGgubWF4KGVuZCwgc3RhcnQgKyAxKSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHN5bmNocm9uaXplVGltZWxpbmUoZHVyYXRpb24pIHtcbiAgICAgICAgICAgIGlmIChkdXJhdGlvbiA8PSAwIHx8IE1hdGguYWJzKGR1cmF0aW9uIC0gc3luY2hyb25pemVkRHVyYXRpb24pIDwgMC4wMSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHN5bmNocm9uaXplZER1cmF0aW9uID0gZHVyYXRpb247XG4gICAgICAgICAgICAkc2NhbGVFbmQudGV4dChmb3JtYXRUaW1lKGR1cmF0aW9uKSk7XG4gICAgICAgICAgICAkc2VnbWVudHMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyICRzZWdtZW50ID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICB2YXIgc3RhcnQgPSBNYXRoLm1pbihzYWZlTnVtYmVyKCRzZWdtZW50LmRhdGEoJ3N0YXJ0JykpLCBkdXJhdGlvbik7XG4gICAgICAgICAgICAgICAgdmFyIGVuZCA9IE1hdGgubWluKE1hdGgubWF4KHNhZmVOdW1iZXIoJHNlZ21lbnQuZGF0YSgnZW5kJykpLCBzdGFydCksIGR1cmF0aW9uKTtcbiAgICAgICAgICAgICAgICAkc2VnbWVudC5jc3Moe1xuICAgICAgICAgICAgICAgICAgICBsZWZ0OiAoKHN0YXJ0IC8gZHVyYXRpb24pICogMTAwKSArICclJyxcbiAgICAgICAgICAgICAgICAgICAgd2lkdGg6ICgoKGVuZCAtIHN0YXJ0KSAvIGR1cmF0aW9uKSAqIDEwMCkgKyAnJSdcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlKCkge1xuICAgICAgICAgICAgdmFyIGR1cmF0aW9uID0gY3VycmVudER1cmF0aW9uKCk7XG4gICAgICAgICAgICB2YXIgdmFsdWUgPSBkdXJhdGlvbiA+IDAgPyBNYXRoLm1pbigxMDAwLCBNYXRoLnJvdW5kKChhdWRpby5jdXJyZW50VGltZSAvIGR1cmF0aW9uKSAqIDEwMDApKSA6IDA7XG4gICAgICAgICAgICBzeW5jaHJvbml6ZVRpbWVsaW5lKGR1cmF0aW9uKTtcbiAgICAgICAgICAgICRyYW5nZS52YWwodmFsdWUpLmF0dHIoJ2FyaWEtdmFsdWV0ZXh0JywgZm9ybWF0VGltZShhdWRpby5jdXJyZW50VGltZSkpO1xuICAgICAgICAgICAgJHBsYXloZWFkLmNzcygnbGVmdCcsICh2YWx1ZSAvIDEwKSArICclJyk7XG4gICAgICAgICAgICAkZHVyYXRpb24udGV4dChmb3JtYXRUaW1lKGF1ZGlvLmN1cnJlbnRUaW1lKSArICcgLyAnICsgKGR1cmF0aW9uID4gMCA/IGZvcm1hdFRpbWUoZHVyYXRpb24pIDogJy0tOi0tJykpO1xuICAgICAgICAgICAgc2V0QWN0aXZlVHVybnMoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHBsYXkoKSB7XG4gICAgICAgICAgICBpZiAoIWVuc3VyZVNvdXJjZSgpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIHJlc3VsdCA9IGF1ZGlvLnBsYXkoKTtcbiAgICAgICAgICAgIGlmIChyZXN1bHQgJiYgdHlwZW9mIHJlc3VsdC5jYXRjaCA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgIHJlc3VsdC5jYXRjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHNldFBsYXlJY29uKGZhbHNlKTtcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNlZWtCeVJhbmdlKHZhbHVlKSB7XG4gICAgICAgICAgICBmdW5jdGlvbiBhcHBseVNlZWsoKSB7XG4gICAgICAgICAgICAgICAgdmFyIGR1cmF0aW9uID0gY3VycmVudER1cmF0aW9uKCk7XG4gICAgICAgICAgICAgICAgaWYgKGR1cmF0aW9uIDw9IDApIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB2YXIgdmFsdWUgPSBwZW5kaW5nUmFuZ2VWYWx1ZTtcbiAgICAgICAgICAgICAgICBwZW5kaW5nUmFuZ2VWYWx1ZSA9IG51bGw7XG4gICAgICAgICAgICAgICAgYXVkaW8uY3VycmVudFRpbWUgPSAoZHVyYXRpb24gKiB2YWx1ZSkgLyAxMDAwO1xuICAgICAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBwZW5kaW5nUmFuZ2VWYWx1ZSA9IHZhbHVlO1xuICAgICAgICAgICAgaWYgKCFlbnN1cmVTb3VyY2UoKSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChhdWRpby5yZWFkeVN0YXRlID49IDEpIHtcbiAgICAgICAgICAgICAgICBhcHBseVNlZWsoKTtcbiAgICAgICAgICAgIH0gZWxzZSBpZiAoIXdhaXRpbmdGb3JNZXRhZGF0YSkge1xuICAgICAgICAgICAgICAgIHdhaXRpbmdGb3JNZXRhZGF0YSA9IHRydWU7XG4gICAgICAgICAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcignbG9hZGVkbWV0YWRhdGEnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHdhaXRpbmdGb3JNZXRhZGF0YSA9IGZhbHNlO1xuICAgICAgICAgICAgICAgICAgICBhcHBseVNlZWsoKTtcbiAgICAgICAgICAgICAgICB9LCB7IG9uY2U6IHRydWUgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZWVrVHVybigkdHVybikge1xuICAgICAgICAgICAgdmFyIHNlY29uZHMgPSBzYWZlTnVtYmVyKCR0dXJuLmRhdGEoJ3N0YXJ0JykpO1xuXG4gICAgICAgICAgICBmdW5jdGlvbiBhcHBseVNlZWsoKSB7XG4gICAgICAgICAgICAgICAgYXVkaW8uY3VycmVudFRpbWUgPSBNYXRoLm1pbihzZWNvbmRzLCBjdXJyZW50RHVyYXRpb24oKSB8fCBzZWNvbmRzKTtcbiAgICAgICAgICAgICAgICB1cGRhdGUoKTtcbiAgICAgICAgICAgICAgICBwbGF5KCk7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGlmICghZW5zdXJlU291cmNlKCkpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoYXVkaW8ucmVhZHlTdGF0ZSA+PSAxKSB7XG4gICAgICAgICAgICAgICAgYXBwbHlTZWVrKCk7XG4gICAgICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2xvYWRlZG1ldGFkYXRhJywgYXBwbHlTZWVrLCB7IG9uY2U6IHRydWUgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzeW5jaHJvbml6ZSgpIHtcbiAgICAgICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICB9XG5cbiAgICAgICAgJHBsYXlCdXR0b24ub24oJ2NsaWNrLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpZiAoYXVkaW8ucGF1c2VkKSB7XG4gICAgICAgICAgICAgICAgcGxheSgpO1xuICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICBhdWRpby5wYXVzZSgpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICAgICAgJHJhbmdlLm9uKCdpbnB1dC5zdHRUcmFuc2NyaXB0IGNoYW5nZS5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgc2Vla0J5UmFuZ2Uoc2FmZU51bWJlcih0aGlzLnZhbHVlKSk7XG4gICAgICAgIH0pO1xuICAgICAgICAkc2Vlay5vbignbW91c2Vtb3ZlLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIHZhciBvZmZzZXQgPSAkc2Vlay5vZmZzZXQoKTtcbiAgICAgICAgICAgIHZhciB3aWR0aCA9ICRzZWVrLndpZHRoKCk7XG4gICAgICAgICAgICB2YXIgcGVyY2VudCA9IHdpZHRoID4gMCA/IE1hdGgubWF4KDAsIE1hdGgubWluKDEwMCwgKChldmVudC5wYWdlWCAtIG9mZnNldC5sZWZ0KSAvIHdpZHRoKSAqIDEwMCkpIDogMDtcbiAgICAgICAgICAgICR0b29sdGlwLnRleHQoZm9ybWF0VGltZSgoY3VycmVudER1cmF0aW9uKCkgKiBwZXJjZW50KSAvIDEwMCkpLmNzcygnbGVmdCcsIHBlcmNlbnQgKyAnJScpO1xuICAgICAgICB9KTtcbiAgICAgICAgJHR1cm5zLm9uKCdjbGljay5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgc2Vla1R1cm4oJCh0aGlzKSk7XG4gICAgICAgIH0pLm9uKCdrZXlkb3duLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIGlmIChldmVudC5rZXkgPT09ICdFbnRlcicgfHwgZXZlbnQua2V5ID09PSAnICcpIHtcbiAgICAgICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgICAgIHNlZWtUdXJuKCQodGhpcykpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcblxuICAgICAgICBhdWRpby5hZGRFdmVudExpc3RlbmVyKCdsb2FkZWRtZXRhZGF0YScsIHN5bmNocm9uaXplKTtcbiAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcigndGltZXVwZGF0ZScsIHVwZGF0ZSk7XG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ3BsYXknLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZXRQbGF5SWNvbih0cnVlKTtcbiAgICAgICAgfSk7XG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ3BhdXNlJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgc2V0UGxheUljb24oZmFsc2UpO1xuICAgICAgICB9KTtcbiAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcignZW5kZWQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBhdWRpby5jdXJyZW50VGltZSA9IDA7XG4gICAgICAgICAgICB1cGRhdGUoKTtcbiAgICAgICAgfSk7XG4gICAgICAgIHVwZGF0ZSgpO1xuICAgICAgICBlbnN1cmVTb3VyY2UoKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplKHJvb3QpIHtcbiAgICAgICAgdmFyICRyb290ID0gcm9vdCAmJiByb290LmpxdWVyeSA/IHJvb3QgOiAkKHJvb3QpO1xuICAgICAgICB2YXIgJGlucHV0ID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LXNlYXJjaCBpbnB1dCcpO1xuICAgICAgICB2YXIgJGZpbHRlcnMgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtZmlsdGVyJyk7XG4gICAgICAgIHZhciAkcm93cyA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC10dXJuJyk7XG4gICAgICAgIHZhciAkZ2FwcyA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1nYXAnKTtcbiAgICAgICAgdmFyICRlbXB0eSA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1lbXB0eScpO1xuICAgICAgICB2YXIgYWN0aXZlU3BlYWtlciA9ICdhbGwnO1xuXG4gICAgICAgIGlmICgkcm9vdC5sZW5ndGggPT09IDAgfHwgJHJvb3QuZGF0YSgnc3R0LXRyYW5zY3JpcHQtaW5pdGlhbGl6ZWQnKSkge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG4gICAgICAgICRyb290LmRhdGEoJ3N0dC10cmFuc2NyaXB0LWluaXRpYWxpemVkJywgdHJ1ZSk7XG5cbiAgICAgICAgZnVuY3Rpb24gYXBwbHkoKSB7XG4gICAgICAgICAgICB2YXIgcXVlcnkgPSBTdHJpbmcoJGlucHV0LnZhbCgpIHx8ICcnKS50cmltKCkudG9Mb2NhbGVMb3dlckNhc2UoKTtcbiAgICAgICAgICAgIHZhciB2aXNpYmxlID0gMDtcblxuICAgICAgICAgICAgJHJvd3MuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyICRyb3cgPSAkKHRoaXMpO1xuICAgICAgICAgICAgICAgIHZhciBzcGVha2VyTWF0Y2hlcyA9IGFjdGl2ZVNwZWFrZXIgPT09ICdhbGwnIHx8ICRyb3cuZGF0YSgnc3BlYWtlcicpID09PSBhY3RpdmVTcGVha2VyO1xuICAgICAgICAgICAgICAgIHZhciB0ZXh0TWF0Y2hlcyA9IHF1ZXJ5ID09PSAnJyB8fCAkcm93LmZpbmQoJy5zdHQtdHVybi10ZXh0JykudGV4dCgpLnRvTG9jYWxlTG93ZXJDYXNlKCkuaW5kZXhPZihxdWVyeSkgIT09IC0xO1xuICAgICAgICAgICAgICAgIHZhciBzaG93biA9IHNwZWFrZXJNYXRjaGVzICYmIHRleHRNYXRjaGVzO1xuXG4gICAgICAgICAgICAgICAgJHJvdy5wcm9wKCdoaWRkZW4nLCAhc2hvd24pO1xuICAgICAgICAgICAgICAgIGlmIChzaG93bikge1xuICAgICAgICAgICAgICAgICAgICB2aXNpYmxlICs9IDE7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAkZ2Fwcy5wcm9wKCdoaWRkZW4nLCBxdWVyeSAhPT0gJycgfHwgYWN0aXZlU3BlYWtlciAhPT0gJ2FsbCcpO1xuICAgICAgICAgICAgJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LWNvdW50JykudGV4dChTdHJpbmcodmlzaWJsZSkpO1xuICAgICAgICAgICAgJGVtcHR5LnByb3AoJ2hpZGRlbicsIHZpc2libGUgIT09IDApO1xuICAgICAgICB9XG5cbiAgICAgICAgJGlucHV0Lm9uKCdpbnB1dC5zdHRUcmFuc2NyaXB0JywgYXBwbHkpO1xuICAgICAgICAkZmlsdGVycy5vbignY2xpY2suc3R0VHJhbnNjcmlwdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGFjdGl2ZVNwZWFrZXIgPSBTdHJpbmcoJCh0aGlzKS5kYXRhKCdzcGVha2VyJykgfHwgJ2FsbCcpO1xuICAgICAgICAgICAgJGZpbHRlcnMucmVtb3ZlQ2xhc3MoJ2FjdGl2ZScpLmF0dHIoJ2FyaWEtcHJlc3NlZCcsICdmYWxzZScpO1xuICAgICAgICAgICAgJCh0aGlzKS5hZGRDbGFzcygnYWN0aXZlJykuYXR0cignYXJpYS1wcmVzc2VkJywgJ3RydWUnKTtcbiAgICAgICAgICAgIGFwcGx5KCk7XG4gICAgICAgIH0pO1xuICAgICAgICBhcHBseSgpO1xuICAgICAgICBpbml0aWFsaXplUGxheWVyKCRyb290KTtcbiAgICB9XG5cbiAgICB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcgPSB7XG4gICAgICAgIGNyZWF0ZTogY3JlYXRlLFxuICAgICAgICBpbml0aWFsaXplOiBpbml0aWFsaXplXG4gICAgfTtcblxuICAgICQoZnVuY3Rpb24gKCkge1xuICAgICAgICAkKCcuc3R0LXRyYW5zY3JpcHQtdmlldycpLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaW5pdGlhbGl6ZSh0aGlzKTtcbiAgICAgICAgfSk7XG4gICAgfSk7XG59KHdpbmRvdywgalF1ZXJ5KSk7XG4iXX0=