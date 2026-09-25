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
    var exportUrl = "".concat(rootUrl, "module-local-speech-to-text/transcript-actions/export");
    var deleteUrl = "".concat(rootUrl, "module-local-speech-to-text/transcript-actions/delete");
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
      if (window.SttTranscriptView && typeof window.SttTranscriptView.dispose === 'function') {
        window.SttTranscriptView.dispose($modal.find('.stt-cdr-transcript-conversation'));
      }
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

    // The history row only ever holds bare numbers, and for an incoming
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
    function showMessages(messages) {
      if (typeof UserMessage !== 'undefined' && messages && typeof UserMessage.showMultiString === 'function') {
        UserMessage.showMultiString(messages);
      }
    }

    // Same consequences and acknowledgement as the full transcript card:
    // Delete stays disabled until the checkbox is ticked, and only then is
    // confirm_delete sent.
    function ensureDeleteModal() {
      var $confirm = $('#stt-cdr-delete-transcript-modal');
      if ($confirm.length > 0) {
        return $confirm;
      }
      var $list = $('<ul>', {
        class: 'ui list'
      });
      ['module_local_speech_to_text_DeleteTranscriptConsequence', 'module_local_speech_to_text_DeleteTranscriptKeepsCdr', 'module_local_speech_to_text_DeleteTranscriptBackupWarning'].forEach(function (key) {
        $list.append($('<li>').text(translate(key)));
      });
      $confirm = $('<div>', {
        class: 'ui tiny modal',
        id: 'stt-cdr-delete-transcript-modal',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-labelledby': 'stt-cdr-delete-transcript-title'
      }).append($('<div>', {
        class: 'header',
        id: 'stt-cdr-delete-transcript-title'
      }).text(translate('module_local_speech_to_text_DeleteTranscript')), $('<div>', {
        class: 'content'
      }).append($list, $('<div>', {
        class: 'ui checkbox'
      }).append($('<input>', {
        type: 'checkbox',
        id: 'stt-cdr-delete-transcript-acknowledge'
      }), $('<label>', {
        for: 'stt-cdr-delete-transcript-acknowledge'
      }).text(translate('module_local_speech_to_text_DeleteTranscriptConfirm')))), $('<div>', {
        class: 'actions'
      }).append($('<button>', {
        type: 'button',
        class: 'ui cancel basic button'
      }).text(translate('module_local_speech_to_text_ConfirmCancel')), $('<button>', {
        type: 'button',
        class: 'ui red approve button disabled',
        disabled: true
      }).text(translate('module_local_speech_to_text_ConfirmDelete'))));
      $('body').append($confirm);
      $confirm.find('#stt-cdr-delete-transcript-acknowledge').on('change', function () {
        var checked = $(this).prop('checked');
        $confirm.find('.approve.button').toggleClass('disabled', !checked).prop('disabled', !checked);
      });
      return $confirm;
    }
    function deleteTranscript(resultId, callId) {
      var $confirm = ensureDeleteModal();
      var $approve = $confirm.find('.approve.button');
      $confirm.find('#stt-cdr-delete-transcript-acknowledge').prop('checked', false);
      $approve.addClass('disabled').prop('disabled', true).removeClass('loading');
      $confirm.modal({
        allowMultiple: true,
        closable: true,
        onApprove: function () {
          if (!$confirm.find('#stt-cdr-delete-transcript-acknowledge').prop('checked')) {
            return false;
          }
          $approve.addClass('loading disabled').prop('disabled', true);
          $.ajax({
            url: deleteUrl,
            method: 'POST',
            dataType: 'json',
            data: {
              result_id: resultId,
              confirm_delete: '1'
            },
            beforeSend: addAuthorizationHeader
          }).done(function (response) {
            showMessages(response && response.message);
            if (response && response.success === true) {
              delete availability[callId];
              $table.find('.stt-cdr-transcript-button').filter(function () {
                return $(this).attr('data-call-id') === callId;
              }).remove();
              $confirm.modal('hide');
              $modal.modal('hide');
              return;
            }
            $confirm.modal('hide');
          }).fail(function () {
            $confirm.modal('hide');
            showMessages({
              error: [translate('module_local_speech_to_text_TranscriptDeleteFailed')]
            });
          }).always(function () {
            $approve.removeClass('loading');
          });
          return false;
        }
      }).modal('show');
    }
    function createActionsMenu(resultId, callId, canDelete) {
      var $menu = $('<div>', {
        class: 'ui vertical menu'
      });
      ['txt', 'json'].forEach(function (format) {
        $menu.append($('<a>', {
          class: 'item',
          href: "".concat(exportUrl, "?id=").concat(encodeURIComponent(String(resultId)), "&format=").concat(format)
        }).text(translate(format === 'txt' ? 'module_local_speech_to_text_DownloadTranscriptTxt' : 'module_local_speech_to_text_DownloadTranscriptJson')));
      });
      if (canDelete) {
        $menu.append($('<div>', {
          class: 'divider'
        }), $('<div>', {
          class: 'item stt-delete-transcript-form'
        }).append($('<button>', {
          type: 'button'
        }).text(translate('module_local_speech_to_text_DeleteTranscript')).on('click', function () {
          deleteTranscript(resultId, callId);
        })));
      }
      var label = translate('module_local_speech_to_text_TranscriptActions');
      var $details = $('<details>', {
        class: 'stt-transcript-actions'
      }).append($('<summary>', {
        'aria-label': label,
        title: label
      }).append($('<i>', {
        class: 'ellipsis horizontal icon'
      })), $menu);
      $menu.on('click', 'a, button', function () {
        $details.removeAttr('open');
      });
      return $details;
    }
    function renderConversation(transcript, rowData, callId, canDelete) {
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
        speed: translate('module_local_speech_to_text_PlaybackSpeed'),
        loading: translate('module_local_speech_to_text_LoadingRecording'),
        unavailable: translate('module_local_speech_to_text_RecordingUnavailable'),
        failed: translate('module_local_speech_to_text_PlaybackFailed'),
        download: translate('module_local_speech_to_text_DownloadRecording'),
        audioUrl: String(recording.playback_url || ''),
        downloadUrl: String(recording.download_url || ''),
        durationSeconds: conversation.durationSeconds,
        actions: transcript.resultId ? createActionsMenu(transcript.resultId, callId, canDelete) : null
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
        renderConversation(response && response.transcript ? response.transcript : null, rowData, callId, Boolean(response && response.canDeleteTranscript));
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
        onHidden: function () {
          if (detailRequest !== null) {
            detailRequest.abort();
            detailRequest = null;
          }
          clearConversation();
        }
      });
      $(document).on('click.sttCdrTranscriptActions', function (event) {
        $modal.find('.stt-transcript-actions[open]').each(function () {
          if (!this.contains(event.target)) {
            $(this).removeAttr('open');
          }
        });
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

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LWNkci5qcyIsIm5hbWVzIjpbIk1BWF9MT09LVVBfQkFUQ0giLCIkIiwidHJhbnNsYXRlIiwia2V5IiwiZ2xvYmFsVHJhbnNsYXRlIiwiU3RyaW5nIiwiZW5zdXJlTW9kYWwiLCIkbW9kYWwiLCJsZW5ndGgiLCJsYWJlbHMiLCJidXR0b24iLCJ0aXRsZSIsImxvYWRpbmciLCJsb2FkRXJyb3IiLCJub3RGb3VuZCIsImVtcHR5IiwiJGhlYWRlciIsImNsYXNzIiwiYXBwZW5kIiwiZG9jdW1lbnQiLCJjcmVhdGVUZXh0Tm9kZSIsIiRsb2FkaW5nIiwicm9sZSIsInRleHQiLCIkY2FsbE1ldGEiLCJoaWRkZW4iLCJpZCIsIiR0YWJsZSIsImZuIiwiRGF0YVRhYmxlIiwiZGF0YSIsInJvb3RVcmwiLCJnbG9iYWxSb290VXJsIiwibG9va3VwVXJsIiwiY29uY2F0IiwiZGV0YWlsVXJsIiwidHJhbnNjcmlwdFVybCIsImV4cG9ydFVybCIsImRlbGV0ZVVybCIsImF2YWlsYWJpbGl0eSIsIk9iamVjdCIsImNyZWF0ZSIsInBlbmRpbmciLCJhY2Nlc3NEZW5pZWQiLCJkZXRhaWxSZXF1ZXN0IiwiYWRkQXV0aG9yaXphdGlvbkhlYWRlciIsInhociIsIlRva2VuTWFuYWdlciIsImFjY2Vzc1Rva2VuIiwic2V0UmVxdWVzdEhlYWRlciIsImN1cnJlbnRSb3dzIiwiZGF0YVRhYmxlIiwicm93cyIsInF1ZXJ5U2VsZWN0b3JBbGwiLCJmb3JFYWNoIiwibm9kZSIsImNhbGxJZCIsInRyaW0iLCJjZWxscyIsInB1c2giLCJyb3ciLCJhY3Rpb25zQ2VsbCIsImhhc0FjdGlvbkNvbHVtbiIsInJlbW92ZUJ1dHRvbnMiLCJmaW5kIiwicmVtb3ZlIiwiYWRkQnV0dG9uIiwiJHRhcmdldCIsIiRidXR0b24iLCJocmVmIiwiZW5jb2RlVVJJQ29tcG9uZW50IiwidGFyZ2V0IiwicmVsIiwiYXR0ciIsImlubmVySFRNTCIsInByZXBlbmQiLCJhZGRDbGFzcyIsInBvcHVwIiwicmVuZGVyQnV0dG9ucyIsInByb3RvdHlwZSIsImhhc093blByb3BlcnR5IiwiY2FsbCIsImxvb2t1cEJhdGNoIiwiYmF0Y2giLCJhamF4IiwidXJsIiwibWV0aG9kIiwiY29udGVudFR5cGUiLCJkYXRhVHlwZSIsIkpTT04iLCJzdHJpbmdpZnkiLCJjYWxsX2lkcyIsImJlZm9yZVNlbmQiLCJkb25lIiwicmVzcG9uc2UiLCJ0cmFuc2NyaXB0TWFwIiwiX3R5cGVvZiIsIk51bWJlciIsImZhaWwiLCJzdGF0dXMiLCJhbHdheXMiLCJyZWZyZXNoQnV0dG9ucyIsInVuc2VlbiIsImluZGV4T2YiLCJvZmZzZXQiLCJzbGljZSIsInN0b3BNb2RhbFBsYXliYWNrIiwid2luZG93IiwiU3R0VHJhbnNjcmlwdFZpZXciLCJkaXNwb3NlIiwiYXVkaW8iLCJnZXQiLCJwYXVzZSIsImN1cnJlbnRUaW1lIiwicmVtb3ZlQXR0cmlidXRlIiwibG9hZCIsImNsZWFyQ29udmVyc2F0aW9uIiwicmVzZXRNb2RhbCIsInJvd0RhdGEiLCJyZW1vdmVBdHRyIiwicmVtb3ZlQ2xhc3MiLCIkbWV0YSIsImFwcGx5UGFydHlMYWJlbHMiLCJwYXJ0aWVzIiwiZmllbGQiLCJsYWJlbCIsInNob3dFcnJvciIsIm1lc3NhZ2UiLCJzaG93TWVzc2FnZXMiLCJtZXNzYWdlcyIsIlVzZXJNZXNzYWdlIiwic2hvd011bHRpU3RyaW5nIiwiZW5zdXJlRGVsZXRlTW9kYWwiLCIkY29uZmlybSIsIiRsaXN0IiwidHlwZSIsImZvciIsImRpc2FibGVkIiwib24iLCJjaGVja2VkIiwicHJvcCIsInRvZ2dsZUNsYXNzIiwiZGVsZXRlVHJhbnNjcmlwdCIsInJlc3VsdElkIiwiJGFwcHJvdmUiLCJtb2RhbCIsImFsbG93TXVsdGlwbGUiLCJjbG9zYWJsZSIsIm9uQXBwcm92ZSIsInJlc3VsdF9pZCIsImNvbmZpcm1fZGVsZXRlIiwic3VjY2VzcyIsImZpbHRlciIsImVycm9yIiwiY3JlYXRlQWN0aW9uc01lbnUiLCJjYW5EZWxldGUiLCIkbWVudSIsImZvcm1hdCIsIiRkZXRhaWxzIiwicmVuZGVyQ29udmVyc2F0aW9uIiwidHJhbnNjcmlwdCIsImNvbnZlcnNhdGlvbiIsInRpbWVsaW5lIiwiQXJyYXkiLCJpc0FycmF5IiwicmVjb3JkaW5ncyIsInBsYXliYWNrQ2RySWQiLCJyZWNvcmRpbmciLCJzb21lIiwiaXRlbSIsImFsbCIsInNlYXJjaCIsInBsYXkiLCJzcGVlZCIsInVuYXZhaWxhYmxlIiwiZmFpbGVkIiwiZG93bmxvYWQiLCJhdWRpb1VybCIsInBsYXliYWNrX3VybCIsImRvd25sb2FkVXJsIiwiZG93bmxvYWRfdXJsIiwiZHVyYXRpb25TZWNvbmRzIiwiYWN0aW9ucyIsIm9wZW5UcmFuc2NyaXB0IiwiY2xvc2VzdCIsImFib3J0IiwiY2FsbF9pZCIsIkJvb2xlYW4iLCJjYW5EZWxldGVUcmFuc2NyaXB0Iiwic3RhcnQiLCJvbkhpZGRlbiIsImV2ZW50IiwiZWFjaCIsImNvbnRhaW5zIiwic3RvcFByb3BhZ2F0aW9uIiwiY3RybEtleSIsIm1ldGFLZXkiLCJwcmV2ZW50RGVmYXVsdCIsIm9wZW5lZFRhYiIsIm9wZW4iLCJvcGVuZXIiLCJmb2N1cyIsInNldFRpbWVvdXQiLCJpc0RhdGFUYWJsZSIsIm9uZSJdLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtY2RyLmpzIl0sInNvdXJjZXNDb250ZW50IjpbIihmdW5jdGlvbiAoKSB7XG4gICAgJ3VzZSBzdHJpY3QnO1xuXG4gICAgY29uc3QgTUFYX0xPT0tVUF9CQVRDSCA9IDEwMDtcblxuICAgICQoZnVuY3Rpb24gKCkge1xuICAgICAgICBmdW5jdGlvbiB0cmFuc2xhdGUoa2V5KSB7XG4gICAgICAgICAgICBpZiAodHlwZW9mIGdsb2JhbFRyYW5zbGF0ZSAhPT0gJ3VuZGVmaW5lZCcgJiYgZ2xvYmFsVHJhbnNsYXRlICYmIGdsb2JhbFRyYW5zbGF0ZVtrZXldKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIFN0cmluZyhnbG9iYWxUcmFuc2xhdGVba2V5XSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZXR1cm4ga2V5O1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gZW5zdXJlTW9kYWwoKSB7XG4gICAgICAgICAgICBsZXQgJG1vZGFsID0gJCgnI3N0dC1jZHItdHJhbnNjcmlwdC1tb2RhbCcpO1xuICAgICAgICAgICAgaWYgKCRtb2RhbC5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuICRtb2RhbDtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgY29uc3QgbGFiZWxzID0ge1xuICAgICAgICAgICAgICAgIGJ1dHRvbjogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RyVHJhbnNjcmlwdEJ1dHRvbicpLFxuICAgICAgICAgICAgICAgIHRpdGxlOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJUcmFuc2NyaXB0TW9kYWxUaXRsZScpLFxuICAgICAgICAgICAgICAgIGxvYWRpbmc6IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0NkclRyYW5zY3JpcHRMb2FkaW5nJyksXG4gICAgICAgICAgICAgICAgbG9hZEVycm9yOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJUcmFuc2NyaXB0TG9hZEVycm9yJyksXG4gICAgICAgICAgICAgICAgbm90Rm91bmQ6IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0NkclRyYW5zY3JpcHROb3RGb3VuZCcpLFxuICAgICAgICAgICAgICAgIGVtcHR5OiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Ob0NvbnZlcnNhdGlvblNlZ21lbnRzJyksXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgY29uc3QgJGhlYWRlciA9ICQoJzxkaXY+JywgeyBjbGFzczogJ2hlYWRlcicgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxpPicsIHsgY2xhc3M6ICdjb21tZW50IGFsdGVybmF0ZSBvdXRsaW5lIGljb24nIH0pLFxuICAgICAgICAgICAgICAgIGRvY3VtZW50LmNyZWF0ZVRleHROb2RlKGxhYmVscy50aXRsZSlcbiAgICAgICAgICAgICk7XG4gICAgICAgICAgICBjb25zdCAkbG9hZGluZyA9ICQoJzxkaXY+Jywge1xuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LWNkci10cmFuc2NyaXB0LWxvYWRpbmcnLFxuICAgICAgICAgICAgICAgIHJvbGU6ICdzdGF0dXMnLFxuICAgICAgICAgICAgICAgICdhcmlhLWxpdmUnOiAncG9saXRlJyxcbiAgICAgICAgICAgIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICd1aSBhY3RpdmUgY2VudGVyZWQgaW5saW5lIGxvYWRlciBzdHQtY2RyLXRyYW5zY3JpcHQtbG9hZGVyJyB9KSxcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicpLnRleHQobGFiZWxzLmxvYWRpbmcpXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgY29uc3QgJGNhbGxNZXRhID0gJCgnPGRpdj4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtY2RyLXRyYW5zY3JpcHQtY2FsbC1tZXRhJyxcbiAgICAgICAgICAgICAgICBoaWRkZW46IHRydWUsXG4gICAgICAgICAgICB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyAnZGF0YS1maWVsZCc6ICdkYXRlJyB9KSxcbiAgICAgICAgICAgICAgICAkKCc8c3Bhbj4nLCB7ICdkYXRhLWZpZWxkJzogJ3NvdXJjZScgfSksXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyAnZGF0YS1maWVsZCc6ICdkZXN0aW5hdGlvbicgfSksXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyAnZGF0YS1maWVsZCc6ICdkdXJhdGlvbicgfSlcbiAgICAgICAgICAgICk7XG4gICAgICAgICAgICAkbW9kYWwgPSAkKCc8ZGl2PicsIHtcbiAgICAgICAgICAgICAgICBjbGFzczogJ3VpIGxhcmdlIG1vZGFsIG1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCBzdHQtY2RyLXRyYW5zY3JpcHQtbW9kYWwnLFxuICAgICAgICAgICAgICAgIGlkOiAnc3R0LWNkci10cmFuc2NyaXB0LW1vZGFsJyxcbiAgICAgICAgICAgICAgICAnZGF0YS1sYWJlbC1idXR0b24nOiBsYWJlbHMuYnV0dG9uLFxuICAgICAgICAgICAgICAgICdkYXRhLWxhYmVsLWxvYWQtZXJyb3InOiBsYWJlbHMubG9hZEVycm9yLFxuICAgICAgICAgICAgICAgICdkYXRhLWxhYmVsLW5vdC1mb3VuZCc6IGxhYmVscy5ub3RGb3VuZCxcbiAgICAgICAgICAgICAgICAnZGF0YS1sYWJlbC1lbXB0eSc6IGxhYmVscy5lbXB0eSxcbiAgICAgICAgICAgIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8aT4nLCB7IGNsYXNzOiAnY2xvc2UgaWNvbicgfSksXG4gICAgICAgICAgICAgICAgJGhlYWRlcixcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdjb250ZW50JyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICRsb2FkaW5nLFxuICAgICAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGNsYXNzOiAndWkgbWVzc2FnZSBzdHQtY2RyLXRyYW5zY3JpcHQtZXJyb3InLFxuICAgICAgICAgICAgICAgICAgICAgICAgcm9sZTogJ2FsZXJ0JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICdhcmlhLWxpdmUnOiAnYXNzZXJ0aXZlJyxcbiAgICAgICAgICAgICAgICAgICAgICAgIGhpZGRlbjogdHJ1ZSxcbiAgICAgICAgICAgICAgICAgICAgfSksXG4gICAgICAgICAgICAgICAgICAgICRjYWxsTWV0YSxcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LWNkci10cmFuc2NyaXB0LWNvbnZlcnNhdGlvbicsIGhpZGRlbjogdHJ1ZSB9KVxuICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICk7XG4gICAgICAgICAgICAkKCdib2R5JykuYXBwZW5kKCRtb2RhbCk7XG4gICAgICAgICAgICByZXR1cm4gJG1vZGFsO1xuICAgICAgICB9XG5cbiAgICAgICAgY29uc3QgJHRhYmxlID0gJCgnI2Nkci10YWJsZScpO1xuICAgICAgICBpZiAoJHRhYmxlLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG4gICAgICAgIGNvbnN0ICRtb2RhbCA9IGVuc3VyZU1vZGFsKCk7XG4gICAgICAgIGlmICgkbW9kYWwubGVuZ3RoID09PSAwIHx8IHR5cGVvZiAkLmZuLkRhdGFUYWJsZSA9PT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIGNvbnN0IGxhYmVscyA9IHtcbiAgICAgICAgICAgIGJ1dHRvbjogU3RyaW5nKCRtb2RhbC5kYXRhKCdsYWJlbC1idXR0b24nKSB8fCAnJyksXG4gICAgICAgICAgICBsb2FkRXJyb3I6IFN0cmluZygkbW9kYWwuZGF0YSgnbGFiZWwtbG9hZC1lcnJvcicpIHx8ICcnKSxcbiAgICAgICAgICAgIG5vdEZvdW5kOiBTdHJpbmcoJG1vZGFsLmRhdGEoJ2xhYmVsLW5vdC1mb3VuZCcpIHx8ICcnKSxcbiAgICAgICAgICAgIGVtcHR5OiBTdHJpbmcoJG1vZGFsLmRhdGEoJ2xhYmVsLWVtcHR5JykgfHwgJycpLFxuICAgICAgICB9O1xuICAgICAgICBjb25zdCByb290VXJsID0gdHlwZW9mIGdsb2JhbFJvb3RVcmwgPT09ICdzdHJpbmcnID8gZ2xvYmFsUm9vdFVybCA6ICcvYWRtaW4tY2FiaW5ldC8nO1xuICAgICAgICBjb25zdCBsb29rdXBVcmwgPSBgJHtyb290VXJsfW1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC9jZHItdHJhbnNjcmlwdC9sb29rdXBgO1xuICAgICAgICBjb25zdCBkZXRhaWxVcmwgPSBgJHtyb290VXJsfW1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC9jZHItdHJhbnNjcmlwdC9kZXRhaWxgO1xuICAgICAgICBjb25zdCB0cmFuc2NyaXB0VXJsID0gYCR7cm9vdFVybH1tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQvbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0L3RyYW5zY3JpcHRgO1xuICAgICAgICBjb25zdCBleHBvcnRVcmwgPSBgJHtyb290VXJsfW1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC90cmFuc2NyaXB0LWFjdGlvbnMvZXhwb3J0YDtcbiAgICAgICAgY29uc3QgZGVsZXRlVXJsID0gYCR7cm9vdFVybH1tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQvdHJhbnNjcmlwdC1hY3Rpb25zL2RlbGV0ZWA7XG4gICAgICAgIGNvbnN0IGF2YWlsYWJpbGl0eSA9IE9iamVjdC5jcmVhdGUobnVsbCk7XG4gICAgICAgIGNvbnN0IHBlbmRpbmcgPSBPYmplY3QuY3JlYXRlKG51bGwpO1xuICAgICAgICBsZXQgYWNjZXNzRGVuaWVkID0gZmFsc2U7XG4gICAgICAgIGxldCBkZXRhaWxSZXF1ZXN0ID0gbnVsbDtcblxuICAgICAgICBmdW5jdGlvbiBhZGRBdXRob3JpemF0aW9uSGVhZGVyKHhocikge1xuICAgICAgICAgICAgaWYgKHR5cGVvZiBUb2tlbk1hbmFnZXIgIT09ICd1bmRlZmluZWQnICYmIFRva2VuTWFuYWdlci5hY2Nlc3NUb2tlbikge1xuICAgICAgICAgICAgICAgIHhoci5zZXRSZXF1ZXN0SGVhZGVyKCdBdXRob3JpemF0aW9uJywgYEJlYXJlciAke1Rva2VuTWFuYWdlci5hY2Nlc3NUb2tlbn1gKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGN1cnJlbnRSb3dzKGRhdGFUYWJsZSkge1xuICAgICAgICAgICAgY29uc3Qgcm93cyA9IFtdO1xuICAgICAgICAgICAgZG9jdW1lbnQucXVlcnlTZWxlY3RvckFsbCgnI2Nkci10YWJsZSB0Ym9keSB0cltpZF0nKS5mb3JFYWNoKGZ1bmN0aW9uIChub2RlKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgY2FsbElkID0gU3RyaW5nKG5vZGUuaWQgfHwgJycpLnRyaW0oKTtcbiAgICAgICAgICAgICAgICBpZiAoY2FsbElkID09PSAnJyB8fCBjYWxsSWQubGVuZ3RoID4gMjU1KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY29uc3QgY2VsbHMgPSBub2RlLnF1ZXJ5U2VsZWN0b3JBbGwoJzpzY29wZSA+IHRkJyk7XG4gICAgICAgICAgICAgICAgaWYgKGNlbGxzLmxlbmd0aCA8IDIpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICByb3dzLnB1c2goe1xuICAgICAgICAgICAgICAgICAgICBub2RlOiAkKG5vZGUpLFxuICAgICAgICAgICAgICAgICAgICBkYXRhOiBkYXRhVGFibGUucm93KG5vZGUpLmRhdGEoKSxcbiAgICAgICAgICAgICAgICAgICAgY2FsbElkLFxuICAgICAgICAgICAgICAgICAgICBhY3Rpb25zQ2VsbDogJChjZWxsc1tjZWxscy5sZW5ndGggLSAxXSksXG4gICAgICAgICAgICAgICAgICAgIGhhc0FjdGlvbkNvbHVtbjogY2VsbHMubGVuZ3RoID4gNSxcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgcmV0dXJuIHJvd3M7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW1vdmVCdXR0b25zKCkge1xuICAgICAgICAgICAgJHRhYmxlLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtYnV0dG9uJykucmVtb3ZlKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBhZGRCdXR0b24ocm93KSB7XG4gICAgICAgICAgICBjb25zdCAkdGFyZ2V0ID0gcm93LmFjdGlvbnNDZWxsO1xuICAgICAgICAgICAgaWYgKCR0YXJnZXQubGVuZ3RoID09PSAwIHx8ICR0YXJnZXQuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1idXR0b24nKS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBjb25zdCAkYnV0dG9uID0gJCgnPGE+Jywge1xuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LWNkci10cmFuc2NyaXB0LWJ1dHRvbicsXG4gICAgICAgICAgICAgICAgaHJlZjogYCR7dHJhbnNjcmlwdFVybH0/aWQ9JHtlbmNvZGVVUklDb21wb25lbnQoU3RyaW5nKGF2YWlsYWJpbGl0eVtyb3cuY2FsbElkXSkpfWAsXG4gICAgICAgICAgICAgICAgdGFyZ2V0OiAnX2JsYW5rJyxcbiAgICAgICAgICAgICAgICByZWw6ICdub29wZW5lcicsXG4gICAgICAgICAgICAgICAgJ2FyaWEtbGFiZWwnOiBsYWJlbHMuYnV0dG9uLFxuICAgICAgICAgICAgICAgIHRpdGxlOiBsYWJlbHMuYnV0dG9uLFxuICAgICAgICAgICAgICAgICdkYXRhLWNvbnRlbnQnOiBsYWJlbHMuYnV0dG9uLFxuICAgICAgICAgICAgfSkuYXR0cignZGF0YS1jYWxsLWlkJywgcm93LmNhbGxJZCk7XG4gICAgICAgICAgICAkYnV0dG9uWzBdLmlubmVySFRNTCA9ICc8c3ZnIHZpZXdCb3g9XCIwIDAgMjQgMjRcIiBmaWxsPVwibm9uZVwiIHN0cm9rZT1cImN1cnJlbnRDb2xvclwiIHN0cm9rZS13aWR0aD1cIjEuOFwiIHN0cm9rZS1saW5lY2FwPVwicm91bmRcIiBhcmlhLWhpZGRlbj1cInRydWVcIj4nXG4gICAgICAgICAgICAgICAgKyAnPHBhdGggZD1cIk00IDEzdi0yTTggMTZWOE0xMiAxOFY2TTE2IDE2VjhNMjAgMTN2LTJcIiAvPjwvc3ZnPic7XG4gICAgICAgICAgICBpZiAocm93Lmhhc0FjdGlvbkNvbHVtbikge1xuICAgICAgICAgICAgICAgICR0YXJnZXQucHJlcGVuZCgkYnV0dG9uKTtcbiAgICAgICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAgICAgJHRhcmdldC5hZGRDbGFzcygnc3R0LWNkci1kdXJhdGlvbi1jZWxsJyk7XG4gICAgICAgICAgICAgICAgJGJ1dHRvbi5hZGRDbGFzcygnc3R0LWNkci10cmFuc2NyaXB0LWR1cmF0aW9uLWJ1dHRvbicpO1xuICAgICAgICAgICAgICAgICR0YXJnZXQuYXBwZW5kKCRidXR0b24pO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKHR5cGVvZiAkYnV0dG9uLnBvcHVwID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgJGJ1dHRvbi5wb3B1cCgpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVuZGVyQnV0dG9ucyhyb3dzKSB7XG4gICAgICAgICAgICByb3dzLmZvckVhY2goZnVuY3Rpb24gKHJvdykge1xuICAgICAgICAgICAgICAgIGlmIChhdmFpbGFiaWxpdHlbcm93LmNhbGxJZF0gPiAwKSB7XG4gICAgICAgICAgICAgICAgICAgIGFkZEJ1dHRvbihyb3cpO1xuICAgICAgICAgICAgICAgIH0gZWxzZSBpZiAoT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKGF2YWlsYWJpbGl0eSwgcm93LmNhbGxJZCkpIHtcbiAgICAgICAgICAgICAgICAgICAgcm93Lm5vZGUuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1idXR0b24nKS5yZW1vdmUoKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGxvb2t1cEJhdGNoKGJhdGNoLCBkYXRhVGFibGUpIHtcbiAgICAgICAgICAgIGJhdGNoLmZvckVhY2goZnVuY3Rpb24gKGNhbGxJZCkge1xuICAgICAgICAgICAgICAgIHBlbmRpbmdbY2FsbElkXSA9IHRydWU7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICQuYWpheCh7XG4gICAgICAgICAgICAgICAgdXJsOiBsb29rdXBVcmwsXG4gICAgICAgICAgICAgICAgbWV0aG9kOiAnUE9TVCcsXG4gICAgICAgICAgICAgICAgY29udGVudFR5cGU6ICdhcHBsaWNhdGlvbi9qc29uOyBjaGFyc2V0PXV0Zi04JyxcbiAgICAgICAgICAgICAgICBkYXRhVHlwZTogJ2pzb24nLFxuICAgICAgICAgICAgICAgIGRhdGE6IEpTT04uc3RyaW5naWZ5KHsgY2FsbF9pZHM6IGJhdGNoIH0pLFxuICAgICAgICAgICAgICAgIGJlZm9yZVNlbmQ6IGFkZEF1dGhvcml6YXRpb25IZWFkZXIsXG4gICAgICAgICAgICB9KS5kb25lKGZ1bmN0aW9uIChyZXNwb25zZSkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHRyYW5zY3JpcHRNYXAgPSByZXNwb25zZSAmJiByZXNwb25zZS50cmFuc2NyaXB0TWFwICYmIHR5cGVvZiByZXNwb25zZS50cmFuc2NyaXB0TWFwID09PSAnb2JqZWN0J1xuICAgICAgICAgICAgICAgICAgICA/IHJlc3BvbnNlLnRyYW5zY3JpcHRNYXBcbiAgICAgICAgICAgICAgICAgICAgOiB7fTtcbiAgICAgICAgICAgICAgICBiYXRjaC5mb3JFYWNoKGZ1bmN0aW9uIChjYWxsSWQpIHtcbiAgICAgICAgICAgICAgICAgICAgaWYgKFxuICAgICAgICAgICAgICAgICAgICAgICAgT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKHRyYW5zY3JpcHRNYXAsIGNhbGxJZClcbiAgICAgICAgICAgICAgICAgICAgICAgICYmIE51bWJlcih0cmFuc2NyaXB0TWFwW2NhbGxJZF0pID4gMFxuICAgICAgICAgICAgICAgICAgICApIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGF2YWlsYWJpbGl0eVtjYWxsSWRdID0gTnVtYmVyKHRyYW5zY3JpcHRNYXBbY2FsbElkXSk7XG4gICAgICAgICAgICAgICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBkZWxldGUgYXZhaWxhYmlsaXR5W2NhbGxJZF07XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH0pLmZhaWwoZnVuY3Rpb24gKHhocikge1xuICAgICAgICAgICAgICAgIGlmICh4aHIuc3RhdHVzID09PSA0MDEgfHwgeGhyLnN0YXR1cyA9PT0gNDAzKSB7XG4gICAgICAgICAgICAgICAgICAgIGFjY2Vzc0RlbmllZCA9IHRydWU7XG4gICAgICAgICAgICAgICAgICAgIHJlbW92ZUJ1dHRvbnMoKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KS5hbHdheXMoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGJhdGNoLmZvckVhY2goZnVuY3Rpb24gKGNhbGxJZCkge1xuICAgICAgICAgICAgICAgICAgICBkZWxldGUgcGVuZGluZ1tjYWxsSWRdO1xuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgICAgIGlmICghYWNjZXNzRGVuaWVkKSB7XG4gICAgICAgICAgICAgICAgICAgIHJlbmRlckJ1dHRvbnMoY3VycmVudFJvd3MoZGF0YVRhYmxlKSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZWZyZXNoQnV0dG9ucyhkYXRhVGFibGUpIHtcbiAgICAgICAgICAgIGlmIChhY2Nlc3NEZW5pZWQpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCByb3dzID0gY3VycmVudFJvd3MoZGF0YVRhYmxlKTtcbiAgICAgICAgICAgIHJlbmRlckJ1dHRvbnMocm93cyk7XG4gICAgICAgICAgICBjb25zdCB1bnNlZW4gPSBbXTtcbiAgICAgICAgICAgIHJvd3MuZm9yRWFjaChmdW5jdGlvbiAocm93KSB7XG4gICAgICAgICAgICAgICAgaWYgKFxuICAgICAgICAgICAgICAgICAgICAhT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKGF2YWlsYWJpbGl0eSwgcm93LmNhbGxJZClcbiAgICAgICAgICAgICAgICAgICAgJiYgIXBlbmRpbmdbcm93LmNhbGxJZF1cbiAgICAgICAgICAgICAgICAgICAgJiYgdW5zZWVuLmluZGV4T2Yocm93LmNhbGxJZCkgPT09IC0xXG4gICAgICAgICAgICAgICAgKSB7XG4gICAgICAgICAgICAgICAgICAgIHVuc2Vlbi5wdXNoKHJvdy5jYWxsSWQpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgZm9yIChsZXQgb2Zmc2V0ID0gMDsgb2Zmc2V0IDwgdW5zZWVuLmxlbmd0aDsgb2Zmc2V0ICs9IE1BWF9MT09LVVBfQkFUQ0gpIHtcbiAgICAgICAgICAgICAgICBsb29rdXBCYXRjaCh1bnNlZW4uc2xpY2Uob2Zmc2V0LCBvZmZzZXQgKyBNQVhfTE9PS1VQX0JBVENIKSwgZGF0YVRhYmxlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHN0b3BNb2RhbFBsYXliYWNrKCkge1xuICAgICAgICAgICAgaWYgKHdpbmRvdy5TdHRUcmFuc2NyaXB0VmlldyAmJiB0eXBlb2Ygd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3LmRpc3Bvc2UgPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICAgICB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcuZGlzcG9zZSgkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1jb252ZXJzYXRpb24nKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCBhdWRpbyA9ICRtb2RhbC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtYXVkaW8nKS5nZXQoMCk7XG4gICAgICAgICAgICBpZiAoIWF1ZGlvKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgYXVkaW8ucGF1c2UoKTtcbiAgICAgICAgICAgIGF1ZGlvLmN1cnJlbnRUaW1lID0gMDtcbiAgICAgICAgICAgIGF1ZGlvLnJlbW92ZUF0dHJpYnV0ZSgnc3JjJyk7XG4gICAgICAgICAgICBhdWRpby5sb2FkKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjbGVhckNvbnZlcnNhdGlvbigpIHtcbiAgICAgICAgICAgIHN0b3BNb2RhbFBsYXliYWNrKCk7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1jb252ZXJzYXRpb24nKS5hdHRyKCdoaWRkZW4nLCB0cnVlKS5lbXB0eSgpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVzZXRNb2RhbChyb3dEYXRhKSB7XG4gICAgICAgICAgICBjbGVhckNvbnZlcnNhdGlvbigpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtbG9hZGluZycpLnJlbW92ZUF0dHIoJ2hpZGRlbicpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtZXJyb3InKS5hdHRyKCdoaWRkZW4nLCB0cnVlKS5yZW1vdmVDbGFzcygnZXJyb3InKS50ZXh0KCcnKTtcbiAgICAgICAgICAgIGNvbnN0ICRtZXRhID0gJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtY2FsbC1tZXRhJykucmVtb3ZlQXR0cignaGlkZGVuJyk7XG4gICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cImRhdGVcIl0nKS50ZXh0KFN0cmluZyhyb3dEYXRhWzBdIHx8ICcnKSk7XG4gICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cInNvdXJjZVwiXScpLnRleHQoU3RyaW5nKHJvd0RhdGFbMV0gfHwgJycpKTtcbiAgICAgICAgICAgICRtZXRhLmZpbmQoJ1tkYXRhLWZpZWxkPVwiZGVzdGluYXRpb25cIl0nKS50ZXh0KFN0cmluZyhyb3dEYXRhWzJdIHx8ICcnKSk7XG4gICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cImR1cmF0aW9uXCJdJykudGV4dChTdHJpbmcocm93RGF0YVszXSB8fCAnJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgLy8gVGhlIGhpc3Rvcnkgcm93IG9ubHkgZXZlciBob2xkcyBiYXJlIG51bWJlcnMsIGFuZCBmb3IgYW4gaW5jb21pbmdcbiAgICAgICAgLy8gY2FsbCB3aXRoIGEgRElEIGl0IGRvZXMgbm90IGV2ZW4gaG9sZCB0aGUgZXh0ZW5zaW9uIHRoZSB0cmFuc2NyaXB0XG4gICAgICAgIC8vIGlzIGFib3V0LiBUaGUgZGV0YWlsIHJlc3BvbnNlIGxhYmVscyB0aGUgY2FsbCdzIG93biBwYXJ0aWVzLCBzbyBpdFxuICAgICAgICAvLyByZXBsYWNlcyB0aG9zZSBudW1iZXJzIG9uY2UgaXQgYXJyaXZlcy5cbiAgICAgICAgZnVuY3Rpb24gYXBwbHlQYXJ0eUxhYmVscyhwYXJ0aWVzKSB7XG4gICAgICAgICAgICBpZiAoIXBhcnRpZXMgfHwgdHlwZW9mIHBhcnRpZXMgIT09ICdvYmplY3QnKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY29uc3QgJG1ldGEgPSAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1jYWxsLW1ldGEnKTtcbiAgICAgICAgICAgIFsnc291cmNlJywgJ2Rlc3RpbmF0aW9uJ10uZm9yRWFjaChmdW5jdGlvbiAoZmllbGQpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBsYWJlbCA9IFN0cmluZyhwYXJ0aWVzW2ZpZWxkXSB8fCAnJyk7XG4gICAgICAgICAgICAgICAgaWYgKGxhYmVsICE9PSAnJykge1xuICAgICAgICAgICAgICAgICAgICAkbWV0YS5maW5kKCdbZGF0YS1maWVsZD1cIicgKyBmaWVsZCArICdcIl0nKS50ZXh0KGxhYmVsKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNob3dFcnJvcihtZXNzYWdlKSB7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1sb2FkaW5nJykuYXR0cignaGlkZGVuJywgdHJ1ZSk7XG4gICAgICAgICAgICBjbGVhckNvbnZlcnNhdGlvbigpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtZXJyb3InKS5yZW1vdmVBdHRyKCdoaWRkZW4nKS5hZGRDbGFzcygnZXJyb3InKS50ZXh0KG1lc3NhZ2UpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2hvd01lc3NhZ2VzKG1lc3NhZ2VzKSB7XG4gICAgICAgICAgICBpZiAodHlwZW9mIFVzZXJNZXNzYWdlICE9PSAndW5kZWZpbmVkJyAmJiBtZXNzYWdlcyAmJiB0eXBlb2YgVXNlck1lc3NhZ2Uuc2hvd011bHRpU3RyaW5nID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgVXNlck1lc3NhZ2Uuc2hvd011bHRpU3RyaW5nKG1lc3NhZ2VzKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIC8vIFNhbWUgY29uc2VxdWVuY2VzIGFuZCBhY2tub3dsZWRnZW1lbnQgYXMgdGhlIGZ1bGwgdHJhbnNjcmlwdCBjYXJkOlxuICAgICAgICAvLyBEZWxldGUgc3RheXMgZGlzYWJsZWQgdW50aWwgdGhlIGNoZWNrYm94IGlzIHRpY2tlZCwgYW5kIG9ubHkgdGhlbiBpc1xuICAgICAgICAvLyBjb25maXJtX2RlbGV0ZSBzZW50LlxuICAgICAgICBmdW5jdGlvbiBlbnN1cmVEZWxldGVNb2RhbCgpIHtcbiAgICAgICAgICAgIGxldCAkY29uZmlybSA9ICQoJyNzdHQtY2RyLWRlbGV0ZS10cmFuc2NyaXB0LW1vZGFsJyk7XG4gICAgICAgICAgICBpZiAoJGNvbmZpcm0ubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybiAkY29uZmlybTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNvbnN0ICRsaXN0ID0gJCgnPHVsPicsIHsgY2xhc3M6ICd1aSBsaXN0JyB9KTtcbiAgICAgICAgICAgIFtcbiAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0RlbGV0ZVRyYW5zY3JpcHRDb25zZXF1ZW5jZScsXG4gICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9EZWxldGVUcmFuc2NyaXB0S2VlcHNDZHInLFxuICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfRGVsZXRlVHJhbnNjcmlwdEJhY2t1cFdhcm5pbmcnLFxuICAgICAgICAgICAgXS5mb3JFYWNoKGZ1bmN0aW9uIChrZXkpIHtcbiAgICAgICAgICAgICAgICAkbGlzdC5hcHBlbmQoJCgnPGxpPicpLnRleHQodHJhbnNsYXRlKGtleSkpKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJGNvbmZpcm0gPSAkKCc8ZGl2PicsIHtcbiAgICAgICAgICAgICAgICBjbGFzczogJ3VpIHRpbnkgbW9kYWwnLFxuICAgICAgICAgICAgICAgIGlkOiAnc3R0LWNkci1kZWxldGUtdHJhbnNjcmlwdC1tb2RhbCcsXG4gICAgICAgICAgICAgICAgcm9sZTogJ2RpYWxvZycsXG4gICAgICAgICAgICAgICAgJ2FyaWEtbW9kYWwnOiAndHJ1ZScsXG4gICAgICAgICAgICAgICAgJ2FyaWEtbGFiZWxsZWRieSc6ICdzdHQtY2RyLWRlbGV0ZS10cmFuc2NyaXB0LXRpdGxlJyxcbiAgICAgICAgICAgIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdoZWFkZXInLCBpZDogJ3N0dC1jZHItZGVsZXRlLXRyYW5zY3JpcHQtdGl0bGUnIH0pXG4gICAgICAgICAgICAgICAgICAgIC50ZXh0KHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0RlbGV0ZVRyYW5zY3JpcHQnKSksXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnY29udGVudCcgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAkbGlzdCxcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAndWkgY2hlY2tib3gnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgICAgICQoJzxpbnB1dD4nLCB7IHR5cGU6ICdjaGVja2JveCcsIGlkOiAnc3R0LWNkci1kZWxldGUtdHJhbnNjcmlwdC1hY2tub3dsZWRnZScgfSksXG4gICAgICAgICAgICAgICAgICAgICAgICAkKCc8bGFiZWw+JywgeyBmb3I6ICdzdHQtY2RyLWRlbGV0ZS10cmFuc2NyaXB0LWFja25vd2xlZGdlJyB9KVxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIC50ZXh0KHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0RlbGV0ZVRyYW5zY3JpcHRDb25maXJtJykpXG4gICAgICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICAgICApLFxuICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ2FjdGlvbnMnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgJCgnPGJ1dHRvbj4nLCB7IHR5cGU6ICdidXR0b24nLCBjbGFzczogJ3VpIGNhbmNlbCBiYXNpYyBidXR0b24nIH0pXG4gICAgICAgICAgICAgICAgICAgICAgICAudGV4dCh0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Db25maXJtQ2FuY2VsJykpLFxuICAgICAgICAgICAgICAgICAgICAkKCc8YnV0dG9uPicsIHsgdHlwZTogJ2J1dHRvbicsIGNsYXNzOiAndWkgcmVkIGFwcHJvdmUgYnV0dG9uIGRpc2FibGVkJywgZGlzYWJsZWQ6IHRydWUgfSlcbiAgICAgICAgICAgICAgICAgICAgICAgIC50ZXh0KHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0NvbmZpcm1EZWxldGUnKSlcbiAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgJCgnYm9keScpLmFwcGVuZCgkY29uZmlybSk7XG4gICAgICAgICAgICAkY29uZmlybS5maW5kKCcjc3R0LWNkci1kZWxldGUtdHJhbnNjcmlwdC1hY2tub3dsZWRnZScpLm9uKCdjaGFuZ2UnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgY2hlY2tlZCA9ICQodGhpcykucHJvcCgnY2hlY2tlZCcpO1xuICAgICAgICAgICAgICAgICRjb25maXJtLmZpbmQoJy5hcHByb3ZlLmJ1dHRvbicpLnRvZ2dsZUNsYXNzKCdkaXNhYmxlZCcsICFjaGVja2VkKS5wcm9wKCdkaXNhYmxlZCcsICFjaGVja2VkKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgcmV0dXJuICRjb25maXJtO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gZGVsZXRlVHJhbnNjcmlwdChyZXN1bHRJZCwgY2FsbElkKSB7XG4gICAgICAgICAgICBjb25zdCAkY29uZmlybSA9IGVuc3VyZURlbGV0ZU1vZGFsKCk7XG4gICAgICAgICAgICBjb25zdCAkYXBwcm92ZSA9ICRjb25maXJtLmZpbmQoJy5hcHByb3ZlLmJ1dHRvbicpO1xuICAgICAgICAgICAgJGNvbmZpcm0uZmluZCgnI3N0dC1jZHItZGVsZXRlLXRyYW5zY3JpcHQtYWNrbm93bGVkZ2UnKS5wcm9wKCdjaGVja2VkJywgZmFsc2UpO1xuICAgICAgICAgICAgJGFwcHJvdmUuYWRkQ2xhc3MoJ2Rpc2FibGVkJykucHJvcCgnZGlzYWJsZWQnLCB0cnVlKS5yZW1vdmVDbGFzcygnbG9hZGluZycpO1xuICAgICAgICAgICAgJGNvbmZpcm0ubW9kYWwoe1xuICAgICAgICAgICAgICAgIGFsbG93TXVsdGlwbGU6IHRydWUsXG4gICAgICAgICAgICAgICAgY2xvc2FibGU6IHRydWUsXG4gICAgICAgICAgICAgICAgb25BcHByb3ZlOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIGlmICghJGNvbmZpcm0uZmluZCgnI3N0dC1jZHItZGVsZXRlLXRyYW5zY3JpcHQtYWNrbm93bGVkZ2UnKS5wcm9wKCdjaGVja2VkJykpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICAkYXBwcm92ZS5hZGRDbGFzcygnbG9hZGluZyBkaXNhYmxlZCcpLnByb3AoJ2Rpc2FibGVkJywgdHJ1ZSk7XG4gICAgICAgICAgICAgICAgICAgICQuYWpheCh7XG4gICAgICAgICAgICAgICAgICAgICAgICB1cmw6IGRlbGV0ZVVybCxcbiAgICAgICAgICAgICAgICAgICAgICAgIG1ldGhvZDogJ1BPU1QnLFxuICAgICAgICAgICAgICAgICAgICAgICAgZGF0YVR5cGU6ICdqc29uJyxcbiAgICAgICAgICAgICAgICAgICAgICAgIGRhdGE6IHsgcmVzdWx0X2lkOiByZXN1bHRJZCwgY29uZmlybV9kZWxldGU6ICcxJyB9LFxuICAgICAgICAgICAgICAgICAgICAgICAgYmVmb3JlU2VuZDogYWRkQXV0aG9yaXphdGlvbkhlYWRlcixcbiAgICAgICAgICAgICAgICAgICAgfSkuZG9uZShmdW5jdGlvbiAocmVzcG9uc2UpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHNob3dNZXNzYWdlcyhyZXNwb25zZSAmJiByZXNwb25zZS5tZXNzYWdlKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIGlmIChyZXNwb25zZSAmJiByZXNwb25zZS5zdWNjZXNzID09PSB0cnVlKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgZGVsZXRlIGF2YWlsYWJpbGl0eVtjYWxsSWRdO1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgICR0YWJsZS5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWJ1dHRvbicpLmZpbHRlcihmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiAkKHRoaXMpLmF0dHIoJ2RhdGEtY2FsbC1pZCcpID09PSBjYWxsSWQ7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgfSkucmVtb3ZlKCk7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgJGNvbmZpcm0ubW9kYWwoJ2hpZGUnKTtcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAkbW9kYWwubW9kYWwoJ2hpZGUnKTtcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgICAgICAkY29uZmlybS5tb2RhbCgnaGlkZScpO1xuICAgICAgICAgICAgICAgICAgICB9KS5mYWlsKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgICRjb25maXJtLm1vZGFsKCdoaWRlJyk7XG4gICAgICAgICAgICAgICAgICAgICAgICBzaG93TWVzc2FnZXMoeyBlcnJvcjogW3RyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHREZWxldGVGYWlsZWQnKV0gfSk7XG4gICAgICAgICAgICAgICAgICAgIH0pLmFsd2F5cyhmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAkYXBwcm92ZS5yZW1vdmVDbGFzcygnbG9hZGluZycpO1xuICAgICAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB9KS5tb2RhbCgnc2hvdycpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY3JlYXRlQWN0aW9uc01lbnUocmVzdWx0SWQsIGNhbGxJZCwgY2FuRGVsZXRlKSB7XG4gICAgICAgICAgICBjb25zdCAkbWVudSA9ICQoJzxkaXY+JywgeyBjbGFzczogJ3VpIHZlcnRpY2FsIG1lbnUnIH0pO1xuICAgICAgICAgICAgWyd0eHQnLCAnanNvbiddLmZvckVhY2goZnVuY3Rpb24gKGZvcm1hdCkge1xuICAgICAgICAgICAgICAgICRtZW51LmFwcGVuZCgkKCc8YT4nLCB7XG4gICAgICAgICAgICAgICAgICAgIGNsYXNzOiAnaXRlbScsXG4gICAgICAgICAgICAgICAgICAgIGhyZWY6IGAke2V4cG9ydFVybH0/aWQ9JHtlbmNvZGVVUklDb21wb25lbnQoU3RyaW5nKHJlc3VsdElkKSl9JmZvcm1hdD0ke2Zvcm1hdH1gLFxuICAgICAgICAgICAgICAgIH0pLnRleHQodHJhbnNsYXRlKGZvcm1hdCA9PT0gJ3R4dCdcbiAgICAgICAgICAgICAgICAgICAgPyAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Rvd25sb2FkVHJhbnNjcmlwdFR4dCdcbiAgICAgICAgICAgICAgICAgICAgOiAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Rvd25sb2FkVHJhbnNjcmlwdEpzb24nKSkpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBpZiAoY2FuRGVsZXRlKSB7XG4gICAgICAgICAgICAgICAgJG1lbnUuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdkaXZpZGVyJyB9KSxcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnaXRlbSBzdHQtZGVsZXRlLXRyYW5zY3JpcHQtZm9ybScgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAgICAgJCgnPGJ1dHRvbj4nLCB7IHR5cGU6ICdidXR0b24nIH0pXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgLnRleHQodHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfRGVsZXRlVHJhbnNjcmlwdCcpKVxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIC5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIGRlbGV0ZVRyYW5zY3JpcHQocmVzdWx0SWQsIGNhbGxJZCk7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgICAgICk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCBsYWJlbCA9IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRBY3Rpb25zJyk7XG4gICAgICAgICAgICBjb25zdCAkZGV0YWlscyA9ICQoJzxkZXRhaWxzPicsIHsgY2xhc3M6ICdzdHQtdHJhbnNjcmlwdC1hY3Rpb25zJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPHN1bW1hcnk+JywgeyAnYXJpYS1sYWJlbCc6IGxhYmVsLCB0aXRsZTogbGFiZWwgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAkKCc8aT4nLCB7IGNsYXNzOiAnZWxsaXBzaXMgaG9yaXpvbnRhbCBpY29uJyB9KVxuICAgICAgICAgICAgICAgICksXG4gICAgICAgICAgICAgICAgJG1lbnVcbiAgICAgICAgICAgICk7XG4gICAgICAgICAgICAkbWVudS5vbignY2xpY2snLCAnYSwgYnV0dG9uJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICRkZXRhaWxzLnJlbW92ZUF0dHIoJ29wZW4nKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgcmV0dXJuICRkZXRhaWxzO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVuZGVyQ29udmVyc2F0aW9uKHRyYW5zY3JpcHQsIHJvd0RhdGEsIGNhbGxJZCwgY2FuRGVsZXRlKSB7XG4gICAgICAgICAgICBjb25zdCBjb252ZXJzYXRpb24gPSB0cmFuc2NyaXB0ICYmIHR5cGVvZiB0cmFuc2NyaXB0LmNvbnZlcnNhdGlvbiA9PT0gJ29iamVjdCdcbiAgICAgICAgICAgICAgICA/IHRyYW5zY3JpcHQuY29udmVyc2F0aW9uXG4gICAgICAgICAgICAgICAgOiBudWxsO1xuICAgICAgICAgICAgY29uc3QgdGltZWxpbmUgPSBjb252ZXJzYXRpb24gJiYgQXJyYXkuaXNBcnJheShjb252ZXJzYXRpb24udGltZWxpbmUpID8gY29udmVyc2F0aW9uLnRpbWVsaW5lIDogW107XG4gICAgICAgICAgICBjb25zdCByZWNvcmRpbmdzID0gcm93RGF0YSAmJiBBcnJheS5pc0FycmF5KHJvd0RhdGFbNF0pID8gcm93RGF0YVs0XSA6IFtdO1xuICAgICAgICAgICAgY29uc3QgcGxheWJhY2tDZHJJZCA9IHRyYW5zY3JpcHQgJiYgdHJhbnNjcmlwdC5wbGF5YmFja0NkcklkICE9PSBudWxsXG4gICAgICAgICAgICAgICAgJiYgdHlwZW9mIHRyYW5zY3JpcHQucGxheWJhY2tDZHJJZCAhPT0gJ3VuZGVmaW5lZCdcbiAgICAgICAgICAgICAgICA/IFN0cmluZyh0cmFuc2NyaXB0LnBsYXliYWNrQ2RySWQpXG4gICAgICAgICAgICAgICAgOiAnJztcbiAgICAgICAgICAgIGxldCByZWNvcmRpbmcgPSB7fTtcbiAgICAgICAgICAgIHJlY29yZGluZ3Muc29tZShmdW5jdGlvbiAoaXRlbSkge1xuICAgICAgICAgICAgICAgIGlmIChwbGF5YmFja0NkcklkICE9PSAnJyAmJiBpdGVtICYmIFN0cmluZyhpdGVtLmlkKSA9PT0gcGxheWJhY2tDZHJJZCkge1xuICAgICAgICAgICAgICAgICAgICByZWNvcmRpbmcgPSBpdGVtO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBjbGVhckNvbnZlcnNhdGlvbigpO1xuICAgICAgICAgICAgY29uc3QgJHRhcmdldCA9ICRtb2RhbC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWNvbnZlcnNhdGlvbicpLnJlbW92ZUF0dHIoJ2hpZGRlbicpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtbG9hZGluZycpLmF0dHIoJ2hpZGRlbicsIHRydWUpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtZXJyb3InKS5hdHRyKCdoaWRkZW4nLCB0cnVlKS50ZXh0KCcnKTtcbiAgICAgICAgICAgIGlmICh0aW1lbGluZS5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgICAgICAkdGFyZ2V0LmFwcGVuZCgkKCc8ZGl2PicsIHsgY2xhc3M6ICd1aSBtZXNzYWdlJyB9KS50ZXh0KGxhYmVscy5lbXB0eSkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICghd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3IHx8IHR5cGVvZiB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcuY3JlYXRlICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgc2hvd0Vycm9yKGxhYmVscy5sb2FkRXJyb3IpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgICR0YXJnZXQuYXBwZW5kKHdpbmRvdy5TdHRUcmFuc2NyaXB0Vmlldy5jcmVhdGUoY29udmVyc2F0aW9uLCB7XG4gICAgICAgICAgICAgICAgYWxsOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5QWxsJyksXG4gICAgICAgICAgICAgICAgc2VhcmNoOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzU2VhcmNoJyksXG4gICAgICAgICAgICAgICAgZW1wdHk6IGxhYmVscy5lbXB0eSxcbiAgICAgICAgICAgICAgICBwbGF5OiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9QbGF5UmVjb3JkaW5nJyksXG4gICAgICAgICAgICAgICAgc3BlZWQ6IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1BsYXliYWNrU3BlZWQnKSxcbiAgICAgICAgICAgICAgICBsb2FkaW5nOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2FkaW5nUmVjb3JkaW5nJyksXG4gICAgICAgICAgICAgICAgdW5hdmFpbGFibGU6IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1JlY29yZGluZ1VuYXZhaWxhYmxlJyksXG4gICAgICAgICAgICAgICAgZmFpbGVkOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9QbGF5YmFja0ZhaWxlZCcpLFxuICAgICAgICAgICAgICAgIGRvd25sb2FkOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Eb3dubG9hZFJlY29yZGluZycpLFxuICAgICAgICAgICAgICAgIGF1ZGlvVXJsOiBTdHJpbmcocmVjb3JkaW5nLnBsYXliYWNrX3VybCB8fCAnJyksXG4gICAgICAgICAgICAgICAgZG93bmxvYWRVcmw6IFN0cmluZyhyZWNvcmRpbmcuZG93bmxvYWRfdXJsIHx8ICcnKSxcbiAgICAgICAgICAgICAgICBkdXJhdGlvblNlY29uZHM6IGNvbnZlcnNhdGlvbi5kdXJhdGlvblNlY29uZHMsXG4gICAgICAgICAgICAgICAgYWN0aW9uczogdHJhbnNjcmlwdC5yZXN1bHRJZCA/IGNyZWF0ZUFjdGlvbnNNZW51KHRyYW5zY3JpcHQucmVzdWx0SWQsIGNhbGxJZCwgY2FuRGVsZXRlKSA6IG51bGwsXG4gICAgICAgICAgICB9KSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBvcGVuVHJhbnNjcmlwdChkYXRhVGFibGUsICRidXR0b24pIHtcbiAgICAgICAgICAgIGNvbnN0IHJvd0RhdGEgPSBkYXRhVGFibGUucm93KCRidXR0b24uY2xvc2VzdCgndHInKSkuZGF0YSgpO1xuICAgICAgICAgICAgY29uc3QgY2FsbElkID0gU3RyaW5nKCRidXR0b24uYXR0cignZGF0YS1jYWxsLWlkJykgfHwgJycpLnRyaW0oKTtcbiAgICAgICAgICAgIGlmICghcm93RGF0YSB8fCBjYWxsSWQgPT09ICcnKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBpZiAoZGV0YWlsUmVxdWVzdCAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIGRldGFpbFJlcXVlc3QuYWJvcnQoKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHJlc2V0TW9kYWwocm93RGF0YSk7XG4gICAgICAgICAgICAkbW9kYWwubW9kYWwoJ3Nob3cnKTtcbiAgICAgICAgICAgIGRldGFpbFJlcXVlc3QgPSAkLmFqYXgoe1xuICAgICAgICAgICAgICAgIHVybDogZGV0YWlsVXJsLFxuICAgICAgICAgICAgICAgIG1ldGhvZDogJ0dFVCcsXG4gICAgICAgICAgICAgICAgZGF0YVR5cGU6ICdqc29uJyxcbiAgICAgICAgICAgICAgICBkYXRhOiB7IGNhbGxfaWQ6IGNhbGxJZCB9LFxuICAgICAgICAgICAgICAgIGJlZm9yZVNlbmQ6IGFkZEF1dGhvcml6YXRpb25IZWFkZXIsXG4gICAgICAgICAgICB9KS5kb25lKGZ1bmN0aW9uIChyZXNwb25zZSkge1xuICAgICAgICAgICAgICAgIGFwcGx5UGFydHlMYWJlbHMocmVzcG9uc2UgJiYgcmVzcG9uc2UudHJhbnNjcmlwdCA/IHJlc3BvbnNlLnRyYW5zY3JpcHQucGFydGllcyA6IG51bGwpO1xuICAgICAgICAgICAgICAgIHJlbmRlckNvbnZlcnNhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgcmVzcG9uc2UgJiYgcmVzcG9uc2UudHJhbnNjcmlwdCA/IHJlc3BvbnNlLnRyYW5zY3JpcHQgOiBudWxsLFxuICAgICAgICAgICAgICAgICAgICByb3dEYXRhLFxuICAgICAgICAgICAgICAgICAgICBjYWxsSWQsXG4gICAgICAgICAgICAgICAgICAgIEJvb2xlYW4ocmVzcG9uc2UgJiYgcmVzcG9uc2UuY2FuRGVsZXRlVHJhbnNjcmlwdClcbiAgICAgICAgICAgICAgICApO1xuICAgICAgICAgICAgfSkuZmFpbChmdW5jdGlvbiAoeGhyLCBzdGF0dXMpIHtcbiAgICAgICAgICAgICAgICBpZiAoc3RhdHVzID09PSAnYWJvcnQnKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgaWYgKHhoci5zdGF0dXMgPT09IDQwNCkge1xuICAgICAgICAgICAgICAgICAgICBkZWxldGUgYXZhaWxhYmlsaXR5W2NhbGxJZF07XG4gICAgICAgICAgICAgICAgICAgICRidXR0b24ucmVtb3ZlKCk7XG4gICAgICAgICAgICAgICAgICAgIHNob3dFcnJvcihsYWJlbHMubm90Rm91bmQpO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmICh4aHIuc3RhdHVzID09PSA0MDEgfHwgeGhyLnN0YXR1cyA9PT0gNDAzKSB7XG4gICAgICAgICAgICAgICAgICAgIGFjY2Vzc0RlbmllZCA9IHRydWU7XG4gICAgICAgICAgICAgICAgICAgIHJlbW92ZUJ1dHRvbnMoKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgc2hvd0Vycm9yKGxhYmVscy5sb2FkRXJyb3IpO1xuICAgICAgICAgICAgfSkuYWx3YXlzKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBkZXRhaWxSZXF1ZXN0ID0gbnVsbDtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc3RhcnQoZGF0YVRhYmxlKSB7XG4gICAgICAgICAgICBpZiAoJHRhYmxlLmRhdGEoJ3N0dC1jZHItdHJhbnNjcmlwdC1zdGFydGVkJykpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICAkdGFibGUuZGF0YSgnc3R0LWNkci10cmFuc2NyaXB0LXN0YXJ0ZWQnLCB0cnVlKTtcbiAgICAgICAgICAgICRtb2RhbC5tb2RhbCh7XG4gICAgICAgICAgICAgICAgY2xvc2FibGU6IHRydWUsXG4gICAgICAgICAgICAgICAgb25IaWRkZW46IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgaWYgKGRldGFpbFJlcXVlc3QgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGRldGFpbFJlcXVlc3QuYWJvcnQoKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIGRldGFpbFJlcXVlc3QgPSBudWxsO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIGNsZWFyQ29udmVyc2F0aW9uKCk7XG4gICAgICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJChkb2N1bWVudCkub24oJ2NsaWNrLnN0dENkclRyYW5zY3JpcHRBY3Rpb25zJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1hY3Rpb25zW29wZW5dJykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIGlmICghdGhpcy5jb250YWlucyhldmVudC50YXJnZXQpKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAkKHRoaXMpLnJlbW92ZUF0dHIoJ29wZW4nKTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAkdGFibGUub24oJ2RyYXcuZHQuc3R0Q2RyVHJhbnNjcmlwdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICByZWZyZXNoQnV0dG9ucyhkYXRhVGFibGUpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAkdGFibGUub24oJ2NsaWNrLnN0dENkclRyYW5zY3JpcHQnLCAnLnN0dC1jZHItdHJhbnNjcmlwdC1idXR0b24nLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKTtcbiAgICAgICAgICAgICAgICBjb25zdCAkYnV0dG9uID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICBpZiAoZXZlbnQuY3RybEtleSB8fCBldmVudC5tZXRhS2V5KSB7XG4gICAgICAgICAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IG9wZW5lZFRhYiA9IHdpbmRvdy5vcGVuKCRidXR0b24uYXR0cignaHJlZicpLCAnX2JsYW5rJyk7XG4gICAgICAgICAgICAgICAgICAgIGlmIChvcGVuZWRUYWIgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIG9wZW5lZFRhYi5vcGVuZXIgPSBudWxsO1xuICAgICAgICAgICAgICAgICAgICAgICAgb3BlbmVkVGFiLmZvY3VzKCk7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgICAgIG9wZW5UcmFuc2NyaXB0KGRhdGFUYWJsZSwgJGJ1dHRvbik7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIHNldFRpbWVvdXQoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHJlZnJlc2hCdXR0b25zKGRhdGFUYWJsZSk7XG4gICAgICAgICAgICB9LCAwKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGlmICgkLmZuLkRhdGFUYWJsZS5pc0RhdGFUYWJsZSgkdGFibGUpKSB7XG4gICAgICAgICAgICBzdGFydCgkdGFibGUuRGF0YVRhYmxlKCkpO1xuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgICAgJHRhYmxlLm9uZSgnaW5pdC5kdC5zdHRDZHJUcmFuc2NyaXB0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHN0YXJ0KCR0YWJsZS5EYXRhVGFibGUoKSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuICAgIH0pO1xufSgpKTtcbiJdLCJtYXBwaW5ncyI6IjtBQUFDLGFBQVk7RUFDVCxZQUFZOztFQUVaLElBQU1BLGdCQUFnQixHQUFHLEdBQUc7RUFFNUJDLENBQUMsQ0FBQyxZQUFZO0lBQ1YsU0FBU0MsU0FBU0EsQ0FBQ0MsR0FBRyxFQUFFO01BQ3BCLElBQUksT0FBT0MsZUFBZSxLQUFLLFdBQVcsSUFBSUEsZUFBZSxJQUFJQSxlQUFlLENBQUNELEdBQUcsQ0FBQyxFQUFFO1FBQ25GLE9BQU9FLE1BQU0sQ0FBQ0QsZUFBZSxDQUFDRCxHQUFHLENBQUMsQ0FBQztNQUN2QztNQUNBLE9BQU9BLEdBQUc7SUFDZDtJQUVBLFNBQVNHLFdBQVdBLENBQUEsRUFBRztNQUNuQixJQUFJQyxNQUFNLEdBQUdOLENBQUMsQ0FBQywyQkFBMkIsQ0FBQztNQUMzQyxJQUFJTSxNQUFNLENBQUNDLE1BQU0sR0FBRyxDQUFDLEVBQUU7UUFDbkIsT0FBT0QsTUFBTTtNQUNqQjtNQUVBLElBQU1FLE1BQU0sR0FBRztRQUNYQyxNQUFNLEVBQUVSLFNBQVMsQ0FBQyxpREFBaUQsQ0FBQztRQUNwRVMsS0FBSyxFQUFFVCxTQUFTLENBQUMscURBQXFELENBQUM7UUFDdkVVLE9BQU8sRUFBRVYsU0FBUyxDQUFDLGtEQUFrRCxDQUFDO1FBQ3RFVyxTQUFTLEVBQUVYLFNBQVMsQ0FBQyxvREFBb0QsQ0FBQztRQUMxRVksUUFBUSxFQUFFWixTQUFTLENBQUMsbURBQW1ELENBQUM7UUFDeEVhLEtBQUssRUFBRWIsU0FBUyxDQUFDLG9EQUFvRDtNQUN6RSxDQUFDO01BQ0QsSUFBTWMsT0FBTyxHQUFHZixDQUFDLENBQUMsT0FBTyxFQUFFO1FBQUVnQixLQUFLLEVBQUU7TUFBUyxDQUFDLENBQUMsQ0FBQ0MsTUFBTSxDQUNsRGpCLENBQUMsQ0FBQyxLQUFLLEVBQUU7UUFBRWdCLEtBQUssRUFBRTtNQUFpQyxDQUFDLENBQUMsRUFDckRFLFFBQVEsQ0FBQ0MsY0FBYyxDQUFDWCxNQUFNLENBQUNFLEtBQUssQ0FDeEMsQ0FBQztNQUNELElBQU1VLFFBQVEsR0FBR3BCLENBQUMsQ0FBQyxPQUFPLEVBQUU7UUFDeEJnQixLQUFLLEVBQUUsNEJBQTRCO1FBQ25DSyxJQUFJLEVBQUUsUUFBUTtRQUNkLFdBQVcsRUFBRTtNQUNqQixDQUFDLENBQUMsQ0FBQ0osTUFBTSxDQUNMakIsQ0FBQyxDQUFDLE9BQU8sRUFBRTtRQUFFZ0IsS0FBSyxFQUFFO01BQTZELENBQUMsQ0FBQyxFQUNuRmhCLENBQUMsQ0FBQyxPQUFPLENBQUMsQ0FBQ3NCLElBQUksQ0FBQ2QsTUFBTSxDQUFDRyxPQUFPLENBQ2xDLENBQUM7TUFDRCxJQUFNWSxTQUFTLEdBQUd2QixDQUFDLENBQUMsT0FBTyxFQUFFO1FBQ3pCZ0IsS0FBSyxFQUFFLDhCQUE4QjtRQUNyQ1EsTUFBTSxFQUFFO01BQ1osQ0FBQyxDQUFDLENBQUNQLE1BQU0sQ0FDTGpCLENBQUMsQ0FBQyxRQUFRLEVBQUU7UUFBRSxZQUFZLEVBQUU7TUFBTyxDQUFDLENBQUMsRUFDckNBLENBQUMsQ0FBQyxRQUFRLEVBQUU7UUFBRSxZQUFZLEVBQUU7TUFBUyxDQUFDLENBQUMsRUFDdkNBLENBQUMsQ0FBQyxRQUFRLEVBQUU7UUFBRSxZQUFZLEVBQUU7TUFBYyxDQUFDLENBQUMsRUFDNUNBLENBQUMsQ0FBQyxRQUFRLEVBQUU7UUFBRSxZQUFZLEVBQUU7TUFBVyxDQUFDLENBQzVDLENBQUM7TUFDRE0sTUFBTSxHQUFHTixDQUFDLENBQUMsT0FBTyxFQUFFO1FBQ2hCZ0IsS0FBSyxFQUFFLHFFQUFxRTtRQUM1RVMsRUFBRSxFQUFFLDBCQUEwQjtRQUM5QixtQkFBbUIsRUFBRWpCLE1BQU0sQ0FBQ0MsTUFBTTtRQUNsQyx1QkFBdUIsRUFBRUQsTUFBTSxDQUFDSSxTQUFTO1FBQ3pDLHNCQUFzQixFQUFFSixNQUFNLENBQUNLLFFBQVE7UUFDdkMsa0JBQWtCLEVBQUVMLE1BQU0sQ0FBQ007TUFDL0IsQ0FBQyxDQUFDLENBQUNHLE1BQU0sQ0FDTGpCLENBQUMsQ0FBQyxLQUFLLEVBQUU7UUFBRWdCLEtBQUssRUFBRTtNQUFhLENBQUMsQ0FBQyxFQUNqQ0QsT0FBTyxFQUNQZixDQUFDLENBQUMsT0FBTyxFQUFFO1FBQUVnQixLQUFLLEVBQUU7TUFBVSxDQUFDLENBQUMsQ0FBQ0MsTUFBTSxDQUNuQ0csUUFBUSxFQUNScEIsQ0FBQyxDQUFDLE9BQU8sRUFBRTtRQUNQZ0IsS0FBSyxFQUFFLHFDQUFxQztRQUM1Q0ssSUFBSSxFQUFFLE9BQU87UUFDYixXQUFXLEVBQUUsV0FBVztRQUN4QkcsTUFBTSxFQUFFO01BQ1osQ0FBQyxDQUFDLEVBQ0ZELFNBQVMsRUFDVHZCLENBQUMsQ0FBQyxPQUFPLEVBQUU7UUFBRWdCLEtBQUssRUFBRSxpQ0FBaUM7UUFBRVEsTUFBTSxFQUFFO01BQUssQ0FBQyxDQUN6RSxDQUNKLENBQUM7TUFDRHhCLENBQUMsQ0FBQyxNQUFNLENBQUMsQ0FBQ2lCLE1BQU0sQ0FBQ1gsTUFBTSxDQUFDO01BQ3hCLE9BQU9BLE1BQU07SUFDakI7SUFFQSxJQUFNb0IsTUFBTSxHQUFHMUIsQ0FBQyxDQUFDLFlBQVksQ0FBQztJQUM5QixJQUFJMEIsTUFBTSxDQUFDbkIsTUFBTSxLQUFLLENBQUMsRUFBRTtNQUNyQjtJQUNKO0lBQ0EsSUFBTUQsTUFBTSxHQUFHRCxXQUFXLENBQUMsQ0FBQztJQUM1QixJQUFJQyxNQUFNLENBQUNDLE1BQU0sS0FBSyxDQUFDLElBQUksT0FBT1AsQ0FBQyxDQUFDMkIsRUFBRSxDQUFDQyxTQUFTLEtBQUssV0FBVyxFQUFFO01BQzlEO0lBQ0o7SUFFQSxJQUFNcEIsTUFBTSxHQUFHO01BQ1hDLE1BQU0sRUFBRUwsTUFBTSxDQUFDRSxNQUFNLENBQUN1QixJQUFJLENBQUMsY0FBYyxDQUFDLElBQUksRUFBRSxDQUFDO01BQ2pEakIsU0FBUyxFQUFFUixNQUFNLENBQUNFLE1BQU0sQ0FBQ3VCLElBQUksQ0FBQyxrQkFBa0IsQ0FBQyxJQUFJLEVBQUUsQ0FBQztNQUN4RGhCLFFBQVEsRUFBRVQsTUFBTSxDQUFDRSxNQUFNLENBQUN1QixJQUFJLENBQUMsaUJBQWlCLENBQUMsSUFBSSxFQUFFLENBQUM7TUFDdERmLEtBQUssRUFBRVYsTUFBTSxDQUFDRSxNQUFNLENBQUN1QixJQUFJLENBQUMsYUFBYSxDQUFDLElBQUksRUFBRTtJQUNsRCxDQUFDO0lBQ0QsSUFBTUMsT0FBTyxHQUFHLE9BQU9DLGFBQWEsS0FBSyxRQUFRLEdBQUdBLGFBQWEsR0FBRyxpQkFBaUI7SUFDckYsSUFBTUMsU0FBUyxNQUFBQyxNQUFBLENBQU1ILE9BQU8sc0RBQW1EO0lBQy9FLElBQU1JLFNBQVMsTUFBQUQsTUFBQSxDQUFNSCxPQUFPLHNEQUFtRDtJQUMvRSxJQUFNSyxhQUFhLE1BQUFGLE1BQUEsQ0FBTUgsT0FBTyx1RUFBb0U7SUFDcEcsSUFBTU0sU0FBUyxNQUFBSCxNQUFBLENBQU1ILE9BQU8sMERBQXVEO0lBQ25GLElBQU1PLFNBQVMsTUFBQUosTUFBQSxDQUFNSCxPQUFPLDBEQUF1RDtJQUNuRixJQUFNUSxZQUFZLEdBQUdDLE1BQU0sQ0FBQ0MsTUFBTSxDQUFDLElBQUksQ0FBQztJQUN4QyxJQUFNQyxPQUFPLEdBQUdGLE1BQU0sQ0FBQ0MsTUFBTSxDQUFDLElBQUksQ0FBQztJQUNuQyxJQUFJRSxZQUFZLEdBQUcsS0FBSztJQUN4QixJQUFJQyxhQUFhLEdBQUcsSUFBSTtJQUV4QixTQUFTQyxzQkFBc0JBLENBQUNDLEdBQUcsRUFBRTtNQUNqQyxJQUFJLE9BQU9DLFlBQVksS0FBSyxXQUFXLElBQUlBLFlBQVksQ0FBQ0MsV0FBVyxFQUFFO1FBQ2pFRixHQUFHLENBQUNHLGdCQUFnQixDQUFDLGVBQWUsWUFBQWYsTUFBQSxDQUFZYSxZQUFZLENBQUNDLFdBQVcsQ0FBRSxDQUFDO01BQy9FO0lBQ0o7SUFFQSxTQUFTRSxXQUFXQSxDQUFDQyxTQUFTLEVBQUU7TUFDNUIsSUFBTUMsSUFBSSxHQUFHLEVBQUU7TUFDZmpDLFFBQVEsQ0FBQ2tDLGdCQUFnQixDQUFDLHlCQUF5QixDQUFDLENBQUNDLE9BQU8sQ0FBQyxVQUFVQyxJQUFJLEVBQUU7UUFDekUsSUFBTUMsTUFBTSxHQUFHbkQsTUFBTSxDQUFDa0QsSUFBSSxDQUFDN0IsRUFBRSxJQUFJLEVBQUUsQ0FBQyxDQUFDK0IsSUFBSSxDQUFDLENBQUM7UUFDM0MsSUFBSUQsTUFBTSxLQUFLLEVBQUUsSUFBSUEsTUFBTSxDQUFDaEQsTUFBTSxHQUFHLEdBQUcsRUFBRTtVQUN0QztRQUNKO1FBQ0EsSUFBTWtELEtBQUssR0FBR0gsSUFBSSxDQUFDRixnQkFBZ0IsQ0FBQyxhQUFhLENBQUM7UUFDbEQsSUFBSUssS0FBSyxDQUFDbEQsTUFBTSxHQUFHLENBQUMsRUFBRTtVQUNsQjtRQUNKO1FBQ0E0QyxJQUFJLENBQUNPLElBQUksQ0FBQztVQUNOSixJQUFJLEVBQUV0RCxDQUFDLENBQUNzRCxJQUFJLENBQUM7VUFDYnpCLElBQUksRUFBRXFCLFNBQVMsQ0FBQ1MsR0FBRyxDQUFDTCxJQUFJLENBQUMsQ0FBQ3pCLElBQUksQ0FBQyxDQUFDO1VBQ2hDMEIsTUFBTSxFQUFOQSxNQUFNO1VBQ05LLFdBQVcsRUFBRTVELENBQUMsQ0FBQ3lELEtBQUssQ0FBQ0EsS0FBSyxDQUFDbEQsTUFBTSxHQUFHLENBQUMsQ0FBQyxDQUFDO1VBQ3ZDc0QsZUFBZSxFQUFFSixLQUFLLENBQUNsRCxNQUFNLEdBQUc7UUFDcEMsQ0FBQyxDQUFDO01BQ04sQ0FBQyxDQUFDO01BQ0YsT0FBTzRDLElBQUk7SUFDZjtJQUVBLFNBQVNXLGFBQWFBLENBQUEsRUFBRztNQUNyQnBDLE1BQU0sQ0FBQ3FDLElBQUksQ0FBQyw0QkFBNEIsQ0FBQyxDQUFDQyxNQUFNLENBQUMsQ0FBQztJQUN0RDtJQUVBLFNBQVNDLFNBQVNBLENBQUNOLEdBQUcsRUFBRTtNQUNwQixJQUFNTyxPQUFPLEdBQUdQLEdBQUcsQ0FBQ0MsV0FBVztNQUMvQixJQUFJTSxPQUFPLENBQUMzRCxNQUFNLEtBQUssQ0FBQyxJQUFJMkQsT0FBTyxDQUFDSCxJQUFJLENBQUMsNEJBQTRCLENBQUMsQ0FBQ3hELE1BQU0sR0FBRyxDQUFDLEVBQUU7UUFDL0U7TUFDSjtNQUVBLElBQU00RCxPQUFPLEdBQUduRSxDQUFDLENBQUMsS0FBSyxFQUFFO1FBQ3JCZ0IsS0FBSyxFQUFFLDJCQUEyQjtRQUNsQ29ELElBQUksS0FBQW5DLE1BQUEsQ0FBS0UsYUFBYSxVQUFBRixNQUFBLENBQU9vQyxrQkFBa0IsQ0FBQ2pFLE1BQU0sQ0FBQ2tDLFlBQVksQ0FBQ3FCLEdBQUcsQ0FBQ0osTUFBTSxDQUFDLENBQUMsQ0FBQyxDQUFFO1FBQ25GZSxNQUFNLEVBQUUsUUFBUTtRQUNoQkMsR0FBRyxFQUFFLFVBQVU7UUFDZixZQUFZLEVBQUUvRCxNQUFNLENBQUNDLE1BQU07UUFDM0JDLEtBQUssRUFBRUYsTUFBTSxDQUFDQyxNQUFNO1FBQ3BCLGNBQWMsRUFBRUQsTUFBTSxDQUFDQztNQUMzQixDQUFDLENBQUMsQ0FBQytELElBQUksQ0FBQyxjQUFjLEVBQUViLEdBQUcsQ0FBQ0osTUFBTSxDQUFDO01BQ25DWSxPQUFPLENBQUMsQ0FBQyxDQUFDLENBQUNNLFNBQVMsR0FBRywwSEFBMEgsR0FDM0ksNkRBQTZEO01BQ25FLElBQUlkLEdBQUcsQ0FBQ0UsZUFBZSxFQUFFO1FBQ3JCSyxPQUFPLENBQUNRLE9BQU8sQ0FBQ1AsT0FBTyxDQUFDO01BQzVCLENBQUMsTUFBTTtRQUNIRCxPQUFPLENBQUNTLFFBQVEsQ0FBQyx1QkFBdUIsQ0FBQztRQUN6Q1IsT0FBTyxDQUFDUSxRQUFRLENBQUMsb0NBQW9DLENBQUM7UUFDdERULE9BQU8sQ0FBQ2pELE1BQU0sQ0FBQ2tELE9BQU8sQ0FBQztNQUMzQjtNQUNBLElBQUksT0FBT0EsT0FBTyxDQUFDUyxLQUFLLEtBQUssVUFBVSxFQUFFO1FBQ3JDVCxPQUFPLENBQUNTLEtBQUssQ0FBQyxDQUFDO01BQ25CO0lBQ0o7SUFFQSxTQUFTQyxhQUFhQSxDQUFDMUIsSUFBSSxFQUFFO01BQ3pCQSxJQUFJLENBQUNFLE9BQU8sQ0FBQyxVQUFVTSxHQUFHLEVBQUU7UUFDeEIsSUFBSXJCLFlBQVksQ0FBQ3FCLEdBQUcsQ0FBQ0osTUFBTSxDQUFDLEdBQUcsQ0FBQyxFQUFFO1VBQzlCVSxTQUFTLENBQUNOLEdBQUcsQ0FBQztRQUNsQixDQUFDLE1BQU0sSUFBSXBCLE1BQU0sQ0FBQ3VDLFNBQVMsQ0FBQ0MsY0FBYyxDQUFDQyxJQUFJLENBQUMxQyxZQUFZLEVBQUVxQixHQUFHLENBQUNKLE1BQU0sQ0FBQyxFQUFFO1VBQ3ZFSSxHQUFHLENBQUNMLElBQUksQ0FBQ1MsSUFBSSxDQUFDLDRCQUE0QixDQUFDLENBQUNDLE1BQU0sQ0FBQyxDQUFDO1FBQ3hEO01BQ0osQ0FBQyxDQUFDO0lBQ047SUFFQSxTQUFTaUIsV0FBV0EsQ0FBQ0MsS0FBSyxFQUFFaEMsU0FBUyxFQUFFO01BQ25DZ0MsS0FBSyxDQUFDN0IsT0FBTyxDQUFDLFVBQVVFLE1BQU0sRUFBRTtRQUM1QmQsT0FBTyxDQUFDYyxNQUFNLENBQUMsR0FBRyxJQUFJO01BQzFCLENBQUMsQ0FBQztNQUNGdkQsQ0FBQyxDQUFDbUYsSUFBSSxDQUFDO1FBQ0hDLEdBQUcsRUFBRXBELFNBQVM7UUFDZHFELE1BQU0sRUFBRSxNQUFNO1FBQ2RDLFdBQVcsRUFBRSxpQ0FBaUM7UUFDOUNDLFFBQVEsRUFBRSxNQUFNO1FBQ2hCMUQsSUFBSSxFQUFFMkQsSUFBSSxDQUFDQyxTQUFTLENBQUM7VUFBRUMsUUFBUSxFQUFFUjtRQUFNLENBQUMsQ0FBQztRQUN6Q1MsVUFBVSxFQUFFL0M7TUFDaEIsQ0FBQyxDQUFDLENBQUNnRCxJQUFJLENBQUMsVUFBVUMsUUFBUSxFQUFFO1FBQ3hCLElBQU1DLGFBQWEsR0FBR0QsUUFBUSxJQUFJQSxRQUFRLENBQUNDLGFBQWEsSUFBSUMsT0FBQSxDQUFPRixRQUFRLENBQUNDLGFBQWEsTUFBSyxRQUFRLEdBQ2hHRCxRQUFRLENBQUNDLGFBQWEsR0FDdEIsQ0FBQyxDQUFDO1FBQ1JaLEtBQUssQ0FBQzdCLE9BQU8sQ0FBQyxVQUFVRSxNQUFNLEVBQUU7VUFDNUIsSUFDSWhCLE1BQU0sQ0FBQ3VDLFNBQVMsQ0FBQ0MsY0FBYyxDQUFDQyxJQUFJLENBQUNjLGFBQWEsRUFBRXZDLE1BQU0sQ0FBQyxJQUN4RHlDLE1BQU0sQ0FBQ0YsYUFBYSxDQUFDdkMsTUFBTSxDQUFDLENBQUMsR0FBRyxDQUFDLEVBQ3RDO1lBQ0VqQixZQUFZLENBQUNpQixNQUFNLENBQUMsR0FBR3lDLE1BQU0sQ0FBQ0YsYUFBYSxDQUFDdkMsTUFBTSxDQUFDLENBQUM7VUFDeEQsQ0FBQyxNQUFNO1lBQ0gsT0FBT2pCLFlBQVksQ0FBQ2lCLE1BQU0sQ0FBQztVQUMvQjtRQUNKLENBQUMsQ0FBQztNQUNOLENBQUMsQ0FBQyxDQUFDMEMsSUFBSSxDQUFDLFVBQVVwRCxHQUFHLEVBQUU7UUFDbkIsSUFBSUEsR0FBRyxDQUFDcUQsTUFBTSxLQUFLLEdBQUcsSUFBSXJELEdBQUcsQ0FBQ3FELE1BQU0sS0FBSyxHQUFHLEVBQUU7VUFDMUN4RCxZQUFZLEdBQUcsSUFBSTtVQUNuQm9CLGFBQWEsQ0FBQyxDQUFDO1FBQ25CO01BQ0osQ0FBQyxDQUFDLENBQUNxQyxNQUFNLENBQUMsWUFBWTtRQUNsQmpCLEtBQUssQ0FBQzdCLE9BQU8sQ0FBQyxVQUFVRSxNQUFNLEVBQUU7VUFDNUIsT0FBT2QsT0FBTyxDQUFDYyxNQUFNLENBQUM7UUFDMUIsQ0FBQyxDQUFDO1FBQ0YsSUFBSSxDQUFDYixZQUFZLEVBQUU7VUFDZm1DLGFBQWEsQ0FBQzVCLFdBQVcsQ0FBQ0MsU0FBUyxDQUFDLENBQUM7UUFDekM7TUFDSixDQUFDLENBQUM7SUFDTjtJQUVBLFNBQVNrRCxjQUFjQSxDQUFDbEQsU0FBUyxFQUFFO01BQy9CLElBQUlSLFlBQVksRUFBRTtRQUNkO01BQ0o7TUFDQSxJQUFNUyxJQUFJLEdBQUdGLFdBQVcsQ0FBQ0MsU0FBUyxDQUFDO01BQ25DMkIsYUFBYSxDQUFDMUIsSUFBSSxDQUFDO01BQ25CLElBQU1rRCxNQUFNLEdBQUcsRUFBRTtNQUNqQmxELElBQUksQ0FBQ0UsT0FBTyxDQUFDLFVBQVVNLEdBQUcsRUFBRTtRQUN4QixJQUNJLENBQUNwQixNQUFNLENBQUN1QyxTQUFTLENBQUNDLGNBQWMsQ0FBQ0MsSUFBSSxDQUFDMUMsWUFBWSxFQUFFcUIsR0FBRyxDQUFDSixNQUFNLENBQUMsSUFDNUQsQ0FBQ2QsT0FBTyxDQUFDa0IsR0FBRyxDQUFDSixNQUFNLENBQUMsSUFDcEI4QyxNQUFNLENBQUNDLE9BQU8sQ0FBQzNDLEdBQUcsQ0FBQ0osTUFBTSxDQUFDLEtBQUssQ0FBQyxDQUFDLEVBQ3RDO1VBQ0U4QyxNQUFNLENBQUMzQyxJQUFJLENBQUNDLEdBQUcsQ0FBQ0osTUFBTSxDQUFDO1FBQzNCO01BQ0osQ0FBQyxDQUFDO01BQ0YsS0FBSyxJQUFJZ0QsTUFBTSxHQUFHLENBQUMsRUFBRUEsTUFBTSxHQUFHRixNQUFNLENBQUM5RixNQUFNLEVBQUVnRyxNQUFNLElBQUl4RyxnQkFBZ0IsRUFBRTtRQUNyRWtGLFdBQVcsQ0FBQ29CLE1BQU0sQ0FBQ0csS0FBSyxDQUFDRCxNQUFNLEVBQUVBLE1BQU0sR0FBR3hHLGdCQUFnQixDQUFDLEVBQUVtRCxTQUFTLENBQUM7TUFDM0U7SUFDSjtJQUVBLFNBQVN1RCxpQkFBaUJBLENBQUEsRUFBRztNQUN6QixJQUFJQyxNQUFNLENBQUNDLGlCQUFpQixJQUFJLE9BQU9ELE1BQU0sQ0FBQ0MsaUJBQWlCLENBQUNDLE9BQU8sS0FBSyxVQUFVLEVBQUU7UUFDcEZGLE1BQU0sQ0FBQ0MsaUJBQWlCLENBQUNDLE9BQU8sQ0FBQ3RHLE1BQU0sQ0FBQ3lELElBQUksQ0FBQyxrQ0FBa0MsQ0FBQyxDQUFDO01BQ3JGO01BQ0EsSUFBTThDLEtBQUssR0FBR3ZHLE1BQU0sQ0FBQ3lELElBQUksQ0FBQyx1QkFBdUIsQ0FBQyxDQUFDK0MsR0FBRyxDQUFDLENBQUMsQ0FBQztNQUN6RCxJQUFJLENBQUNELEtBQUssRUFBRTtRQUNSO01BQ0o7TUFDQUEsS0FBSyxDQUFDRSxLQUFLLENBQUMsQ0FBQztNQUNiRixLQUFLLENBQUNHLFdBQVcsR0FBRyxDQUFDO01BQ3JCSCxLQUFLLENBQUNJLGVBQWUsQ0FBQyxLQUFLLENBQUM7TUFDNUJKLEtBQUssQ0FBQ0ssSUFBSSxDQUFDLENBQUM7SUFDaEI7SUFFQSxTQUFTQyxpQkFBaUJBLENBQUEsRUFBRztNQUN6QlYsaUJBQWlCLENBQUMsQ0FBQztNQUNuQm5HLE1BQU0sQ0FBQ3lELElBQUksQ0FBQyxrQ0FBa0MsQ0FBQyxDQUFDUyxJQUFJLENBQUMsUUFBUSxFQUFFLElBQUksQ0FBQyxDQUFDMUQsS0FBSyxDQUFDLENBQUM7SUFDaEY7SUFFQSxTQUFTc0csVUFBVUEsQ0FBQ0MsT0FBTyxFQUFFO01BQ3pCRixpQkFBaUIsQ0FBQyxDQUFDO01BQ25CN0csTUFBTSxDQUFDeUQsSUFBSSxDQUFDLDZCQUE2QixDQUFDLENBQUN1RCxVQUFVLENBQUMsUUFBUSxDQUFDO01BQy9EaEgsTUFBTSxDQUFDeUQsSUFBSSxDQUFDLDJCQUEyQixDQUFDLENBQUNTLElBQUksQ0FBQyxRQUFRLEVBQUUsSUFBSSxDQUFDLENBQUMrQyxXQUFXLENBQUMsT0FBTyxDQUFDLENBQUNqRyxJQUFJLENBQUMsRUFBRSxDQUFDO01BQzNGLElBQU1rRyxLQUFLLEdBQUdsSCxNQUFNLENBQUN5RCxJQUFJLENBQUMsK0JBQStCLENBQUMsQ0FBQ3VELFVBQVUsQ0FBQyxRQUFRLENBQUM7TUFDL0VFLEtBQUssQ0FBQ3pELElBQUksQ0FBQyxxQkFBcUIsQ0FBQyxDQUFDekMsSUFBSSxDQUFDbEIsTUFBTSxDQUFDaUgsT0FBTyxDQUFDLENBQUMsQ0FBQyxJQUFJLEVBQUUsQ0FBQyxDQUFDO01BQ2hFRyxLQUFLLENBQUN6RCxJQUFJLENBQUMsdUJBQXVCLENBQUMsQ0FBQ3pDLElBQUksQ0FBQ2xCLE1BQU0sQ0FBQ2lILE9BQU8sQ0FBQyxDQUFDLENBQUMsSUFBSSxFQUFFLENBQUMsQ0FBQztNQUNsRUcsS0FBSyxDQUFDekQsSUFBSSxDQUFDLDRCQUE0QixDQUFDLENBQUN6QyxJQUFJLENBQUNsQixNQUFNLENBQUNpSCxPQUFPLENBQUMsQ0FBQyxDQUFDLElBQUksRUFBRSxDQUFDLENBQUM7TUFDdkVHLEtBQUssQ0FBQ3pELElBQUksQ0FBQyx5QkFBeUIsQ0FBQyxDQUFDekMsSUFBSSxDQUFDbEIsTUFBTSxDQUFDaUgsT0FBTyxDQUFDLENBQUMsQ0FBQyxJQUFJLEVBQUUsQ0FBQyxDQUFDO0lBQ3hFOztJQUVBO0lBQ0E7SUFDQTtJQUNBO0lBQ0EsU0FBU0ksZ0JBQWdCQSxDQUFDQyxPQUFPLEVBQUU7TUFDL0IsSUFBSSxDQUFDQSxPQUFPLElBQUkzQixPQUFBLENBQU8yQixPQUFPLE1BQUssUUFBUSxFQUFFO1FBQ3pDO01BQ0o7TUFDQSxJQUFNRixLQUFLLEdBQUdsSCxNQUFNLENBQUN5RCxJQUFJLENBQUMsK0JBQStCLENBQUM7TUFDMUQsQ0FBQyxRQUFRLEVBQUUsYUFBYSxDQUFDLENBQUNWLE9BQU8sQ0FBQyxVQUFVc0UsS0FBSyxFQUFFO1FBQy9DLElBQU1DLEtBQUssR0FBR3hILE1BQU0sQ0FBQ3NILE9BQU8sQ0FBQ0MsS0FBSyxDQUFDLElBQUksRUFBRSxDQUFDO1FBQzFDLElBQUlDLEtBQUssS0FBSyxFQUFFLEVBQUU7VUFDZEosS0FBSyxDQUFDekQsSUFBSSxDQUFDLGVBQWUsR0FBRzRELEtBQUssR0FBRyxJQUFJLENBQUMsQ0FBQ3JHLElBQUksQ0FBQ3NHLEtBQUssQ0FBQztRQUMxRDtNQUNKLENBQUMsQ0FBQztJQUNOO0lBRUEsU0FBU0MsU0FBU0EsQ0FBQ0MsT0FBTyxFQUFFO01BQ3hCeEgsTUFBTSxDQUFDeUQsSUFBSSxDQUFDLDZCQUE2QixDQUFDLENBQUNTLElBQUksQ0FBQyxRQUFRLEVBQUUsSUFBSSxDQUFDO01BQy9EMkMsaUJBQWlCLENBQUMsQ0FBQztNQUNuQjdHLE1BQU0sQ0FBQ3lELElBQUksQ0FBQywyQkFBMkIsQ0FBQyxDQUFDdUQsVUFBVSxDQUFDLFFBQVEsQ0FBQyxDQUFDM0MsUUFBUSxDQUFDLE9BQU8sQ0FBQyxDQUFDckQsSUFBSSxDQUFDd0csT0FBTyxDQUFDO0lBQ2pHO0lBRUEsU0FBU0MsWUFBWUEsQ0FBQ0MsUUFBUSxFQUFFO01BQzVCLElBQUksT0FBT0MsV0FBVyxLQUFLLFdBQVcsSUFBSUQsUUFBUSxJQUFJLE9BQU9DLFdBQVcsQ0FBQ0MsZUFBZSxLQUFLLFVBQVUsRUFBRTtRQUNyR0QsV0FBVyxDQUFDQyxlQUFlLENBQUNGLFFBQVEsQ0FBQztNQUN6QztJQUNKOztJQUVBO0lBQ0E7SUFDQTtJQUNBLFNBQVNHLGlCQUFpQkEsQ0FBQSxFQUFHO01BQ3pCLElBQUlDLFFBQVEsR0FBR3BJLENBQUMsQ0FBQyxrQ0FBa0MsQ0FBQztNQUNwRCxJQUFJb0ksUUFBUSxDQUFDN0gsTUFBTSxHQUFHLENBQUMsRUFBRTtRQUNyQixPQUFPNkgsUUFBUTtNQUNuQjtNQUNBLElBQU1DLEtBQUssR0FBR3JJLENBQUMsQ0FBQyxNQUFNLEVBQUU7UUFBRWdCLEtBQUssRUFBRTtNQUFVLENBQUMsQ0FBQztNQUM3QyxDQUNJLHlEQUF5RCxFQUN6RCxzREFBc0QsRUFDdEQsMkRBQTJELENBQzlELENBQUNxQyxPQUFPLENBQUMsVUFBVW5ELEdBQUcsRUFBRTtRQUNyQm1JLEtBQUssQ0FBQ3BILE1BQU0sQ0FBQ2pCLENBQUMsQ0FBQyxNQUFNLENBQUMsQ0FBQ3NCLElBQUksQ0FBQ3JCLFNBQVMsQ0FBQ0MsR0FBRyxDQUFDLENBQUMsQ0FBQztNQUNoRCxDQUFDLENBQUM7TUFDRmtJLFFBQVEsR0FBR3BJLENBQUMsQ0FBQyxPQUFPLEVBQUU7UUFDbEJnQixLQUFLLEVBQUUsZUFBZTtRQUN0QlMsRUFBRSxFQUFFLGlDQUFpQztRQUNyQ0osSUFBSSxFQUFFLFFBQVE7UUFDZCxZQUFZLEVBQUUsTUFBTTtRQUNwQixpQkFBaUIsRUFBRTtNQUN2QixDQUFDLENBQUMsQ0FBQ0osTUFBTSxDQUNMakIsQ0FBQyxDQUFDLE9BQU8sRUFBRTtRQUFFZ0IsS0FBSyxFQUFFLFFBQVE7UUFBRVMsRUFBRSxFQUFFO01BQWtDLENBQUMsQ0FBQyxDQUNqRUgsSUFBSSxDQUFDckIsU0FBUyxDQUFDLDhDQUE4QyxDQUFDLENBQUMsRUFDcEVELENBQUMsQ0FBQyxPQUFPLEVBQUU7UUFBRWdCLEtBQUssRUFBRTtNQUFVLENBQUMsQ0FBQyxDQUFDQyxNQUFNLENBQ25Db0gsS0FBSyxFQUNMckksQ0FBQyxDQUFDLE9BQU8sRUFBRTtRQUFFZ0IsS0FBSyxFQUFFO01BQWMsQ0FBQyxDQUFDLENBQUNDLE1BQU0sQ0FDdkNqQixDQUFDLENBQUMsU0FBUyxFQUFFO1FBQUVzSSxJQUFJLEVBQUUsVUFBVTtRQUFFN0csRUFBRSxFQUFFO01BQXdDLENBQUMsQ0FBQyxFQUMvRXpCLENBQUMsQ0FBQyxTQUFTLEVBQUU7UUFBRXVJLEdBQUcsRUFBRTtNQUF3QyxDQUFDLENBQUMsQ0FDekRqSCxJQUFJLENBQUNyQixTQUFTLENBQUMscURBQXFELENBQUMsQ0FDOUUsQ0FDSixDQUFDLEVBQ0RELENBQUMsQ0FBQyxPQUFPLEVBQUU7UUFBRWdCLEtBQUssRUFBRTtNQUFVLENBQUMsQ0FBQyxDQUFDQyxNQUFNLENBQ25DakIsQ0FBQyxDQUFDLFVBQVUsRUFBRTtRQUFFc0ksSUFBSSxFQUFFLFFBQVE7UUFBRXRILEtBQUssRUFBRTtNQUF5QixDQUFDLENBQUMsQ0FDN0RNLElBQUksQ0FBQ3JCLFNBQVMsQ0FBQywyQ0FBMkMsQ0FBQyxDQUFDLEVBQ2pFRCxDQUFDLENBQUMsVUFBVSxFQUFFO1FBQUVzSSxJQUFJLEVBQUUsUUFBUTtRQUFFdEgsS0FBSyxFQUFFLGdDQUFnQztRQUFFd0gsUUFBUSxFQUFFO01BQUssQ0FBQyxDQUFDLENBQ3JGbEgsSUFBSSxDQUFDckIsU0FBUyxDQUFDLDJDQUEyQyxDQUFDLENBQ3BFLENBQ0osQ0FBQztNQUNERCxDQUFDLENBQUMsTUFBTSxDQUFDLENBQUNpQixNQUFNLENBQUNtSCxRQUFRLENBQUM7TUFDMUJBLFFBQVEsQ0FBQ3JFLElBQUksQ0FBQyx3Q0FBd0MsQ0FBQyxDQUFDMEUsRUFBRSxDQUFDLFFBQVEsRUFBRSxZQUFZO1FBQzdFLElBQU1DLE9BQU8sR0FBRzFJLENBQUMsQ0FBQyxJQUFJLENBQUMsQ0FBQzJJLElBQUksQ0FBQyxTQUFTLENBQUM7UUFDdkNQLFFBQVEsQ0FBQ3JFLElBQUksQ0FBQyxpQkFBaUIsQ0FBQyxDQUFDNkUsV0FBVyxDQUFDLFVBQVUsRUFBRSxDQUFDRixPQUFPLENBQUMsQ0FBQ0MsSUFBSSxDQUFDLFVBQVUsRUFBRSxDQUFDRCxPQUFPLENBQUM7TUFDakcsQ0FBQyxDQUFDO01BQ0YsT0FBT04sUUFBUTtJQUNuQjtJQUVBLFNBQVNTLGdCQUFnQkEsQ0FBQ0MsUUFBUSxFQUFFdkYsTUFBTSxFQUFFO01BQ3hDLElBQU02RSxRQUFRLEdBQUdELGlCQUFpQixDQUFDLENBQUM7TUFDcEMsSUFBTVksUUFBUSxHQUFHWCxRQUFRLENBQUNyRSxJQUFJLENBQUMsaUJBQWlCLENBQUM7TUFDakRxRSxRQUFRLENBQUNyRSxJQUFJLENBQUMsd0NBQXdDLENBQUMsQ0FBQzRFLElBQUksQ0FBQyxTQUFTLEVBQUUsS0FBSyxDQUFDO01BQzlFSSxRQUFRLENBQUNwRSxRQUFRLENBQUMsVUFBVSxDQUFDLENBQUNnRSxJQUFJLENBQUMsVUFBVSxFQUFFLElBQUksQ0FBQyxDQUFDcEIsV0FBVyxDQUFDLFNBQVMsQ0FBQztNQUMzRWEsUUFBUSxDQUFDWSxLQUFLLENBQUM7UUFDWEMsYUFBYSxFQUFFLElBQUk7UUFDbkJDLFFBQVEsRUFBRSxJQUFJO1FBQ2RDLFNBQVMsRUFBRSxTQUFBQSxDQUFBLEVBQVk7VUFDbkIsSUFBSSxDQUFDZixRQUFRLENBQUNyRSxJQUFJLENBQUMsd0NBQXdDLENBQUMsQ0FBQzRFLElBQUksQ0FBQyxTQUFTLENBQUMsRUFBRTtZQUMxRSxPQUFPLEtBQUs7VUFDaEI7VUFDQUksUUFBUSxDQUFDcEUsUUFBUSxDQUFDLGtCQUFrQixDQUFDLENBQUNnRSxJQUFJLENBQUMsVUFBVSxFQUFFLElBQUksQ0FBQztVQUM1RDNJLENBQUMsQ0FBQ21GLElBQUksQ0FBQztZQUNIQyxHQUFHLEVBQUUvQyxTQUFTO1lBQ2RnRCxNQUFNLEVBQUUsTUFBTTtZQUNkRSxRQUFRLEVBQUUsTUFBTTtZQUNoQjFELElBQUksRUFBRTtjQUFFdUgsU0FBUyxFQUFFTixRQUFRO2NBQUVPLGNBQWMsRUFBRTtZQUFJLENBQUM7WUFDbEQxRCxVQUFVLEVBQUUvQztVQUNoQixDQUFDLENBQUMsQ0FBQ2dELElBQUksQ0FBQyxVQUFVQyxRQUFRLEVBQUU7WUFDeEJrQyxZQUFZLENBQUNsQyxRQUFRLElBQUlBLFFBQVEsQ0FBQ2lDLE9BQU8sQ0FBQztZQUMxQyxJQUFJakMsUUFBUSxJQUFJQSxRQUFRLENBQUN5RCxPQUFPLEtBQUssSUFBSSxFQUFFO2NBQ3ZDLE9BQU9oSCxZQUFZLENBQUNpQixNQUFNLENBQUM7Y0FDM0I3QixNQUFNLENBQUNxQyxJQUFJLENBQUMsNEJBQTRCLENBQUMsQ0FBQ3dGLE1BQU0sQ0FBQyxZQUFZO2dCQUN6RCxPQUFPdkosQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDd0UsSUFBSSxDQUFDLGNBQWMsQ0FBQyxLQUFLakIsTUFBTTtjQUNsRCxDQUFDLENBQUMsQ0FBQ1MsTUFBTSxDQUFDLENBQUM7Y0FDWG9FLFFBQVEsQ0FBQ1ksS0FBSyxDQUFDLE1BQU0sQ0FBQztjQUN0QjFJLE1BQU0sQ0FBQzBJLEtBQUssQ0FBQyxNQUFNLENBQUM7Y0FDcEI7WUFDSjtZQUNBWixRQUFRLENBQUNZLEtBQUssQ0FBQyxNQUFNLENBQUM7VUFDMUIsQ0FBQyxDQUFDLENBQUMvQyxJQUFJLENBQUMsWUFBWTtZQUNoQm1DLFFBQVEsQ0FBQ1ksS0FBSyxDQUFDLE1BQU0sQ0FBQztZQUN0QmpCLFlBQVksQ0FBQztjQUFFeUIsS0FBSyxFQUFFLENBQUN2SixTQUFTLENBQUMsb0RBQW9ELENBQUM7WUFBRSxDQUFDLENBQUM7VUFDOUYsQ0FBQyxDQUFDLENBQUNrRyxNQUFNLENBQUMsWUFBWTtZQUNsQjRDLFFBQVEsQ0FBQ3hCLFdBQVcsQ0FBQyxTQUFTLENBQUM7VUFDbkMsQ0FBQyxDQUFDO1VBQ0YsT0FBTyxLQUFLO1FBQ2hCO01BQ0osQ0FBQyxDQUFDLENBQUN5QixLQUFLLENBQUMsTUFBTSxDQUFDO0lBQ3BCO0lBRUEsU0FBU1MsaUJBQWlCQSxDQUFDWCxRQUFRLEVBQUV2RixNQUFNLEVBQUVtRyxTQUFTLEVBQUU7TUFDcEQsSUFBTUMsS0FBSyxHQUFHM0osQ0FBQyxDQUFDLE9BQU8sRUFBRTtRQUFFZ0IsS0FBSyxFQUFFO01BQW1CLENBQUMsQ0FBQztNQUN2RCxDQUFDLEtBQUssRUFBRSxNQUFNLENBQUMsQ0FBQ3FDLE9BQU8sQ0FBQyxVQUFVdUcsTUFBTSxFQUFFO1FBQ3RDRCxLQUFLLENBQUMxSSxNQUFNLENBQUNqQixDQUFDLENBQUMsS0FBSyxFQUFFO1VBQ2xCZ0IsS0FBSyxFQUFFLE1BQU07VUFDYm9ELElBQUksS0FBQW5DLE1BQUEsQ0FBS0csU0FBUyxVQUFBSCxNQUFBLENBQU9vQyxrQkFBa0IsQ0FBQ2pFLE1BQU0sQ0FBQzBJLFFBQVEsQ0FBQyxDQUFDLGNBQUE3RyxNQUFBLENBQVcySCxNQUFNO1FBQ2xGLENBQUMsQ0FBQyxDQUFDdEksSUFBSSxDQUFDckIsU0FBUyxDQUFDMkosTUFBTSxLQUFLLEtBQUssR0FDNUIsbURBQW1ELEdBQ25ELG9EQUFvRCxDQUFDLENBQUMsQ0FBQztNQUNqRSxDQUFDLENBQUM7TUFDRixJQUFJRixTQUFTLEVBQUU7UUFDWEMsS0FBSyxDQUFDMUksTUFBTSxDQUNSakIsQ0FBQyxDQUFDLE9BQU8sRUFBRTtVQUFFZ0IsS0FBSyxFQUFFO1FBQVUsQ0FBQyxDQUFDLEVBQ2hDaEIsQ0FBQyxDQUFDLE9BQU8sRUFBRTtVQUFFZ0IsS0FBSyxFQUFFO1FBQWtDLENBQUMsQ0FBQyxDQUFDQyxNQUFNLENBQzNEakIsQ0FBQyxDQUFDLFVBQVUsRUFBRTtVQUFFc0ksSUFBSSxFQUFFO1FBQVMsQ0FBQyxDQUFDLENBQzVCaEgsSUFBSSxDQUFDckIsU0FBUyxDQUFDLDhDQUE4QyxDQUFDLENBQUMsQ0FDL0R3SSxFQUFFLENBQUMsT0FBTyxFQUFFLFlBQVk7VUFDckJJLGdCQUFnQixDQUFDQyxRQUFRLEVBQUV2RixNQUFNLENBQUM7UUFDdEMsQ0FBQyxDQUNULENBQ0osQ0FBQztNQUNMO01BQ0EsSUFBTXFFLEtBQUssR0FBRzNILFNBQVMsQ0FBQywrQ0FBK0MsQ0FBQztNQUN4RSxJQUFNNEosUUFBUSxHQUFHN0osQ0FBQyxDQUFDLFdBQVcsRUFBRTtRQUFFZ0IsS0FBSyxFQUFFO01BQXlCLENBQUMsQ0FBQyxDQUFDQyxNQUFNLENBQ3ZFakIsQ0FBQyxDQUFDLFdBQVcsRUFBRTtRQUFFLFlBQVksRUFBRTRILEtBQUs7UUFBRWxILEtBQUssRUFBRWtIO01BQU0sQ0FBQyxDQUFDLENBQUMzRyxNQUFNLENBQ3hEakIsQ0FBQyxDQUFDLEtBQUssRUFBRTtRQUFFZ0IsS0FBSyxFQUFFO01BQTJCLENBQUMsQ0FDbEQsQ0FBQyxFQUNEMkksS0FDSixDQUFDO01BQ0RBLEtBQUssQ0FBQ2xCLEVBQUUsQ0FBQyxPQUFPLEVBQUUsV0FBVyxFQUFFLFlBQVk7UUFDdkNvQixRQUFRLENBQUN2QyxVQUFVLENBQUMsTUFBTSxDQUFDO01BQy9CLENBQUMsQ0FBQztNQUNGLE9BQU91QyxRQUFRO0lBQ25CO0lBRUEsU0FBU0Msa0JBQWtCQSxDQUFDQyxVQUFVLEVBQUUxQyxPQUFPLEVBQUU5RCxNQUFNLEVBQUVtRyxTQUFTLEVBQUU7TUFDaEUsSUFBTU0sWUFBWSxHQUFHRCxVQUFVLElBQUloRSxPQUFBLENBQU9nRSxVQUFVLENBQUNDLFlBQVksTUFBSyxRQUFRLEdBQ3hFRCxVQUFVLENBQUNDLFlBQVksR0FDdkIsSUFBSTtNQUNWLElBQU1DLFFBQVEsR0FBR0QsWUFBWSxJQUFJRSxLQUFLLENBQUNDLE9BQU8sQ0FBQ0gsWUFBWSxDQUFDQyxRQUFRLENBQUMsR0FBR0QsWUFBWSxDQUFDQyxRQUFRLEdBQUcsRUFBRTtNQUNsRyxJQUFNRyxVQUFVLEdBQUcvQyxPQUFPLElBQUk2QyxLQUFLLENBQUNDLE9BQU8sQ0FBQzlDLE9BQU8sQ0FBQyxDQUFDLENBQUMsQ0FBQyxHQUFHQSxPQUFPLENBQUMsQ0FBQyxDQUFDLEdBQUcsRUFBRTtNQUN6RSxJQUFNZ0QsYUFBYSxHQUFHTixVQUFVLElBQUlBLFVBQVUsQ0FBQ00sYUFBYSxLQUFLLElBQUksSUFDOUQsT0FBT04sVUFBVSxDQUFDTSxhQUFhLEtBQUssV0FBVyxHQUNoRGpLLE1BQU0sQ0FBQzJKLFVBQVUsQ0FBQ00sYUFBYSxDQUFDLEdBQ2hDLEVBQUU7TUFDUixJQUFJQyxTQUFTLEdBQUcsQ0FBQyxDQUFDO01BQ2xCRixVQUFVLENBQUNHLElBQUksQ0FBQyxVQUFVQyxJQUFJLEVBQUU7UUFDNUIsSUFBSUgsYUFBYSxLQUFLLEVBQUUsSUFBSUcsSUFBSSxJQUFJcEssTUFBTSxDQUFDb0ssSUFBSSxDQUFDL0ksRUFBRSxDQUFDLEtBQUs0SSxhQUFhLEVBQUU7VUFDbkVDLFNBQVMsR0FBR0UsSUFBSTtVQUNoQixPQUFPLElBQUk7UUFDZjtRQUNBLE9BQU8sS0FBSztNQUNoQixDQUFDLENBQUM7TUFDRnJELGlCQUFpQixDQUFDLENBQUM7TUFDbkIsSUFBTWpELE9BQU8sR0FBRzVELE1BQU0sQ0FBQ3lELElBQUksQ0FBQyxrQ0FBa0MsQ0FBQyxDQUFDdUQsVUFBVSxDQUFDLFFBQVEsQ0FBQztNQUNwRmhILE1BQU0sQ0FBQ3lELElBQUksQ0FBQyw2QkFBNkIsQ0FBQyxDQUFDUyxJQUFJLENBQUMsUUFBUSxFQUFFLElBQUksQ0FBQztNQUMvRGxFLE1BQU0sQ0FBQ3lELElBQUksQ0FBQywyQkFBMkIsQ0FBQyxDQUFDUyxJQUFJLENBQUMsUUFBUSxFQUFFLElBQUksQ0FBQyxDQUFDbEQsSUFBSSxDQUFDLEVBQUUsQ0FBQztNQUN0RSxJQUFJMkksUUFBUSxDQUFDMUosTUFBTSxLQUFLLENBQUMsRUFBRTtRQUN2QjJELE9BQU8sQ0FBQ2pELE1BQU0sQ0FBQ2pCLENBQUMsQ0FBQyxPQUFPLEVBQUU7VUFBRWdCLEtBQUssRUFBRTtRQUFhLENBQUMsQ0FBQyxDQUFDTSxJQUFJLENBQUNkLE1BQU0sQ0FBQ00sS0FBSyxDQUFDLENBQUM7UUFDdEU7TUFDSjtNQUNBLElBQUksQ0FBQzRGLE1BQU0sQ0FBQ0MsaUJBQWlCLElBQUksT0FBT0QsTUFBTSxDQUFDQyxpQkFBaUIsQ0FBQ25FLE1BQU0sS0FBSyxVQUFVLEVBQUU7UUFDcEZxRixTQUFTLENBQUNySCxNQUFNLENBQUNJLFNBQVMsQ0FBQztRQUMzQjtNQUNKO01BQ0FzRCxPQUFPLENBQUNqRCxNQUFNLENBQUN5RixNQUFNLENBQUNDLGlCQUFpQixDQUFDbkUsTUFBTSxDQUFDd0gsWUFBWSxFQUFFO1FBQ3pEUyxHQUFHLEVBQUV4SyxTQUFTLENBQUMsMkNBQTJDLENBQUM7UUFDM0R5SyxNQUFNLEVBQUV6SyxTQUFTLENBQUMsd0NBQXdDLENBQUM7UUFDM0RhLEtBQUssRUFBRU4sTUFBTSxDQUFDTSxLQUFLO1FBQ25CNkosSUFBSSxFQUFFMUssU0FBUyxDQUFDLDJDQUEyQyxDQUFDO1FBQzVEMkssS0FBSyxFQUFFM0ssU0FBUyxDQUFDLDJDQUEyQyxDQUFDO1FBQzdEVSxPQUFPLEVBQUVWLFNBQVMsQ0FBQyw4Q0FBOEMsQ0FBQztRQUNsRTRLLFdBQVcsRUFBRTVLLFNBQVMsQ0FBQyxrREFBa0QsQ0FBQztRQUMxRTZLLE1BQU0sRUFBRTdLLFNBQVMsQ0FBQyw0Q0FBNEMsQ0FBQztRQUMvRDhLLFFBQVEsRUFBRTlLLFNBQVMsQ0FBQywrQ0FBK0MsQ0FBQztRQUNwRStLLFFBQVEsRUFBRTVLLE1BQU0sQ0FBQ2tLLFNBQVMsQ0FBQ1csWUFBWSxJQUFJLEVBQUUsQ0FBQztRQUM5Q0MsV0FBVyxFQUFFOUssTUFBTSxDQUFDa0ssU0FBUyxDQUFDYSxZQUFZLElBQUksRUFBRSxDQUFDO1FBQ2pEQyxlQUFlLEVBQUVwQixZQUFZLENBQUNvQixlQUFlO1FBQzdDQyxPQUFPLEVBQUV0QixVQUFVLENBQUNqQixRQUFRLEdBQUdXLGlCQUFpQixDQUFDTSxVQUFVLENBQUNqQixRQUFRLEVBQUV2RixNQUFNLEVBQUVtRyxTQUFTLENBQUMsR0FBRztNQUMvRixDQUFDLENBQUMsQ0FBQztJQUNQO0lBRUEsU0FBUzRCLGNBQWNBLENBQUNwSSxTQUFTLEVBQUVpQixPQUFPLEVBQUU7TUFDeEMsSUFBTWtELE9BQU8sR0FBR25FLFNBQVMsQ0FBQ1MsR0FBRyxDQUFDUSxPQUFPLENBQUNvSCxPQUFPLENBQUMsSUFBSSxDQUFDLENBQUMsQ0FBQzFKLElBQUksQ0FBQyxDQUFDO01BQzNELElBQU0wQixNQUFNLEdBQUduRCxNQUFNLENBQUMrRCxPQUFPLENBQUNLLElBQUksQ0FBQyxjQUFjLENBQUMsSUFBSSxFQUFFLENBQUMsQ0FBQ2hCLElBQUksQ0FBQyxDQUFDO01BQ2hFLElBQUksQ0FBQzZELE9BQU8sSUFBSTlELE1BQU0sS0FBSyxFQUFFLEVBQUU7UUFDM0I7TUFDSjtNQUVBLElBQUlaLGFBQWEsS0FBSyxJQUFJLEVBQUU7UUFDeEJBLGFBQWEsQ0FBQzZJLEtBQUssQ0FBQyxDQUFDO01BQ3pCO01BQ0FwRSxVQUFVLENBQUNDLE9BQU8sQ0FBQztNQUNuQi9HLE1BQU0sQ0FBQzBJLEtBQUssQ0FBQyxNQUFNLENBQUM7TUFDcEJyRyxhQUFhLEdBQUczQyxDQUFDLENBQUNtRixJQUFJLENBQUM7UUFDbkJDLEdBQUcsRUFBRWxELFNBQVM7UUFDZG1ELE1BQU0sRUFBRSxLQUFLO1FBQ2JFLFFBQVEsRUFBRSxNQUFNO1FBQ2hCMUQsSUFBSSxFQUFFO1VBQUU0SixPQUFPLEVBQUVsSTtRQUFPLENBQUM7UUFDekJvQyxVQUFVLEVBQUUvQztNQUNoQixDQUFDLENBQUMsQ0FBQ2dELElBQUksQ0FBQyxVQUFVQyxRQUFRLEVBQUU7UUFDeEI0QixnQkFBZ0IsQ0FBQzVCLFFBQVEsSUFBSUEsUUFBUSxDQUFDa0UsVUFBVSxHQUFHbEUsUUFBUSxDQUFDa0UsVUFBVSxDQUFDckMsT0FBTyxHQUFHLElBQUksQ0FBQztRQUN0Rm9DLGtCQUFrQixDQUNkakUsUUFBUSxJQUFJQSxRQUFRLENBQUNrRSxVQUFVLEdBQUdsRSxRQUFRLENBQUNrRSxVQUFVLEdBQUcsSUFBSSxFQUM1RDFDLE9BQU8sRUFDUDlELE1BQU0sRUFDTm1JLE9BQU8sQ0FBQzdGLFFBQVEsSUFBSUEsUUFBUSxDQUFDOEYsbUJBQW1CLENBQ3BELENBQUM7TUFDTCxDQUFDLENBQUMsQ0FBQzFGLElBQUksQ0FBQyxVQUFVcEQsR0FBRyxFQUFFcUQsTUFBTSxFQUFFO1FBQzNCLElBQUlBLE1BQU0sS0FBSyxPQUFPLEVBQUU7VUFDcEI7UUFDSjtRQUNBLElBQUlyRCxHQUFHLENBQUNxRCxNQUFNLEtBQUssR0FBRyxFQUFFO1VBQ3BCLE9BQU81RCxZQUFZLENBQUNpQixNQUFNLENBQUM7VUFDM0JZLE9BQU8sQ0FBQ0gsTUFBTSxDQUFDLENBQUM7VUFDaEI2RCxTQUFTLENBQUNySCxNQUFNLENBQUNLLFFBQVEsQ0FBQztVQUMxQjtRQUNKO1FBQ0EsSUFBSWdDLEdBQUcsQ0FBQ3FELE1BQU0sS0FBSyxHQUFHLElBQUlyRCxHQUFHLENBQUNxRCxNQUFNLEtBQUssR0FBRyxFQUFFO1VBQzFDeEQsWUFBWSxHQUFHLElBQUk7VUFDbkJvQixhQUFhLENBQUMsQ0FBQztRQUNuQjtRQUNBK0QsU0FBUyxDQUFDckgsTUFBTSxDQUFDSSxTQUFTLENBQUM7TUFDL0IsQ0FBQyxDQUFDLENBQUN1RixNQUFNLENBQUMsWUFBWTtRQUNsQnhELGFBQWEsR0FBRyxJQUFJO01BQ3hCLENBQUMsQ0FBQztJQUNOO0lBRUEsU0FBU2lKLEtBQUtBLENBQUMxSSxTQUFTLEVBQUU7TUFDdEIsSUFBSXhCLE1BQU0sQ0FBQ0csSUFBSSxDQUFDLDRCQUE0QixDQUFDLEVBQUU7UUFDM0M7TUFDSjtNQUNBSCxNQUFNLENBQUNHLElBQUksQ0FBQyw0QkFBNEIsRUFBRSxJQUFJLENBQUM7TUFDL0N2QixNQUFNLENBQUMwSSxLQUFLLENBQUM7UUFDVEUsUUFBUSxFQUFFLElBQUk7UUFDZDJDLFFBQVEsRUFBRSxTQUFBQSxDQUFBLEVBQVk7VUFDbEIsSUFBSWxKLGFBQWEsS0FBSyxJQUFJLEVBQUU7WUFDeEJBLGFBQWEsQ0FBQzZJLEtBQUssQ0FBQyxDQUFDO1lBQ3JCN0ksYUFBYSxHQUFHLElBQUk7VUFDeEI7VUFDQXdFLGlCQUFpQixDQUFDLENBQUM7UUFDdkI7TUFDSixDQUFDLENBQUM7TUFDRm5ILENBQUMsQ0FBQ2tCLFFBQVEsQ0FBQyxDQUFDdUgsRUFBRSxDQUFDLCtCQUErQixFQUFFLFVBQVVxRCxLQUFLLEVBQUU7UUFDN0R4TCxNQUFNLENBQUN5RCxJQUFJLENBQUMsK0JBQStCLENBQUMsQ0FBQ2dJLElBQUksQ0FBQyxZQUFZO1VBQzFELElBQUksQ0FBQyxJQUFJLENBQUNDLFFBQVEsQ0FBQ0YsS0FBSyxDQUFDeEgsTUFBTSxDQUFDLEVBQUU7WUFDOUJ0RSxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUNzSCxVQUFVLENBQUMsTUFBTSxDQUFDO1VBQzlCO1FBQ0osQ0FBQyxDQUFDO01BQ04sQ0FBQyxDQUFDO01BQ0Y1RixNQUFNLENBQUMrRyxFQUFFLENBQUMsMEJBQTBCLEVBQUUsWUFBWTtRQUM5Q3JDLGNBQWMsQ0FBQ2xELFNBQVMsQ0FBQztNQUM3QixDQUFDLENBQUM7TUFDRnhCLE1BQU0sQ0FBQytHLEVBQUUsQ0FBQyx3QkFBd0IsRUFBRSw0QkFBNEIsRUFBRSxVQUFVcUQsS0FBSyxFQUFFO1FBQy9FQSxLQUFLLENBQUNHLGVBQWUsQ0FBQyxDQUFDO1FBQ3ZCLElBQU05SCxPQUFPLEdBQUduRSxDQUFDLENBQUMsSUFBSSxDQUFDO1FBQ3ZCLElBQUk4TCxLQUFLLENBQUNJLE9BQU8sSUFBSUosS0FBSyxDQUFDSyxPQUFPLEVBQUU7VUFDaENMLEtBQUssQ0FBQ00sY0FBYyxDQUFDLENBQUM7VUFDdEIsSUFBTUMsU0FBUyxHQUFHM0YsTUFBTSxDQUFDNEYsSUFBSSxDQUFDbkksT0FBTyxDQUFDSyxJQUFJLENBQUMsTUFBTSxDQUFDLEVBQUUsUUFBUSxDQUFDO1VBQzdELElBQUk2SCxTQUFTLEtBQUssSUFBSSxFQUFFO1lBQ3BCQSxTQUFTLENBQUNFLE1BQU0sR0FBRyxJQUFJO1lBQ3ZCRixTQUFTLENBQUNHLEtBQUssQ0FBQyxDQUFDO1VBQ3JCO1VBQ0E7UUFDSjtRQUNBVixLQUFLLENBQUNNLGNBQWMsQ0FBQyxDQUFDO1FBQ3RCZCxjQUFjLENBQUNwSSxTQUFTLEVBQUVpQixPQUFPLENBQUM7TUFDdEMsQ0FBQyxDQUFDO01BQ0ZzSSxVQUFVLENBQUMsWUFBWTtRQUNuQnJHLGNBQWMsQ0FBQ2xELFNBQVMsQ0FBQztNQUM3QixDQUFDLEVBQUUsQ0FBQyxDQUFDO0lBQ1Q7SUFFQSxJQUFJbEQsQ0FBQyxDQUFDMkIsRUFBRSxDQUFDQyxTQUFTLENBQUM4SyxXQUFXLENBQUNoTCxNQUFNLENBQUMsRUFBRTtNQUNwQ2tLLEtBQUssQ0FBQ2xLLE1BQU0sQ0FBQ0UsU0FBUyxDQUFDLENBQUMsQ0FBQztJQUM3QixDQUFDLE1BQU07TUFDSEYsTUFBTSxDQUFDaUwsR0FBRyxDQUFDLDBCQUEwQixFQUFFLFlBQVk7UUFDL0NmLEtBQUssQ0FBQ2xLLE1BQU0sQ0FBQ0UsU0FBUyxDQUFDLENBQUMsQ0FBQztNQUM3QixDQUFDLENBQUM7SUFDTjtFQUNKLENBQUMsQ0FBQztBQUNOLENBQUMsRUFBQyxDQUFDIiwiaWdub3JlTGlzdCI6W119