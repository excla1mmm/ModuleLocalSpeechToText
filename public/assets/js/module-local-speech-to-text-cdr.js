"use strict";

function _typeof(obj) { "@babel/helpers - typeof"; if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof = function _typeof(obj) { return typeof obj; }; } else { _typeof = function _typeof(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof(obj); }

(function () {
  'use strict';

  var MAX_LOOKUP_BATCH = 100;
  $(function () {
    function translate(key) {
      if (typeof globalTranslate !== 'undefined' && globalTranslate && globalTranslate[key]) {
        return String(globalTranslate[key]);
      }

      return key;
    }

    function ensureModal() {
      var $modal = $('#stt-cdr-transcript-modal');

      if ($modal.length > 0) {
        return $modal;
      }

      var labels = {
        button: translate('module_local_speech_to_text_CdrTranscriptButton'),
        title: translate('module_local_speech_to_text_CdrTranscriptModalTitle'),
        loading: translate('module_local_speech_to_text_CdrTranscriptLoading'),
        loadError: translate('module_local_speech_to_text_CdrTranscriptLoadError'),
        notFound: translate('module_local_speech_to_text_CdrTranscriptNotFound'),
        empty: translate('module_local_speech_to_text_NoConversationSegments')
      };
      var $header = $('<div>', {
        "class": 'header'
      }).append($('<i>', {
        "class": 'comment alternate outline icon'
      }), document.createTextNode(labels.title));
      var $loading = $('<div>', {
        "class": 'stt-cdr-transcript-loading',
        role: 'status',
        'aria-live': 'polite'
      }).append($('<div>', {
        "class": 'ui active centered inline loader stt-cdr-transcript-loader'
      }), $('<div>').text(labels.loading));
      var $callMeta = $('<div>', {
        "class": 'stt-cdr-transcript-call-meta',
        hidden: true
      }).append($('<span>', {
        'data-field': 'date'
      }), $('<span>', {
        'data-field': 'source'
      }), $('<span>', {
        'data-field': 'destination'
      }), $('<span>', {
        'data-field': 'duration'
      }));
      $modal = $('<div>', {
        "class": 'ui large modal module-local-speech-to-text stt-cdr-transcript-modal',
        id: 'stt-cdr-transcript-modal',
        'data-label-button': labels.button,
        'data-label-load-error': labels.loadError,
        'data-label-not-found': labels.notFound,
        'data-label-empty': labels.empty
      }).append($('<i>', {
        "class": 'close icon'
      }), $header, $('<div>', {
        "class": 'content'
      }).append($loading, $('<div>', {
        "class": 'ui message stt-cdr-transcript-error',
        role: 'alert',
        'aria-live': 'assertive',
        hidden: true
      }), $callMeta, $('<div>', {
        "class": 'stt-cdr-transcript-conversation',
        hidden: true
      })));
      $('body').append($modal);
      return $modal;
    }

    var $table = $('#cdr-table');

    if ($table.length === 0) {
      return;
    }

    var $modal = ensureModal();

    if ($modal.length === 0 || typeof $.fn.DataTable === 'undefined') {
      return;
    }

    var labels = {
      button: String($modal.data('label-button') || ''),
      loadError: String($modal.data('label-load-error') || ''),
      notFound: String($modal.data('label-not-found') || ''),
      empty: String($modal.data('label-empty') || '')
    };
    var rootUrl = typeof globalRootUrl === 'string' ? globalRootUrl : '/admin-cabinet/';
    var lookupUrl = "".concat(rootUrl, "module-local-speech-to-text/cdr-transcript/lookup");
    var detailUrl = "".concat(rootUrl, "module-local-speech-to-text/cdr-transcript/detail");
    var transcriptUrl = "".concat(rootUrl, "module-local-speech-to-text/module-local-speech-to-text/transcript");
    var availability = Object.create(null);
    var pending = Object.create(null);
    var accessDenied = false;
    var detailRequest = null;

    function addAuthorizationHeader(xhr) {
      if (typeof TokenManager !== 'undefined' && TokenManager.accessToken) {
        xhr.setRequestHeader('Authorization', "Bearer ".concat(TokenManager.accessToken));
      }
    }

    function currentRows(dataTable) {
      var rows = [];
      document.querySelectorAll('#cdr-table tbody tr[id]').forEach(function (node) {
        var callId = String(node.id || '').trim();

        if (callId === '' || callId.length > 255) {
          return;
        }

        var cells = node.querySelectorAll(':scope > td');

        if (cells.length < 2) {
          return;
        }

        rows.push({
          node: $(node),
          data: dataTable.row(node).data(),
          callId: callId,
          actionsCell: $(cells[cells.length - 1]),
          hasActionColumn: cells.length > 5
        });
      });
      return rows;
    }

    function removeButtons() {
      $table.find('.stt-cdr-transcript-button').remove();
    }

    function addButton(row) {
      var $target = row.actionsCell;

      if ($target.length === 0 || $target.find('.stt-cdr-transcript-button').length > 0) {
        return;
      }

      var $button = $('<a>', {
        "class": 'stt-cdr-transcript-button',
        href: "".concat(transcriptUrl, "?id=").concat(encodeURIComponent(String(availability[row.callId]))),
        target: '_blank',
        rel: 'noopener',
        'aria-label': labels.button,
        title: labels.button,
        'data-content': labels.button
      }).attr('data-call-id', row.callId);
      $button[0].innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">' + '<path d="M4 13v-2M8 16V8M12 18V6M16 16V8M20 13v-2" /></svg>';

      if (row.hasActionColumn) {
        $target.prepend($button);
      } else {
        $target.addClass('stt-cdr-duration-cell');
        $button.addClass('stt-cdr-transcript-duration-button');
        $target.append($button);
      }

      if (typeof $button.popup === 'function') {
        $button.popup();
      }
    }

    function renderButtons(rows) {
      rows.forEach(function (row) {
        if (availability[row.callId] > 0) {
          addButton(row);
        } else if (Object.prototype.hasOwnProperty.call(availability, row.callId)) {
          row.node.find('.stt-cdr-transcript-button').remove();
        }
      });
    }

    function lookupBatch(batch, dataTable) {
      batch.forEach(function (callId) {
        pending[callId] = true;
      });
      $.ajax({
        url: lookupUrl,
        method: 'POST',
        contentType: 'application/json; charset=utf-8',
        dataType: 'json',
        data: JSON.stringify({
          call_ids: batch
        }),
        beforeSend: addAuthorizationHeader
      }).done(function (response) {
        var transcriptMap = response && response.transcriptMap && _typeof(response.transcriptMap) === 'object' ? response.transcriptMap : {};
        batch.forEach(function (callId) {
          if (Object.prototype.hasOwnProperty.call(transcriptMap, callId) && Number(transcriptMap[callId]) > 0) {
            availability[callId] = Number(transcriptMap[callId]);
          } else {
            delete availability[callId];
          }
        });
      }).fail(function (xhr) {
        if (xhr.status === 401 || xhr.status === 403) {
          accessDenied = true;
          removeButtons();
        }
      }).always(function () {
        batch.forEach(function (callId) {
          delete pending[callId];
        });

        if (!accessDenied) {
          renderButtons(currentRows(dataTable));
        }
      });
    }

    function refreshButtons(dataTable) {
      if (accessDenied) {
        return;
      }

      var rows = currentRows(dataTable);
      renderButtons(rows);
      var unseen = [];
      rows.forEach(function (row) {
        if (!Object.prototype.hasOwnProperty.call(availability, row.callId) && !pending[row.callId] && unseen.indexOf(row.callId) === -1) {
          unseen.push(row.callId);
        }
      });

      for (var offset = 0; offset < unseen.length; offset += MAX_LOOKUP_BATCH) {
        lookupBatch(unseen.slice(offset, offset + MAX_LOOKUP_BATCH), dataTable);
      }
    }

    function stopModalPlayback() {
      var audio = $modal.find('.stt-transcript-audio').get(0);

      if (!audio) {
        return;
      }

      audio.pause();
      audio.currentTime = 0;
      audio.removeAttribute('src');
      audio.load();
    }

    function clearConversation() {
      stopModalPlayback();
      $modal.find('.stt-cdr-transcript-conversation').attr('hidden', true).empty();
    }

    function resetModal(rowData) {
      clearConversation();
      $modal.find('.stt-cdr-transcript-loading').removeAttr('hidden');
      $modal.find('.stt-cdr-transcript-error').attr('hidden', true).removeClass('error').text('');
      var $meta = $modal.find('.stt-cdr-transcript-call-meta').removeAttr('hidden');
      $meta.find('[data-field="date"]').text(String(rowData[0] || ''));
      $meta.find('[data-field="source"]').text(String(rowData[1] || ''));
      $meta.find('[data-field="destination"]').text(String(rowData[2] || ''));
      $meta.find('[data-field="duration"]').text(String(rowData[3] || ''));
    } // The history row only ever holds bare numbers, and for an incoming
    // call with a DID it does not even hold the extension the transcript
    // is about. The detail response labels the call's own parties, so it
    // replaces those numbers once it arrives.


    function applyPartyLabels(parties) {
      if (!parties || _typeof(parties) !== 'object') {
        return;
      }

      var $meta = $modal.find('.stt-cdr-transcript-call-meta');
      ['source', 'destination'].forEach(function (field) {
        var label = String(parties[field] || '');

        if (label !== '') {
          $meta.find('[data-field="' + field + '"]').text(label);
        }
      });
    }

    function showError(message) {
      $modal.find('.stt-cdr-transcript-loading').attr('hidden', true);
      clearConversation();
      $modal.find('.stt-cdr-transcript-error').removeAttr('hidden').addClass('error').text(message);
    }

    function renderConversation(transcript, rowData) {
      var conversation = transcript && _typeof(transcript.conversation) === 'object' ? transcript.conversation : null;
      var timeline = conversation && Array.isArray(conversation.timeline) ? conversation.timeline : [];
      var recordings = rowData && Array.isArray(rowData[4]) ? rowData[4] : [];
      var playbackCdrId = transcript && transcript.playbackCdrId !== null && typeof transcript.playbackCdrId !== 'undefined' ? String(transcript.playbackCdrId) : '';
      var recording = {};
      recordings.some(function (item) {
        if (playbackCdrId !== '' && item && String(item.id) === playbackCdrId) {
          recording = item;
          return true;
        }

        return false;
      });
      clearConversation();
      var $target = $modal.find('.stt-cdr-transcript-conversation').removeAttr('hidden');
      $modal.find('.stt-cdr-transcript-loading').attr('hidden', true);
      $modal.find('.stt-cdr-transcript-error').attr('hidden', true).text('');

      if (timeline.length === 0) {
        $target.append($('<div>', {
          "class": 'ui message'
        }).text(labels.empty));
        return;
      }

      if (!window.SttTranscriptView || typeof window.SttTranscriptView.create !== 'function') {
        showError(labels.loadError);
        return;
      }

      $target.append(window.SttTranscriptView.create(conversation, {
        all: translate('module_local_speech_to_text_CdrHistoryAll'),
        search: translate('module_local_speech_to_text_LogsSearch'),
        empty: labels.empty,
        play: translate('module_local_speech_to_text_PlayRecording'),
        download: translate('module_local_speech_to_text_DownloadRecording'),
        audioUrl: String(recording.playback_url || ''),
        downloadUrl: String(recording.download_url || ''),
        durationSeconds: conversation.durationSeconds
      }));
    }

    function openTranscript(dataTable, $button) {
      var rowData = dataTable.row($button.closest('tr')).data();
      var callId = String($button.attr('data-call-id') || '').trim();

      if (!rowData || callId === '') {
        return;
      }

      if (detailRequest !== null) {
        detailRequest.abort();
      }

      resetModal(rowData);
      $modal.modal('show');
      detailRequest = $.ajax({
        url: detailUrl,
        method: 'GET',
        dataType: 'json',
        data: {
          call_id: callId
        },
        beforeSend: addAuthorizationHeader
      }).done(function (response) {
        applyPartyLabels(response && response.transcript ? response.transcript.parties : null);
        renderConversation(response && response.transcript ? response.transcript : null, rowData);
      }).fail(function (xhr, status) {
        if (status === 'abort') {
          return;
        }

        if (xhr.status === 404) {
          delete availability[callId];
          $button.remove();
          showError(labels.notFound);
          return;
        }

        if (xhr.status === 401 || xhr.status === 403) {
          accessDenied = true;
          removeButtons();
        }

        showError(labels.loadError);
      }).always(function () {
        detailRequest = null;
      });
    }

    function start(dataTable) {
      if ($table.data('stt-cdr-transcript-started')) {
        return;
      }

      $table.data('stt-cdr-transcript-started', true);
      $modal.modal({
        closable: true,
        onHidden: function onHidden() {
          if (detailRequest !== null) {
            detailRequest.abort();
            detailRequest = null;
          }

          clearConversation();
        }
      });
      $table.on('draw.dt.sttCdrTranscript', function () {
        refreshButtons(dataTable);
      });
      $table.on('click.sttCdrTranscript', '.stt-cdr-transcript-button', function (event) {
        event.stopPropagation();
        var $button = $(this);

        if (event.ctrlKey || event.metaKey) {
          event.preventDefault();
          var openedTab = window.open($button.attr('href'), '_blank');

          if (openedTab !== null) {
            openedTab.opener = null;
            openedTab.focus();
          }

          return;
        }

        event.preventDefault();
        openTranscript(dataTable, $button);
      });
      setTimeout(function () {
        refreshButtons(dataTable);
      }, 0);
    }

    if ($.fn.DataTable.isDataTable($table)) {
      start($table.DataTable());
    } else {
      $table.one('init.dt.sttCdrTranscript', function () {
        start($table.DataTable());
      });
    }
  });
})();
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtY2RyLmpzIl0sIm5hbWVzIjpbIk1BWF9MT09LVVBfQkFUQ0giLCIkIiwidHJhbnNsYXRlIiwia2V5IiwiZ2xvYmFsVHJhbnNsYXRlIiwiU3RyaW5nIiwiZW5zdXJlTW9kYWwiLCIkbW9kYWwiLCJsZW5ndGgiLCJsYWJlbHMiLCJidXR0b24iLCJ0aXRsZSIsImxvYWRpbmciLCJsb2FkRXJyb3IiLCJub3RGb3VuZCIsImVtcHR5IiwiJGhlYWRlciIsImFwcGVuZCIsImRvY3VtZW50IiwiY3JlYXRlVGV4dE5vZGUiLCIkbG9hZGluZyIsInJvbGUiLCJ0ZXh0IiwiJGNhbGxNZXRhIiwiaGlkZGVuIiwiaWQiLCIkdGFibGUiLCJmbiIsIkRhdGFUYWJsZSIsImRhdGEiLCJyb290VXJsIiwiZ2xvYmFsUm9vdFVybCIsImxvb2t1cFVybCIsImRldGFpbFVybCIsInRyYW5zY3JpcHRVcmwiLCJhdmFpbGFiaWxpdHkiLCJPYmplY3QiLCJjcmVhdGUiLCJwZW5kaW5nIiwiYWNjZXNzRGVuaWVkIiwiZGV0YWlsUmVxdWVzdCIsImFkZEF1dGhvcml6YXRpb25IZWFkZXIiLCJ4aHIiLCJUb2tlbk1hbmFnZXIiLCJhY2Nlc3NUb2tlbiIsInNldFJlcXVlc3RIZWFkZXIiLCJjdXJyZW50Um93cyIsImRhdGFUYWJsZSIsInJvd3MiLCJxdWVyeVNlbGVjdG9yQWxsIiwiZm9yRWFjaCIsIm5vZGUiLCJjYWxsSWQiLCJ0cmltIiwiY2VsbHMiLCJwdXNoIiwicm93IiwiYWN0aW9uc0NlbGwiLCJoYXNBY3Rpb25Db2x1bW4iLCJyZW1vdmVCdXR0b25zIiwiZmluZCIsInJlbW92ZSIsImFkZEJ1dHRvbiIsIiR0YXJnZXQiLCIkYnV0dG9uIiwiaHJlZiIsImVuY29kZVVSSUNvbXBvbmVudCIsInRhcmdldCIsInJlbCIsImF0dHIiLCJpbm5lckhUTUwiLCJwcmVwZW5kIiwiYWRkQ2xhc3MiLCJwb3B1cCIsInJlbmRlckJ1dHRvbnMiLCJwcm90b3R5cGUiLCJoYXNPd25Qcm9wZXJ0eSIsImNhbGwiLCJsb29rdXBCYXRjaCIsImJhdGNoIiwiYWpheCIsInVybCIsIm1ldGhvZCIsImNvbnRlbnRUeXBlIiwiZGF0YVR5cGUiLCJKU09OIiwic3RyaW5naWZ5IiwiY2FsbF9pZHMiLCJiZWZvcmVTZW5kIiwiZG9uZSIsInJlc3BvbnNlIiwidHJhbnNjcmlwdE1hcCIsIk51bWJlciIsImZhaWwiLCJzdGF0dXMiLCJhbHdheXMiLCJyZWZyZXNoQnV0dG9ucyIsInVuc2VlbiIsImluZGV4T2YiLCJvZmZzZXQiLCJzbGljZSIsInN0b3BNb2RhbFBsYXliYWNrIiwiYXVkaW8iLCJnZXQiLCJwYXVzZSIsImN1cnJlbnRUaW1lIiwicmVtb3ZlQXR0cmlidXRlIiwibG9hZCIsImNsZWFyQ29udmVyc2F0aW9uIiwicmVzZXRNb2RhbCIsInJvd0RhdGEiLCJyZW1vdmVBdHRyIiwicmVtb3ZlQ2xhc3MiLCIkbWV0YSIsImFwcGx5UGFydHlMYWJlbHMiLCJwYXJ0aWVzIiwiZmllbGQiLCJsYWJlbCIsInNob3dFcnJvciIsIm1lc3NhZ2UiLCJyZW5kZXJDb252ZXJzYXRpb24iLCJ0cmFuc2NyaXB0IiwiY29udmVyc2F0aW9uIiwidGltZWxpbmUiLCJBcnJheSIsImlzQXJyYXkiLCJyZWNvcmRpbmdzIiwicGxheWJhY2tDZHJJZCIsInJlY29yZGluZyIsInNvbWUiLCJpdGVtIiwid2luZG93IiwiU3R0VHJhbnNjcmlwdFZpZXciLCJhbGwiLCJzZWFyY2giLCJwbGF5IiwiZG93bmxvYWQiLCJhdWRpb1VybCIsInBsYXliYWNrX3VybCIsImRvd25sb2FkVXJsIiwiZG93bmxvYWRfdXJsIiwiZHVyYXRpb25TZWNvbmRzIiwib3BlblRyYW5zY3JpcHQiLCJjbG9zZXN0IiwiYWJvcnQiLCJtb2RhbCIsImNhbGxfaWQiLCJzdGFydCIsImNsb3NhYmxlIiwib25IaWRkZW4iLCJvbiIsImV2ZW50Iiwic3RvcFByb3BhZ2F0aW9uIiwiY3RybEtleSIsIm1ldGFLZXkiLCJwcmV2ZW50RGVmYXVsdCIsIm9wZW5lZFRhYiIsIm9wZW4iLCJvcGVuZXIiLCJmb2N1cyIsInNldFRpbWVvdXQiLCJpc0RhdGFUYWJsZSIsIm9uZSJdLCJtYXBwaW5ncyI6Ijs7OztBQUFDLGFBQVk7QUFDVDs7QUFFQSxNQUFNQSxnQkFBZ0IsR0FBRyxHQUF6QjtBQUVBQyxFQUFBQSxDQUFDLENBQUMsWUFBWTtBQUNWLGFBQVNDLFNBQVQsQ0FBbUJDLEdBQW5CLEVBQXdCO0FBQ3BCLFVBQUksT0FBT0MsZUFBUCxLQUEyQixXQUEzQixJQUEwQ0EsZUFBMUMsSUFBNkRBLGVBQWUsQ0FBQ0QsR0FBRCxDQUFoRixFQUF1RjtBQUNuRixlQUFPRSxNQUFNLENBQUNELGVBQWUsQ0FBQ0QsR0FBRCxDQUFoQixDQUFiO0FBQ0g7O0FBQ0QsYUFBT0EsR0FBUDtBQUNIOztBQUVELGFBQVNHLFdBQVQsR0FBdUI7QUFDbkIsVUFBSUMsTUFBTSxHQUFHTixDQUFDLENBQUMsMkJBQUQsQ0FBZDs7QUFDQSxVQUFJTSxNQUFNLENBQUNDLE1BQVAsR0FBZ0IsQ0FBcEIsRUFBdUI7QUFDbkIsZUFBT0QsTUFBUDtBQUNIOztBQUVELFVBQU1FLE1BQU0sR0FBRztBQUNYQyxRQUFBQSxNQUFNLEVBQUVSLFNBQVMsQ0FBQyxpREFBRCxDQUROO0FBRVhTLFFBQUFBLEtBQUssRUFBRVQsU0FBUyxDQUFDLHFEQUFELENBRkw7QUFHWFUsUUFBQUEsT0FBTyxFQUFFVixTQUFTLENBQUMsa0RBQUQsQ0FIUDtBQUlYVyxRQUFBQSxTQUFTLEVBQUVYLFNBQVMsQ0FBQyxvREFBRCxDQUpUO0FBS1hZLFFBQUFBLFFBQVEsRUFBRVosU0FBUyxDQUFDLG1EQUFELENBTFI7QUFNWGEsUUFBQUEsS0FBSyxFQUFFYixTQUFTLENBQUMsb0RBQUQ7QUFOTCxPQUFmO0FBUUEsVUFBTWMsT0FBTyxHQUFHZixDQUFDLENBQUMsT0FBRCxFQUFVO0FBQUUsaUJBQU87QUFBVCxPQUFWLENBQUQsQ0FBZ0NnQixNQUFoQyxDQUNaaEIsQ0FBQyxDQUFDLEtBQUQsRUFB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QUFEO0FBb1pILENBelpBLEdBQUQiLCJzb3VyY2VzQ29udGVudCI6WyIoZnVuY3Rpb24gKCkge1xuICAgICd1c2Ugc3RyaWN0JztcblxuICAgIGNvbnN0IE1BWF9MT09LVVBfQkFUQ0ggPSAxMDA7XG5cbiAgICAkKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgZnVuY3Rpb24gdHJhbnNsYXRlKGtleSkge1xuICAgICAgICAgICAgaWYgKHR5cGVvZiBnbG9iYWxUcmFuc2xhdGUgIT09ICd1bmRlZmluZWQnICYmIGdsb2JhbFRyYW5zbGF0ZSAmJiBnbG9iYWxUcmFuc2xhdGVba2V5XSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBTdHJpbmcoZ2xvYmFsVHJhbnNsYXRlW2tleV0pO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmV0dXJuIGtleTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGVuc3VyZU1vZGFsKCkge1xuICAgICAgICAgICAgbGV0ICRtb2RhbCA9ICQoJyNzdHQtY2RyLXRyYW5zY3JpcHQtbW9kYWwnKTtcbiAgICAgICAgICAgIGlmICgkbW9kYWwubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybiAkbW9kYWw7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGNvbnN0IGxhYmVscyA9IHtcbiAgICAgICAgICAgICAgICBidXR0b246IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0NkclRyYW5zY3JpcHRCdXR0b24nKSxcbiAgICAgICAgICAgICAgICB0aXRsZTogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RyVHJhbnNjcmlwdE1vZGFsVGl0bGUnKSxcbiAgICAgICAgICAgICAgICBsb2FkaW5nOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJUcmFuc2NyaXB0TG9hZGluZycpLFxuICAgICAgICAgICAgICAgIGxvYWRFcnJvcjogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RyVHJhbnNjcmlwdExvYWRFcnJvcicpLFxuICAgICAgICAgICAgICAgIG5vdEZvdW5kOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJUcmFuc2NyaXB0Tm90Rm91bmQnKSxcbiAgICAgICAgICAgICAgICBlbXB0eTogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTm9Db252ZXJzYXRpb25TZWdtZW50cycpLFxuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgIGNvbnN0ICRoZWFkZXIgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdoZWFkZXInIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8aT4nLCB7IGNsYXNzOiAnY29tbWVudCBhbHRlcm5hdGUgb3V0bGluZSBpY29uJyB9KSxcbiAgICAgICAgICAgICAgICBkb2N1bWVudC5jcmVhdGVUZXh0Tm9kZShsYWJlbHMudGl0bGUpXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgY29uc3QgJGxvYWRpbmcgPSAkKCc8ZGl2PicsIHtcbiAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC1jZHItdHJhbnNjcmlwdC1sb2FkaW5nJyxcbiAgICAgICAgICAgICAgICByb2xlOiAnc3RhdHVzJyxcbiAgICAgICAgICAgICAgICAnYXJpYS1saXZlJzogJ3BvbGl0ZScsXG4gICAgICAgICAgICB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAndWkgYWN0aXZlIGNlbnRlcmVkIGlubGluZSBsb2FkZXIgc3R0LWNkci10cmFuc2NyaXB0LWxvYWRlcicgfSksXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nKS50ZXh0KGxhYmVscy5sb2FkaW5nKVxuICAgICAgICAgICAgKTtcbiAgICAgICAgICAgIGNvbnN0ICRjYWxsTWV0YSA9ICQoJzxkaXY+Jywge1xuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LWNkci10cmFuc2NyaXB0LWNhbGwtbWV0YScsXG4gICAgICAgICAgICAgICAgaGlkZGVuOiB0cnVlLFxuICAgICAgICAgICAgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgJ2RhdGEtZmllbGQnOiAnZGF0ZScgfSksXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyAnZGF0YS1maWVsZCc6ICdzb3VyY2UnIH0pLFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgJ2RhdGEtZmllbGQnOiAnZGVzdGluYXRpb24nIH0pLFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgJ2RhdGEtZmllbGQnOiAnZHVyYXRpb24nIH0pXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgJG1vZGFsID0gJCgnPGRpdj4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICd1aSBsYXJnZSBtb2RhbCBtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgc3R0LWNkci10cmFuc2NyaXB0LW1vZGFsJyxcbiAgICAgICAgICAgICAgICBpZDogJ3N0dC1jZHItdHJhbnNjcmlwdC1tb2RhbCcsXG4gICAgICAgICAgICAgICAgJ2RhdGEtbGFiZWwtYnV0dG9uJzogbGFiZWxzLmJ1dHRvbixcbiAgICAgICAgICAgICAgICAnZGF0YS1sYWJlbC1sb2FkLWVycm9yJzogbGFiZWxzLmxvYWRFcnJvcixcbiAgICAgICAgICAgICAgICAnZGF0YS1sYWJlbC1ub3QtZm91bmQnOiBsYWJlbHMubm90Rm91bmQsXG4gICAgICAgICAgICAgICAgJ2RhdGEtbGFiZWwtZW1wdHknOiBsYWJlbHMuZW1wdHksXG4gICAgICAgICAgICB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGk+JywgeyBjbGFzczogJ2Nsb3NlIGljb24nIH0pLFxuICAgICAgICAgICAgICAgICRoZWFkZXIsXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnY29udGVudCcgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAkbG9hZGluZyxcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7XG4gICAgICAgICAgICAgICAgICAgICAgICBjbGFzczogJ3VpIG1lc3NhZ2Ugc3R0LWNkci10cmFuc2NyaXB0LWVycm9yJyxcbiAgICAgICAgICAgICAgICAgICAgICAgIHJvbGU6ICdhbGVydCcsXG4gICAgICAgICAgICAgICAgICAgICAgICAnYXJpYS1saXZlJzogJ2Fzc2VydGl2ZScsXG4gICAgICAgICAgICAgICAgICAgICAgICBoaWRkZW46IHRydWUsXG4gICAgICAgICAgICAgICAgICAgIH0pLFxuICAgICAgICAgICAgICAgICAgICAkY2FsbE1ldGEsXG4gICAgICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC1jZHItdHJhbnNjcmlwdC1jb252ZXJzYXRpb24nLCBoaWRkZW46IHRydWUgfSlcbiAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgJCgnYm9keScpLmFwcGVuZCgkbW9kYWwpO1xuICAgICAgICAgICAgcmV0dXJuICRtb2RhbDtcbiAgICAgICAgfVxuXG4gICAgICAgIGNvbnN0ICR0YWJsZSA9ICQoJyNjZHItdGFibGUnKTtcbiAgICAgICAgaWYgKCR0YWJsZS5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCAkbW9kYWwgPSBlbnN1cmVNb2RhbCgpO1xuICAgICAgICBpZiAoJG1vZGFsLmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJC5mbi5EYXRhVGFibGUgPT09ICd1bmRlZmluZWQnKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICBjb25zdCBsYWJlbHMgPSB7XG4gICAgICAgICAgICBidXR0b246IFN0cmluZygkbW9kYWwuZGF0YSgnbGFiZWwtYnV0dG9uJykgfHwgJycpLFxuICAgICAgICAgICAgbG9hZEVycm9yOiBTdHJpbmcoJG1vZGFsLmRhdGEoJ2xhYmVsLWxvYWQtZXJyb3InKSB8fCAnJyksXG4gICAgICAgICAgICBub3RGb3VuZDogU3RyaW5nKCRtb2RhbC5kYXRhKCdsYWJlbC1ub3QtZm91bmQnKSB8fCAnJyksXG4gICAgICAgICAgICBlbXB0eTogU3RyaW5nKCRtb2RhbC5kYXRhKCdsYWJlbC1lbXB0eScpIHx8ICcnKSxcbiAgICAgICAgfTtcbiAgICAgICAgY29uc3Qgcm9vdFVybCA9IHR5cGVvZiBnbG9iYWxSb290VXJsID09PSAnc3RyaW5nJyA/IGdsb2JhbFJvb3RVcmwgOiAnL2FkbWluLWNhYmluZXQvJztcbiAgICAgICAgY29uc3QgbG9va3VwVXJsID0gYCR7cm9vdFVybH1tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQvY2RyLXRyYW5zY3JpcHQvbG9va3VwYDtcbiAgICAgICAgY29uc3QgZGV0YWlsVXJsID0gYCR7cm9vdFVybH1tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQvY2RyLXRyYW5zY3JpcHQvZGV0YWlsYDtcbiAgICAgICAgY29uc3QgdHJhbnNjcmlwdFVybCA9IGAke3Jvb3RVcmx9bW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0L21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC90cmFuc2NyaXB0YDtcbiAgICAgICAgY29uc3QgYXZhaWxhYmlsaXR5ID0gT2JqZWN0LmNyZWF0ZShudWxsKTtcbiAgICAgICAgY29uc3QgcGVuZGluZyA9IE9iamVjdC5jcmVhdGUobnVsbCk7XG4gICAgICAgIGxldCBhY2Nlc3NEZW5pZWQgPSBmYWxzZTtcbiAgICAgICAgbGV0IGRldGFpbFJlcXVlc3QgPSBudWxsO1xuXG4gICAgICAgIGZ1bmN0aW9uIGFkZEF1dGhvcml6YXRpb25IZWFkZXIoeGhyKSB7XG4gICAgICAgICAgICBpZiAodHlwZW9mIFRva2VuTWFuYWdlciAhPT0gJ3VuZGVmaW5lZCcgJiYgVG9rZW5NYW5hZ2VyLmFjY2Vzc1Rva2VuKSB7XG4gICAgICAgICAgICAgICAgeGhyLnNldFJlcXVlc3RIZWFkZXIoJ0F1dGhvcml6YXRpb24nLCBgQmVhcmVyICR7VG9rZW5NYW5hZ2VyLmFjY2Vzc1Rva2VufWApO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY3VycmVudFJvd3MoZGF0YVRhYmxlKSB7XG4gICAgICAgICAgICBjb25zdCByb3dzID0gW107XG4gICAgICAgICAgICBkb2N1bWVudC5xdWVyeVNlbGVjdG9yQWxsKCcjY2RyLXRhYmxlIHRib2R5IHRyW2lkXScpLmZvckVhY2goZnVuY3Rpb24gKG5vZGUpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBjYWxsSWQgPSBTdHJpbmcobm9kZS5pZCB8fCAnJykudHJpbSgpO1xuICAgICAgICAgICAgICAgIGlmIChjYWxsSWQgPT09ICcnIHx8IGNhbGxJZC5sZW5ndGggPiAyNTUpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjb25zdCBjZWxscyA9IG5vZGUucXVlcnlTZWxlY3RvckFsbCgnOnNjb3BlID4gdGQnKTtcbiAgICAgICAgICAgICAgICBpZiAoY2VsbHMubGVuZ3RoIDwgMikge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHJvd3MucHVzaCh7XG4gICAgICAgICAgICAgICAgICAgIG5vZGU6ICQobm9kZSksXG4gICAgICAgICAgICAgICAgICAgIGRhdGE6IGRhdGFUYWJsZS5yb3cobm9kZSkuZGF0YSgpLFxuICAgICAgICAgICAgICAgICAgICBjYWxsSWQsXG4gICAgICAgICAgICAgICAgICAgIGFjdGlvbnNDZWxsOiAkKGNlbGxzW2NlbGxzLmxlbmd0aCAtIDFdKSxcbiAgICAgICAgICAgICAgICAgICAgaGFzQWN0aW9uQ29sdW1uOiBjZWxscy5sZW5ndGggPiA1LFxuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICByZXR1cm4gcm93cztcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbW92ZUJ1dHRvbnMoKSB7XG4gICAgICAgICAgICAkdGFibGUuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1idXR0b24nKS5yZW1vdmUoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGFkZEJ1dHRvbihyb3cpIHtcbiAgICAgICAgICAgIGNvbnN0ICR0YXJnZXQgPSByb3cuYWN0aW9uc0NlbGw7XG4gICAgICAgICAgICBpZiAoJHRhcmdldC5sZW5ndGggPT09IDAgfHwgJHRhcmdldC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWJ1dHRvbicpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGNvbnN0ICRidXR0b24gPSAkKCc8YT4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtY2RyLXRyYW5zY3JpcHQtYnV0dG9uJyxcbiAgICAgICAgICAgICAgICBocmVmOiBgJHt0cmFuc2NyaXB0VXJsfT9pZD0ke2VuY29kZVVSSUNvbXBvbmVudChTdHJpbmcoYXZhaWxhYmlsaXR5W3Jvdy5jYWxsSWRdKSl9YCxcbiAgICAgICAgICAgICAgICB0YXJnZXQ6ICdfYmxhbmsnLFxuICAgICAgICAgICAgICAgIHJlbDogJ25vb3BlbmVyJyxcbiAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5idXR0b24sXG4gICAgICAgICAgICAgICAgdGl0bGU6IGxhYmVscy5idXR0b24sXG4gICAgICAgICAgICAgICAgJ2RhdGEtY29udGVudCc6IGxhYmVscy5idXR0b24sXG4gICAgICAgICAgICB9KS5hdHRyKCdkYXRhLWNhbGwtaWQnLCByb3cuY2FsbElkKTtcbiAgICAgICAgICAgICRidXR0b25bMF0uaW5uZXJIVE1MID0gJzxzdmcgdmlld0JveD1cIjAgMCAyNCAyNFwiIGZpbGw9XCJub25lXCIgc3Ryb2tlPVwiY3VycmVudENvbG9yXCIgc3Ryb2tlLXdpZHRoPVwiMS44XCIgc3Ryb2tlLWxpbmVjYXA9XCJyb3VuZFwiIGFyaWEtaGlkZGVuPVwidHJ1ZVwiPidcbiAgICAgICAgICAgICAgICArICc8cGF0aCBkPVwiTTQgMTN2LTJNOCAxNlY4TTEyIDE4VjZNMTYgMTZWOE0yMCAxM3YtMlwiIC8+PC9zdmc+JztcbiAgICAgICAgICAgIGlmIChyb3cuaGFzQWN0aW9uQ29sdW1uKSB7XG4gICAgICAgICAgICAgICAgJHRhcmdldC5wcmVwZW5kKCRidXR0b24pO1xuICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICAkdGFyZ2V0LmFkZENsYXNzKCdzdHQtY2RyLWR1cmF0aW9uLWNlbGwnKTtcbiAgICAgICAgICAgICAgICAkYnV0dG9uLmFkZENsYXNzKCdzdHQtY2RyLXRyYW5zY3JpcHQtZHVyYXRpb24tYnV0dG9uJyk7XG4gICAgICAgICAgICAgICAgJHRhcmdldC5hcHBlbmQoJGJ1dHRvbik7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAodHlwZW9mICRidXR0b24ucG9wdXAgPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICAgICAkYnV0dG9uLnBvcHVwKCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJCdXR0b25zKHJvd3MpIHtcbiAgICAgICAgICAgIHJvd3MuZm9yRWFjaChmdW5jdGlvbiAocm93KSB7XG4gICAgICAgICAgICAgICAgaWYgKGF2YWlsYWJpbGl0eVtyb3cuY2FsbElkXSA+IDApIHtcbiAgICAgICAgICAgICAgICAgICAgYWRkQnV0dG9uKHJvdyk7XG4gICAgICAgICAgICAgICAgfSBlbHNlIGlmIChPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoYXZhaWxhYmlsaXR5LCByb3cuY2FsbElkKSkge1xuICAgICAgICAgICAgICAgICAgICByb3cubm9kZS5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWJ1dHRvbicpLnJlbW92ZSgpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gbG9va3VwQmF0Y2goYmF0Y2gsIGRhdGFUYWJsZSkge1xuICAgICAgICAgICAgYmF0Y2guZm9yRWFjaChmdW5jdGlvbiAoY2FsbElkKSB7XG4gICAgICAgICAgICAgICAgcGVuZGluZ1tjYWxsSWRdID0gdHJ1ZTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJC5hamF4KHtcbiAgICAgICAgICAgICAgICB1cmw6IGxvb2t1cFVybCxcbiAgICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgICAgICAgICAgICBjb250ZW50VHlwZTogJ2FwcGxpY2F0aW9uL2pzb247IGNoYXJzZXQ9dXRmLTgnLFxuICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbicsXG4gICAgICAgICAgICAgICAgZGF0YTogSlNPTi5zdHJpbmdpZnkoeyBjYWxsX2lkczogYmF0Y2ggfSksXG4gICAgICAgICAgICAgICAgYmVmb3JlU2VuZDogYWRkQXV0aG9yaXphdGlvbkhlYWRlcixcbiAgICAgICAgICAgIH0pLmRvbmUoZnVuY3Rpb24gKHJlc3BvbnNlKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgdHJhbnNjcmlwdE1hcCA9IHJlc3BvbnNlICYmIHJlc3BvbnNlLnRyYW5zY3JpcHRNYXAgJiYgdHlwZW9mIHJlc3BvbnNlLnRyYW5zY3JpcHRNYXAgPT09ICdvYmplY3QnXG4gICAgICAgICAgICAgICAgICAgID8gcmVzcG9uc2UudHJhbnNjcmlwdE1hcFxuICAgICAgICAgICAgICAgICAgICA6IHt9O1xuICAgICAgICAgICAgICAgIGJhdGNoLmZvckVhY2goZnVuY3Rpb24gKGNhbGxJZCkge1xuICAgICAgICAgICAgICAgICAgICBpZiAoXG4gICAgICAgICAgICAgICAgICAgICAgICBPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwodHJhbnNjcmlwdE1hcCwgY2FsbElkKVxuICAgICAgICAgICAgICAgICAgICAgICAgJiYgTnVtYmVyKHRyYW5zY3JpcHRNYXBbY2FsbElkXSkgPiAwXG4gICAgICAgICAgICAgICAgICAgICkge1xuICAgICAgICAgICAgICAgICAgICAgICAgYXZhaWxhYmlsaXR5W2NhbGxJZF0gPSBOdW1iZXIodHJhbnNjcmlwdE1hcFtjYWxsSWRdKTtcbiAgICAgICAgICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGRlbGV0ZSBhdmFpbGFiaWxpdHlbY2FsbElkXTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfSkuZmFpbChmdW5jdGlvbiAoeGhyKSB7XG4gICAgICAgICAgICAgICAgaWYgKHhoci5zdGF0dXMgPT09IDQwMSB8fCB4aHIuc3RhdHVzID09PSA0MDMpIHtcbiAgICAgICAgICAgICAgICAgICAgYWNjZXNzRGVuaWVkID0gdHJ1ZTtcbiAgICAgICAgICAgICAgICAgICAgcmVtb3ZlQnV0dG9ucygpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pLmFsd2F5cyhmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgYmF0Y2guZm9yRWFjaChmdW5jdGlvbiAoY2FsbElkKSB7XG4gICAgICAgICAgICAgICAgICAgIGRlbGV0ZSBwZW5kaW5nW2NhbGxJZF07XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAgICAgaWYgKCFhY2Nlc3NEZW5pZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmVuZGVyQnV0dG9ucyhjdXJyZW50Um93cyhkYXRhVGFibGUpKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlZnJlc2hCdXR0b25zKGRhdGFUYWJsZSkge1xuICAgICAgICAgICAgaWYgKGFjY2Vzc0RlbmllZCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNvbnN0IHJvd3MgPSBjdXJyZW50Um93cyhkYXRhVGFibGUpO1xuICAgICAgICAgICAgcmVuZGVyQnV0dG9ucyhyb3dzKTtcbiAgICAgICAgICAgIGNvbnN0IHVuc2VlbiA9IFtdO1xuICAgICAgICAgICAgcm93cy5mb3JFYWNoKGZ1bmN0aW9uIChyb3cpIHtcbiAgICAgICAgICAgICAgICBpZiAoXG4gICAgICAgICAgICAgICAgICAgICFPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoYXZhaWxhYmlsaXR5LCByb3cuY2FsbElkKVxuICAgICAgICAgICAgICAgICAgICAmJiAhcGVuZGluZ1tyb3cuY2FsbElkXVxuICAgICAgICAgICAgICAgICAgICAmJiB1bnNlZW4uaW5kZXhPZihyb3cuY2FsbElkKSA9PT0gLTFcbiAgICAgICAgICAgICAgICApIHtcbiAgICAgICAgICAgICAgICAgICAgdW5zZWVuLnB1c2gocm93LmNhbGxJZCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBmb3IgKGxldCBvZmZzZXQgPSAwOyBvZmZzZXQgPCB1bnNlZW4ubGVuZ3RoOyBvZmZzZXQgKz0gTUFYX0xPT0tVUF9CQVRDSCkge1xuICAgICAgICAgICAgICAgIGxvb2t1cEJhdGNoKHVuc2Vlbi5zbGljZShvZmZzZXQsIG9mZnNldCArIE1BWF9MT09LVVBfQkFUQ0gpLCBkYXRhVGFibGUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc3RvcE1vZGFsUGxheWJhY2soKSB7XG4gICAgICAgICAgICBjb25zdCBhdWRpbyA9ICRtb2RhbC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtYXVkaW8nKS5nZXQoMCk7XG4gICAgICAgICAgICBpZiAoIWF1ZGlvKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgYXVkaW8ucGF1c2UoKTtcbiAgICAgICAgICAgIGF1ZGlvLmN1cnJlbnRUaW1lID0gMDtcbiAgICAgICAgICAgIGF1ZGlvLnJlbW92ZUF0dHJpYnV0ZSgnc3JjJyk7XG4gICAgICAgICAgICBhdWRpby5sb2FkKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjbGVhckNvbnZlcnNhdGlvbigpIHtcbiAgICAgICAgICAgIHN0b3BNb2RhbFBsYXliYWNrKCk7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1jb252ZXJzYXRpb24nKS5hdHRyKCdoaWRkZW4nLCB0cnVlKS5lbXB0eSgpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVzZXRNb2RhbChyb3dEYXRhKSB7XG4gICAgICAgICAgICBjbGVhckNvbnZlcnNhdGlvbigpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtbG9hZGluZycpLnJlbW92ZUF0dHIoJ2hpZGRlbicpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtZXJyb3InKS5hdHRyKCdoaWRkZW4nLCB0cnVlKS5yZW1vdmVDbGFzcygnZXJyb3InKS50ZXh0KCcnKTtcbiAgICAgICAgICAgIGNvbnN0ICRtZXRhID0gJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtY2FsbC1tZXRhJykucmVtb3ZlQXR0cignaGlkZGVuJyk7XG4gICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cImRhdGVcIl0nKS50ZXh0KFN0cmluZyhyb3dEYXRhWzBdIHx8ICcnKSk7XG4gICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cInNvdXJjZVwiXScpLnRleHQoU3RyaW5nKHJvd0RhdGFbMV0gfHwgJycpKTtcbiAgICAgICAgICAgICRtZXRhLmZpbmQoJ1tkYXRhLWZpZWxkPVwiZGVzdGluYXRpb25cIl0nKS50ZXh0KFN0cmluZyhyb3dEYXRhWzJdIHx8ICcnKSk7XG4gICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cImR1cmF0aW9uXCJdJykudGV4dChTdHJpbmcocm93RGF0YVszXSB8fCAnJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgLy8gVGhlIGhpc3Rvcnkgcm93IG9ubHkgZXZlciBob2xkcyBiYXJlIG51bWJlcnMsIGFuZCBmb3IgYW4gaW5jb21pbmdcbiAgICAgICAgLy8gY2FsbCB3aXRoIGEgRElEIGl0IGRvZXMgbm90IGV2ZW4gaG9sZCB0aGUgZXh0ZW5zaW9uIHRoZSB0cmFuc2NyaXB0XG4gICAgICAgIC8vIGlzIGFib3V0LiBUaGUgZGV0YWlsIHJlc3BvbnNlIGxhYmVscyB0aGUgY2FsbCdzIG93biBwYXJ0aWVzLCBzbyBpdFxuICAgICAgICAvLyByZXBsYWNlcyB0aG9zZSBudW1iZXJzIG9uY2UgaXQgYXJyaXZlcy5cbiAgICAgICAgZnVuY3Rpb24gYXBwbHlQYXJ0eUxhYmVscyhwYXJ0aWVzKSB7XG4gICAgICAgICAgICBpZiAoIXBhcnRpZXMgfHwgdHlwZW9mIHBhcnRpZXMgIT09ICdvYmplY3QnKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY29uc3QgJG1ldGEgPSAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1jYWxsLW1ldGEnKTtcbiAgICAgICAgICAgIFsnc291cmNlJywgJ2Rlc3RpbmF0aW9uJ10uZm9yRWFjaChmdW5jdGlvbiAoZmllbGQpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBsYWJlbCA9IFN0cmluZyhwYXJ0aWVzW2ZpZWxkXSB8fCAnJyk7XG4gICAgICAgICAgICAgICAgaWYgKGxhYmVsICE9PSAnJykge1xuICAgICAgICAgICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cIicgKyBmaWVsZCArICdcIl0nKS50ZXh0KGxhYmVsKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNob3dFcnJvcihtZXNzYWdlKSB7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1sb2FkaW5nJykuYXR0cignaGlkZGVuJywgdHJ1ZSk7XG4gICAgICAgICAgICBjbGVhckNvbnZlcnNhdGlvbigpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtZXJyb3InKS5yZW1vdmVBdHRyKCdoaWRkZW4nKS5hZGRDbGFzcygnZXJyb3InKS50ZXh0KG1lc3NhZ2UpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVuZGVyQ29udmVyc2F0aW9uKHRyYW5zY3JpcHQsIHJvd0RhdGEpIHtcbiAgICAgICAgICAgIGNvbnN0IGNvbnZlcnNhdGlvbiA9IHRyYW5zY3JpcHQgJiYgdHlwZW9mIHRyYW5zY3JpcHQuY29udmVyc2F0aW9uID09PSAnb2JqZWN0J1xuICAgICAgICAgICAgICAgID8gdHJhbnNjcmlwdC5jb252ZXJzYXRpb25cbiAgICAgICAgICAgICAgICA6IG51bGw7XG4gICAgICAgICAgICBjb25zdCB0aW1lbGluZSA9IGNvbnZlcnNhdGlvbiAmJiBBcnJheS5pc0FycmF5KGNvbnZlcnNhdGlvbi50aW1lbGluZSkgPyBjb252ZXJzYXRpb24udGltZWxpbmUgOiBbXTtcbiAgICAgICAgICAgIGNvbnN0IHJlY29yZGluZ3MgPSByb3dEYXRhICYmIEFycmF5LmlzQXJyYXkocm93RGF0YVs0XSkgPyByb3dEYXRhWzRdIDogW107XG4gICAgICAgICAgICBjb25zdCBwbGF5YmFja0NkcklkID0gdHJhbnNjcmlwdCAmJiB0cmFuc2NyaXB0LnBsYXliYWNrQ2RySWQgIT09IG51bGxcbiAgICAgICAgICAgICAgICAmJiB0eXBlb2YgdHJhbnNjcmlwdC5wbGF5YmFja0NkcklkICE9PSAndW5kZWZpbmVkJ1xuICAgICAgICAgICAgICAgID8gU3RyaW5nKHRyYW5zY3JpcHQucGxheWJhY2tDZHJJZClcbiAgICAgICAgICAgICAgICA6ICcnO1xuICAgICAgICAgICAgbGV0IHJlY29yZGluZyA9IHt9O1xuICAgICAgICAgICAgcmVjb3JkaW5ncy5zb21lKGZ1bmN0aW9uIChpdGVtKSB7XG4gICAgICAgICAgICAgICAgaWYgKHBsYXliYWNrQ2RySWQgIT09ICcnICYmIGl0ZW0gJiYgU3RyaW5nKGl0ZW0uaWQpID09PSBwbGF5YmFja0NkcklkKSB7XG4gICAgICAgICAgICAgICAgICAgIHJlY29yZGluZyA9IGl0ZW07XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIGNsZWFyQ29udmVyc2F0aW9uKCk7XG4gICAgICAgICAgICBjb25zdCAkdGFyZ2V0ID0gJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtY29udmVyc2F0aW9uJykucmVtb3ZlQXR0cignaGlkZGVuJyk7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1sb2FkaW5nJykuYXR0cignaGlkZGVuJywgdHJ1ZSk7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1lcnJvcicpLmF0dHIoJ2hpZGRlbicsIHRydWUpLnRleHQoJycpO1xuICAgICAgICAgICAgaWYgKHRpbWVsaW5lLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgICAgICR0YXJnZXQuYXBwZW5kKCQoJzxkaXY+JywgeyBjbGFzczogJ3VpIG1lc3NhZ2UnIH0pLnRleHQobGFiZWxzLmVtcHR5KSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKCF3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcgfHwgdHlwZW9mIHdpbmRvdy5TdHRUcmFuc2NyaXB0Vmlldy5jcmVhdGUgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICAgICBzaG93RXJyb3IobGFiZWxzLmxvYWRFcnJvcik7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgJHRhcmdldC5hcHBlbmQod2luZG93LlN0dFRyYW5zY3JpcHRWaWV3LmNyZWF0ZShjb252ZXJzYXRpb24sIHtcbiAgICAgICAgICAgICAgICBhbGw6IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnlBbGwnKSxcbiAgICAgICAgICAgICAgICBzZWFyY2g6IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NTZWFyY2gnKSxcbiAgICAgICAgICAgICAgICBlbXB0eTogbGFiZWxzLmVtcHR5LFxuICAgICAgICAgICAgICAgIHBsYXk6IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1BsYXlSZWNvcmRpbmcnKSxcbiAgICAgICAgICAgICAgICBkb3dubG9hZDogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfRG93bmxvYWRSZWNvcmRpbmcnKSxcbiAgICAgICAgICAgICAgICBhdWRpb1VybDogU3RyaW5nKHJlY29yZGluZy5wbGF5YmFja191cmwgfHwgJycpLFxuICAgICAgICAgICAgICAgIGRvd25sb2FkVXJsOiBTdHJpbmcocmVjb3JkaW5nLmRvd25sb2FkX3VybCB8fCAnJyksXG4gICAgICAgICAgICAgICAgZHVyYXRpb25TZWNvbmRzOiBjb252ZXJzYXRpb24uZHVyYXRpb25TZWNvbmRzLFxuICAgICAgICAgICAgfSkpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gb3BlblRyYW5zY3JpcHQoZGF0YVRhYmxlLCAkYnV0dG9uKSB7XG4gICAgICAgICAgICBjb25zdCByb3dEYXRhID0gZGF0YVRhYmxlLnJvdygkYnV0dG9uLmNsb3Nlc3QoJ3RyJykpLmRhdGEoKTtcbiAgICAgICAgICAgIGNvbnN0IGNhbGxJZCA9IFN0cmluZygkYnV0dG9uLmF0dHIoJ2RhdGEtY2FsbC1pZCcpIHx8ICcnKS50cmltKCk7XG4gICAgICAgICAgICBpZiAoIXJvd0RhdGEgfHwgY2FsbElkID09PSAnJykge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgaWYgKGRldGFpbFJlcXVlc3QgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICBkZXRhaWxSZXF1ZXN0LmFib3J0KCk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZXNldE1vZGFsKHJvd0RhdGEpO1xuICAgICAgICAgICAgJG1vZGFsLm1vZGFsKCdzaG93Jyk7XG4gICAgICAgICAgICBkZXRhaWxSZXF1ZXN0ID0gJC5hamF4KHtcbiAgICAgICAgICAgICAgICB1cmw6IGRldGFpbFVybCxcbiAgICAgICAgICAgICAgICBtZXRob2Q6ICdHRVQnLFxuICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbicsXG4gICAgICAgICAgICAgICAgZGF0YTogeyBjYWxsX2lkOiBjYWxsSWQgfSxcbiAgICAgICAgICAgICAgICBiZWZvcmVTZW5kOiBhZGRBdXRob3JpemF0aW9uSGVhZGVyLFxuICAgICAgICAgICAgfSkuZG9uZShmdW5jdGlvbiAocmVzcG9uc2UpIHtcbiAgICAgICAgICAgICAgICBhcHBseVBhcnR5TGFiZWxzKHJlc3BvbnNlICYmIHJlc3BvbnNlLnRyYW5zY3JpcHQgPyByZXNwb25zZS50cmFuc2NyaXB0LnBhcnRpZXMgOiBudWxsKTtcbiAgICAgICAgICAgICAgICByZW5kZXJDb252ZXJzYXRpb24ocmVzcG9uc2UgJiYgcmVzcG9uc2UudHJhbnNjcmlwdCA/IHJlc3BvbnNlLnRyYW5zY3JpcHQgOiBudWxsLCByb3dEYXRhKTtcbiAgICAgICAgICAgIH0pLmZhaWwoZnVuY3Rpb24gKHhociwgc3RhdHVzKSB7XG4gICAgICAgICAgICAgICAgaWYgKHN0YXR1cyA9PT0gJ2Fib3J0Jykge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmICh4aHIuc3RhdHVzID09PSA0MDQpIHtcbiAgICAgICAgICAgICAgICAgICAgZGVsZXRlIGF2YWlsYWJpbGl0eVtjYWxsSWRdO1xuICAgICAgICAgICAgICAgICAgICAkYnV0dG9uLnJlbW92ZSgpO1xuICAgICAgICAgICAgICAgICAgICBzaG93RXJyb3IobGFiZWxzLm5vdEZvdW5kKTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBpZiAoeGhyLnN0YXR1cyA9PT0gNDAxIHx8IHhoci5zdGF0dXMgPT09IDQwMykge1xuICAgICAgICAgICAgICAgICAgICBhY2Nlc3NEZW5pZWQgPSB0cnVlO1xuICAgICAgICAgICAgICAgICAgICByZW1vdmVCdXR0b25zKCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHNob3dFcnJvcihsYWJlbHMubG9hZEVycm9yKTtcbiAgICAgICAgICAgIH0pLmFsd2F5cyhmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgZGV0YWlsUmVxdWVzdCA9IG51bGw7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHN0YXJ0KGRhdGFUYWJsZSkge1xuICAgICAgICAgICAgaWYgKCR0YWJsZS5kYXRhKCdzdHQtY2RyLXRyYW5zY3JpcHQtc3RhcnRlZCcpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgJHRhYmxlLmRhdGEoJ3N0dC1jZHItdHJhbnNjcmlwdC1zdGFydGVkJywgdHJ1ZSk7XG4gICAgICAgICAgICAkbW9kYWwubW9kYWwoe1xuICAgICAgICAgICAgICAgIGNsb3NhYmxlOiB0cnVlLFxuICAgICAgICAgICAgICAgIG9uSGlkZGVuOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIGlmIChkZXRhaWxSZXF1ZXN0ICE9PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBkZXRhaWxSZXF1ZXN0LmFib3J0KCk7XG4gICAgICAgICAgICAgICAgICAgICAgICBkZXRhaWxSZXF1ZXN0ID0gbnVsbDtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBjbGVhckNvbnZlcnNhdGlvbigpO1xuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICR0YWJsZS5vbignZHJhdy5kdC5zdHRDZHJUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHJlZnJlc2hCdXR0b25zKGRhdGFUYWJsZSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICR0YWJsZS5vbignY2xpY2suc3R0Q2RyVHJhbnNjcmlwdCcsICcuc3R0LWNkci10cmFuc2NyaXB0LWJ1dHRvbicsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgICAgIGV2ZW50LnN0b3BQcm9wYWdhdGlvbigpO1xuICAgICAgICAgICAgICAgIGNvbnN0ICRidXR0b24gPSAkKHRoaXMpO1xuICAgICAgICAgICAgICAgIGlmIChldmVudC5jdHJsS2V5IHx8IGV2ZW50Lm1ldGFLZXkpIHtcbiAgICAgICAgICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3BlbmVkVGFiID0gd2luZG93Lm9wZW4oJGJ1dHRvbi5hdHRyKCdocmVmJyksICdfYmxhbmsnKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKG9wZW5lZFRhYiAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgb3BlbmVkVGFiLm9wZW5lciA9IG51bGw7XG4gICAgICAgICAgICAgICAgICAgICAgICBvcGVuZWRUYWIuZm9jdXMoKTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICAgICAgb3BlblRyYW5zY3JpcHQoZGF0YVRhYmxlLCAkYnV0dG9uKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgc2V0VGltZW91dChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgcmVmcmVzaEJ1dHRvbnMoZGF0YVRhYmxlKTtcbiAgICAgICAgICAgIH0sIDApO1xuICAgICAgICB9XG5cbiAgICAgICAgaWYgKCQuZm4uRGF0YVRhYmxlLmlzRGF0YVRhYmxlKCR0YWJsZSkpIHtcbiAgICAgICAgICAgIHN0YXJ0KCR0YWJsZS5EYXRhVGFibGUoKSk7XG4gICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAkdGFibGUub25lKCdpbml0LmR0LnN0dENkclRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgc3RhcnQoJHRhYmxlLkRhdGFUYWJsZSgpKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG4gICAgfSk7XG59KCkpO1xuIl19