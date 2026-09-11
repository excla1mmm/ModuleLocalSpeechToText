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
            const $header = $('<div>', { class: 'header' }).append(
                $('<i>', { class: 'comment alternate outline icon' }),
                document.createTextNode(labels.title)
            );
            const $loading = $('<div>', {
                class: 'stt-cdr-transcript-loading',
                role: 'status',
                'aria-live': 'polite',
            }).append(
                $('<div>', { class: 'ui active centered inline loader stt-cdr-transcript-loader' }),
                $('<div>').text(labels.loading)
            );
            const $callMeta = $('<div>', {
                class: 'stt-cdr-transcript-call-meta',
                hidden: true,
            }).append(
                $('<span>', { 'data-field': 'date' }),
                $('<span>', { 'data-field': 'source' }),
                $('<span>', { 'data-field': 'destination' }),
                $('<span>', { 'data-field': 'duration' })
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
                    $callMeta,
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

            const $button = $('<button>', {
                type: 'button',
                class: 'ui compact basic icon button stt-cdr-transcript-button',
                'aria-label': labels.button,
                title: labels.button,
                'data-content': labels.button,
            }).attr('data-call-id', row.callId).append(
                $('<i>', { class: 'comment alternate outline icon' })
            );
            if (row.hasActionColumn) {
                $target.prepend($button);
            } else {
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
            $meta.find('[data-field="duration"]').text(String(rowData[3] || ''));
        }

        function showError(message) {
            $modal.find('.stt-cdr-transcript-loading').attr('hidden', true);
            clearConversation();
            $modal.find('.stt-cdr-transcript-error').removeAttr('hidden').addClass('error').text(message);
        }

        function renderConversation(transcript, rowData) {
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
                download: translate('module_local_speech_to_text_DownloadRecording'),
                audioUrl: String(recording.playback_url || ''),
                downloadUrl: String(recording.download_url || ''),
                durationSeconds: conversation.durationSeconds,
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
                onHidden: function () {
                    if (detailRequest !== null) {
                        detailRequest.abort();
                        detailRequest = null;
                    }
                    clearConversation();
                },
            });
            $table.on('draw.dt.sttCdrTranscript', function () {
                refreshButtons(dataTable);
            });
            $table.on('click.sttCdrTranscript', '.stt-cdr-transcript-button', function (event) {
                event.preventDefault();
                event.stopPropagation();
                openTranscript(dataTable, $(this));
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
