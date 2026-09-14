function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
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
        class: 'header'
      }).append($('<i>', {
        class: 'comment alternate outline icon'
      }), document.createTextNode(labels.title));
      var $loading = $('<div>', {
        class: 'stt-cdr-transcript-loading',
        role: 'status',
        'aria-live': 'polite'
      }).append($('<div>', {
        class: 'ui active centered inline loader stt-cdr-transcript-loader'
      }), $('<div>').text(labels.loading));
      var $callMeta = $('<div>', {
        class: 'stt-cdr-transcript-call-meta',
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
        class: 'ui large modal module-local-speech-to-text stt-cdr-transcript-modal',
        id: 'stt-cdr-transcript-modal',
        'data-label-button': labels.button,
        'data-label-load-error': labels.loadError,
        'data-label-not-found': labels.notFound,
        'data-label-empty': labels.empty
      }).append($('<i>', {
        class: 'close icon'
      }), $header, $('<div>', {
        class: 'content'
      }).append($loading, $('<div>', {
        class: 'ui message stt-cdr-transcript-error',
        role: 'alert',
        'aria-live': 'assertive',
        hidden: true
      }), $callMeta, $('<div>', {
        class: 'stt-cdr-transcript-conversation',
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
        class: 'stt-cdr-transcript-button',
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
          class: 'ui message'
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
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJuYW1lcyI6WyJNQVhfTE9PS1VQX0JBVENIIiwiJCIsInRyYW5zbGF0ZSIsImtleSIsImdsb2JhbFRyYW5zbGF0ZSIsIlN0cmluZyIsImVuc3VyZU1vZGFsIiwiJG1vZGFsIiwibGVuZ3RoIiwibGFiZWxzIiwiYnV0dG9uIiwidGl0bGUiLCJsb2FkaW5nIiwibG9hZEVycm9yIiwibm90Rm91bmQiLCJlbXB0eSIsIiRoZWFkZXIiLCJjbGFzcyIsImFwcGVuZCIsImRvY3VtZW50IiwiY3JlYXRlVGV4dE5vZGUiLCIkbG9hZGluZyIsInJvbGUiLCJ0ZXh0IiwiJGNhbGxNZXRhIiwiaGlkZGVuIiwiaWQiLCIkdGFibGUiLCJmbiIsIkRhdGFUYWJsZSIsImRhdGEiLCJyb290VXJsIiwiZ2xvYmFsUm9vdFVybCIsImxvb2t1cFVybCIsImNvbmNhdCIsImRldGFpbFVybCIsInRyYW5zY3JpcHRVcmwiLCJhdmFpbGFiaWxpdHkiLCJPYmplY3QiLCJjcmVhdGUiLCJwZW5kaW5nIiwiYWNjZXNzRGVuaWVkIiwiZGV0YWlsUmVxdWVzdCIsImFkZEF1dGhvcml6YXRpb25IZWFkZXIiLCJ4aHIiLCJUb2tlbk1hbmFnZXIiLCJhY2Nlc3NUb2tlbiIsInNldFJlcXVlc3RIZWFkZXIiLCJjdXJyZW50Um93cyIsImRhdGFUYWJsZSIsInJvd3MiLCJxdWVyeVNlbGVjdG9yQWxsIiwiZm9yRWFjaCIsIm5vZGUiLCJjYWxsSWQiLCJ0cmltIiwiY2VsbHMiLCJwdXNoIiwicm93IiwiYWN0aW9uc0NlbGwiLCJoYXNBY3Rpb25Db2x1bW4iLCJyZW1vdmVCdXR0b25zIiwiZmluZCIsInJlbW92ZSIsImFkZEJ1dHRvbiIsIiR0YXJnZXQiLCIkYnV0dG9uIiwiaHJlZiIsImVuY29kZVVSSUNvbXBvbmVudCIsInRhcmdldCIsInJlbCIsImF0dHIiLCJpbm5lckhUTUwiLCJwcmVwZW5kIiwiYWRkQ2xhc3MiLCJwb3B1cCIsInJlbmRlckJ1dHRvbnMiLCJwcm90b3R5cGUiLCJoYXNPd25Qcm9wZXJ0eSIsImNhbGwiLCJsb29rdXBCYXRjaCIsImJhdGNoIiwiYWpheCIsInVybCIsIm1ldGhvZCIsImNvbnRlbnRUeXBlIiwiZGF0YVR5cGUiLCJKU09OIiwic3RyaW5naWZ5IiwiY2FsbF9pZHMiLCJiZWZvcmVTZW5kIiwiZG9uZSIsInJlc3BvbnNlIiwidHJhbnNjcmlwdE1hcCIsIl90eXBlb2YiLCJOdW1iZXIiLCJmYWlsIiwic3RhdHVzIiwiYWx3YXlzIiwicmVmcmVzaEJ1dHRvbnMiLCJ1bnNlZW4iLCJpbmRleE9mIiwib2Zmc2V0Iiwic2xpY2UiLCJzdG9wTW9kYWxQbGF5YmFjayIsImF1ZGlvIiwiZ2V0IiwicGF1c2UiLCJjdXJyZW50VGltZSIsInJlbW92ZUF0dHJpYnV0ZSIsImxvYWQiLCJjbGVhckNvbnZlcnNhdGlvbiIsInJlc2V0TW9kYWwiLCJyb3dEYXRhIiwicmVtb3ZlQXR0ciIsInJlbW92ZUNsYXNzIiwiJG1ldGEiLCJzaG93RXJyb3IiLCJtZXNzYWdlIiwicmVuZGVyQ29udmVyc2F0aW9uIiwidHJhbnNjcmlwdCIsImNvbnZlcnNhdGlvbiIsInRpbWVsaW5lIiwiQXJyYXkiLCJpc0FycmF5IiwicmVjb3JkaW5ncyIsInBsYXliYWNrQ2RySWQiLCJyZWNvcmRpbmciLCJzb21lIiwiaXRlbSIsIndpbmRvdyIsIlN0dFRyYW5zY3JpcHRWaWV3IiwiYWxsIiwic2VhcmNoIiwicGxheSIsImRvd25sb2FkIiwiYXVkaW9VcmwiLCJwbGF5YmFja191cmwiLCJkb3dubG9hZFVybCIsImRvd25sb2FkX3VybCIsImR1cmF0aW9uU2Vjb25kcyIsIm9wZW5UcmFuc2NyaXB0IiwiY2xvc2VzdCIsImFib3J0IiwibW9kYWwiLCJjYWxsX2lkIiwic3RhcnQiLCJjbG9zYWJsZSIsIm9uSGlkZGVuIiwib24iLCJldmVudCIsInN0b3BQcm9wYWdhdGlvbiIsImN0cmxLZXkiLCJtZXRhS2V5IiwicHJldmVudERlZmF1bHQiLCJvcGVuZWRUYWIiLCJvcGVuIiwib3BlbmVyIiwiZm9jdXMiLCJzZXRUaW1lb3V0IiwiaXNEYXRhVGFibGUiLCJvbmUiXSwic291cmNlcyI6WyJzcmMvbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LWNkci5qcyJdLCJzb3VyY2VzQ29udGVudCI6WyIoZnVuY3Rpb24gKCkge1xuICAgICd1c2Ugc3RyaWN0JztcblxuICAgIGNvbnN0IE1BWF9MT09LVVBfQkFUQ0ggPSAxMDA7XG5cbiAgICAkKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgZnVuY3Rpb24gdHJhbnNsYXRlKGtleSkge1xuICAgICAgICAgICAgaWYgKHR5cGVvZiBnbG9iYWxUcmFuc2xhdGUgIT09ICd1bmRlZmluZWQnICYmIGdsb2JhbFRyYW5zbGF0ZSAmJiBnbG9iYWxUcmFuc2xhdGVba2V5XSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBTdHJpbmcoZ2xvYmFsVHJhbnNsYXRlW2tleV0pO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmV0dXJuIGtleTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGVuc3VyZU1vZGFsKCkge1xuICAgICAgICAgICAgbGV0ICRtb2RhbCA9ICQoJyNzdHQtY2RyLXRyYW5zY3JpcHQtbW9kYWwnKTtcbiAgICAgICAgICAgIGlmICgkbW9kYWwubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybiAkbW9kYWw7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGNvbnN0IGxhYmVscyA9IHtcbiAgICAgICAgICAgICAgICBidXR0b246IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0NkclRyYW5zY3JpcHRCdXR0b24nKSxcbiAgICAgICAgICAgICAgICB0aXRsZTogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RyVHJhbnNjcmlwdE1vZGFsVGl0bGUnKSxcbiAgICAgICAgICAgICAgICBsb2FkaW5nOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJUcmFuc2NyaXB0TG9hZGluZycpLFxuICAgICAgICAgICAgICAgIGxvYWRFcnJvcjogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RyVHJhbnNjcmlwdExvYWRFcnJvcicpLFxuICAgICAgICAgICAgICAgIG5vdEZvdW5kOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJUcmFuc2NyaXB0Tm90Rm91bmQnKSxcbiAgICAgICAgICAgICAgICBlbXB0eTogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTm9Db252ZXJzYXRpb25TZWdtZW50cycpLFxuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgIGNvbnN0ICRoZWFkZXIgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdoZWFkZXInIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8aT4nLCB7IGNsYXNzOiAnY29tbWVudCBhbHRlcm5hdGUgb3V0bGluZSBpY29uJyB9KSxcbiAgICAgICAgICAgICAgICBkb2N1bWVudC5jcmVhdGVUZXh0Tm9kZShsYWJlbHMudGl0bGUpXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgY29uc3QgJGxvYWRpbmcgPSAkKCc8ZGl2PicsIHtcbiAgICAgICAgICAgICAgICBjbGFzczogJ3N0dC1jZHItdHJhbnNjcmlwdC1sb2FkaW5nJyxcbiAgICAgICAgICAgICAgICByb2xlOiAnc3RhdHVzJyxcbiAgICAgICAgICAgICAgICAnYXJpYS1saXZlJzogJ3BvbGl0ZScsXG4gICAgICAgICAgICB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAndWkgYWN0aXZlIGNlbnRlcmVkIGlubGluZSBsb2FkZXIgc3R0LWNkci10cmFuc2NyaXB0LWxvYWRlcicgfSksXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nKS50ZXh0KGxhYmVscy5sb2FkaW5nKVxuICAgICAgICAgICAgKTtcbiAgICAgICAgICAgIGNvbnN0ICRjYWxsTWV0YSA9ICQoJzxkaXY+Jywge1xuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LWNkci10cmFuc2NyaXB0LWNhbGwtbWV0YScsXG4gICAgICAgICAgICAgICAgaGlkZGVuOiB0cnVlLFxuICAgICAgICAgICAgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgJ2RhdGEtZmllbGQnOiAnZGF0ZScgfSksXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyAnZGF0YS1maWVsZCc6ICdzb3VyY2UnIH0pLFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgJ2RhdGEtZmllbGQnOiAnZGVzdGluYXRpb24nIH0pLFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgJ2RhdGEtZmllbGQnOiAnZHVyYXRpb24nIH0pXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgJG1vZGFsID0gJCgnPGRpdj4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICd1aSBsYXJnZSBtb2RhbCBtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgc3R0LWNkci10cmFuc2NyaXB0LW1vZGFsJyxcbiAgICAgICAgICAgICAgICBpZDogJ3N0dC1jZHItdHJhbnNjcmlwdC1tb2RhbCcsXG4gICAgICAgICAgICAgICAgJ2RhdGEtbGFiZWwtYnV0dG9uJzogbGFiZWxzLmJ1dHRvbixcbiAgICAgICAgICAgICAgICAnZGF0YS1sYWJlbC1sb2FkLWVycm9yJzogbGFiZWxzLmxvYWRFcnJvcixcbiAgICAgICAgICAgICAgICAnZGF0YS1sYWJlbC1ub3QtZm91bmQnOiBsYWJlbHMubm90Rm91bmQsXG4gICAgICAgICAgICAgICAgJ2RhdGEtbGFiZWwtZW1wdHknOiBsYWJlbHMuZW1wdHksXG4gICAgICAgICAgICB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGk+JywgeyBjbGFzczogJ2Nsb3NlIGljb24nIH0pLFxuICAgICAgICAgICAgICAgICRoZWFkZXIsXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnY29udGVudCcgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAkbG9hZGluZyxcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7XG4gICAgICAgICAgICAgICAgICAgICAgICBjbGFzczogJ3VpIG1lc3NhZ2Ugc3R0LWNkci10cmFuc2NyaXB0LWVycm9yJyxcbiAgICAgICAgICAgICAgICAgICAgICAgIHJvbGU6ICdhbGVydCcsXG4gICAgICAgICAgICAgICAgICAgICAgICAnYXJpYS1saXZlJzogJ2Fzc2VydGl2ZScsXG4gICAgICAgICAgICAgICAgICAgICAgICBoaWRkZW46IHRydWUsXG4gICAgICAgICAgICAgICAgICAgIH0pLFxuICAgICAgICAgICAgICAgICAgICAkY2FsbE1ldGEsXG4gICAgICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC1jZHItdHJhbnNjcmlwdC1jb252ZXJzYXRpb24nLCBoaWRkZW46IHRydWUgfSlcbiAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgJCgnYm9keScpLmFwcGVuZCgkbW9kYWwpO1xuICAgICAgICAgICAgcmV0dXJuICRtb2RhbDtcbiAgICAgICAgfVxuXG4gICAgICAgIGNvbnN0ICR0YWJsZSA9ICQoJyNjZHItdGFibGUnKTtcbiAgICAgICAgaWYgKCR0YWJsZS5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCAkbW9kYWwgPSBlbnN1cmVNb2RhbCgpO1xuICAgICAgICBpZiAoJG1vZGFsLmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJC5mbi5EYXRhVGFibGUgPT09ICd1bmRlZmluZWQnKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICBjb25zdCBsYWJlbHMgPSB7XG4gICAgICAgICAgICBidXR0b246IFN0cmluZygkbW9kYWwuZGF0YSgnbGFiZWwtYnV0dG9uJykgfHwgJycpLFxuICAgICAgICAgICAgbG9hZEVycm9yOiBTdHJpbmcoJG1vZGFsLmRhdGEoJ2xhYmVsLWxvYWQtZXJyb3InKSB8fCAnJyksXG4gICAgICAgICAgICBub3RGb3VuZDogU3RyaW5nKCRtb2RhbC5kYXRhKCdsYWJlbC1ub3QtZm91bmQnKSB8fCAnJyksXG4gICAgICAgICAgICBlbXB0eTogU3RyaW5nKCRtb2RhbC5kYXRhKCdsYWJlbC1lbXB0eScpIHx8ICcnKSxcbiAgICAgICAgfTtcbiAgICAgICAgY29uc3Qgcm9vdFVybCA9IHR5cGVvZiBnbG9iYWxSb290VXJsID09PSAnc3RyaW5nJyA/IGdsb2JhbFJvb3RVcmwgOiAnL2FkbWluLWNhYmluZXQvJztcbiAgICAgICAgY29uc3QgbG9va3VwVXJsID0gYCR7cm9vdFVybH1tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQvY2RyLXRyYW5zY3JpcHQvbG9va3VwYDtcbiAgICAgICAgY29uc3QgZGV0YWlsVXJsID0gYCR7cm9vdFVybH1tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQvY2RyLXRyYW5zY3JpcHQvZGV0YWlsYDtcbiAgICAgICAgY29uc3QgdHJhbnNjcmlwdFVybCA9IGAke3Jvb3RVcmx9bW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0L21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC90cmFuc2NyaXB0YDtcbiAgICAgICAgY29uc3QgYXZhaWxhYmlsaXR5ID0gT2JqZWN0LmNyZWF0ZShudWxsKTtcbiAgICAgICAgY29uc3QgcGVuZGluZyA9IE9iamVjdC5jcmVhdGUobnVsbCk7XG4gICAgICAgIGxldCBhY2Nlc3NEZW5pZWQgPSBmYWxzZTtcbiAgICAgICAgbGV0IGRldGFpbFJlcXVlc3QgPSBudWxsO1xuXG4gICAgICAgIGZ1bmN0aW9uIGFkZEF1dGhvcml6YXRpb25IZWFkZXIoeGhyKSB7XG4gICAgICAgICAgICBpZiAodHlwZW9mIFRva2VuTWFuYWdlciAhPT0gJ3VuZGVmaW5lZCcgJiYgVG9rZW5NYW5hZ2VyLmFjY2Vzc1Rva2VuKSB7XG4gICAgICAgICAgICAgICAgeGhyLnNldFJlcXVlc3RIZWFkZXIoJ0F1dGhvcml6YXRpb24nLCBgQmVhcmVyICR7VG9rZW5NYW5hZ2VyLmFjY2Vzc1Rva2VufWApO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY3VycmVudFJvd3MoZGF0YVRhYmxlKSB7XG4gICAgICAgICAgICBjb25zdCByb3dzID0gW107XG4gICAgICAgICAgICBkb2N1bWVudC5xdWVyeVNlbGVjdG9yQWxsKCcjY2RyLXRhYmxlIHRib2R5IHRyW2lkXScpLmZvckVhY2goZnVuY3Rpb24gKG5vZGUpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBjYWxsSWQgPSBTdHJpbmcobm9kZS5pZCB8fCAnJykudHJpbSgpO1xuICAgICAgICAgICAgICAgIGlmIChjYWxsSWQgPT09ICcnIHx8IGNhbGxJZC5sZW5ndGggPiAyNTUpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjb25zdCBjZWxscyA9IG5vZGUucXVlcnlTZWxlY3RvckFsbCgnOnNjb3BlID4gdGQnKTtcbiAgICAgICAgICAgICAgICBpZiAoY2VsbHMubGVuZ3RoIDwgMikge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHJvd3MucHVzaCh7XG4gICAgICAgICAgICAgICAgICAgIG5vZGU6ICQobm9kZSksXG4gICAgICAgICAgICAgICAgICAgIGRhdGE6IGRhdGFUYWJsZS5yb3cobm9kZSkuZGF0YSgpLFxuICAgICAgICAgICAgICAgICAgICBjYWxsSWQsXG4gICAgICAgICAgICAgICAgICAgIGFjdGlvbnNDZWxsOiAkKGNlbGxzW2NlbGxzLmxlbmd0aCAtIDFdKSxcbiAgICAgICAgICAgICAgICAgICAgaGFzQWN0aW9uQ29sdW1uOiBjZWxscy5sZW5ndGggPiA1LFxuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICByZXR1cm4gcm93cztcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbW92ZUJ1dHRvbnMoKSB7XG4gICAgICAgICAgICAkdGFibGUuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1idXR0b24nKS5yZW1vdmUoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGFkZEJ1dHRvbihyb3cpIHtcbiAgICAgICAgICAgIGNvbnN0ICR0YXJnZXQgPSByb3cuYWN0aW9uc0NlbGw7XG4gICAgICAgICAgICBpZiAoJHRhcmdldC5sZW5ndGggPT09IDAgfHwgJHRhcmdldC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWJ1dHRvbicpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGNvbnN0ICRidXR0b24gPSAkKCc8YT4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtY2RyLXRyYW5zY3JpcHQtYnV0dG9uJyxcbiAgICAgICAgICAgICAgICBocmVmOiBgJHt0cmFuc2NyaXB0VXJsfT9pZD0ke2VuY29kZVVSSUNvbXBvbmVudChTdHJpbmcoYXZhaWxhYmlsaXR5W3Jvdy5jYWxsSWRdKSl9YCxcbiAgICAgICAgICAgICAgICB0YXJnZXQ6ICdfYmxhbmsnLFxuICAgICAgICAgICAgICAgIHJlbDogJ25vb3BlbmVyJyxcbiAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5idXR0b24sXG4gICAgICAgICAgICAgICAgdGl0bGU6IGxhYmVscy5idXR0b24sXG4gICAgICAgICAgICAgICAgJ2RhdGEtY29udGVudCc6IGxhYmVscy5idXR0b24sXG4gICAgICAgICAgICB9KS5hdHRyKCdkYXRhLWNhbGwtaWQnLCByb3cuY2FsbElkKTtcbiAgICAgICAgICAgICRidXR0b25bMF0uaW5uZXJIVE1MID0gJzxzdmcgdmlld0JveD1cIjAgMCAyNCAyNFwiIGZpbGw9XCJub25lXCIgc3Ryb2tlPVwiY3VycmVudENvbG9yXCIgc3Ryb2tlLXdpZHRoPVwiMS44XCIgc3Ryb2tlLWxpbmVjYXA9XCJyb3VuZFwiIGFyaWEtaGlkZGVuPVwidHJ1ZVwiPidcbiAgICAgICAgICAgICAgICArICc8cGF0aCBkPVwiTTQgMTN2LTJNOCAxNlY4TTEyIDE4VjZNMTYgMTZWOE0yMCAxM3YtMlwiIC8+PC9zdmc+JztcbiAgICAgICAgICAgIGlmIChyb3cuaGFzQWN0aW9uQ29sdW1uKSB7XG4gICAgICAgICAgICAgICAgJHRhcmdldC5wcmVwZW5kKCRidXR0b24pO1xuICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICAkdGFyZ2V0LmFkZENsYXNzKCdzdHQtY2RyLWR1cmF0aW9uLWNlbGwnKTtcbiAgICAgICAgICAgICAgICAkYnV0dG9uLmFkZENsYXNzKCdzdHQtY2RyLXRyYW5zY3JpcHQtZHVyYXRpb24tYnV0dG9uJyk7XG4gICAgICAgICAgICAgICAgJHRhcmdldC5hcHBlbmQoJGJ1dHRvbik7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAodHlwZW9mICRidXR0b24ucG9wdXAgPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICAgICAkYnV0dG9uLnBvcHVwKCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJCdXR0b25zKHJvd3MpIHtcbiAgICAgICAgICAgIHJvd3MuZm9yRWFjaChmdW5jdGlvbiAocm93KSB7XG4gICAgICAgICAgICAgICAgaWYgKGF2YWlsYWJpbGl0eVtyb3cuY2FsbElkXSA+IDApIHtcbiAgICAgICAgICAgICAgICAgICAgYWRkQnV0dG9uKHJvdyk7XG4gICAgICAgICAgICAgICAgfSBlbHNlIGlmIChPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoYXZhaWxhYmlsaXR5LCByb3cuY2FsbElkKSkge1xuICAgICAgICAgICAgICAgICAgICByb3cubm9kZS5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWJ1dHRvbicpLnJlbW92ZSgpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gbG9va3VwQmF0Y2goYmF0Y2gsIGRhdGFUYWJsZSkge1xuICAgICAgICAgICAgYmF0Y2guZm9yRWFjaChmdW5jdGlvbiAoY2FsbElkKSB7XG4gICAgICAgICAgICAgICAgcGVuZGluZ1tjYWxsSWRdID0gdHJ1ZTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJC5hamF4KHtcbiAgICAgICAgICAgICAgICB1cmw6IGxvb2t1cFVybCxcbiAgICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgICAgICAgICAgICBjb250ZW50VHlwZTogJ2FwcGxpY2F0aW9uL2pzb247IGNoYXJzZXQ9dXRmLTgnLFxuICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbicsXG4gICAgICAgICAgICAgICAgZGF0YTogSlNPTi5zdHJpbmdpZnkoeyBjYWxsX2lkczogYmF0Y2ggfSksXG4gICAgICAgICAgICAgICAgYmVmb3JlU2VuZDogYWRkQXV0aG9yaXphdGlvbkhlYWRlcixcbiAgICAgICAgICAgIH0pLmRvbmUoZnVuY3Rpb24gKHJlc3BvbnNlKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgdHJhbnNjcmlwdE1hcCA9IHJlc3BvbnNlICYmIHJlc3BvbnNlLnRyYW5zY3JpcHRNYXAgJiYgdHlwZW9mIHJlc3BvbnNlLnRyYW5zY3JpcHRNYXAgPT09ICdvYmplY3QnXG4gICAgICAgICAgICAgICAgICAgID8gcmVzcG9uc2UudHJhbnNjcmlwdE1hcFxuICAgICAgICAgICAgICAgICAgICA6IHt9O1xuICAgICAgICAgICAgICAgIGJhdGNoLmZvckVhY2goZnVuY3Rpb24gKGNhbGxJZCkge1xuICAgICAgICAgICAgICAgICAgICBpZiAoXG4gICAgICAgICAgICAgICAgICAgICAgICBPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwodHJhbnNjcmlwdE1hcCwgY2FsbElkKVxuICAgICAgICAgICAgICAgICAgICAgICAgJiYgTnVtYmVyKHRyYW5zY3JpcHRNYXBbY2FsbElkXSkgPiAwXG4gICAgICAgICAgICAgICAgICAgICkge1xuICAgICAgICAgICAgICAgICAgICAgICAgYXZhaWxhYmlsaXR5W2NhbGxJZF0gPSBOdW1iZXIodHJhbnNjcmlwdE1hcFtjYWxsSWRdKTtcbiAgICAgICAgICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGRlbGV0ZSBhdmFpbGFiaWxpdHlbY2FsbElkXTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfSkuZmFpbChmdW5jdGlvbiAoeGhyKSB7XG4gICAgICAgICAgICAgICAgaWYgKHhoci5zdGF0dXMgPT09IDQwMSB8fCB4aHIuc3RhdHVzID09PSA0MDMpIHtcbiAgICAgICAgICAgICAgICAgICAgYWNjZXNzRGVuaWVkID0gdHJ1ZTtcbiAgICAgICAgICAgICAgICAgICAgcmVtb3ZlQnV0dG9ucygpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pLmFsd2F5cyhmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgYmF0Y2guZm9yRWFjaChmdW5jdGlvbiAoY2FsbElkKSB7XG4gICAgICAgICAgICAgICAgICAgIGRlbGV0ZSBwZW5kaW5nW2NhbGxJZF07XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAgICAgaWYgKCFhY2Nlc3NEZW5pZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmVuZGVyQnV0dG9ucyhjdXJyZW50Um93cyhkYXRhVGFibGUpKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlZnJlc2hCdXR0b25zKGRhdGFUYWJsZSkge1xuICAgICAgICAgICAgaWYgKGFjY2Vzc0RlbmllZCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNvbnN0IHJvd3MgPSBjdXJyZW50Um93cyhkYXRhVGFibGUpO1xuICAgICAgICAgICAgcmVuZGVyQnV0dG9ucyhyb3dzKTtcbiAgICAgICAgICAgIGNvbnN0IHVuc2VlbiA9IFtdO1xuICAgICAgICAgICAgcm93cy5mb3JFYWNoKGZ1bmN0aW9uIChyb3cpIHtcbiAgICAgICAgICAgICAgICBpZiAoXG4gICAgICAgICAgICAgICAgICAgICFPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoYXZhaWxhYmlsaXR5LCByb3cuY2FsbElkKVxuICAgICAgICAgICAgICAgICAgICAmJiAhcGVuZGluZ1tyb3cuY2FsbElkXVxuICAgICAgICAgICAgICAgICAgICAmJiB1bnNlZW4uaW5kZXhPZihyb3cuY2FsbElkKSA9PT0gLTFcbiAgICAgICAgICAgICAgICApIHtcbiAgICAgICAgICAgICAgICAgICAgdW5zZWVuLnB1c2gocm93LmNhbGxJZCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBmb3IgKGxldCBvZmZzZXQgPSAwOyBvZmZzZXQgPCB1bnNlZW4ubGVuZ3RoOyBvZmZzZXQgKz0gTUFYX0xPT0tVUF9CQVRDSCkge1xuICAgICAgICAgICAgICAgIGxvb2t1cEJhdGNoKHVuc2Vlbi5zbGljZShvZmZzZXQsIG9mZnNldCArIE1BWF9MT09LVVBfQkFUQ0gpLCBkYXRhVGFibGUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc3RvcE1vZGFsUGxheWJhY2soKSB7XG4gICAgICAgICAgICBjb25zdCBhdWRpbyA9ICRtb2RhbC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtYXVkaW8nKS5nZXQoMCk7XG4gICAgICAgICAgICBpZiAoIWF1ZGlvKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgYXVkaW8ucGF1c2UoKTtcbiAgICAgICAgICAgIGF1ZGlvLmN1cnJlbnRUaW1lID0gMDtcbiAgICAgICAgICAgIGF1ZGlvLnJlbW92ZUF0dHJpYnV0ZSgnc3JjJyk7XG4gICAgICAgICAgICBhdWRpby5sb2FkKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjbGVhckNvbnZlcnNhdGlvbigpIHtcbiAgICAgICAgICAgIHN0b3BNb2RhbFBsYXliYWNrKCk7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1jb252ZXJzYXRpb24nKS5hdHRyKCdoaWRkZW4nLCB0cnVlKS5lbXB0eSgpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVzZXRNb2RhbChyb3dEYXRhKSB7XG4gICAgICAgICAgICBjbGVhckNvbnZlcnNhdGlvbigpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtbG9hZGluZycpLnJlbW92ZUF0dHIoJ2hpZGRlbicpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtZXJyb3InKS5hdHRyKCdoaWRkZW4nLCB0cnVlKS5yZW1vdmVDbGFzcygnZXJyb3InKS50ZXh0KCcnKTtcbiAgICAgICAgICAgIGNvbnN0ICRtZXRhID0gJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtY2FsbC1tZXRhJykucmVtb3ZlQXR0cignaGlkZGVuJyk7XG4gICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cImRhdGVcIl0nKS50ZXh0KFN0cmluZyhyb3dEYXRhWzBdIHx8ICcnKSk7XG4gICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cInNvdXJjZVwiXScpLnRleHQoU3RyaW5nKHJvd0RhdGFbMV0gfHwgJycpKTtcbiAgICAgICAgICAgICRtZXRhLmZpbmQoJ1tkYXRhLWZpZWxkPVwiZGVzdGluYXRpb25cIl0nKS50ZXh0KFN0cmluZyhyb3dEYXRhWzJdIHx8ICcnKSk7XG4gICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cImR1cmF0aW9uXCJdJykudGV4dChTdHJpbmcocm93RGF0YVszXSB8fCAnJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICAgICAgICAgICRtb2RhbC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWxvYWRpbmcnKS5hdHRyKCdoaWRkZW4nLCB0cnVlKTtcbiAgICAgICAgICAgIGNsZWFyQ29udmVyc2F0aW9uKCk7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1lcnJvcicpLnJlbW92ZUF0dHIoJ2hpZGRlbicpLmFkZENsYXNzKCdlcnJvcicpLnRleHQobWVzc2FnZSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJDb252ZXJzYXRpb24odHJhbnNjcmlwdCwgcm93RGF0YSkge1xuICAgICAgICAgICAgY29uc3QgY29udmVyc2F0aW9uID0gdHJhbnNjcmlwdCAmJiB0eXBlb2YgdHJhbnNjcmlwdC5jb252ZXJzYXRpb24gPT09ICdvYmplY3QnXG4gICAgICAgICAgICAgICAgPyB0cmFuc2NyaXB0LmNvbnZlcnNhdGlvblxuICAgICAgICAgICAgICAgIDogbnVsbDtcbiAgICAgICAgICAgIGNvbnN0IHRpbWVsaW5lID0gY29udmVyc2F0aW9uICYmIEFycmF5LmlzQXJyYXkoY29udmVyc2F0aW9uLnRpbWVsaW5lKSA/IGNvbnZlcnNhdGlvbi50aW1lbGluZSA6IFtdO1xuICAgICAgICAgICAgY29uc3QgcmVjb3JkaW5ncyA9IHJvd0RhdGEgJiYgQXJyYXkuaXNBcnJheShyb3dEYXRhWzRdKSA/IHJvd0RhdGFbNF0gOiBbXTtcbiAgICAgICAgICAgIGNvbnN0IHBsYXliYWNrQ2RySWQgPSB0cmFuc2NyaXB0ICYmIHRyYW5zY3JpcHQucGxheWJhY2tDZHJJZCAhPT0gbnVsbFxuICAgICAgICAgICAgICAgICYmIHR5cGVvZiB0cmFuc2NyaXB0LnBsYXliYWNrQ2RySWQgIT09ICd1bmRlZmluZWQnXG4gICAgICAgICAgICAgICAgPyBTdHJpbmcodHJhbnNjcmlwdC5wbGF5YmFja0NkcklkKVxuICAgICAgICAgICAgICAgIDogJyc7XG4gICAgICAgICAgICBsZXQgcmVjb3JkaW5nID0ge307XG4gICAgICAgICAgICByZWNvcmRpbmdzLnNvbWUoZnVuY3Rpb24gKGl0ZW0pIHtcbiAgICAgICAgICAgICAgICBpZiAocGxheWJhY2tDZHJJZCAhPT0gJycgJiYgaXRlbSAmJiBTdHJpbmcoaXRlbS5pZCkgPT09IHBsYXliYWNrQ2RySWQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmVjb3JkaW5nID0gaXRlbTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgY2xlYXJDb252ZXJzYXRpb24oKTtcbiAgICAgICAgICAgIGNvbnN0ICR0YXJnZXQgPSAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1jb252ZXJzYXRpb24nKS5yZW1vdmVBdHRyKCdoaWRkZW4nKTtcbiAgICAgICAgICAgICRtb2RhbC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWxvYWRpbmcnKS5hdHRyKCdoaWRkZW4nLCB0cnVlKTtcbiAgICAgICAgICAgICRtb2RhbC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWVycm9yJykuYXR0cignaGlkZGVuJywgdHJ1ZSkudGV4dCgnJyk7XG4gICAgICAgICAgICBpZiAodGltZWxpbmUubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICAgICAgJHRhcmdldC5hcHBlbmQoJCgnPGRpdj4nLCB7IGNsYXNzOiAndWkgbWVzc2FnZScgfSkudGV4dChsYWJlbHMuZW1wdHkpKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoIXdpbmRvdy5TdHRUcmFuc2NyaXB0VmlldyB8fCB0eXBlb2Ygd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3LmNyZWF0ZSAhPT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgIHNob3dFcnJvcihsYWJlbHMubG9hZEVycm9yKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICAkdGFyZ2V0LmFwcGVuZCh3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcuY3JlYXRlKGNvbnZlcnNhdGlvbiwge1xuICAgICAgICAgICAgICAgIGFsbDogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeUFsbCcpLFxuICAgICAgICAgICAgICAgIHNlYXJjaDogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1NlYXJjaCcpLFxuICAgICAgICAgICAgICAgIGVtcHR5OiBsYWJlbHMuZW1wdHksXG4gICAgICAgICAgICAgICAgcGxheTogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfUGxheVJlY29yZGluZycpLFxuICAgICAgICAgICAgICAgIGRvd25sb2FkOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Eb3dubG9hZFJlY29yZGluZycpLFxuICAgICAgICAgICAgICAgIGF1ZGlvVXJsOiBTdHJpbmcocmVjb3JkaW5nLnBsYXliYWNrX3VybCB8fCAnJyksXG4gICAgICAgICAgICAgICAgZG93bmxvYWRVcmw6IFN0cmluZyhyZWNvcmRpbmcuZG93bmxvYWRfdXJsIHx8ICcnKSxcbiAgICAgICAgICAgICAgICBkdXJhdGlvblNlY29uZHM6IGNvbnZlcnNhdGlvbi5kdXJhdGlvblNlY29uZHMsXG4gICAgICAgICAgICB9KSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBvcGVuVHJhbnNjcmlwdChkYXRhVGFibGUsICRidXR0b24pIHtcbiAgICAgICAgICAgIGNvbnN0IHJvd0RhdGEgPSBkYXRhVGFibGUucm93KCRidXR0b24uY2xvc2VzdCgndHInKSkuZGF0YSgpO1xuICAgICAgICAgICAgY29uc3QgY2FsbElkID0gU3RyaW5nKCRidXR0b24uYXR0cignZGF0YS1jYWxsLWlkJykgfHwgJycpLnRyaW0oKTtcbiAgICAgICAgICAgIGlmICghcm93RGF0YSB8fCBjYWxsSWQgPT09ICcnKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBpZiAoZGV0YWlsUmVxdWVzdCAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIGRldGFpbFJlcXVlc3QuYWJvcnQoKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHJlc2V0TW9kYWwocm93RGF0YSk7XG4gICAgICAgICAgICAkbW9kYWwubW9kYWwoJ3Nob3cnKTtcbiAgICAgICAgICAgIGRldGFpbFJlcXVlc3QgPSAkLmFqYXgoe1xuICAgICAgICAgICAgICAgIHVybDogZGV0YWlsVXJsLFxuICAgICAgICAgICAgICAgIG1ldGhvZDogJ0dFVCcsXG4gICAgICAgICAgICAgICAgZGF0YVR5cGU6ICdqc29uJyxcbiAgICAgICAgICAgICAgICBkYXRhOiB7IGNhbGxfaWQ6IGNhbGxJZCB9LFxuICAgICAgICAgICAgICAgIGJlZm9yZVNlbmQ6IGFkZEF1dGhvcml6YXRpb25IZWFkZXIsXG4gICAgICAgICAgICB9KS5kb25lKGZ1bmN0aW9uIChyZXNwb25zZSkge1xuICAgICAgICAgICAgICAgIHJlbmRlckNvbnZlcnNhdGlvbihyZXNwb25zZSAmJiByZXNwb25zZS50cmFuc2NyaXB0ID8gcmVzcG9uc2UudHJhbnNjcmlwdCA6IG51bGwsIHJvd0RhdGEpO1xuICAgICAgICAgICAgfSkuZmFpbChmdW5jdGlvbiAoeGhyLCBzdGF0dXMpIHtcbiAgICAgICAgICAgICAgICBpZiAoc3RhdHVzID09PSAnYWJvcnQnKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgaWYgKHhoci5zdGF0dXMgPT09IDQwNCkge1xuICAgICAgICAgICAgICAgICAgICBkZWxldGUgYXZhaWxhYmlsaXR5W2NhbGxJZF07XG4gICAgICAgICAgICAgICAgICAgICRidXR0b24ucmVtb3ZlKCk7XG4gICAgICAgICAgICAgICAgICAgIHNob3dFcnJvcihsYWJlbHMubm90Rm91bmQpO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmICh4aHIuc3RhdHVzID09PSA0MDEgfHwgeGhyLnN0YXR1cyA9PT0gNDAzKSB7XG4gICAgICAgICAgICAgICAgICAgIGFjY2Vzc0RlbmllZCA9IHRydWU7XG4gICAgICAgICAgICAgICAgICAgIHJlbW92ZUJ1dHRvbnMoKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgc2hvd0Vycm9yKGxhYmVscy5sb2FkRXJyb3IpO1xuICAgICAgICAgICAgfSkuYWx3YXlzKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBkZXRhaWxSZXF1ZXN0ID0gbnVsbDtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc3RhcnQoZGF0YVRhYmxlKSB7XG4gICAgICAgICAgICBpZiAoJHRhYmxlLmRhdGEoJ3N0dC1jZHItdHJhbnNjcmlwdC1zdGFydGVkJykpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICAkdGFibGUuZGF0YSgnc3R0LWNkci10cmFuc2NyaXB0LXN0YXJ0ZWQnLCB0cnVlKTtcbiAgICAgICAgICAgICRtb2RhbC5tb2RhbCh7XG4gICAgICAgICAgICAgICAgY2xvc2FibGU6IHRydWUsXG4gICAgICAgICAgICAgICAgb25IaWRkZW46IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgaWYgKGRldGFpbFJlcXVlc3QgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGRldGFpbFJlcXVlc3QuYWJvcnQoKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIGRldGFpbFJlcXVlc3QgPSBudWxsO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIGNsZWFyQ29udmVyc2F0aW9uKCk7XG4gICAgICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJHRhYmxlLm9uKCdkcmF3LmR0LnN0dENkclRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgcmVmcmVzaEJ1dHRvbnMoZGF0YVRhYmxlKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJHRhYmxlLm9uKCdjbGljay5zdHRDZHJUcmFuc2NyaXB0JywgJy5zdHQtY2RyLXRyYW5zY3JpcHQtYnV0dG9uJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICAgICAgZXZlbnQuc3RvcFByb3BhZ2F0aW9uKCk7XG4gICAgICAgICAgICAgICAgY29uc3QgJGJ1dHRvbiA9ICQodGhpcyk7XG4gICAgICAgICAgICAgICAgaWYgKGV2ZW50LmN0cmxLZXkgfHwgZXZlbnQubWV0YUtleSkge1xuICAgICAgICAgICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgICAgICAgICBjb25zdCBvcGVuZWRUYWIgPSB3aW5kb3cub3BlbigkYnV0dG9uLmF0dHIoJ2hyZWYnKSwgJ19ibGFuaycpO1xuICAgICAgICAgICAgICAgICAgICBpZiAob3BlbmVkVGFiICE9PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBvcGVuZWRUYWIub3BlbmVyID0gbnVsbDtcbiAgICAgICAgICAgICAgICAgICAgICAgIG9wZW5lZFRhYi5mb2N1cygpO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgICAgICBvcGVuVHJhbnNjcmlwdChkYXRhVGFibGUsICRidXR0b24pO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBzZXRUaW1lb3V0KGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICByZWZyZXNoQnV0dG9ucyhkYXRhVGFibGUpO1xuICAgICAgICAgICAgfSwgMCk7XG4gICAgICAgIH1cblxuICAgICAgICBpZiAoJC5mbi5EYXRhVGFibGUuaXNEYXRhVGFibGUoJHRhYmxlKSkge1xuICAgICAgICAgICAgc3RhcnQoJHRhYmxlLkRhdGFUYWJsZSgpKTtcbiAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICR0YWJsZS5vbmUoJ2luaXQuZHQuc3R0Q2RyVHJhbnNjcmlwdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBzdGFydCgkdGFibGUuRGF0YVRhYmxlKCkpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cbiAgICB9KTtcbn0oKSk7XG4iXSwibWFwcGluZ3MiOiI7QUFBQyxhQUFZO0VBQ1QsWUFBWTs7RUFFWixJQUFNQSxnQkFBZ0IsR0FBRyxHQUFHO0VBRTVCQyxDQUFDLENBQUMsWUFBWTtJQUNWLFNBQVNDLFNBQVNBLENBQUNDLEdBQUcsRUFBRTtNQUNwQixJQUFJLE9BQU9DLGVBQWUsS0FBSyxXQUFXLElBQUlBLGVBQWUsSUFBSUEsZUFBZSxDQUFDRCxHQUFHLENBQUMsRUFBRTtRQUNuRixPQUFPRSxNQUFNLENBQUNELGVBQWUsQ0FBQ0QsR0FBRyxDQUFDLENBQUM7TUFDdkM7TUFDQSxPQUFPQSxHQUFHO0lBQ2Q7SUFFQSxTQUFTRyxXQUFXQSxDQUFBLEVBQUc7TUFDbkIsSUFBSUMs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