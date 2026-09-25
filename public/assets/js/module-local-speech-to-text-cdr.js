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
        onApprove: function onApprove() {
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
        onHidden: function onHidden() {
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
