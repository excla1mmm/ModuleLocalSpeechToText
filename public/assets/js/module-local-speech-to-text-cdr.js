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
      var $callMeta = $('<div>', {
        "class": 'stt-cdr-transcript-call-meta',
        hidden: true
      }).append($('<span>', {
        'data-field': 'date'
      }), $('<span>', {
        "class": 'stt-cdr-transcript-parties'
      }).append($('<span>', {
        'data-field': 'source'
      }), $('<span>', {
        'data-field': 'destination'
      })));
      var $header = $('<div>', {
        "class": 'header stt-cdr-transcript-header'
      }).append($('<span>', {
        "class": 'stt-cdr-transcript-header-icon',
        'aria-hidden': 'true'
      }).append($('<i>', {
        "class": 'file alternate outline icon'
      })), $('<div>', {
        "class": 'stt-cdr-transcript-header-text'
      }).append($('<div>', {
        "class": 'stt-cdr-transcript-title'
      }).text(labels.title), $callMeta));
      var $loading = $('<div>', {
        "class": 'stt-cdr-transcript-loading',
        role: 'status',
        'aria-live': 'polite'
      }).append($('<div>', {
        "class": 'ui active centered inline loader stt-cdr-transcript-loader'
      }), $('<div>').text(labels.loading));
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
      }), $('<div>', {
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

    function showMessages(messages) {
      if (typeof UserMessage !== 'undefined' && messages && typeof UserMessage.showMultiString === 'function') {
        UserMessage.showMultiString(messages);
      }
    } // Same consequences and acknowledgement as the full transcript card:
    // Delete stays disabled until the checkbox is ticked, and only then is
    // confirm_delete sent.


    function ensureDeleteModal() {
      var $confirm = $('#stt-cdr-delete-transcript-modal');

      if ($confirm.length > 0) {
        return $confirm;
      }

      var $list = $('<ul>', {
        "class": 'ui list'
      });
      ['module_local_speech_to_text_DeleteTranscriptConsequence', 'module_local_speech_to_text_DeleteTranscriptKeepsCdr', 'module_local_speech_to_text_DeleteTranscriptBackupWarning'].forEach(function (key) {
        $list.append($('<li>').text(translate(key)));
      });
      $confirm = $('<div>', {
        "class": 'ui tiny modal',
        id: 'stt-cdr-delete-transcript-modal',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-labelledby': 'stt-cdr-delete-transcript-title'
      }).append($('<div>', {
        "class": 'header',
        id: 'stt-cdr-delete-transcript-title'
      }).text(translate('module_local_speech_to_text_DeleteTranscript')), $('<div>', {
        "class": 'content'
      }).append($list, $('<div>', {
        "class": 'ui checkbox'
      }).append($('<input>', {
        type: 'checkbox',
        id: 'stt-cdr-delete-transcript-acknowledge'
      }), $('<label>', {
        "for": 'stt-cdr-delete-transcript-acknowledge'
      }).text(translate('module_local_speech_to_text_DeleteTranscriptConfirm')))), $('<div>', {
        "class": 'actions'
      }).append($('<button>', {
        type: 'button',
        "class": 'ui cancel basic button'
      }).text(translate('module_local_speech_to_text_ConfirmCancel')), $('<button>', {
        type: 'button',
        "class": 'ui red approve button disabled',
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
        "class": 'ui vertical menu'
      });
      ['txt', 'json'].forEach(function (format) {
        $menu.append($('<a>', {
          "class": 'item',
          href: "".concat(exportUrl, "?id=").concat(encodeURIComponent(String(resultId)), "&format=").concat(format)
        }).text(translate(format === 'txt' ? 'module_local_speech_to_text_DownloadTranscriptTxt' : 'module_local_speech_to_text_DownloadTranscriptJson')));
      });

      if (canDelete) {
        $menu.append($('<div>', {
          "class": 'divider'
        }), $('<div>', {
          "class": 'item stt-delete-transcript-form'
        }).append($('<button>', {
          type: 'button'
        }).text(translate('module_local_speech_to_text_DeleteTranscript')).on('click', function () {
          deleteTranscript(resultId, callId);
        })));
      }

      var label = translate('module_local_speech_to_text_TranscriptActions');
      var $details = $('<details>', {
        "class": 'stt-transcript-actions'
      }).append($('<summary>', {
        'aria-label': label,
        title: label
      }).append($('<i>', {
        "class": 'ellipsis horizontal icon'
      })), $menu);
      $menu.on('click', 'a, button', function () {
        $details.removeAttr('open');
      });
      return $details;
    } // The "Summarization" field: one block per transcribed leg of the
    // call, present only while summarization is turned on.


    function renderSummaries(summaries) {
      if (!Array.isArray(summaries) || summaries.length === 0) {
        return null;
      }

      var states = {
        pending: 'module_local_speech_to_text_SummaryPending',
        skipped: 'module_local_speech_to_text_SummarySkipped',
        error: 'module_local_speech_to_text_SummaryFailed',
        unavailable: 'module_local_speech_to_text_SummaryUnavailable'
      };
      var $list = $('<div>', {
        "class": 'stt-summary-list'
      });
      summaries.forEach(function (card) {
        var state = card && typeof card.state === 'string' ? card.state : 'unavailable';
        var $panel = $('<div>', {
          "class": "stt-summary-panel stt-summary-".concat(state),
          'data-stt-summary-state': state
        }).append($('<div>', {
          "class": 'stt-summary-label'
        }).append($('<i>', {
          "class": 'align left icon'
        })).append(document.createTextNode(translate('module_local_speech_to_text_Summarization'))));

        if (state === 'done') {
          if (card.topic) {
            $panel.append($('<div>', {
              "class": 'stt-summary-topic',
              dir: 'auto'
            }).text(String(card.topic)));
          }

          $panel.append($('<div>', {
            "class": 'stt-summary-text',
            dir: 'auto'
          }).text(String(card.summary || '')));
        } else {
          $panel.append($('<div>', {
            "class": 'stt-summary-status'
          }).text(translate(states[state] || states.unavailable)));
        }

        $list.append($panel);
      });
      return $list;
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
      $target.append(renderSummaries(transcript ? transcript.summaries : null));

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
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtY2RyLmpzIl0sIm5hbWVzIjpbIk1BWF9MT09LVVBfQkFUQ0giLCIkIiwidHJhbnNsYXRlIiwia2V5IiwiZ2xvYmFsVHJhbnNsYXRlIiwiU3RyaW5nIiwiZW5zdXJlTW9kYWwiLCIkbW9kYWwiLCJsZW5ndGgiLCJsYWJlbHMiLCJidXR0b24iLCJ0aXRsZSIsImxvYWRpbmciLCJsb2FkRXJyb3IiLCJub3RGb3VuZCIsImVtcHR5IiwiJGNhbGxNZXRhIiwiaGlkZGVuIiwiYXBwZW5kIiwiJGhlYWRlciIsInRleHQiLCIkbG9hZGluZyIsInJvbGUiLCJpZCIsIiR0YWJsZSIsImZuIiwiRGF0YVRhYmxlIiwiZGF0YSIsInJvb3RVcmwiLCJnbG9iYWxSb290VXJsIiwibG9va3VwVXJsIiwiZGV0YWlsVXJsIiwidHJhbnNjcmlwdFVybCIsImV4cG9ydFVybCIsImRlbGV0ZVVybCIsImF2YWlsYWJpbGl0eSIsIk9iamVjdCIsImNyZWF0ZSIsInBlbmRpbmciLCJhY2Nlc3NEZW5pZWQiLCJkZXRhaWxSZXF1ZXN0IiwiYWRkQXV0aG9yaXphdGlvbkhlYWRlciIsInhociIsIlRva2VuTWFuYWdlciIsImFjY2Vzc1Rva2VuIiwic2V0UmVxdWVzdEhlYWRlciIsImN1cnJlbnRSb3dzIiwiZGF0YVRhYmxlIiwicm93cyIsImRvY3VtZW50IiwicXVlcnlTZWxlY3RvckFsbCIsImZvckVhY2giLCJub2RlIiwiY2FsbElkIiwidHJpbSIsImNlbGxzIiwicHVzaCIsInJvdyIsImFjdGlvbnNDZWxsIiwiaGFzQWN0aW9uQ29sdW1uIiwicmVtb3ZlQnV0dG9ucyIsImZpbmQiLCJyZW1vdmUiLCJhZGRCdXR0b24iLCIkdGFyZ2V0IiwiJGJ1dHRvbiIsImhyZWYiLCJlbmNvZGVVUklDb21wb25lbnQiLCJ0YXJnZXQiLCJyZWwiLCJhdHRyIiwiaW5uZXJIVE1MIiwicHJlcGVuZCIsImFkZENsYXNzIiwicG9wdXAiLCJyZW5kZXJCdXR0b25zIiwicHJvdG90eXBlIiwiaGFzT3duUHJvcGVydHkiLCJjYWxsIiwibG9va3VwQmF0Y2giLCJiYXRjaCIsImFqYXgiLCJ1cmwiLCJtZXRob2QiLCJjb250ZW50VHlwZSIsImRhdGFUeXBlIiwiSlNPTiIsInN0cmluZ2lmeSIsImNhbGxfaWRzIiwiYmVmb3JlU2VuZCIsImRvbmUiLCJyZXNwb25zZSIsInRyYW5zY3JpcHRNYXAiLCJOdW1iZXIiLCJmYWlsIiwic3RhdHVzIiwiYWx3YXlzIiwicmVmcmVzaEJ1dHRvbnMiLCJ1bnNlZW4iLCJpbmRleE9mIiwib2Zmc2V0Iiwic2xpY2UiLCJzdG9wTW9kYWxQbGF5YmFjayIsIndpbmRvdyIsIlN0dFRyYW5zY3JpcHRWaWV3IiwiZGlzcG9zZSIsImF1ZGlvIiwiZ2V0IiwicGF1c2UiLCJjdXJyZW50VGltZSIsInJlbW92ZUF0dHJpYnV0ZSIsImxvYWQiLCJjbGVhckNvbnZlcnNhdGlvbiIsInJlc2V0TW9kYWwiLCJyb3dEYXRhIiwicmVtb3ZlQXR0ciIsInJlbW92ZUNsYXNzIiwiJG1ldGEiLCJhcHBseVBhcnR5TGFiZWxzIiwicGFydGllcyIsImZpZWxkIiwibGFiZWwiLCJzaG93RXJyb3IiLCJtZXNzYWdlIiwic2hvd01lc3NhZ2VzIiwibWVzc2FnZXMiLCJVc2VyTWVzc2FnZSIsInNob3dNdWx0aVN0cmluZyIsImVuc3VyZURlbGV0ZU1vZGFsIiwiJGNvbmZpcm0iLCIkbGlzdCIsInR5cGUiLCJkaXNhYmxlZCIsIm9uIiwiY2hlY2tlZCIsInByb3AiLCJ0b2dnbGVDbGFzcyIsImRlbGV0ZVRyYW5zY3JpcHQiLCJyZXN1bHRJZCIsIiRhcHByb3ZlIiwibW9kYWwiLCJhbGxvd011bHRpcGxlIiwiY2xvc2FibGUiLCJvbkFwcHJvdmUiLCJyZXN1bHRfaWQiLCJjb25maXJtX2RlbGV0ZSIsInN1Y2Nlc3MiLCJmaWx0ZXIiLCJlcnJvciIsImNyZWF0ZUFjdGlvbnNNZW51IiwiY2FuRGVsZXRlIiwiJG1lbnUiLCJmb3JtYXQiLCIkZGV0YWlscyIsInJlbmRlclN1bW1hcmllcyIsInN1bW1hcmllcyIsIkFycmF5IiwiaXNBcnJheSIsInN0YXRlcyIsInNraXBwZWQiLCJ1bmF2YWlsYWJsZSIsImNhcmQiLCJzdGF0ZSIsIiRwYW5lbCIsImNyZWF0ZVRleHROb2RlIiwidG9waWMiLCJkaXIiLCJzdW1tYXJ5IiwicmVuZGVyQ29udmVyc2F0aW9uIiwidHJhbnNjcmlwdCIsImNvbnZlcnNhdGlvbiIsInRpbWVsaW5lIiwicmVjb3JkaW5ncyIsInBsYXliYWNrQ2RySWQiLCJyZWNvcmRpbmciLCJzb21lIiwiaXRlbSIsImFsbCIsInNlYXJjaCIsInBsYXkiLCJzcGVlZCIsImZhaWxlZCIsImRvd25sb2FkIiwiYXVkaW9VcmwiLCJwbGF5YmFja191cmwiLCJkb3dubG9hZFVybCIsImRvd25sb2FkX3VybCIsImR1cmF0aW9uU2Vjb25kcyIsImFjdGlvbnMiLCJvcGVuVHJhbnNjcmlwdCIsImNsb3Nlc3QiLCJhYm9ydCIsImNhbGxfaWQiLCJCb29sZWFuIiwiY2FuRGVsZXRlVHJhbnNjcmlwdCIsInN0YXJ0Iiwib25IaWRkZW4iLCJldmVudCIsImVhY2giLCJjb250YWlucyIsInN0b3BQcm9wYWdhdGlvbiIsImN0cmxLZXkiLCJtZXRhS2V5IiwicHJldmVudERlZmF1bHQiLCJvcGVuZWRUYWIiLCJvcGVuIiwib3BlbmVyIiwiZm9jdXMiLCJzZXRUaW1lb3V0IiwiaXNEYXRhVGFibGUiLCJvbmUiXSwibWFwcGluZ3MiOiI7Ozs7QUFBQyxhQUFZO0FBQ1Q7O0FBRUEsTUFBTUEsZ0JBQWdCLEdBQUcsR0FBekI7QUFFQUMsRUFBQUEsQ0FBQyxDQUFDLFlBQVk7QUFDVixhQUFTQyxTQUFULENBQW1CQyxHQUFuQixFQUF3QjtBQUNwQixVQUFJLE9BQU9DLGVBQVAsS0FBMkIsV0FBM0IsSUFBMENBLGVBQTFDLElBQTZEQSxlQUFlLENBQUNELEdBQUQsQ0FBaEYsRUFBdUY7QUFDbkYsZUFBT0UsTUFBTSxDQUFDRCxlQUFlLENBQUNELEdBQUQsQ0FBaEIsQ0FBYjtBQUNIOztB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MEwsR0FBUCxDQUFXLDBCQUFYLEVBQXVDLFlBQVk7QUFDL0NmLFFBQUFBLEtBQUssQ0FBQzNLLE1BQU0sQ0FBQ0UsU0FBUCxFQUFELENBQUw7QUFDSCxPQUZEO0FBR0g7QUFDSixHQWpsQkEsQ0FBRDtBQWtsQkgsQ0F2bEJBLEdBQUQiLCJzb3VyY2VzQ29udGVudCI6WyIoZnVuY3Rpb24gKCkge1xuICAgICd1c2Ugc3RyaWN0JztcblxuICAgIGNvbnN0IE1BWF9MT09LVVBfQkFUQ0ggPSAxMDA7XG5cbiAgICAkKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgZnVuY3Rpb24gdHJhbnNsYXRlKGtleSkge1xuICAgICAgICAgICAgaWYgKHR5cGVvZiBnbG9iYWxUcmFuc2xhdGUgIT09ICd1bmRlZmluZWQnICYmIGdsb2JhbFRyYW5zbGF0ZSAmJiBnbG9iYWxUcmFuc2xhdGVba2V5XSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBTdHJpbmcoZ2xvYmFsVHJhbnNsYXRlW2tleV0pO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmV0dXJuIGtleTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGVuc3VyZU1vZGFsKCkge1xuICAgICAgICAgICAgbGV0ICRtb2RhbCA9ICQoJyNzdHQtY2RyLXRyYW5zY3JpcHQtbW9kYWwnKTtcbiAgICAgICAgICAgIGlmICgkbW9kYWwubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybiAkbW9kYWw7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGNvbnN0IGxhYmVscyA9IHtcbiAgICAgICAgICAgICAgICBidXR0b246IHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0NkclRyYW5zY3JpcHRCdXR0b24nKSxcbiAgICAgICAgICAgICAgICB0aXRsZTogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RyVHJhbnNjcmlwdE1vZGFsVGl0bGUnKSxcbiAgICAgICAgICAgICAgICBsb2FkaW5nOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJUcmFuc2NyaXB0TG9hZGluZycpLFxuICAgICAgICAgICAgICAgIGxvYWRFcnJvcjogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RyVHJhbnNjcmlwdExvYWRFcnJvcicpLFxuICAgICAgICAgICAgICAgIG5vdEZvdW5kOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJUcmFuc2NyaXB0Tm90Rm91bmQnKSxcbiAgICAgICAgICAgICAgICBlbXB0eTogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTm9Db252ZXJzYXRpb25TZWdtZW50cycpLFxuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgIGNvbnN0ICRjYWxsTWV0YSA9ICQoJzxkaXY+Jywge1xuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LWNkci10cmFuc2NyaXB0LWNhbGwtbWV0YScsXG4gICAgICAgICAgICAgICAgaGlkZGVuOiB0cnVlLFxuICAgICAgICAgICAgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgJ2RhdGEtZmllbGQnOiAnZGF0ZScgfSksXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC1jZHItdHJhbnNjcmlwdC1wYXJ0aWVzJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICQoJzxzcGFuPicsIHsgJ2RhdGEtZmllbGQnOiAnc291cmNlJyB9KSxcbiAgICAgICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyAnZGF0YS1maWVsZCc6ICdkZXN0aW5hdGlvbicgfSlcbiAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgY29uc3QgJGhlYWRlciA9ICQoJzxkaXY+JywgeyBjbGFzczogJ2hlYWRlciBzdHQtY2RyLXRyYW5zY3JpcHQtaGVhZGVyJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPHNwYW4+JywgeyBjbGFzczogJ3N0dC1jZHItdHJhbnNjcmlwdC1oZWFkZXItaWNvbicsICdhcmlhLWhpZGRlbic6ICd0cnVlJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICQoJzxpPicsIHsgY2xhc3M6ICdmaWxlIGFsdGVybmF0ZSBvdXRsaW5lIGljb24nIH0pXG4gICAgICAgICAgICAgICAgKSxcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtY2RyLXRyYW5zY3JpcHQtaGVhZGVyLXRleHQnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnc3R0LWNkci10cmFuc2NyaXB0LXRpdGxlJyB9KS50ZXh0KGxhYmVscy50aXRsZSksXG4gICAgICAgICAgICAgICAgICAgICRjYWxsTWV0YVxuICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICk7XG4gICAgICAgICAgICBjb25zdCAkbG9hZGluZyA9ICQoJzxkaXY+Jywge1xuICAgICAgICAgICAgICAgIGNsYXNzOiAnc3R0LWNkci10cmFuc2NyaXB0LWxvYWRpbmcnLFxuICAgICAgICAgICAgICAgIHJvbGU6ICdzdGF0dXMnLFxuICAgICAgICAgICAgICAgICdhcmlhLWxpdmUnOiAncG9saXRlJyxcbiAgICAgICAgICAgIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICd1aSBhY3RpdmUgY2VudGVyZWQgaW5saW5lIGxvYWRlciBzdHQtY2RyLXRyYW5zY3JpcHQtbG9hZGVyJyB9KSxcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicpLnRleHQobGFiZWxzLmxvYWRpbmcpXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgJG1vZGFsID0gJCgnPGRpdj4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICd1aSBsYXJnZSBtb2RhbCBtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgc3R0LWNkci10cmFuc2NyaXB0LW1vZGFsJyxcbiAgICAgICAgICAgICAgICBpZDogJ3N0dC1jZHItdHJhbnNjcmlwdC1tb2RhbCcsXG4gICAgICAgICAgICAgICAgJ2RhdGEtbGFiZWwtYnV0dG9uJzogbGFiZWxzLmJ1dHRvbixcbiAgICAgICAgICAgICAgICAnZGF0YS1sYWJlbC1sb2FkLWVycm9yJzogbGFiZWxzLmxvYWRFcnJvcixcbiAgICAgICAgICAgICAgICAnZGF0YS1sYWJlbC1ub3QtZm91bmQnOiBsYWJlbHMubm90Rm91bmQsXG4gICAgICAgICAgICAgICAgJ2RhdGEtbGFiZWwtZW1wdHknOiBsYWJlbHMuZW1wdHksXG4gICAgICAgICAgICB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGk+JywgeyBjbGFzczogJ2Nsb3NlIGljb24nIH0pLFxuICAgICAgICAgICAgICAgICRoZWFkZXIsXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnY29udGVudCcgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICAgICAkbG9hZGluZyxcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7XG4gICAgICAgICAgICAgICAgICAgICAgICBjbGFzczogJ3VpIG1lc3NhZ2Ugc3R0LWNkci10cmFuc2NyaXB0LWVycm9yJyxcbiAgICAgICAgICAgICAgICAgICAgICAgIHJvbGU6ICdhbGVydCcsXG4gICAgICAgICAgICAgICAgICAgICAgICAnYXJpYS1saXZlJzogJ2Fzc2VydGl2ZScsXG4gICAgICAgICAgICAgICAgICAgICAgICBoaWRkZW46IHRydWUsXG4gICAgICAgICAgICAgICAgICAgIH0pLFxuICAgICAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtY2RyLXRyYW5zY3JpcHQtY29udmVyc2F0aW9uJywgaGlkZGVuOiB0cnVlIH0pXG4gICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgKTtcbiAgICAgICAgICAgICQoJ2JvZHknKS5hcHBlbmQoJG1vZGFsKTtcbiAgICAgICAgICAgIHJldHVybiAkbW9kYWw7XG4gICAgICAgIH1cblxuICAgICAgICBjb25zdCAkdGFibGUgPSAkKCcjY2RyLXRhYmxlJyk7XG4gICAgICAgIGlmICgkdGFibGUubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgY29uc3QgJG1vZGFsID0gZW5zdXJlTW9kYWwoKTtcbiAgICAgICAgaWYgKCRtb2RhbC5sZW5ndGggPT09IDAgfHwgdHlwZW9mICQuZm4uRGF0YVRhYmxlID09PSAndW5kZWZpbmVkJykge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG5cbiAgICAgICAgY29uc3QgbGFiZWxzID0ge1xuICAgICAgICAgICAgYnV0dG9uOiBTdHJpbmcoJG1vZGFsLmRhdGEoJ2xhYmVsLWJ1dHRvbicpIHx8ICcnKSxcbiAgICAgICAgICAgIGxvYWRFcnJvcjogU3RyaW5nKCRtb2RhbC5kYXRhKCdsYWJlbC1sb2FkLWVycm9yJykgfHwgJycpLFxuICAgICAgICAgICAgbm90Rm91bmQ6IFN0cmluZygkbW9kYWwuZGF0YSgnbGFiZWwtbm90LWZvdW5kJykgfHwgJycpLFxuICAgICAgICAgICAgZW1wdHk6IFN0cmluZygkbW9kYWwuZGF0YSgnbGFiZWwtZW1wdHknKSB8fCAnJyksXG4gICAgICAgIH07XG4gICAgICAgIGNvbnN0IHJvb3RVcmwgPSB0eXBlb2YgZ2xvYmFsUm9vdFVybCA9PT0gJ3N0cmluZycgPyBnbG9iYWxSb290VXJsIDogJy9hZG1pbi1jYWJpbmV0Lyc7XG4gICAgICAgIGNvbnN0IGxvb2t1cFVybCA9IGAke3Jvb3RVcmx9bW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0L2Nkci10cmFuc2NyaXB0L2xvb2t1cGA7XG4gICAgICAgIGNvbnN0IGRldGFpbFVybCA9IGAke3Jvb3RVcmx9bW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0L2Nkci10cmFuc2NyaXB0L2RldGFpbGA7XG4gICAgICAgIGNvbnN0IHRyYW5zY3JpcHRVcmwgPSBgJHtyb290VXJsfW1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC9tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQvdHJhbnNjcmlwdGA7XG4gICAgICAgIGNvbnN0IGV4cG9ydFVybCA9IGAke3Jvb3RVcmx9bW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0L3RyYW5zY3JpcHQtYWN0aW9ucy9leHBvcnRgO1xuICAgICAgICBjb25zdCBkZWxldGVVcmwgPSBgJHtyb290VXJsfW1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC90cmFuc2NyaXB0LWFjdGlvbnMvZGVsZXRlYDtcbiAgICAgICAgY29uc3QgYXZhaWxhYmlsaXR5ID0gT2JqZWN0LmNyZWF0ZShudWxsKTtcbiAgICAgICAgY29uc3QgcGVuZGluZyA9IE9iamVjdC5jcmVhdGUobnVsbCk7XG4gICAgICAgIGxldCBhY2Nlc3NEZW5pZWQgPSBmYWxzZTtcbiAgICAgICAgbGV0IGRldGFpbFJlcXVlc3QgPSBudWxsO1xuXG4gICAgICAgIGZ1bmN0aW9uIGFkZEF1dGhvcml6YXRpb25IZWFkZXIoeGhyKSB7XG4gICAgICAgICAgICBpZiAodHlwZW9mIFRva2VuTWFuYWdlciAhPT0gJ3VuZGVmaW5lZCcgJiYgVG9rZW5NYW5hZ2VyLmFjY2Vzc1Rva2VuKSB7XG4gICAgICAgICAgICAgICAgeGhyLnNldFJlcXVlc3RIZWFkZXIoJ0F1dGhvcml6YXRpb24nLCBgQmVhcmVyICR7VG9rZW5NYW5hZ2VyLmFjY2Vzc1Rva2VufWApO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY3VycmVudFJvd3MoZGF0YVRhYmxlKSB7XG4gICAgICAgICAgICBjb25zdCByb3dzID0gW107XG4gICAgICAgICAgICBkb2N1bWVudC5xdWVyeVNlbGVjdG9yQWxsKCcjY2RyLXRhYmxlIHRib2R5IHRyW2lkXScpLmZvckVhY2goZnVuY3Rpb24gKG5vZGUpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBjYWxsSWQgPSBTdHJpbmcobm9kZS5pZCB8fCAnJykudHJpbSgpO1xuICAgICAgICAgICAgICAgIGlmIChjYWxsSWQgPT09ICcnIHx8IGNhbGxJZC5sZW5ndGggPiAyNTUpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjb25zdCBjZWxscyA9IG5vZGUucXVlcnlTZWxlY3RvckFsbCgnOnNjb3BlID4gdGQnKTtcbiAgICAgICAgICAgICAgICBpZiAoY2VsbHMubGVuZ3RoIDwgMikge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHJvd3MucHVzaCh7XG4gICAgICAgICAgICAgICAgICAgIG5vZGU6ICQobm9kZSksXG4gICAgICAgICAgICAgICAgICAgIGRhdGE6IGRhdGFUYWJsZS5yb3cobm9kZSkuZGF0YSgpLFxuICAgICAgICAgICAgICAgICAgICBjYWxsSWQsXG4gICAgICAgICAgICAgICAgICAgIGFjdGlvbnNDZWxsOiAkKGNlbGxzW2NlbGxzLmxlbmd0aCAtIDFdKSxcbiAgICAgICAgICAgICAgICAgICAgaGFzQWN0aW9uQ29sdW1uOiBjZWxscy5sZW5ndGggPiA1LFxuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICByZXR1cm4gcm93cztcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbW92ZUJ1dHRvbnMoKSB7XG4gICAgICAgICAgICAkdGFibGUuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1idXR0b24nKS5yZW1vdmUoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGFkZEJ1dHRvbihyb3cpIHtcbiAgICAgICAgICAgIGNvbnN0ICR0YXJnZXQgPSByb3cuYWN0aW9uc0NlbGw7XG4gICAgICAgICAgICBpZiAoJHRhcmdldC5sZW5ndGggPT09IDAgfHwgJHRhcmdldC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWJ1dHRvbicpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGNvbnN0ICRidXR0b24gPSAkKCc8YT4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICdzdHQtY2RyLXRyYW5zY3JpcHQtYnV0dG9uJyxcbiAgICAgICAgICAgICAgICBocmVmOiBgJHt0cmFuc2NyaXB0VXJsfT9pZD0ke2VuY29kZVVSSUNvbXBvbmVudChTdHJpbmcoYXZhaWxhYmlsaXR5W3Jvdy5jYWxsSWRdKSl9YCxcbiAgICAgICAgICAgICAgICB0YXJnZXQ6ICdfYmxhbmsnLFxuICAgICAgICAgICAgICAgIHJlbDogJ25vb3BlbmVyJyxcbiAgICAgICAgICAgICAgICAnYXJpYS1sYWJlbCc6IGxhYmVscy5idXR0b24sXG4gICAgICAgICAgICAgICAgdGl0bGU6IGxhYmVscy5idXR0b24sXG4gICAgICAgICAgICAgICAgJ2RhdGEtY29udGVudCc6IGxhYmVscy5idXR0b24sXG4gICAgICAgICAgICB9KS5hdHRyKCdkYXRhLWNhbGwtaWQnLCByb3cuY2FsbElkKTtcbiAgICAgICAgICAgICRidXR0b25bMF0uaW5uZXJIVE1MID0gJzxzdmcgdmlld0JveD1cIjAgMCAyNCAyNFwiIGZpbGw9XCJub25lXCIgc3Ryb2tlPVwiY3VycmVudENvbG9yXCIgc3Ryb2tlLXdpZHRoPVwiMS44XCIgc3Ryb2tlLWxpbmVjYXA9XCJyb3VuZFwiIGFyaWEtaGlkZGVuPVwidHJ1ZVwiPidcbiAgICAgICAgICAgICAgICArICc8cGF0aCBkPVwiTTQgMTN2LTJNOCAxNlY4TTEyIDE4VjZNMTYgMTZWOE0yMCAxM3YtMlwiIC8+PC9zdmc+JztcbiAgICAgICAgICAgIGlmIChyb3cuaGFzQWN0aW9uQ29sdW1uKSB7XG4gICAgICAgICAgICAgICAgJHRhcmdldC5wcmVwZW5kKCRidXR0b24pO1xuICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICAkdGFyZ2V0LmFkZENsYXNzKCdzdHQtY2RyLWR1cmF0aW9uLWNlbGwnKTtcbiAgICAgICAgICAgICAgICAkYnV0dG9uLmFkZENsYXNzKCdzdHQtY2RyLXRyYW5zY3JpcHQtZHVyYXRpb24tYnV0dG9uJyk7XG4gICAgICAgICAgICAgICAgJHRhcmdldC5hcHBlbmQoJGJ1dHRvbik7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAodHlwZW9mICRidXR0b24ucG9wdXAgPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICAgICAkYnV0dG9uLnBvcHVwKCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJCdXR0b25zKHJvd3MpIHtcbiAgICAgICAgICAgIHJvd3MuZm9yRWFjaChmdW5jdGlvbiAocm93KSB7XG4gICAgICAgICAgICAgICAgaWYgKGF2YWlsYWJpbGl0eVtyb3cuY2FsbElkXSA+IDApIHtcbiAgICAgICAgICAgICAgICAgICAgYWRkQnV0dG9uKHJvdyk7XG4gICAgICAgICAgICAgICAgfSBlbHNlIGlmIChPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoYXZhaWxhYmlsaXR5LCByb3cuY2FsbElkKSkge1xuICAgICAgICAgICAgICAgICAgICByb3cubm9kZS5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWJ1dHRvbicpLnJlbW92ZSgpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gbG9va3VwQmF0Y2goYmF0Y2gsIGRhdGFUYWJsZSkge1xuICAgICAgICAgICAgYmF0Y2guZm9yRWFjaChmdW5jdGlvbiAoY2FsbElkKSB7XG4gICAgICAgICAgICAgICAgcGVuZGluZ1tjYWxsSWRdID0gdHJ1ZTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJC5hamF4KHtcbiAgICAgICAgICAgICAgICB1cmw6IGxvb2t1cFVybCxcbiAgICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgICAgICAgICAgICBjb250ZW50VHlwZTogJ2FwcGxpY2F0aW9uL2pzb247IGNoYXJzZXQ9dXRmLTgnLFxuICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbicsXG4gICAgICAgICAgICAgICAgZGF0YTogSlNPTi5zdHJpbmdpZnkoeyBjYWxsX2lkczogYmF0Y2ggfSksXG4gICAgICAgICAgICAgICAgYmVmb3JlU2VuZDogYWRkQXV0aG9yaXphdGlvbkhlYWRlcixcbiAgICAgICAgICAgIH0pLmRvbmUoZnVuY3Rpb24gKHJlc3BvbnNlKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgdHJhbnNjcmlwdE1hcCA9IHJlc3BvbnNlICYmIHJlc3BvbnNlLnRyYW5zY3JpcHRNYXAgJiYgdHlwZW9mIHJlc3BvbnNlLnRyYW5zY3JpcHRNYXAgPT09ICdvYmplY3QnXG4gICAgICAgICAgICAgICAgICAgID8gcmVzcG9uc2UudHJhbnNjcmlwdE1hcFxuICAgICAgICAgICAgICAgICAgICA6IHt9O1xuICAgICAgICAgICAgICAgIGJhdGNoLmZvckVhY2goZnVuY3Rpb24gKGNhbGxJZCkge1xuICAgICAgICAgICAgICAgICAgICBpZiAoXG4gICAgICAgICAgICAgICAgICAgICAgICBPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwodHJhbnNjcmlwdE1hcCwgY2FsbElkKVxuICAgICAgICAgICAgICAgICAgICAgICAgJiYgTnVtYmVyKHRyYW5zY3JpcHRNYXBbY2FsbElkXSkgPiAwXG4gICAgICAgICAgICAgICAgICAgICkge1xuICAgICAgICAgICAgICAgICAgICAgICAgYXZhaWxhYmlsaXR5W2NhbGxJZF0gPSBOdW1iZXIodHJhbnNjcmlwdE1hcFtjYWxsSWRdKTtcbiAgICAgICAgICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGRlbGV0ZSBhdmFpbGFiaWxpdHlbY2FsbElkXTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfSkuZmFpbChmdW5jdGlvbiAoeGhyKSB7XG4gICAgICAgICAgICAgICAgaWYgKHhoci5zdGF0dXMgPT09IDQwMSB8fCB4aHIuc3RhdHVzID09PSA0MDMpIHtcbiAgICAgICAgICAgICAgICAgICAgYWNjZXNzRGVuaWVkID0gdHJ1ZTtcbiAgICAgICAgICAgICAgICAgICAgcmVtb3ZlQnV0dG9ucygpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pLmFsd2F5cyhmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgYmF0Y2guZm9yRWFjaChmdW5jdGlvbiAoY2FsbElkKSB7XG4gICAgICAgICAgICAgICAgICAgIGRlbGV0ZSBwZW5kaW5nW2NhbGxJZF07XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAgICAgaWYgKCFhY2Nlc3NEZW5pZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmVuZGVyQnV0dG9ucyhjdXJyZW50Um93cyhkYXRhVGFibGUpKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlZnJlc2hCdXR0b25zKGRhdGFUYWJsZSkge1xuICAgICAgICAgICAgaWYgKGFjY2Vzc0RlbmllZCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNvbnN0IHJvd3MgPSBjdXJyZW50Um93cyhkYXRhVGFibGUpO1xuICAgICAgICAgICAgcmVuZGVyQnV0dG9ucyhyb3dzKTtcbiAgICAgICAgICAgIGNvbnN0IHVuc2VlbiA9IFtdO1xuICAgICAgICAgICAgcm93cy5mb3JFYWNoKGZ1bmN0aW9uIChyb3cpIHtcbiAgICAgICAgICAgICAgICBpZiAoXG4gICAgICAgICAgICAgICAgICAgICFPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoYXZhaWxhYmlsaXR5LCByb3cuY2FsbElkKVxuICAgICAgICAgICAgICAgICAgICAmJiAhcGVuZGluZ1tyb3cuY2FsbElkXVxuICAgICAgICAgICAgICAgICAgICAmJiB1bnNlZW4uaW5kZXhPZihyb3cuY2FsbElkKSA9PT0gLTFcbiAgICAgICAgICAgICAgICApIHtcbiAgICAgICAgICAgICAgICAgICAgdW5zZWVuLnB1c2gocm93LmNhbGxJZCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBmb3IgKGxldCBvZmZzZXQgPSAwOyBvZmZzZXQgPCB1bnNlZW4ubGVuZ3RoOyBvZmZzZXQgKz0gTUFYX0xPT0tVUF9CQVRDSCkge1xuICAgICAgICAgICAgICAgIGxvb2t1cEJhdGNoKHVuc2Vlbi5zbGljZShvZmZzZXQsIG9mZnNldCArIE1BWF9MT09LVVBfQkFUQ0gpLCBkYXRhVGFibGUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc3RvcE1vZGFsUGxheWJhY2soKSB7XG4gICAgICAgICAgICBpZiAod2luZG93LlN0dFRyYW5zY3JpcHRWaWV3ICYmIHR5cGVvZiB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcuZGlzcG9zZSA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgIHdpbmRvdy5TdHRUcmFuc2NyaXB0Vmlldy5kaXNwb3NlKCRtb2RhbC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWNvbnZlcnNhdGlvbicpKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNvbnN0IGF1ZGlvID0gJG1vZGFsLmZpbmQoJy5zdHQtdHJhbnNjcmlwdC1hdWRpbycpLmdldCgwKTtcbiAgICAgICAgICAgIGlmICghYXVkaW8pIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBhdWRpby5wYXVzZSgpO1xuICAgICAgICAgICAgYXVkaW8uY3VycmVudFRpbWUgPSAwO1xuICAgICAgICAgICAgYXVkaW8ucmVtb3ZlQXR0cmlidXRlKCdzcmMnKTtcbiAgICAgICAgICAgIGF1ZGlvLmxvYWQoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGNsZWFyQ29udmVyc2F0aW9uKCkge1xuICAgICAgICAgICAgc3RvcE1vZGFsUGxheWJhY2soKTtcbiAgICAgICAgICAgICRtb2RhbC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWNvbnZlcnNhdGlvbicpLmF0dHIoJ2hpZGRlbicsIHRydWUpLmVtcHR5KCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZXNldE1vZGFsKHJvd0RhdGEpIHtcbiAgICAgICAgICAgIGNsZWFyQ29udmVyc2F0aW9uKCk7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1sb2FkaW5nJykucmVtb3ZlQXR0cignaGlkZGVuJyk7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1lcnJvcicpLmF0dHIoJ2hpZGRlbicsIHRydWUpLnJlbW92ZUNsYXNzKCdlcnJvcicpLnRleHQoJycpO1xuICAgICAgICAgICAgY29uc3QgJG1ldGEgPSAkbW9kYWwuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1jYWxsLW1ldGEnKS5yZW1vdmVBdHRyKCdoaWRkZW4nKTtcbiAgICAgICAgICAgICRtZXRhLmZpbmQoJ1tkYXRhLWZpZWxkPVwiZGF0ZVwiXScpLnRleHQoU3RyaW5nKHJvd0RhdGFbMF0gfHwgJycpKTtcbiAgICAgICAgICAgICRtZXRhLmZpbmQoJ1tkYXRhLWZpZWxkPVwic291cmNlXCJdJykudGV4dChTdHJpbmcocm93RGF0YVsxXSB8fCAnJykpO1xuICAgICAgICAgICAgJG1ldGEuZmluZCgnW2RhdGEtZmllbGQ9XCJkZXN0aW5hdGlvblwiXScpLnRleHQoU3RyaW5nKHJvd0RhdGFbMl0gfHwgJycpKTtcbiAgICAgICAgfVxuXG4gICAgICAgIC8vIFRoZSBoaXN0b3J5IHJvdyBvbmx5IGV2ZXIgaG9sZHMgYmFyZSBudW1iZXJzLCBhbmQgZm9yIGFuIGluY29taW5nXG4gICAgICAgIC8vIGNhbGwgd2l0aCBhIERJRCBpdCBkb2VzIG5vdCBldmVuIGhvbGQgdGhlIGV4dGVuc2lvbiB0aGUgdHJhbnNjcmlwdFxuICAgICAgICAvLyBpcyBhYm91dC4gVGhlIGRldGFpbCByZXNwb25zZSBsYWJlbHMgdGhlIGNhbGwncyBvd24gcGFydGllcywgc28gaXRcbiAgICAgICAgLy8gcmVwbGFjZXMgdGhvc2UgbnVtYmVycyBvbmNlIGl0IGFycml2ZXMuXG4gICAgICAgIGZ1bmN0aW9uIGFwcGx5UGFydHlMYWJlbHMocGFydGllcykge1xuICAgICAgICAgICAgaWYgKCFwYXJ0aWVzIHx8IHR5cGVvZiBwYXJ0aWVzICE9PSAnb2JqZWN0Jykge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNvbnN0ICRtZXRhID0gJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtY2FsbC1tZXRhJyk7XG4gICAgICAgICAgICBbJ3NvdXJjZScsICdkZXN0aW5hdGlvbiddLmZvckVhY2goZnVuY3Rpb24gKGZpZWxkKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgbGFiZWwgPSBTdHJpbmcocGFydGllc1tmaWVsZF0gfHwgJycpO1xuICAgICAgICAgICAgICAgIGlmIChsYWJlbCAhPT0gJycpIHtcbiAgICAgICAgICAgICAgICAgICAgJG1ldGEuZmluZCgnW2RhdGEtZmllbGQ9XCInICsgZmllbGQgKyAnXCJdJykudGV4dChsYWJlbCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzaG93RXJyb3IobWVzc2FnZSkge1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtbG9hZGluZycpLmF0dHIoJ2hpZGRlbicsIHRydWUpO1xuICAgICAgICAgICAgY2xlYXJDb252ZXJzYXRpb24oKTtcbiAgICAgICAgICAgICRtb2RhbC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWVycm9yJykucmVtb3ZlQXR0cignaGlkZGVuJykuYWRkQ2xhc3MoJ2Vycm9yJykudGV4dChtZXNzYWdlKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNob3dNZXNzYWdlcyhtZXNzYWdlcykge1xuICAgICAgICAgICAgaWYgKHR5cGVvZiBVc2VyTWVzc2FnZSAhPT0gJ3VuZGVmaW5lZCcgJiYgbWVzc2FnZXMgJiYgdHlwZW9mIFVzZXJNZXNzYWdlLnNob3dNdWx0aVN0cmluZyA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgIFVzZXJNZXNzYWdlLnNob3dNdWx0aVN0cmluZyhtZXNzYWdlcyk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICAvLyBTYW1lIGNvbnNlcXVlbmNlcyBhbmQgYWNrbm93bGVkZ2VtZW50IGFzIHRoZSBmdWxsIHRyYW5zY3JpcHQgY2FyZDpcbiAgICAgICAgLy8gRGVsZXRlIHN0YXlzIGRpc2FibGVkIHVudGlsIHRoZSBjaGVja2JveCBpcyB0aWNrZWQsIGFuZCBvbmx5IHRoZW4gaXNcbiAgICAgICAgLy8gY29uZmlybV9kZWxldGUgc2VudC5cbiAgICAgICAgZnVuY3Rpb24gZW5zdXJlRGVsZXRlTW9kYWwoKSB7XG4gICAgICAgICAgICBsZXQgJGNvbmZpcm0gPSAkKCcjc3R0LWNkci1kZWxldGUtdHJhbnNjcmlwdC1tb2RhbCcpO1xuICAgICAgICAgICAgaWYgKCRjb25maXJtLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gJGNvbmZpcm07XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCAkbGlzdCA9ICQoJzx1bD4nLCB7IGNsYXNzOiAndWkgbGlzdCcgfSk7XG4gICAgICAgICAgICBbXG4gICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9EZWxldGVUcmFuc2NyaXB0Q29uc2VxdWVuY2UnLFxuICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfRGVsZXRlVHJhbnNjcmlwdEtlZXBzQ2RyJyxcbiAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0RlbGV0ZVRyYW5zY3JpcHRCYWNrdXBXYXJuaW5nJyxcbiAgICAgICAgICAgIF0uZm9yRWFjaChmdW5jdGlvbiAoa2V5KSB7XG4gICAgICAgICAgICAgICAgJGxpc3QuYXBwZW5kKCQoJzxsaT4nKS50ZXh0KHRyYW5zbGF0ZShrZXkpKSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICRjb25maXJtID0gJCgnPGRpdj4nLCB7XG4gICAgICAgICAgICAgICAgY2xhc3M6ICd1aSB0aW55IG1vZGFsJyxcbiAgICAgICAgICAgICAgICBpZDogJ3N0dC1jZHItZGVsZXRlLXRyYW5zY3JpcHQtbW9kYWwnLFxuICAgICAgICAgICAgICAgIHJvbGU6ICdkaWFsb2cnLFxuICAgICAgICAgICAgICAgICdhcmlhLW1vZGFsJzogJ3RydWUnLFxuICAgICAgICAgICAgICAgICdhcmlhLWxhYmVsbGVkYnknOiAnc3R0LWNkci1kZWxldGUtdHJhbnNjcmlwdC10aXRsZScsXG4gICAgICAgICAgICB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnaGVhZGVyJywgaWQ6ICdzdHQtY2RyLWRlbGV0ZS10cmFuc2NyaXB0LXRpdGxlJyB9KVxuICAgICAgICAgICAgICAgICAgICAudGV4dCh0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9EZWxldGVUcmFuc2NyaXB0JykpLFxuICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ2NvbnRlbnQnIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgJGxpc3QsXG4gICAgICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ3VpIGNoZWNrYm94JyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICAgICAkKCc8aW5wdXQ+JywgeyB0eXBlOiAnY2hlY2tib3gnLCBpZDogJ3N0dC1jZHItZGVsZXRlLXRyYW5zY3JpcHQtYWNrbm93bGVkZ2UnIH0pLFxuICAgICAgICAgICAgICAgICAgICAgICAgJCgnPGxhYmVsPicsIHsgZm9yOiAnc3R0LWNkci1kZWxldGUtdHJhbnNjcmlwdC1hY2tub3dsZWRnZScgfSlcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAudGV4dCh0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9EZWxldGVUcmFuc2NyaXB0Q29uZmlybScpKVxuICAgICAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICAgICAgKSxcbiAgICAgICAgICAgICAgICAkKCc8ZGl2PicsIHsgY2xhc3M6ICdhY3Rpb25zJyB9KS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgICAgICQoJzxidXR0b24+JywgeyB0eXBlOiAnYnV0dG9uJywgY2xhc3M6ICd1aSBjYW5jZWwgYmFzaWMgYnV0dG9uJyB9KVxuICAgICAgICAgICAgICAgICAgICAgICAgLnRleHQodHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ29uZmlybUNhbmNlbCcpKSxcbiAgICAgICAgICAgICAgICAgICAgJCgnPGJ1dHRvbj4nLCB7IHR5cGU6ICdidXR0b24nLCBjbGFzczogJ3VpIHJlZCBhcHByb3ZlIGJ1dHRvbiBkaXNhYmxlZCcsIGRpc2FibGVkOiB0cnVlIH0pXG4gICAgICAgICAgICAgICAgICAgICAgICAudGV4dCh0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Db25maXJtRGVsZXRlJykpXG4gICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgKTtcbiAgICAgICAgICAgICQoJ2JvZHknKS5hcHBlbmQoJGNvbmZpcm0pO1xuICAgICAgICAgICAgJGNvbmZpcm0uZmluZCgnI3N0dC1jZHItZGVsZXRlLXRyYW5zY3JpcHQtYWNrbm93bGVkZ2UnKS5vbignY2hhbmdlJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IGNoZWNrZWQgPSAkKHRoaXMpLnByb3AoJ2NoZWNrZWQnKTtcbiAgICAgICAgICAgICAgICAkY29uZmlybS5maW5kKCcuYXBwcm92ZS5idXR0b24nKS50b2dnbGVDbGFzcygnZGlzYWJsZWQnLCAhY2hlY2tlZCkucHJvcCgnZGlzYWJsZWQnLCAhY2hlY2tlZCk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIHJldHVybiAkY29uZmlybTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGRlbGV0ZVRyYW5zY3JpcHQocmVzdWx0SWQsIGNhbGxJZCkge1xuICAgICAgICAgICAgY29uc3QgJGNvbmZpcm0gPSBlbnN1cmVEZWxldGVNb2RhbCgpO1xuICAgICAgICAgICAgY29uc3QgJGFwcHJvdmUgPSAkY29uZmlybS5maW5kKCcuYXBwcm92ZS5idXR0b24nKTtcbiAgICAgICAgICAgICRjb25maXJtLmZpbmQoJyNzdHQtY2RyLWRlbGV0ZS10cmFuc2NyaXB0LWFja25vd2xlZGdlJykucHJvcCgnY2hlY2tlZCcsIGZhbHNlKTtcbiAgICAgICAgICAgICRhcHByb3ZlLmFkZENsYXNzKCdkaXNhYmxlZCcpLnByb3AoJ2Rpc2FibGVkJywgdHJ1ZSkucmVtb3ZlQ2xhc3MoJ2xvYWRpbmcnKTtcbiAgICAgICAgICAgICRjb25maXJtLm1vZGFsKHtcbiAgICAgICAgICAgICAgICBhbGxvd011bHRpcGxlOiB0cnVlLFxuICAgICAgICAgICAgICAgIGNsb3NhYmxlOiB0cnVlLFxuICAgICAgICAgICAgICAgIG9uQXBwcm92ZTogZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBpZiAoISRjb25maXJtLmZpbmQoJyNzdHQtY2RyLWRlbGV0ZS10cmFuc2NyaXB0LWFja25vd2xlZGdlJykucHJvcCgnY2hlY2tlZCcpKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgJGFwcHJvdmUuYWRkQ2xhc3MoJ2xvYWRpbmcgZGlzYWJsZWQnKS5wcm9wKCdkaXNhYmxlZCcsIHRydWUpO1xuICAgICAgICAgICAgICAgICAgICAkLmFqYXgoe1xuICAgICAgICAgICAgICAgICAgICAgICAgdXJsOiBkZWxldGVVcmwsXG4gICAgICAgICAgICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgICAgICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbicsXG4gICAgICAgICAgICAgICAgICAgICAgICBkYXRhOiB7IHJlc3VsdF9pZDogcmVzdWx0SWQsIGNvbmZpcm1fZGVsZXRlOiAnMScgfSxcbiAgICAgICAgICAgICAgICAgICAgICAgIGJlZm9yZVNlbmQ6IGFkZEF1dGhvcml6YXRpb25IZWFkZXIsXG4gICAgICAgICAgICAgICAgICAgIH0pLmRvbmUoZnVuY3Rpb24gKHJlc3BvbnNlKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBzaG93TWVzc2FnZXMocmVzcG9uc2UgJiYgcmVzcG9uc2UubWVzc2FnZSk7XG4gICAgICAgICAgICAgICAgICAgICAgICBpZiAocmVzcG9uc2UgJiYgcmVzcG9uc2Uuc3VjY2VzcyA9PT0gdHJ1ZSkge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIGRlbGV0ZSBhdmFpbGFiaWxpdHlbY2FsbElkXTtcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAkdGFibGUuZmluZCgnLnN0dC1jZHItdHJhbnNjcmlwdC1idXR0b24nKS5maWx0ZXIoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICByZXR1cm4gJCh0aGlzKS5hdHRyKCdkYXRhLWNhbGwtaWQnKSA9PT0gY2FsbElkO1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIH0pLnJlbW92ZSgpO1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgICRjb25maXJtLm1vZGFsKCdoaWRlJyk7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgJG1vZGFsLm1vZGFsKCdoaWRlJyk7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICAgICAgJGNvbmZpcm0ubW9kYWwoJ2hpZGUnKTtcbiAgICAgICAgICAgICAgICAgICAgfSkuZmFpbChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAkY29uZmlybS5tb2RhbCgnaGlkZScpO1xuICAgICAgICAgICAgICAgICAgICAgICAgc2hvd01lc3NhZ2VzKHsgZXJyb3I6IFt0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0RGVsZXRlRmFpbGVkJyldIH0pO1xuICAgICAgICAgICAgICAgICAgICB9KS5hbHdheXMoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgJGFwcHJvdmUucmVtb3ZlQ2xhc3MoJ2xvYWRpbmcnKTtcbiAgICAgICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgICAgICB9LFxuICAgICAgICAgICAgfSkubW9kYWwoJ3Nob3cnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGNyZWF0ZUFjdGlvbnNNZW51KHJlc3VsdElkLCBjYWxsSWQsIGNhbkRlbGV0ZSkge1xuICAgICAgICAgICAgY29uc3QgJG1lbnUgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICd1aSB2ZXJ0aWNhbCBtZW51JyB9KTtcbiAgICAgICAgICAgIFsndHh0JywgJ2pzb24nXS5mb3JFYWNoKGZ1bmN0aW9uIChmb3JtYXQpIHtcbiAgICAgICAgICAgICAgICAkbWVudS5hcHBlbmQoJCgnPGE+Jywge1xuICAgICAgICAgICAgICAgICAgICBjbGFzczogJ2l0ZW0nLFxuICAgICAgICAgICAgICAgICAgICBocmVmOiBgJHtleHBvcnRVcmx9P2lkPSR7ZW5jb2RlVVJJQ29tcG9uZW50KFN0cmluZyhyZXN1bHRJZCkpfSZmb3JtYXQ9JHtmb3JtYXR9YCxcbiAgICAgICAgICAgICAgICB9KS50ZXh0KHRyYW5zbGF0ZShmb3JtYXQgPT09ICd0eHQnXG4gICAgICAgICAgICAgICAgICAgID8gJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Eb3dubG9hZFRyYW5zY3JpcHRUeHQnXG4gICAgICAgICAgICAgICAgICAgIDogJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Eb3dubG9hZFRyYW5zY3JpcHRKc29uJykpKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgaWYgKGNhbkRlbGV0ZSkge1xuICAgICAgICAgICAgICAgICRtZW51LmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgJCgnPGRpdj4nLCB7IGNsYXNzOiAnZGl2aWRlcicgfSksXG4gICAgICAgICAgICAgICAgICAgICQoJzxkaXY+JywgeyBjbGFzczogJ2l0ZW0gc3R0LWRlbGV0ZS10cmFuc2NyaXB0LWZvcm0nIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgICAgICQoJzxidXR0b24+JywgeyB0eXBlOiAnYnV0dG9uJyB9KVxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIC50ZXh0KHRyYW5zbGF0ZSgnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0RlbGV0ZVRyYW5zY3JpcHQnKSlcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICBkZWxldGVUcmFuc2NyaXB0KHJlc3VsdElkLCBjYWxsSWQpO1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIH0pXG4gICAgICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICAgICApO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY29uc3QgbGFiZWwgPSB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0QWN0aW9ucycpO1xuICAgICAgICAgICAgY29uc3QgJGRldGFpbHMgPSAkKCc8ZGV0YWlscz4nLCB7IGNsYXNzOiAnc3R0LXRyYW5zY3JpcHQtYWN0aW9ucycgfSkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxzdW1tYXJ5PicsIHsgJ2FyaWEtbGFiZWwnOiBsYWJlbCwgdGl0bGU6IGxhYmVsIH0pLmFwcGVuZChcbiAgICAgICAgICAgICAgICAgICAgJCgnPGk+JywgeyBjbGFzczogJ2VsbGlwc2lzIGhvcml6b250YWwgaWNvbicgfSlcbiAgICAgICAgICAgICAgICApLFxuICAgICAgICAgICAgICAgICRtZW51XG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgJG1lbnUub24oJ2NsaWNrJywgJ2EsIGJ1dHRvbicsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAkZGV0YWlscy5yZW1vdmVBdHRyKCdvcGVuJyk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIHJldHVybiAkZGV0YWlscztcbiAgICAgICAgfVxuXG4gICAgICAgIC8vIFRoZSBcIlN1bW1hcml6YXRpb25cIiBmaWVsZDogb25lIGJsb2NrIHBlciB0cmFuc2NyaWJlZCBsZWcgb2YgdGhlXG4gICAgICAgIC8vIGNhbGwsIHByZXNlbnQgb25seSB3aGlsZSBzdW1tYXJpemF0aW9uIGlzIHR1cm5lZCBvbi5cbiAgICAgICAgZnVuY3Rpb24gcmVuZGVyU3VtbWFyaWVzKHN1bW1hcmllcykge1xuICAgICAgICAgICAgaWYgKCFBcnJheS5pc0FycmF5KHN1bW1hcmllcykgfHwgc3VtbWFyaWVzLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBudWxsO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY29uc3Qgc3RhdGVzID0ge1xuICAgICAgICAgICAgICAgIHBlbmRpbmc6ICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfU3VtbWFyeVBlbmRpbmcnLFxuICAgICAgICAgICAgICAgIHNraXBwZWQ6ICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfU3VtbWFyeVNraXBwZWQnLFxuICAgICAgICAgICAgICAgIGVycm9yOiAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1N1bW1hcnlGYWlsZWQnLFxuICAgICAgICAgICAgICAgIHVuYXZhaWxhYmxlOiAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1N1bW1hcnlVbmF2YWlsYWJsZScsXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgY29uc3QgJGxpc3QgPSAkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtc3VtbWFyeS1saXN0JyB9KTtcbiAgICAgICAgICAgIHN1bW1hcmllcy5mb3JFYWNoKGZ1bmN0aW9uIChjYXJkKSB7XG4gICAgICAgICAgICAgICAgY29uc3Qgc3RhdGUgPSBjYXJkICYmIHR5cGVvZiBjYXJkLnN0YXRlID09PSAnc3RyaW5nJyA/IGNhcmQuc3RhdGUgOiAndW5hdmFpbGFibGUnO1xuICAgICAgICAgICAgICAgIGNvbnN0ICRwYW5lbCA9ICQoJzxkaXY+JywgeyBjbGFzczogYHN0dC1zdW1tYXJ5LXBhbmVsIHN0dC1zdW1tYXJ5LSR7c3RhdGV9YCwgJ2RhdGEtc3R0LXN1bW1hcnktc3RhdGUnOiBzdGF0ZSB9KVxuICAgICAgICAgICAgICAgICAgICAuYXBwZW5kKCQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC1zdW1tYXJ5LWxhYmVsJyB9KVxuICAgICAgICAgICAgICAgICAgICAgICAgLmFwcGVuZCgkKCc8aT4nLCB7IGNsYXNzOiAnYWxpZ24gbGVmdCBpY29uJyB9KSlcbiAgICAgICAgICAgICAgICAgICAgICAgIC5hcHBlbmQoZG9jdW1lbnQuY3JlYXRlVGV4dE5vZGUodHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfU3VtbWFyaXphdGlvbicpKSkpO1xuICAgICAgICAgICAgICAgIGlmIChzdGF0ZSA9PT0gJ2RvbmUnKSB7XG4gICAgICAgICAgICAgICAgICAgIGlmIChjYXJkLnRvcGljKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAkcGFuZWwuYXBwZW5kKCQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC1zdW1tYXJ5LXRvcGljJywgZGlyOiAnYXV0bycgfSkudGV4dChTdHJpbmcoY2FyZC50b3BpYykpKTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICAkcGFuZWwuYXBwZW5kKCQoJzxkaXY+JywgeyBjbGFzczogJ3N0dC1zdW1tYXJ5LXRleHQnLCBkaXI6ICdhdXRvJyB9KS50ZXh0KFN0cmluZyhjYXJkLnN1bW1hcnkgfHwgJycpKSk7XG4gICAgICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgJHBhbmVsLmFwcGVuZCgkKCc8ZGl2PicsIHsgY2xhc3M6ICdzdHQtc3VtbWFyeS1zdGF0dXMnIH0pLnRleHQodHJhbnNsYXRlKHN0YXRlc1tzdGF0ZV0gfHwgc3RhdGVzLnVuYXZhaWxhYmxlKSkpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAkbGlzdC5hcHBlbmQoJHBhbmVsKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgcmV0dXJuICRsaXN0O1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVuZGVyQ29udmVyc2F0aW9uKHRyYW5zY3JpcHQsIHJvd0RhdGEsIGNhbGxJZCwgY2FuRGVsZXRlKSB7XG4gICAgICAgICAgICBjb25zdCBjb252ZXJzYXRpb24gPSB0cmFuc2NyaXB0ICYmIHR5cGVvZiB0cmFuc2NyaXB0LmNvbnZlcnNhdGlvbiA9PT0gJ29iamVjdCdcbiAgICAgICAgICAgICAgICA/IHRyYW5zY3JpcHQuY29udmVyc2F0aW9uXG4gICAgICAgICAgICAgICAgOiBudWxsO1xuICAgICAgICAgICAgY29uc3QgdGltZWxpbmUgPSBjb252ZXJzYXRpb24gJiYgQXJyYXkuaXNBcnJheShjb252ZXJzYXRpb24udGltZWxpbmUpID8gY29udmVyc2F0aW9uLnRpbWVsaW5lIDogW107XG4gICAgICAgICAgICBjb25zdCByZWNvcmRpbmdzID0gcm93RGF0YSAmJiBBcnJheS5pc0FycmF5KHJvd0RhdGFbNF0pID8gcm93RGF0YVs0XSA6IFtdO1xuICAgICAgICAgICAgY29uc3QgcGxheWJhY2tDZHJJZCA9IHRyYW5zY3JpcHQgJiYgdHJhbnNjcmlwdC5wbGF5YmFja0NkcklkICE9PSBudWxsXG4gICAgICAgICAgICAgICAgJiYgdHlwZW9mIHRyYW5zY3JpcHQucGxheWJhY2tDZHJJZCAhPT0gJ3VuZGVmaW5lZCdcbiAgICAgICAgICAgICAgICA/IFN0cmluZyh0cmFuc2NyaXB0LnBsYXliYWNrQ2RySWQpXG4gICAgICAgICAgICAgICAgOiAnJztcbiAgICAgICAgICAgIGxldCByZWNvcmRpbmcgPSB7fTtcbiAgICAgICAgICAgIHJlY29yZGluZ3Muc29tZShmdW5jdGlvbiAoaXRlbSkge1xuICAgICAgICAgICAgICAgIGlmIChwbGF5YmFja0NkcklkICE9PSAnJyAmJiBpdGVtICYmIFN0cmluZyhpdGVtLmlkKSA9PT0gcGxheWJhY2tDZHJJZCkge1xuICAgICAgICAgICAgICAgICAgICByZWNvcmRpbmcgPSBpdGVtO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBjbGVhckNvbnZlcnNhdGlvbigpO1xuICAgICAgICAgICAgY29uc3QgJHRhcmdldCA9ICRtb2RhbC5maW5kKCcuc3R0LWNkci10cmFuc2NyaXB0LWNvbnZlcnNhdGlvbicpLnJlbW92ZUF0dHIoJ2hpZGRlbicpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtbG9hZGluZycpLmF0dHIoJ2hpZGRlbicsIHRydWUpO1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY2RyLXRyYW5zY3JpcHQtZXJyb3InKS5hdHRyKCdoaWRkZW4nLCB0cnVlKS50ZXh0KCcnKTtcbiAgICAgICAgICAgICR0YXJnZXQuYXBwZW5kKHJlbmRlclN1bW1hcmllcyh0cmFuc2NyaXB0ID8gdHJhbnNjcmlwdC5zdW1tYXJpZXMgOiBudWxsKSk7XG4gICAgICAgICAgICBpZiAodGltZWxpbmUubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICAgICAgJHRhcmdldC5hcHBlbmQoJCgnPGRpdj4nLCB7IGNsYXNzOiAndWkgbWVzc2FnZScgfSkudGV4dChsYWJlbHMuZW1wdHkpKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoIXdpbmRvdy5TdHRUcmFuc2NyaXB0VmlldyB8fCB0eXBlb2Ygd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3LmNyZWF0ZSAhPT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgIHNob3dFcnJvcihsYWJlbHMubG9hZEVycm9yKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICAkdGFyZ2V0LmFwcGVuZCh3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcuY3JlYXRlKGNvbnZlcnNhdGlvbiwge1xuICAgICAgICAgICAgICAgIGFsbDogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeUFsbCcpLFxuICAgICAgICAgICAgICAgIHNlYXJjaDogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1NlYXJjaCcpLFxuICAgICAgICAgICAgICAgIGVtcHR5OiBsYWJlbHMuZW1wdHksXG4gICAgICAgICAgICAgICAgcGxheTogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfUGxheVJlY29yZGluZycpLFxuICAgICAgICAgICAgICAgIHNwZWVkOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9QbGF5YmFja1NwZWVkJyksXG4gICAgICAgICAgICAgICAgbG9hZGluZzogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9hZGluZ1JlY29yZGluZycpLFxuICAgICAgICAgICAgICAgIHVuYXZhaWxhYmxlOiB0cmFuc2xhdGUoJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9SZWNvcmRpbmdVbmF2YWlsYWJsZScpLFxuICAgICAgICAgICAgICAgIGZhaWxlZDogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfUGxheWJhY2tGYWlsZWQnKSxcbiAgICAgICAgICAgICAgICBkb3dubG9hZDogdHJhbnNsYXRlKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfRG93bmxvYWRSZWNvcmRpbmcnKSxcbiAgICAgICAgICAgICAgICBhdWRpb1VybDogU3RyaW5nKHJlY29yZGluZy5wbGF5YmFja191cmwgfHwgJycpLFxuICAgICAgICAgICAgICAgIGRvd25sb2FkVXJsOiBTdHJpbmcocmVjb3JkaW5nLmRvd25sb2FkX3VybCB8fCAnJyksXG4gICAgICAgICAgICAgICAgZHVyYXRpb25TZWNvbmRzOiBjb252ZXJzYXRpb24uZHVyYXRpb25TZWNvbmRzLFxuICAgICAgICAgICAgICAgIGFjdGlvbnM6IHRyYW5zY3JpcHQucmVzdWx0SWQgPyBjcmVhdGVBY3Rpb25zTWVudSh0cmFuc2NyaXB0LnJlc3VsdElkLCBjYWxsSWQsIGNhbkRlbGV0ZSkgOiBudWxsLFxuICAgICAgICAgICAgfSkpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gb3BlblRyYW5zY3JpcHQoZGF0YVRhYmxlLCAkYnV0dG9uKSB7XG4gICAgICAgICAgICBjb25zdCByb3dEYXRhID0gZGF0YVRhYmxlLnJvdygkYnV0dG9uLmNsb3Nlc3QoJ3RyJykpLmRhdGEoKTtcbiAgICAgICAgICAgIGNvbnN0IGNhbGxJZCA9IFN0cmluZygkYnV0dG9uLmF0dHIoJ2RhdGEtY2FsbC1pZCcpIHx8ICcnKS50cmltKCk7XG4gICAgICAgICAgICBpZiAoIXJvd0RhdGEgfHwgY2FsbElkID09PSAnJykge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgaWYgKGRldGFpbFJlcXVlc3QgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICBkZXRhaWxSZXF1ZXN0LmFib3J0KCk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZXNldE1vZGFsKHJvd0RhdGEpO1xuICAgICAgICAgICAgJG1vZGFsLm1vZGFsKCdzaG93Jyk7XG4gICAgICAgICAgICBkZXRhaWxSZXF1ZXN0ID0gJC5hamF4KHtcbiAgICAgICAgICAgICAgICB1cmw6IGRldGFpbFVybCxcbiAgICAgICAgICAgICAgICBtZXRob2Q6ICdHRVQnLFxuICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbicsXG4gICAgICAgICAgICAgICAgZGF0YTogeyBjYWxsX2lkOiBjYWxsSWQgfSxcbiAgICAgICAgICAgICAgICBiZWZvcmVTZW5kOiBhZGRBdXRob3JpemF0aW9uSGVhZGVyLFxuICAgICAgICAgICAgfSkuZG9uZShmdW5jdGlvbiAocmVzcG9uc2UpIHtcbiAgICAgICAgICAgICAgICBhcHBseVBhcnR5TGFiZWxzKHJlc3BvbnNlICYmIHJlc3BvbnNlLnRyYW5zY3JpcHQgPyByZXNwb25zZS50cmFuc2NyaXB0LnBhcnRpZXMgOiBudWxsKTtcbiAgICAgICAgICAgICAgICByZW5kZXJDb252ZXJzYXRpb24oXG4gICAgICAgICAgICAgICAgICAgIHJlc3BvbnNlICYmIHJlc3BvbnNlLnRyYW5zY3JpcHQgPyByZXNwb25zZS50cmFuc2NyaXB0IDogbnVsbCxcbiAgICAgICAgICAgICAgICAgICAgcm93RGF0YSxcbiAgICAgICAgICAgICAgICAgICAgY2FsbElkLFxuICAgICAgICAgICAgICAgICAgICBCb29sZWFuKHJlc3BvbnNlICYmIHJlc3BvbnNlLmNhbkRlbGV0ZVRyYW5zY3JpcHQpXG4gICAgICAgICAgICAgICAgKTtcbiAgICAgICAgICAgIH0pLmZhaWwoZnVuY3Rpb24gKHhociwgc3RhdHVzKSB7XG4gICAgICAgICAgICAgICAgaWYgKHN0YXR1cyA9PT0gJ2Fib3J0Jykge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmICh4aHIuc3RhdHVzID09PSA0MDQpIHtcbiAgICAgICAgICAgICAgICAgICAgZGVsZXRlIGF2YWlsYWJpbGl0eVtjYWxsSWRdO1xuICAgICAgICAgICAgICAgICAgICAkYnV0dG9uLnJlbW92ZSgpO1xuICAgICAgICAgICAgICAgICAgICBzaG93RXJyb3IobGFiZWxzLm5vdEZvdW5kKTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBpZiAoeGhyLnN0YXR1cyA9PT0gNDAxIHx8IHhoci5zdGF0dXMgPT09IDQwMykge1xuICAgICAgICAgICAgICAgICAgICBhY2Nlc3NEZW5pZWQgPSB0cnVlO1xuICAgICAgICAgICAgICAgICAgICByZW1vdmVCdXR0b25zKCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHNob3dFcnJvcihsYWJlbHMubG9hZEVycm9yKTtcbiAgICAgICAgICAgIH0pLmFsd2F5cyhmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgZGV0YWlsUmVxdWVzdCA9IG51bGw7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHN0YXJ0KGRhdGFUYWJsZSkge1xuICAgICAgICAgICAgaWYgKCR0YWJsZS5kYXRhKCdzdHQtY2RyLXRyYW5zY3JpcHQtc3RhcnRlZCcpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgJHRhYmxlLmRhdGEoJ3N0dC1jZHItdHJhbnNjcmlwdC1zdGFydGVkJywgdHJ1ZSk7XG4gICAgICAgICAgICAkbW9kYWwubW9kYWwoe1xuICAgICAgICAgICAgICAgIGNsb3NhYmxlOiB0cnVlLFxuICAgICAgICAgICAgICAgIG9uSGlkZGVuOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIGlmIChkZXRhaWxSZXF1ZXN0ICE9PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBkZXRhaWxSZXF1ZXN0LmFib3J0KCk7XG4gICAgICAgICAgICAgICAgICAgICAgICBkZXRhaWxSZXF1ZXN0ID0gbnVsbDtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBjbGVhckNvbnZlcnNhdGlvbigpO1xuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICQoZG9jdW1lbnQpLm9uKCdjbGljay5zdHRDZHJUcmFuc2NyaXB0QWN0aW9ucycsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgICAgICRtb2RhbC5maW5kKCcuc3R0LXRyYW5zY3JpcHQtYWN0aW9uc1tvcGVuXScpLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBpZiAoIXRoaXMuY29udGFpbnMoZXZlbnQudGFyZ2V0KSkge1xuICAgICAgICAgICAgICAgICAgICAgICAgJCh0aGlzKS5yZW1vdmVBdHRyKCdvcGVuJyk7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJHRhYmxlLm9uKCdkcmF3LmR0LnN0dENkclRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgcmVmcmVzaEJ1dHRvbnMoZGF0YVRhYmxlKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJHRhYmxlLm9uKCdjbGljay5zdHRDZHJUcmFuc2NyaXB0JywgJy5zdHQtY2RyLXRyYW5zY3JpcHQtYnV0dG9uJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICAgICAgZXZlbnQuc3RvcFByb3BhZ2F0aW9uKCk7XG4gICAgICAgICAgICAgICAgY29uc3QgJGJ1dHRvbiA9ICQodGhpcyk7XG4gICAgICAgICAgICAgICAgaWYgKGV2ZW50LmN0cmxLZXkgfHwgZXZlbnQubWV0YUtleSkge1xuICAgICAgICAgICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgICAgICAgICBjb25zdCBvcGVuZWRUYWIgPSB3aW5kb3cub3BlbigkYnV0dG9uLmF0dHIoJ2hyZWYnKSwgJ19ibGFuaycpO1xuICAgICAgICAgICAgICAgICAgICBpZiAob3BlbmVkVGFiICE9PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBvcGVuZWRUYWIub3BlbmVyID0gbnVsbDtcbiAgICAgICAgICAgICAgICAgICAgICAgIG9wZW5lZFRhYi5mb2N1cygpO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgICAgICBvcGVuVHJhbnNjcmlwdChkYXRhVGFibGUsICRidXR0b24pO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBzZXRUaW1lb3V0KGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICByZWZyZXNoQnV0dG9ucyhkYXRhVGFibGUpO1xuICAgICAgICAgICAgfSwgMCk7XG4gICAgICAgIH1cblxuICAgICAgICBpZiAoJC5mbi5EYXRhVGFibGUuaXNEYXRhVGFibGUoJHRhYmxlKSkge1xuICAgICAgICAgICAgc3RhcnQoJHRhYmxlLkRhdGFUYWJsZSgpKTtcbiAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICR0YWJsZS5vbmUoJ2luaXQuZHQuc3R0Q2RyVHJhbnNjcmlwdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBzdGFydCgkdGFibGUuRGF0YVRhYmxlKCkpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cbiAgICB9KTtcbn0oKSk7XG4iXX0=