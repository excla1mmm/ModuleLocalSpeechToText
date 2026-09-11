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
        'data-content': labels.download,
        'aria-label': labels.download,
        title: labels.download
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
    }).text(String(turn.speakerLabel || '')));
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
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtY29udmVyc2F0aW9uLmpzIl0sIm5hbWVzIjpbIndpbmRvdyIsIiQiLCJzYWZlTnVtYmVyIiwidmFsdWUiLCJudW1iZXIiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsIk1hdGgiLCJtYXgiLCJzcGVha2VyQ2xhc3MiLCJrZXkiLCJTdHJpbmciLCJyZXBsYWNlIiwiY3JlYXRlTGVnZW5kIiwic3BlYWtlcnMiLCIkbGVnZW5kIiwiZm9yRWFjaCIsInNwZWFrZXIiLCJhcHBlbmQiLCJkb2N1bWVudCIsImNyZWF0ZVRleHROb2RlIiwibGFiZWwiLCJjcmVhdGVQbGF5ZXIiLCJsYWJlbHMiLCJkdXJhdGlvbkxhYmVsIiwiJHBsYXllciIsImR1cmF0aW9uU2Vjb25kcyIsInByZWxvYWQiLCJhdWRpb1VybCIsInR5cGUiLCJwbGF5IiwidGV4dCIsImRvd25sb2FkVXJsIiwiaHJlZiIsImRvd25sb2FkIiwiY3JlYXRlT3ZlcnZpZXciLCJjb252ZXJzYXRpb24iLCJBcnJheSIsImlzQXJyYXkiLCJwbGF5YWJsZSIsIkJvb2xlYW4iLCIkb3ZlcnZpZXciLCIkb3ZlcnZpZXdIZWFkZXIiLCIkbGFuZXMiLCIkbGFuZSIsInNlZ21lbnRzIiwidHVybiIsInN0YXJ0IiwiZW5kIiwiY3NzIiwibGVmdCIsInN0YXJ0UGVyY2VudCIsIndpZHRoIiwid2lkdGhQZXJjZW50IiwiJHNlZWsiLCJhZGRDbGFzcyIsIm1pbiIsImNyZWF0ZVRvb2xiYXIiLCIkZmlsdGVycyIsInJvbGUiLCJhbGwiLCJwbGFjZWhvbGRlciIsInNlYXJjaCIsImNyZWF0ZVR1cm4iLCJzcGVha2VyS2V5IiwiJHJvdyIsInRhYmluZGV4IiwiJG1ldGEiLCJzcGVha2VyTGFiZWwiLCJzdGFydExhYmVsIiwiZGlyIiwiY3JlYXRlIiwiZGF0YSIsInRpbWVsaW5lIiwiT2JqZWN0IiwiYXNzaWduIiwiZW1wdHkiLCIkcm9vdCIsIiRsaXN0IiwiZ2FwQmVmb3JlTGFiZWwiLCJoaWRkZW4iLCJpbml0aWFsaXplIiwiZm9ybWF0VGltZSIsInNlY29uZHMiLCJ0b3RhbCIsImZsb29yIiwiaG91cnMiLCJtaW51dGVzIiwicmVzdCIsInBhZFN0YXJ0IiwiaW5pdGlhbGl6ZVBsYXllciIsImF1ZGlvIiwiZmluZCIsImdldCIsIiRwbGF5QnV0dG9uIiwiJHBsYXlJY29uIiwiJGR1cmF0aW9uIiwiJHJhbmdlIiwiJHBsYXloZWFkIiwiJHRvb2x0aXAiLCIkc2NhbGVFbmQiLCJsYXN0IiwiJHNlZ21lbnRzIiwiJHR1cm5zIiwicGVuZGluZ1JhbmdlVmFsdWUiLCJ3YWl0aW5nRm9yTWV0YWRhdGEiLCJzeW5jaHJvbml6ZWREdXJhdGlvbiIsImxlbmd0aCIsImZhbGxiYWNrRHVyYXRpb24iLCJjdXJyZW50RHVyYXRpb24iLCJkdXJhdGlvbiIsImVuc3VyZVNvdXJjZSIsImdldEF0dHJpYnV0ZSIsInNvdXJjZSIsInNldEF0dHJpYnV0ZSIsImxvYWQiLCJzZXRQbGF5SWNvbiIsInBsYXlpbmciLCJ0b2dnbGVDbGFzcyIsInNldEFjdGl2ZVR1cm5zIiwiY3VycmVudCIsImN1cnJlbnRUaW1lIiwiZWFjaCIsIiR0dXJuIiwic3luY2hyb25pemVUaW1lbGluZSIsImFicyIsIiRzZWdtZW50IiwidXBkYXRlIiwicm91bmQiLCJ2YWwiLCJhdHRyIiwicmVzdWx0Iiwic2Vla0J5UmFuZ2UiLCJhcHBseVNlZWsiLCJyZWFkeVN0YXRlIiwiYWRkRXZlbnRMaXN0ZW5lciIsIm9uY2UiLCJzZWVrVHVybiIsInN5bmNocm9uaXplIiwib24iLCJwYXVzZWQiLCJwYXVzZSIsImV2ZW50Iiwib2Zmc2V0IiwicGVyY2VudCIsInBhZ2VYIiwicHJldmVudERlZmF1bHQiLCJyb290IiwianF1ZXJ5IiwiJGlucHV0IiwiJHJvd3MiLCIkZ2FwcyIsIiRlbXB0eSIsImFjdGl2ZVNwZWFrZXIiLCJhcHBseSIsInF1ZXJ5IiwidHJpbSIsInRvTG9jYWxlTG93ZXJDYXNlIiwidmlzaWJsZSIsInNwZWFrZXJNYXRjaGVzIiwidGV4dE1hdGNoZXMiLCJpbmRleE9mIiwic2hvd24iLCJwcm9wIiwicmVtb3ZlQ2xhc3MiLCJTdHRUcmFuc2NyaXB0VmlldyIsImpRdWVyeSJdLCJtYXBwaW5ncyI6Ijs7OztBQUFDLFdBQVVBLE1BQVYsRUFBa0JDLENBQWxCLEVBQXFCO0FBQ2xCOztBQUVBLFdBQVNDLFVBQVQsQ0FBb0JDLEtBQXBCLEVBQTJCO0FBQ3ZCLFFBQUlDLE1BQU0sR0FBR0MsTUFBTSxDQUFDRixLQUFELENBQW5CO0FBRUEsV0FBT0UsTUFBTSxDQUFDQyxRQUFQLENBQWdCRixNQUFoQixJQUEwQkcsSUFBSSxDQUFDQyxHQUFMLENBQVMsQ0FBVCxFQUFZSixNQUFaLENBQTFCLEdBQWdELENBQXZEO0FBQ0g7O0FBRUQsV0FBU0ssWUFBVCxDQUFzQkMsR0FBdEIsRUFBMkI7QUFDdkIsV0FBT0MsTUFBTSxDQUFDRCxHQUFHLElBQUksRUFBUixDQUFOLENBQWtCRSxPQUFsQixDQUEwQixlQUExQixFQUEyQyxHQUEzQyxLQUFtRCxNQUExRDtBQUNIOztBQUVELFdBQVNDLFlBQVQsQ0FBc0JDLFFBQXRCLEVBQWdDO0FBQzVCLFFBQUlDLE9BQU8sR0FBR2QsQ0FBQyxDQUFDLE9BQUQsRUFBVTtBQUFFLGVBQU87QUFBVCxLQUFWLENBQWY7QUFFQWEsSUFBQUEsUUFBUSxDQUFDRSxPQUFULENBQWlCLFVBQVVDLE9BQVYsRUFBbUI7QUFDaENGLE1BQUFBLE9BQU8sQ0FBQ0csTUFBUixDQUNJakIsQ0FBQyxDQUFDLFFBQUQsRUFBVztBQUFFLGlCQUFPLDRDQUE0Q1EsWUFBWSxDQUFDUSxPQUFPLENBQUNQLEdBQVQ7QUFBakUsT0FBWCxDQUFELENBQThGUSxNQUE5RixDQUNJakIsQ0FBQyxDQUFDLFFBQUQsRUFBVztBQUFFLGlCQUFPO0FBQVQsT0FBWCxDQURMLEVBRUlrQixRQUFRLENBQUNDLGNBQVQsQ0FBd0JULE1BQU0sQ0FBQ00sT0FBTyxDQUFDSSxLQUFSLElBQWlCLEVBQWxCLENBQTlCLENBRkosQ0FESjtBQU1ILEtBUEQ7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QStCLFlBQVk7QUFDdkN6QyxNQUFBQSxVQUFVLENBQUMsSUFBRCxDQUFWO0FBQ0gsS0FGRDtBQUdILEdBSkEsQ0FBRDtBQUtILENBOWNBLEVBOGNDakYsTUE5Y0QsRUE4Y1NzSyxNQTljVCxDQUFEIiwic291cmNlc0NvbnRlbnQiOlsiKGZ1bmN0aW9uICh3aW5kb3csICQpIHtcbiAgICAndXNlIHN0cmljdCc7XG5cbiAgICBmdW5jdGlvbiBzYWZlTnVtYmVyKHZhbHVlKSB7XG4gICAgICAgIHZhciBudW1iZXIgPSBOdW1iZXIodmFsdWUpO1xuXG4gICAgICAgIHJldHVybiBOdW1iZXIuaXNGaW5pdGUobnVtYmVyKSA/IE1hdGgubWF4KDAsIG51bWJlcikgOiAwO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIHNwZWFrZXJDbGFzcyhrZXkpIHtcbiAgICAgICAgcmV0dXJuIFN0cmluZyhrZXkgfHwgJycpLnJlcGxhY2UoL1teYS16MC05Xy1dL2dpLCAnLScpIHx8ICdsZWZ0JztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjcmVhdGVMZWdlbmQoc3BlYWtlcnMpIHtcbiAgICAgICAgdmFyICRsZWdlbmQgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sZWdlbmQnIH0pO1xuXG4gICAgICAgIHNwZWFrZXJzLmZvckVhY2goZnVuY3Rpb24gKHNwZWFrZXIpIHtcbiAgICAgICAgICAgICRsZWdlbmQuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1sZWdlbmQtaXRlbSBzdHQtc3BlYWtlci0nICsgc3BlYWtlckNsYXNzKHNwZWFrZXIua2V5KSB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtc3BlYWtlci1kb3QnIH0pLFxuICAgICAgICAgICAgICAgICAgICBkb2N1bWVudC5jcmVhdGVUZXh0Tm9kZShTdHJpbmcoc3BlYWtlci5sYWJlbCB8fCAnJykpXG4gICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgcmV0dXJuICRsZWdlbmQ7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlUGxheWVyKGxhYmVscywgZHVyYXRpb25MYWJlbCkge1xuICAgICAgICB2YXIgJHBsYXllciA9ICQoJzxkaXY+Jywge1xuICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1wbGF5ZXInLFxuICAgICAgICAgICAgJ2RhdGEtZHVyYXRpb24nOiBzYWZlTnVtYmVyKGxhYmVscy5kdXJhdGlvblNlY29uZHMpXG4gICAgICAgIH0pLmFwcGVuZChcbiAgICAgICAgICAgICQoJzxhdWRpbz4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1hdWRpbycsXG4gICAgICAgICAgICAgICAgcHJlbG9hZDogJ21ldGFkYXRhJyxcbiAgICAgICAgICAgICAgICAnZGF0YS1zcmMnOiBTdHJpbmcobGFiZWxzLmF1ZGlvVXJsIHx8ICcnKVxuICAgICAgICAgICAgfSksXG4gICAgICAgICAgICAkKCc8YnV0dG9uPicsIHtcbiAgICAgICAgICAgICAgICB0eXBlOiAnYnV0dG9uJyxcbiAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC1wbGF5ZXItcGxheScsXG4gICAgICAgICAgICAgICAgJ2FyaWEtbGFiZWwnOiBsYWJlbHMucGxheSxcbiAgICAgICAgICAgICAgICAnZGF0YS1jb250ZW50JzogbGFiZWxzLnBsYXlcbiAgICAgICAgICAgIH0pLmFwcGVuZCgkKCc8aT4nLCB7IGNsYXNzOiAncGxheSBpY29uJyB9KSksXG4gICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXBsYXllci1kdXJhdGlvbicgfSkudGV4dCgnMDA6MDAgLyAnICsgZHVyYXRpb25MYWJlbClcbiAgICAgICAgKTtcblxuICAgICAgICBpZiAobGFiZWxzLmRvd25sb2FkVXJsKSB7XG4gICAgICAgICAgICAkcGxheWVyLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8YT4nLCB7XG4gICAgICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXBsYXllci1kb3dubG9hZCcsXG4gICAgICAgICAgICAgICAgICAgIGhyZWY6IFN0cmluZyhsYWJlbHMuZG93bmxvYWRVcmwpLFxuICAgICAgICAgICAgICAgICAgICAnZGF0YS1jb250ZW50JzogbGFiZWxzLmRvd25sb2FkXG4gICAgICAgICAgICAgICAgfSkuYXBwZW5kKCQoJzxpPicsIHsgY2xhc3M6ICdkb3dubG9hZCBpY29uJyB9KSlcbiAgICAgICAgICAgICk7XG4gICAgICAgIH1cblxuICAgICAgICByZXR1cm4gJHBsYXllcjtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjcmVhdGVPdmVydmlldyhjb252ZXJzYXRpb24sIGxhYmVscykge1xuICAgICAgICB2YXIgc3BlYWtlcnMgPSBBcnJheS5pc0FycmF5KGNvbnZlcnNhdGlvbi5zcGVha2VycykgPyBjb252ZXJzYXRpb24uc3BlYWtlcnMgOiBbXTtcbiAgICAgICAgdmFyIGR1cmF0aW9uTGFiZWwgPSBTdHJpbmcoY29udmVyc2F0aW9uLmR1cmF0aW9uTGFiZWwgfHwgJzA6MDAnKTtcbiAgICAgICAgdmFyIHBsYXlhYmxlID0gQm9vbGVhbihsYWJlbHMuYXVkaW9VcmwpO1xuICAgICAgICB2YXIgJG92ZXJ2aWV3ID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtb3ZlcnZpZXcnIH0pO1xuICAgICAgICB2YXIgJG92ZXJ2aWV3SGVhZGVyID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtb3ZlcnZpZXctaGVhZGVyJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICBwbGF5YWJsZVxuICAgICAgICAgICAgICAgID8gY3JlYXRlUGxheWVyKGxhYmVscywgZHVyYXRpb25MYWJlbClcbiAgICAgICAgICAgICAgICA6ICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1kdXJhdGlvbicgfSkudGV4dChkdXJhdGlvbkxhYmVsKSxcbiAgICAgICAgICAgIGNyZWF0ZUxlZ2VuZChzcGVha2VycylcbiAgICAgICAgKTtcbiAgICAgICAgdmFyICRsYW5lcyA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWxhbmVzJyB9KTtcblxuICAgICAgICBzcGVha2Vycy5mb3JFYWNoKGZ1bmN0aW9uIChzcGVha2VyKSB7XG4gICAgICAgICAgICB2YXIga2V5ID0gc3BlYWtlckNsYXNzKHNwZWFrZXIua2V5KTtcbiAgICAgICAgICAgIHZhciAkbGFuZSA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWxhbmUgc3R0LXNwZWFrZXItJyArIGtleSB9KTtcbiAgICAgICAgICAgIHZhciBzZWdtZW50cyA9IEFycmF5LmlzQXJyYXkoc3BlYWtlci5zZWdtZW50cykgPyBzcGVha2VyLnNlZ21lbnRzIDogW107XG5cbiAgICAgICAgICAgIHNlZ21lbnRzLmZvckVhY2goZnVuY3Rpb24gKHR1cm4pIHtcbiAgICAgICAgICAgICAgICAkbGFuZS5hcHBlbmQoJCgnPHNwYW4+Jywge1xuICAgICAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWxhbmUtc2VnbWVudCcsXG4gICAgICAgICAgICAgICAgICAgICdkYXRhLXN0YXJ0Jzogc2FmZU51bWJlcih0dXJuLnN0YXJ0KSxcbiAgICAgICAgICAgICAgICAgICAgJ2RhdGEtZW5kJzogc2FmZU51bWJlcih0dXJuLmVuZClcbiAgICAgICAgICAgICAgICB9KS5jc3Moe1xuICAgICAgICAgICAgICAgICAgICBsZWZ0OiBzYWZlTnVtYmVyKHR1cm4uc3RhcnRQZXJjZW50KSArICclJyxcbiAgICAgICAgICAgICAgICAgICAgd2lkdGg6IHNhZmVOdW1iZXIodHVybi53aWR0aFBlcmNlbnQpICsgJyUnXG4gICAgICAgICAgICAgICAgfSkpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAkbGFuZXMuYXBwZW5kKCRsYW5lKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgdmFyICRzZWVrID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtc2VlaycgfSkuYXBwZW5kKCRsYW5lcyk7XG4gICAgICAgIGlmIChwbGF5YWJsZSkge1xuICAgICAgICAgICAgJHNlZWsuYWRkQ2xhc3MoJ2lzLXBsYXlhYmxlJykuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1wbGF5aGVhZCcgfSksXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXNlZWstdG9vbHRpcCcgfSkudGV4dCgnMDowMCcpLFxuICAgICAgICAgICAgICAgICQoJzxpbnB1dD4nLCB7XG4gICAgICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtcmFuZ2UnLFxuICAgICAgICAgICAgICAgICAgICB0eXBlOiAncmFuZ2UnLFxuICAgICAgICAgICAgICAgICAgICBtaW46IDAsXG4gICAgICAgICAgICAgICAgICAgIG1heDogMTAwMCxcbiAgICAgICAgICAgICAgICAgICAgdmFsdWU6IDAsXG4gICAgICAgICAgICAgICAgICAgICdhcmlhLWxhYmVsJzogbGFiZWxzLnBsYXlcbiAgICAgICAgICAgICAgICB9KVxuICAgICAgICAgICAgKTtcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiAkb3ZlcnZpZXcuYXBwZW5kKFxuICAgICAgICAgICAgJG92ZXJ2aWV3SGVhZGVyLFxuICAgICAgICAgICAgJHNlZWssXG4gICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1zY2FsZScgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicpLnRleHQoJzA6MDAnKSxcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nKS50ZXh0KGR1cmF0aW9uTGFiZWwpXG4gICAgICAgICAgICApXG4gICAgICAgICk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlVG9vbGJhcihzcGVha2VycywgbGFiZWxzKSB7XG4gICAgICAgIHZhciAkZmlsdGVycyA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWZpbHRlcnMnLCByb2xlOiAnZ3JvdXAnIH0pO1xuXG4gICAgICAgICRmaWx0ZXJzLmFwcGVuZCgkKCc8YnV0dG9uPicsIHtcbiAgICAgICAgICAgIHR5cGU6ICdidXR0b24nLFxuICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1maWx0ZXIgYWN0aXZlJyxcbiAgICAgICAgICAgICdkYXRhLXNwZWFrZXInOiAnYWxsJyxcbiAgICAgICAgICAgICdhcmlhLXByZXNzZWQnOiAndHJ1ZSdcbiAgICAgICAgfSkudGV4dChsYWJlbHMuYWxsKSk7XG4gICAgICAgIHNwZWFrZXJzLmZvckVhY2goZnVuY3Rpb24gKHNwZWFrZXIpIHtcbiAgICAgICAgICAgICRmaWx0ZXJzLmFwcGVuZCgkKCc8YnV0dG9uPicsIHtcbiAgICAgICAgICAgICAgICB0eXBlOiAnYnV0dG9uJyxcbiAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWZpbHRlcicsXG4gICAgICAgICAgICAgICAgJ2RhdGEtc3BlYWtlcic6IHNwZWFrZXJDbGFzcyhzcGVha2VyLmtleSksXG4gICAgICAgICAgICAgICAgJ2FyaWEtcHJlc3NlZCc6ICdmYWxzZSdcbiAgICAgICAgICAgIH0pLnRleHQoU3RyaW5nKHNwZWFrZXIubGFiZWwgfHwgJycpKSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHJldHVybiAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC10b29sYmFyJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAkKCc8bGFiZWw+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LXNlYXJjaCcgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxpPicsIHsgY2xhc3M6ICdzZWFyY2ggaWNvbicgfSksXG4gICAgICAgICAgICAgICAgJCgnPGlucHV0PicsIHtcbiAgICAgICAgICAgICAgICAgICAgdHlwZTogJ3NlYXJjaCcsXG4gICAgICAgICAgICAgICAgICAgIHBsYWNlaG9sZGVyOiBsYWJlbHMuc2VhcmNoLFxuICAgICAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5zZWFyY2hcbiAgICAgICAgICAgICAgICB9KVxuICAgICAgICAgICAgKSxcbiAgICAgICAgICAgICRmaWx0ZXJzLFxuICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWNvdW50JywgJ2FyaWEtbGl2ZSc6ICdwb2xpdGUnIH0pXG4gICAgICAgICk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlVHVybih0dXJuLCBwbGF5YWJsZSkge1xuICAgICAgICB2YXIga2V5ID0gc3BlYWtlckNsYXNzKHR1cm4uc3BlYWtlcktleSk7XG4gICAgICAgIHZhciAkcm93ID0gJCgnPGFydGljbGU+Jywge1xuICAgICAgICAgICAgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC10dXJuIHN0dC10dXJuLScgKyBrZXkgKyAocGxheWFibGUgPyAnIHN0dC1wbGF5YWJsZS10dXJuJyA6ICcnKSxcbiAgICAgICAgICAgICdkYXRhLXNwZWFrZXInOiBrZXksXG4gICAgICAgICAgICAnZGF0YS1zdGFydCc6IHNhZmVOdW1iZXIodHVybi5zdGFydCksXG4gICAgICAgICAgICAnZGF0YS1lbmQnOiBzYWZlTnVtYmVyKHR1cm4uZW5kKSxcbiAgICAgICAgICAgIHRhYmluZGV4OiBwbGF5YWJsZSA/IDAgOiBudWxsXG4gICAgICAgIH0pO1xuICAgICAgICB2YXIgJG1ldGEgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHVybi1tZXRhJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAkKCc8c3Bhbj4nLCB7IGNsYXNzOiAnc3R0LXNwZWFrZXItZG90JyB9KSxcbiAgICAgICAgICAgICQoJzxzdHJvbmc+JywgeyBjbGFzczogJ3N0dC10dXJuLXNwZWFrZXInIH0pLnRleHQoU3RyaW5nKHR1cm4uc3BlYWtlckxhYmVsIHx8ICcnKSlcbiAgICAgICAgKTtcblxuICAgICAgICByZXR1cm4gJHJvdy5hcHBlbmQoXG4gICAgICAgICAgICAkKCc8dGltZT4nLCB7IGNsYXNzOiAnc3R0LXR1cm4tdGltZScgfSkudGV4dChTdHJpbmcodHVybi5zdGFydExhYmVsIHx8ICcnKSksXG4gICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHVybi1jb250ZW50JyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJG1ldGEsXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXR1cm4tdGV4dCcsIGRpcjogJ2F1dG8nIH0pLnRleHQoU3RyaW5nKHR1cm4udGV4dCB8fCAnJykpXG4gICAgICAgICAgICApXG4gICAgICAgICk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY3JlYXRlKGNvbnZlcnNhdGlvbiwgbGFiZWxzKSB7XG4gICAgICAgIHZhciBkYXRhID0gY29udmVyc2F0aW9uICYmIHR5cGVvZiBjb252ZXJzYXRpb24gPT09ICdvYmplY3QnID8gY29udmVyc2F0aW9uIDoge307XG4gICAgICAgIHZhciBzcGVha2VycyA9IEFycmF5LmlzQXJyYXkoZGF0YS5zcGVha2VycykgPyBkYXRhLnNwZWFrZXJzIDogW107XG4gICAgICAgIHZhciB0aW1lbGluZSA9IEFycmF5LmlzQXJyYXkoZGF0YS50aW1lbGluZSkgPyBkYXRhLnRpbWVsaW5lIDogW107XG4gICAgICAgIHZhciB0ZXh0ID0gT2JqZWN0LmFzc2lnbih7IGFsbDogJ0FsbCcsIHNlYXJjaDogJ1NlYXJjaCcsIGVtcHR5OiAnJywgcGxheTogJycsIGRvd25sb2FkOiAnJyB9LCBsYWJlbHMgfHwge30pO1xuICAgICAgICB2YXIgcGxheWFibGUgPSBCb29sZWFuKHRleHQuYXVkaW9VcmwpO1xuICAgICAgICB2YXIgJHJvb3QgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC12aWV3JyB9KTtcbiAgICAgICAgdmFyICRsaXN0ID0gJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtbGlzdCcgfSk7XG5cbiAgICAgICAgdGltZWxpbmUuZm9yRWFjaChmdW5jdGlvbiAodHVybikge1xuICAgICAgICAgICAgaWYgKCF0dXJuIHx8IHR5cGVvZiB0dXJuICE9PSAnb2JqZWN0Jykge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICh0dXJuLmdhcEJlZm9yZUxhYmVsKSB7XG4gICAgICAgICAgICAgICAgJGxpc3QuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1nYXAnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgICAgICQoJzxzcGFuPicpLnRleHQoJ8K3wrfCtyAnICsgU3RyaW5nKHR1cm4uZ2FwQmVmb3JlTGFiZWwpKVxuICAgICAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICAgICAgKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgICRsaXN0LmFwcGVuZChjcmVhdGVUdXJuKHR1cm4sIHBsYXlhYmxlKSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRyb290LmFwcGVuZChcbiAgICAgICAgICAgIGNyZWF0ZU92ZXJ2aWV3KGRhdGEsIHRleHQpLFxuICAgICAgICAgICAgY3JlYXRlVG9vbGJhcihzcGVha2VycywgdGV4dCksXG4gICAgICAgICAgICAkbGlzdCxcbiAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC10cmFuc2NyaXB0LWVtcHR5IHVpIG1lc3NhZ2UnLCBoaWRkZW46IHRydWUgfSkudGV4dCh0ZXh0LmVtcHR5KVxuICAgICAgICApO1xuICAgICAgICBpbml0aWFsaXplKCRyb290KTtcblxuICAgICAgICByZXR1cm4gJHJvb3Q7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gZm9ybWF0VGltZShzZWNvbmRzKSB7XG4gICAgICAgIHZhciB0b3RhbCA9IE1hdGguZmxvb3IoTWF0aC5tYXgoMCwgc2Vjb25kcyB8fCAwKSk7XG4gICAgICAgIHZhciBob3VycyA9IE1hdGguZmxvb3IodG90YWwgLyAzNjAwKTtcbiAgICAgICAgdmFyIG1pbnV0ZXMgPSBNYXRoLmZsb29yKCh0b3RhbCAlIDM2MDApIC8gNjApO1xuICAgICAgICB2YXIgcmVzdCA9IHRvdGFsICUgNjA7XG5cbiAgICAgICAgaWYgKGhvdXJzID4gMCkge1xuICAgICAgICAgICAgcmV0dXJuIGhvdXJzICsgJzonICsgU3RyaW5nKG1pbnV0ZXMpLnBhZFN0YXJ0KDIsICcwJykgKyAnOicgKyBTdHJpbmcocmVzdCkucGFkU3RhcnQoMiwgJzAnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiBNYXRoLmZsb29yKHRvdGFsIC8gNjApICsgJzonICsgU3RyaW5nKHJlc3QpLnBhZFN0YXJ0KDIsICcwJyk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVBsYXllcigkcm9vdCkge1xuICAgICAgICB2YXIgYXVkaW8gPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtYXVkaW8nKS5nZXQoMCk7XG4gICAgICAgIHZhciAkcGxheWVyID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LXBsYXllcicpO1xuICAgICAgICB2YXIgJHBsYXlCdXR0b24gPSAkcGxheWVyLmZpbmQoJy5zdHQtcGxheWVyLXBsYXknKTtcbiAgICAgICAgdmFyICRwbGF5SWNvbiA9ICRwbGF5QnV0dG9uLmZpbmQoJ2knKTtcbiAgICAgICAgdmFyICRkdXJhdGlvbiA9ICRwbGF5ZXIuZmluZCgnLnN0dC1wbGF5ZXItZHVyYXRpb24nKTtcbiAgICAgICAgdmFyICRzZWVrID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LXNlZWsuaXMtcGxheWFibGUnKTtcbiAgICAgICAgdmFyICRyYW5nZSA9ICRzZWVrLmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1yYW5nZScpO1xuICAgICAgICB2YXIgJHBsYXloZWFkID0gJHNlZWsuZmluZCgnLnN0dC10cmFuc2NyaXB0LXBsYXloZWFkJyk7XG4gICAgICAgIHZhciAkdG9vbHRpcCA9ICRzZWVrLmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1zZWVrLXRvb2x0aXAnKTtcbiAgICAgICAgdmFyICRzY2FsZUVuZCA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1zY2FsZSBzcGFuJykubGFzdCgpO1xuICAgICAgICB2YXIgJHNlZ21lbnRzID0gJHNlZWsuZmluZCgnLnN0dC10cmFuc2NyaXB0LWxhbmUtc2VnbWVudCcpO1xuICAgICAgICB2YXIgJHR1cm5zID0gJHJvb3QuZmluZCgnLnN0dC1wbGF5YWJsZS10dXJuJyk7XG4gICAgICAgIHZhciBwZW5kaW5nUmFuZ2VWYWx1ZSA9IG51bGw7XG4gICAgICAgIHZhciB3YWl0aW5nRm9yTWV0YWRhdGEgPSBmYWxzZTtcbiAgICAgICAgdmFyIHN5bmNocm9uaXplZER1cmF0aW9uID0gMDtcblxuICAgICAgICBpZiAoIWF1ZGlvIHx8ICRyYW5nZS5sZW5ndGggPT09IDAgfHwgJHJvb3QuZGF0YSgnc3R0LXBsYXllci1pbml0aWFsaXplZCcpKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgJHJvb3QuZGF0YSgnc3R0LXBsYXllci1pbml0aWFsaXplZCcsIHRydWUpO1xuXG4gICAgICAgIGZ1bmN0aW9uIGZhbGxiYWNrRHVyYXRpb24oKSB7XG4gICAgICAgICAgICByZXR1cm4gc2FmZU51bWJlcigkcGxheWVyLmRhdGEoJ2R1cmF0aW9uJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY3VycmVudER1cmF0aW9uKCkge1xuICAgICAgICAgICAgcmV0dXJuIE51bWJlci5pc0Zpbml0ZShhdWRpby5kdXJhdGlvbikgJiYgYXVkaW8uZHVyYXRpb24gPiAwID8gYXVkaW8uZHVyYXRpb24gOiBmYWxsYmFja0R1cmF0aW9uKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBlbnN1cmVTb3VyY2UoKSB7XG4gICAgICAgICAgICBpZiAoYXVkaW8uZ2V0QXR0cmlidXRlKCdzcmMnKSkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIHNvdXJjZSA9IFN0cmluZyhhdWRpby5nZXRBdHRyaWJ1dGUoJ2RhdGEtc3JjJykgfHwgJycpO1xuICAgICAgICAgICAgaWYgKCFzb3VyY2UpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBhdWRpby5zZXRBdHRyaWJ1dGUoJ3NyYycsIHNvdXJjZSk7XG4gICAgICAgICAgICBhdWRpby5sb2FkKCk7XG5cbiAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2V0UGxheUljb24ocGxheWluZykge1xuICAgICAgICAgICAgJHBsYXlJY29uLnRvZ2dsZUNsYXNzKCdwbGF5JywgIXBsYXlpbmcpLnRvZ2dsZUNsYXNzKCdwYXVzZScsIHBsYXlpbmcpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2V0QWN0aXZlVHVybnMoKSB7XG4gICAgICAgICAgICB2YXIgY3VycmVudCA9IGF1ZGlvLmN1cnJlbnRUaW1lO1xuICAgICAgICAgICAgJHR1cm5zLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHZhciAkdHVybiA9ICQodGhpcyk7XG4gICAgICAgICAgICAgICAgdmFyIHN0YXJ0ID0gc2FmZU51bWJlcigkdHVybi5kYXRhKCdzdGFydCcpKTtcbiAgICAgICAgICAgICAgICB2YXIgZW5kID0gc2FmZU51bWJlcigkdHVybi5kYXRhKCdlbmQnKSk7XG4gICAgICAgICAgICAgICAgJHR1cm4udG9nZ2xlQ2xhc3MoJ3N0dC10dXJuLWFjdGl2ZScsIGN1cnJlbnQgPj0gc3RhcnQgJiYgY3VycmVudCA8PSBNYXRoLm1heChlbmQsIHN0YXJ0ICsgMSkpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzeW5jaHJvbml6ZVRpbWVsaW5lKGR1cmF0aW9uKSB7XG4gICAgICAgICAgICBpZiAoZHVyYXRpb24gPD0gMCB8fCBNYXRoLmFicyhkdXJhdGlvbiAtIHN5bmNocm9uaXplZER1cmF0aW9uKSA8IDAuMDEpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBzeW5jaHJvbml6ZWREdXJhdGlvbiA9IGR1cmF0aW9uO1xuICAgICAgICAgICAgJHNjYWxlRW5kLnRleHQoZm9ybWF0VGltZShkdXJhdGlvbikpO1xuICAgICAgICAgICAgJHNlZ21lbnRzLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHZhciAkc2VnbWVudCA9ICQodGhpcyk7XG4gICAgICAgICAgICAgICAgdmFyIHN0YXJ0ID0gTWF0aC5taW4oc2FmZU51bWJlcigkc2VnbWVudC5kYXRhKCdzdGFydCcpKSwgZHVyYXRpb24pO1xuICAgICAgICAgICAgICAgIHZhciBlbmQgPSBNYXRoLm1pbihNYXRoLm1heChzYWZlTnVtYmVyKCRzZWdtZW50LmRhdGEoJ2VuZCcpKSwgc3RhcnQpLCBkdXJhdGlvbik7XG4gICAgICAgICAgICAgICAgJHNlZ21lbnQuY3NzKHtcbiAgICAgICAgICAgICAgICAgICAgbGVmdDogKChzdGFydCAvIGR1cmF0aW9uKSAqIDEwMCkgKyAnJScsXG4gICAgICAgICAgICAgICAgICAgIHdpZHRoOiAoKChlbmQgLSBzdGFydCkgLyBkdXJhdGlvbikgKiAxMDApICsgJyUnXG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZSgpIHtcbiAgICAgICAgICAgIHZhciBkdXJhdGlvbiA9IGN1cnJlbnREdXJhdGlvbigpO1xuICAgICAgICAgICAgdmFyIHZhbHVlID0gZHVyYXRpb24gPiAwID8gTWF0aC5taW4oMTAwMCwgTWF0aC5yb3VuZCgoYXVkaW8uY3VycmVudFRpbWUgLyBkdXJhdGlvbikgKiAxMDAwKSkgOiAwO1xuICAgICAgICAgICAgc3luY2hyb25pemVUaW1lbGluZShkdXJhdGlvbik7XG4gICAgICAgICAgICAkcmFuZ2UudmFsKHZhbHVlKS5hdHRyKCdhcmlhLXZhbHVldGV4dCcsIGZvcm1hdFRpbWUoYXVkaW8uY3VycmVudFRpbWUpKTtcbiAgICAgICAgICAgICRwbGF5aGVhZC5jc3MoJ2xlZnQnLCAodmFsdWUgLyAxMCkgKyAnJScpO1xuICAgICAgICAgICAgJGR1cmF0aW9uLnRleHQoZm9ybWF0VGltZShhdWRpby5jdXJyZW50VGltZSkgKyAnIC8gJyArIChkdXJhdGlvbiA+IDAgPyBmb3JtYXRUaW1lKGR1cmF0aW9uKSA6ICctLTotLScpKTtcbiAgICAgICAgICAgIHNldEFjdGl2ZVR1cm5zKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBwbGF5KCkge1xuICAgICAgICAgICAgaWYgKCFlbnN1cmVTb3VyY2UoKSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHZhciByZXN1bHQgPSBhdWRpby5wbGF5KCk7XG4gICAgICAgICAgICBpZiAocmVzdWx0ICYmIHR5cGVvZiByZXN1bHQuY2F0Y2ggPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICAgICByZXN1bHQuY2F0Y2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBzZXRQbGF5SWNvbihmYWxzZSk7XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZWVrQnlSYW5nZSh2YWx1ZSkge1xuICAgICAgICAgICAgZnVuY3Rpb24gYXBwbHlTZWVrKCkge1xuICAgICAgICAgICAgICAgIHZhciBkdXJhdGlvbiA9IGN1cnJlbnREdXJhdGlvbigpO1xuICAgICAgICAgICAgICAgIGlmIChkdXJhdGlvbiA8PSAwKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgdmFyIHZhbHVlID0gcGVuZGluZ1JhbmdlVmFsdWU7XG4gICAgICAgICAgICAgICAgcGVuZGluZ1JhbmdlVmFsdWUgPSBudWxsO1xuICAgICAgICAgICAgICAgIGF1ZGlvLmN1cnJlbnRUaW1lID0gKGR1cmF0aW9uICogdmFsdWUpIC8gMTAwMDtcbiAgICAgICAgICAgICAgICB1cGRhdGUoKTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgcGVuZGluZ1JhbmdlVmFsdWUgPSB2YWx1ZTtcbiAgICAgICAgICAgIGlmICghZW5zdXJlU291cmNlKCkpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoYXVkaW8ucmVhZHlTdGF0ZSA+PSAxKSB7XG4gICAgICAgICAgICAgICAgYXBwbHlTZWVrKCk7XG4gICAgICAgICAgICB9IGVsc2UgaWYgKCF3YWl0aW5nRm9yTWV0YWRhdGEpIHtcbiAgICAgICAgICAgICAgICB3YWl0aW5nRm9yTWV0YWRhdGEgPSB0cnVlO1xuICAgICAgICAgICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2xvYWRlZG1ldGFkYXRhJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICB3YWl0aW5nRm9yTWV0YWRhdGEgPSBmYWxzZTtcbiAgICAgICAgICAgICAgICAgICAgYXBwbHlTZWVrKCk7XG4gICAgICAgICAgICAgICAgfSwgeyBvbmNlOiB0cnVlIH0pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2Vla1R1cm4oJHR1cm4pIHtcbiAgICAgICAgICAgIHZhciBzZWNvbmRzID0gc2FmZU51bWJlcigkdHVybi5kYXRhKCdzdGFydCcpKTtcblxuICAgICAgICAgICAgZnVuY3Rpb24gYXBwbHlTZWVrKCkge1xuICAgICAgICAgICAgICAgIGF1ZGlvLmN1cnJlbnRUaW1lID0gTWF0aC5taW4oc2Vjb25kcywgY3VycmVudER1cmF0aW9uKCkgfHwgc2Vjb25kcyk7XG4gICAgICAgICAgICAgICAgdXBkYXRlKCk7XG4gICAgICAgICAgICAgICAgcGxheSgpO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBpZiAoIWVuc3VyZVNvdXJjZSgpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGF1ZGlvLnJlYWR5U3RhdGUgPj0gMSkge1xuICAgICAgICAgICAgICAgIGFwcGx5U2VlaygpO1xuICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICBhdWRpby5hZGRFdmVudExpc3RlbmVyKCdsb2FkZWRtZXRhZGF0YScsIGFwcGx5U2VlaywgeyBvbmNlOiB0cnVlIH0pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc3luY2hyb25pemUoKSB7XG4gICAgICAgICAgICB1cGRhdGUoKTtcbiAgICAgICAgfVxuXG4gICAgICAgICRwbGF5QnV0dG9uLm9uKCdjbGljay5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaWYgKGF1ZGlvLnBhdXNlZCkge1xuICAgICAgICAgICAgICAgIHBsYXkoKTtcbiAgICAgICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAgICAgYXVkaW8ucGF1c2UoKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgICRyYW5nZS5vbignaW5wdXQuc3R0VHJhbnNjcmlwdCBjaGFuZ2Uuc3R0VHJhbnNjcmlwdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHNlZWtCeVJhbmdlKHNhZmVOdW1iZXIodGhpcy52YWx1ZSkpO1xuICAgICAgICB9KTtcbiAgICAgICAgJHNlZWsub24oJ21vdXNlbW92ZS5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICB2YXIgb2Zmc2V0ID0gJHNlZWsub2Zmc2V0KCk7XG4gICAgICAgICAgICB2YXIgd2lkdGggPSAkc2Vlay53aWR0aCgpO1xuICAgICAgICAgICAgdmFyIHBlcmNlbnQgPSB3aWR0aCA+IDAgPyBNYXRoLm1heCgwLCBNYXRoLm1pbigxMDAsICgoZXZlbnQucGFnZVggLSBvZmZzZXQubGVmdCkgLyB3aWR0aCkgKiAxMDApKSA6IDA7XG4gICAgICAgICAgICAkdG9vbHRpcC50ZXh0KGZvcm1hdFRpbWUoKGN1cnJlbnREdXJhdGlvbigpICogcGVyY2VudCkgLyAxMDApKS5jc3MoJ2xlZnQnLCBwZXJjZW50ICsgJyUnKTtcbiAgICAgICAgfSk7XG4gICAgICAgICR0dXJucy5vbignY2xpY2suc3R0VHJhbnNjcmlwdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHNlZWtUdXJuKCQodGhpcykpO1xuICAgICAgICB9KS5vbigna2V5ZG93bi5zdHRUcmFuc2NyaXB0JywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICBpZiAoZXZlbnQua2V5ID09PSAnRW50ZXInIHx8IGV2ZW50LmtleSA9PT0gJyAnKSB7XG4gICAgICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgICAgICBzZWVrVHVybigkKHRoaXMpKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG5cbiAgICAgICAgYXVkaW8uYWRkRXZlbnRMaXN0ZW5lcignbG9hZGVkbWV0YWRhdGEnLCBzeW5jaHJvbml6ZSk7XG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ3RpbWV1cGRhdGUnLCB1cGRhdGUpO1xuICAgICAgICBhdWRpby5hZGRFdmVudExpc3RlbmVyKCdwbGF5JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgc2V0UGxheUljb24odHJ1ZSk7XG4gICAgICAgIH0pO1xuICAgICAgICBhdWRpby5hZGRFdmVudExpc3RlbmVyKCdwYXVzZScsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHNldFBsYXlJY29uKGZhbHNlKTtcbiAgICAgICAgfSk7XG4gICAgICAgIGF1ZGlvLmFkZEV2ZW50TGlzdGVuZXIoJ2VuZGVkJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgYXVkaW8uY3VycmVudFRpbWUgPSAwO1xuICAgICAgICAgICAgdXBkYXRlKCk7XG4gICAgICAgIH0pO1xuICAgICAgICB1cGRhdGUoKTtcbiAgICAgICAgZW5zdXJlU291cmNlKCk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZShyb290KSB7XG4gICAgICAgIHZhciAkcm9vdCA9IHJvb3QgJiYgcm9vdC5qcXVlcnkgPyByb290IDogJChyb290KTtcbiAgICAgICAgdmFyICRpbnB1dCA9ICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1zZWFyY2ggaW5wdXQnKTtcbiAgICAgICAgdmFyICRmaWx0ZXJzID0gJHJvb3QuZmluZCgnLnN0dC10cmFuc2NyaXB0LWZpbHRlcicpO1xuICAgICAgICB2YXIgJHJvd3MgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtdHVybicpO1xuICAgICAgICB2YXIgJGdhcHMgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtZ2FwJyk7XG4gICAgICAgIHZhciAkZW1wdHkgPSAkcm9vdC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtZW1wdHknKTtcbiAgICAgICAgdmFyIGFjdGl2ZVNwZWFrZXIgPSAnYWxsJztcblxuICAgICAgICBpZiAoJHJvb3QubGVuZ3RoID09PSAwIHx8ICRyb290LmRhdGEoJ3N0dC10cmFuc2NyaXB0LWluaXRpYWxpemVkJykpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuICAgICAgICAkcm9vdC5kYXRhKCdzdHQtdHJhbnNjcmlwdC1pbml0aWFsaXplZCcsIHRydWUpO1xuXG4gICAgICAgIGZ1bmN0aW9uIGFwcGx5KCkge1xuICAgICAgICAgICAgdmFyIHF1ZXJ5ID0gU3RyaW5nKCRpbnB1dC52YWwoKSB8fCAnJykudHJpbSgpLnRvTG9jYWxlTG93ZXJDYXNlKCk7XG4gICAgICAgICAgICB2YXIgdmlzaWJsZSA9IDA7XG5cbiAgICAgICAgICAgICRyb3dzLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHZhciAkcm93ID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICB2YXIgc3BlYWtlck1hdGNoZXMgPSBhY3RpdmVTcGVha2VyID09PSAnYWxsJyB8fCAkcm93LmRhdGEoJ3NwZWFrZXInKSA9PT0gYWN0aXZlU3BlYWtlcjtcbiAgICAgICAgICAgICAgICB2YXIgdGV4dE1hdGNoZXMgPSBxdWVyeSA9PT0gJycgfHwgJHJvdy5maW5kKCcuc3R0LXR1cm4tdGV4dCcpLnRleHQoKS50b0xvY2FsZUxvd2VyQ2FzZSgpLmluZGV4T2YocXVlcnkpICE9PSAtMTtcbiAgICAgICAgICAgICAgICB2YXIgc2hvd24gPSBzcGVha2VyTWF0Y2hlcyAmJiB0ZXh0TWF0Y2hlcztcblxuICAgICAgICAgICAgICAgICRyb3cucHJvcCgnaGlkZGVuJywgIXNob3duKTtcbiAgICAgICAgICAgICAgICBpZiAoc2hvd24pIHtcbiAgICAgICAgICAgICAgICAgICAgdmlzaWJsZSArPSAxO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJGdhcHMucHJvcCgnaGlkZGVuJywgcXVlcnkgIT09ICcnIHx8IGFjdGl2ZVNwZWFrZXIgIT09ICdhbGwnKTtcbiAgICAgICAgICAgICRyb290LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1jb3VudCcpLnRleHQoU3RyaW5nKHZpc2libGUpKTtcbiAgICAgICAgICAgICRlbXB0eS5wcm9wKCdoaWRkZW4nLCB2aXNpYmxlICE9PSAwKTtcbiAgICAgICAgfVxuXG4gICAgICAgICRpbnB1dC5vbignaW5wdXQuc3R0VHJhbnNjcmlwdCcsIGFwcGx5KTtcbiAgICAgICAgJGZpbHRlcnMub24oJ2NsaWNrLnN0dFRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBhY3RpdmVTcGVha2VyID0gU3RyaW5nKCQodGhpcykuZGF0YSgnc3BlYWtlcicpIHx8ICdhbGwnKTtcbiAgICAgICAgICAgICRmaWx0ZXJzLnJlbW92ZUNsYXNzKCdhY3RpdmUnKS5hdHRyKCdhcmlhLXByZXNzZWQnLCAnZmFsc2UnKTtcbiAgICAgICAgICAgICQodGhpcykuYWRkQ2xhc3MoJ2FjdGl2ZScpLmF0dHIoJ2FyaWEtcHJlc3NlZCcsICd0cnVlJyk7XG4gICAgICAgICAgICBhcHBseSgpO1xuICAgICAgICB9KTtcbiAgICAgICAgYXBwbHkoKTtcbiAgICAgICAgaW5pdGlhbGl6ZVBsYXllcigkcm9vdCk7XG4gICAgfVxuXG4gICAgd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3ID0ge1xuICAgICAgICBjcmVhdGU6IGNyZWF0ZSxcbiAgICAgICAgaW5pdGlhbGl6ZTogaW5pdGlhbGl6ZVxuICAgIH07XG5cbiAgICAkKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgJCgnLnN0dC10cmFuc2NyaXB0LXZpZXcnKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGluaXRpYWxpemUodGhpcyk7XG4gICAgICAgIH0pO1xuICAgIH0pO1xufSh3aW5kb3csIGpRdWVyeSkpO1xuIl19
