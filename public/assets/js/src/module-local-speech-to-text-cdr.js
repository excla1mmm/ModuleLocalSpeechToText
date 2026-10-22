(function () {
    'use strict';

    const MAX_LOOKUP_BATCH = 100;

    $(function () {
        function translate(key) {
            if (typeof globalTranslate !== 'undefined' && globalTranslate && globalTranslate[key]) {
                return String(globalTranslate[key]);
            }
            return key;
        }

        function ensureModal() {
            let $modal = $('#stt-cdr-transcript-modal');
            if ($modal.length > 0) {
                return $modal;
            }

            const labels = {
                button: translate('module_local_speech_to_text_CdrTranscriptButton'),
                title: translate('module_local_speech_to_text_CdrTranscriptModalTitle'),
                loading: translate('module_local_speech_to_text_CdrTranscriptLoading'),
                loadError: translate('module_local_speech_to_text_CdrTranscriptLoadError'),
                notFound: translate('module_local_speech_to_text_CdrTranscriptNotFound'),
                empty: translate('module_local_speech_to_text_NoConversationSegments'),
            };
            const $callMeta = $('<div>', {
                class: 'stt-cdr-transcript-call-meta',
                hidden: true,
            }).append(
                $('<span>', { 'data-field': 'date' }),
                $('<span>', { class: 'stt-cdr-transcript-parties' }).append(
                    $('<span>', { 'data-field': 'source' }),
                    $('<span>', { 'data-field': 'destination' })
                )
            );
            const $header = $('<div>', { class: 'header stt-cdr-transcript-header' }).append(
                $('<span>', { class: 'stt-cdr-transcript-header-icon', 'aria-hidden': 'true' }).append(
                    $('<i>', { class: 'file alternate outline icon' })
                ),
                $('<div>', { class: 'stt-cdr-transcript-header-text' }).append(
                    $('<div>', { class: 'stt-cdr-transcript-title' }).text(labels.title),
                    $callMeta
                )
            );
            const $loading = $('<div>', {
                class: 'stt-cdr-transcript-loading',
                role: 'status',
                'aria-live': 'polite',
            }).append(
                $('<div>', { class: 'ui active centered inline loader stt-cdr-transcript-loader' }),
                $('<div>').text(labels.loading)
            );
            $modal = $('<div>', {
                class: 'ui large modal module-local-speech-to-text stt-cdr-transcript-modal',
                id: 'stt-cdr-transcript-modal',
                'data-label-button': labels.button,
                'data-label-load-error': labels.loadError,
                'data-label-not-found': labels.notFound,
                'data-label-empty': labels.empty,
            }).append(
                $('<i>', { class: 'close icon' }),
                $header,
                $('<div>', { class: 'content' }).append(
                    $loading,
                    $('<div>', {
                        class: 'ui message stt-cdr-transcript-error',
                        role: 'alert',
                        'aria-live': 'assertive',
                        hidden: true,
                    }),
                    $('<div>', { class: 'stt-cdr-transcript-conversation', hidden: true })
                )
            );
            $('body').append($modal);
            return $modal;
        }

        const $table = $('#cdr-table');
        if ($table.length === 0) {
            return;
        }
        const $modal = ensureModal();
        if ($modal.length === 0 || typeof $.fn.DataTable === 'undefined') {
            return;
        }

        const labels = {
            button: String($modal.data('label-button') || ''),
            loadError: String($modal.data('label-load-error') || ''),
            notFound: String($modal.data('label-not-found') || ''),
            empty: String($modal.data('label-empty') || ''),
        };
        const rootUrl = typeof globalRootUrl === 'string' ? globalRootUrl : '/admin-cabinet/';
        const lookupUrl = `${rootUrl}module-local-speech-to-text/cdr-transcript/lookup`;
        const detailUrl = `${rootUrl}module-local-speech-to-text/cdr-transcript/detail`;
        const transcriptUrl = `${rootUrl}module-local-speech-to-text/module-local-speech-to-text/transcript`;
        const exportUrl = `${rootUrl}module-local-speech-to-text/transcript-actions/export`;
        const deleteUrl = `${rootUrl}module-local-speech-to-text/transcript-actions/delete`;
        const availability = Object.create(null);
        const pending = Object.create(null);
        let accessDenied = false;
        let detailRequest = null;

        function addAuthorizationHeader(xhr) {
            if (typeof TokenManager !== 'undefined' && TokenManager.accessToken) {
                xhr.setRequestHeader('Authorization', `Bearer ${TokenManager.accessToken}`);
            }
        }

        function currentRows(dataTable) {
            const rows = [];
            document.querySelectorAll('#cdr-table tbody tr[id]').forEach(function (node) {
                const callId = String(node.id || '').trim();
                if (callId === '' || callId.length > 255) {
                    return;
                }
                const cells = node.querySelectorAll(':scope > td');
                if (cells.length < 2) {
                    return;
                }
                rows.push({
                    node: $(node),
                    data: dataTable.row(node).data(),
                    callId,
                    actionsCell: $(cells[cells.length - 1]),
                    hasActionColumn: cells.length > 5,
                });
            });
            return rows;
        }

        function removeButtons() {
            $table.find('.stt-cdr-transcript-button').remove();
        }

        function addButton(row) {
            const $target = row.actionsCell;
            if ($target.length === 0 || $target.find('.stt-cdr-transcript-button').length > 0) {
                return;
            }

            const $button = $('<a>', {
                class: 'stt-cdr-transcript-button',
                href: `${transcriptUrl}?id=${encodeURIComponent(String(availability[row.callId]))}`,
                target: '_blank',
                rel: 'noopener',
                'aria-label': labels.button,
                title: labels.button,
                'data-content': labels.button,
            }).attr('data-call-id', row.callId);
            $button[0].innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">'
                + '<path d="M4 13v-2M8 16V8M12 18V6M16 16V8M20 13v-2" /></svg>';
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
                data: JSON.stringify({ call_ids: batch }),
                beforeSend: addAuthorizationHeader,
            }).done(function (response) {
                const transcriptMap = response && response.transcriptMap && typeof response.transcriptMap === 'object'
                    ? response.transcriptMap
                    : {};
                batch.forEach(function (callId) {
                    if (
                        Object.prototype.hasOwnProperty.call(transcriptMap, callId)
                        && Number(transcriptMap[callId]) > 0
                    ) {
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
            const rows = currentRows(dataTable);
            renderButtons(rows);
            const unseen = [];
            rows.forEach(function (row) {
                if (
                    !Object.prototype.hasOwnProperty.call(availability, row.callId)
                    && !pending[row.callId]
                    && unseen.indexOf(row.callId) === -1
                ) {
                    unseen.push(row.callId);
                }
            });
            for (let offset = 0; offset < unseen.length; offset += MAX_LOOKUP_BATCH) {
                lookupBatch(unseen.slice(offset, offset + MAX_LOOKUP_BATCH), dataTable);
            }
        }

        function stopModalPlayback() {
            if (window.SttTranscriptView && typeof window.SttTranscriptView.dispose === 'function') {
                window.SttTranscriptView.dispose($modal.find('.stt-cdr-transcript-conversation'));
            }
            const audio = $modal.find('.stt-transcript-audio').get(0);
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
            const $meta = $modal.find('.stt-cdr-transcript-call-meta').removeAttr('hidden');
            $meta.find('[data-field="date"]').text(String(rowData[0] || ''));
            $meta.find('[data-field="source"]').text(String(rowData[1] || ''));
            $meta.find('[data-field="destination"]').text(String(rowData[2] || ''));
        }

        // The history row only ever holds bare numbers, and for an incoming
        // call with a DID it does not even hold the extension the transcript
        // is about. The detail response labels the call's own parties, so it
        // replaces those numbers once it arrives.
        function applyPartyLabels(parties) {
            if (!parties || typeof parties !== 'object') {
                return;
            }
            const $meta = $modal.find('.stt-cdr-transcript-call-meta');
            ['source', 'destination'].forEach(function (field) {
                const label = String(parties[field] || '');
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
            let $confirm = $('#stt-cdr-delete-transcript-modal');
            if ($confirm.length > 0) {
                return $confirm;
            }
            const $list = $('<ul>', { class: 'ui list' });
            [
                'module_local_speech_to_text_DeleteTranscriptConsequence',
                'module_local_speech_to_text_DeleteTranscriptKeepsCdr',
                'module_local_speech_to_text_DeleteTranscriptBackupWarning',
            ].forEach(function (key) {
                $list.append($('<li>').text(translate(key)));
            });
            $confirm = $('<div>', {
                class: 'ui tiny modal',
                id: 'stt-cdr-delete-transcript-modal',
                role: 'dialog',
                'aria-modal': 'true',
                'aria-labelledby': 'stt-cdr-delete-transcript-title',
            }).append(
                $('<div>', { class: 'header', id: 'stt-cdr-delete-transcript-title' })
                    .text(translate('module_local_speech_to_text_DeleteTranscript')),
                $('<div>', { class: 'content' }).append(
                    $list,
                    $('<div>', { class: 'ui checkbox' }).append(
                        $('<input>', { type: 'checkbox', id: 'stt-cdr-delete-transcript-acknowledge' }),
                        $('<label>', { for: 'stt-cdr-delete-transcript-acknowledge' })
                            .text(translate('module_local_speech_to_text_DeleteTranscriptConfirm'))
                    )
                ),
                $('<div>', { class: 'actions' }).append(
                    $('<button>', { type: 'button', class: 'ui cancel basic button' })
                        .text(translate('module_local_speech_to_text_ConfirmCancel')),
                    $('<button>', { type: 'button', class: 'ui red approve button disabled', disabled: true })
                        .text(translate('module_local_speech_to_text_ConfirmDelete'))
                )
            );
            $('body').append($confirm);
            $confirm.find('#stt-cdr-delete-transcript-acknowledge').on('change', function () {
                const checked = $(this).prop('checked');
                $confirm.find('.approve.button').toggleClass('disabled', !checked).prop('disabled', !checked);
            });
            return $confirm;
        }

        function deleteTranscript(resultId, callId) {
            const $confirm = ensureDeleteModal();
            const $approve = $confirm.find('.approve.button');
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
                        data: { result_id: resultId, confirm_delete: '1' },
                        beforeSend: addAuthorizationHeader,
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
                        showMessages({ error: [translate('module_local_speech_to_text_TranscriptDeleteFailed')] });
                    }).always(function () {
                        $approve.removeClass('loading');
                    });
                    return false;
                },
            }).modal('show');
        }

        function createActionsMenu(resultId, callId, canDelete) {
            const $menu = $('<div>', { class: 'ui vertical menu' });
            ['txt', 'json'].forEach(function (format) {
                $menu.append($('<a>', {
                    class: 'item',
                    href: `${exportUrl}?id=${encodeURIComponent(String(resultId))}&format=${format}`,
                }).text(translate(format === 'txt'
                    ? 'module_local_speech_to_text_DownloadTranscriptTxt'
                    : 'module_local_speech_to_text_DownloadTranscriptJson')));
            });
            if (canDelete) {
                $menu.append(
                    $('<div>', { class: 'divider' }),
                    $('<div>', { class: 'item stt-delete-transcript-form' }).append(
                        $('<button>', { type: 'button' })
                            .text(translate('module_local_speech_to_text_DeleteTranscript'))
                            .on('click', function () {
                                deleteTranscript(resultId, callId);
                            })
                    )
                );
            }
            const label = translate('module_local_speech_to_text_TranscriptActions');
            const $details = $('<details>', { class: 'stt-transcript-actions' }).append(
                $('<summary>', { 'aria-label': label, title: label }).append(
                    $('<i>', { class: 'ellipsis horizontal icon' })
                ),
                $menu
            );
            $menu.on('click', 'a, button', function () {
                $details.removeAttr('open');
            });
            return $details;
        }

        // The "Summarization" field: one block per transcribed leg of the
        // call, present only while summarization is turned on.
        function renderSummaries(summaries) {
            if (!Array.isArray(summaries) || summaries.length === 0) {
                return null;
            }
            const states = {
                pending: 'module_local_speech_to_text_SummaryPending',
                skipped: 'module_local_speech_to_text_SummarySkipped',
                error: 'module_local_speech_to_text_SummaryFailed',
                unavailable: 'module_local_speech_to_text_SummaryUnavailable',
            };
            const $list = $('<div>', { class: 'stt-summary-list' });
            summaries.forEach(function (card) {
                const state = card && typeof card.state === 'string' ? card.state : 'unavailable';
                const $panel = $('<div>', { class: `stt-summary-panel stt-summary-${state}`, 'data-stt-summary-state': state })
                    .append($('<div>', { class: 'stt-summary-label' })
                        .append($('<i>', { class: 'align left icon' }))
                        .append(document.createTextNode(translate('module_local_speech_to_text_Summarization'))));
                if (state === 'done') {
                    if (card.topic) {
                        $panel.append($('<div>', { class: 'stt-summary-topic', dir: 'auto' }).text(String(card.topic)));
                    }
                    $panel.append($('<div>', { class: 'stt-summary-text', dir: 'auto' }).text(String(card.summary || '')));
                } else {
                    $panel.append($('<div>', { class: 'stt-summary-status' }).text(translate(states[state] || states.unavailable)));
                }
                $list.append($panel);
            });
            return $list;
        }

        function renderConversation(transcript, rowData, callId, canDelete) {
            const conversation = transcript && typeof transcript.conversation === 'object'
                ? transcript.conversation
                : null;
            const timeline = conversation && Array.isArray(conversation.timeline) ? conversation.timeline : [];
            const recordings = rowData && Array.isArray(rowData[4]) ? rowData[4] : [];
            const playbackCdrId = transcript && transcript.playbackCdrId !== null
                && typeof transcript.playbackCdrId !== 'undefined'
                ? String(transcript.playbackCdrId)
                : '';
            let recording = {};
            recordings.some(function (item) {
                if (playbackCdrId !== '' && item && String(item.id) === playbackCdrId) {
                    recording = item;
                    return true;
                }
                return false;
            });
            clearConversation();
            const $target = $modal.find('.stt-cdr-transcript-conversation').removeAttr('hidden');
            $modal.find('.stt-cdr-transcript-loading').attr('hidden', true);
            $modal.find('.stt-cdr-transcript-error').attr('hidden', true).text('');
            $target.append(renderSummaries(transcript ? transcript.summaries : null));
            if (timeline.length === 0) {
                $target.append($('<div>', { class: 'ui message' }).text(labels.empty));
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
                actions: transcript.resultId ? createActionsMenu(transcript.resultId, callId, canDelete) : null,
            }));
        }

        function openTranscript(dataTable, $button) {
            const rowData = dataTable.row($button.closest('tr')).data();
            const callId = String($button.attr('data-call-id') || '').trim();
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
                data: { call_id: callId },
                beforeSend: addAuthorizationHeader,
            }).done(function (response) {
                applyPartyLabels(response && response.transcript ? response.transcript.parties : null);
                renderConversation(
                    response && response.transcript ? response.transcript : null,
                    rowData,
                    callId,
                    Boolean(response && response.canDeleteTranscript)
                );
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
                },
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
                const $button = $(this);
                if (event.ctrlKey || event.metaKey) {
                    event.preventDefault();
                    const openedTab = window.open($button.attr('href'), '_blank');
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
}());
