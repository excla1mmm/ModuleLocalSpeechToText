(function () {
    'use strict';

    const MAX_LOOKUP_BATCH = 100;

    $(function () {
        const $table = $('#cdr-table');
        const $modal = $('#stt-cdr-transcript-modal');
        if ($table.length === 0 || $modal.length === 0 || typeof $.fn.DataTable === 'undefined') {
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
            dataTable.rows({ page: 'current' }).every(function () {
                const data = this.data();
                const callId = data && typeof data.DT_RowId === 'string' ? data.DT_RowId.trim() : '';
                const node = this.node();
                if (callId !== '' && node) {
                    rows.push({ node: $(node), data, callId });
                }
            });
            return rows;
        }

        function removeButtons() {
            $table.find('.stt-cdr-transcript-button').remove();
        }

        function addButton(row) {
            const $cells = row.node.children('td');
            const hasActionColumn = $cells.length > 5;
            const $target = hasActionColumn ? $cells.eq(5) : $cells.eq(4);
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
            if (hasActionColumn) {
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
                method: 'GET',
                dataType: 'json',
                data: { call_ids: JSON.stringify(batch) },
                beforeSend: addAuthorizationHeader,
            }).done(function (response) {
                const transcriptMap = response && response.transcriptMap && typeof response.transcriptMap === 'object'
                    ? response.transcriptMap
                    : {};
                batch.forEach(function (callId) {
                    availability[callId] = 0;
                    if (
                        Object.prototype.hasOwnProperty.call(transcriptMap, callId)
                        && Number(transcriptMap[callId]) > 0
                    ) {
                        availability[callId] = Number(transcriptMap[callId]);
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

        function resetModal(rowData) {
            $modal.find('.stt-cdr-transcript-loading').removeAttr('hidden');
            $modal.find('.stt-cdr-transcript-error').attr('hidden', true).removeClass('error').text('');
            $modal.find('.stt-cdr-transcript-conversation').attr('hidden', true).empty();
            const $meta = $modal.find('.stt-cdr-transcript-call-meta').removeAttr('hidden');
            $meta.find('[data-field="date"]').text(String(rowData[0] || ''));
            $meta.find('[data-field="source"]').text(String(rowData[1] || ''));
            $meta.find('[data-field="destination"]').text(String(rowData[2] || ''));
            $meta.find('[data-field="duration"]').text(String(rowData[3] || ''));
        }

        function showError(message) {
            $modal.find('.stt-cdr-transcript-loading').attr('hidden', true);
            $modal.find('.stt-cdr-transcript-conversation').attr('hidden', true).empty();
            $modal.find('.stt-cdr-transcript-error').removeAttr('hidden').addClass('error').text(message);
        }

        function dialogueHeader(speaker) {
            const isRight = speaker && speaker.key === 'right';
            return $('<div>', {
                class: `stt-dialogue-header ${isRight ? 'stt-speaker-right' : 'stt-speaker-left'}`,
            }).append(
                $('<span>', { class: 'stt-speaker-dot' }),
                document.createTextNode(String(speaker && speaker.label ? speaker.label : ''))
            );
        }

        function dialogueTurn(turn) {
            const isRight = turn && turn.speakerKey === 'right';
            const $row = $('<div>', {
                class: `stt-dialogue-row ${isRight ? 'stt-dialogue-row-right' : 'stt-dialogue-row-left'}`,
            });
            const $left = $('<div>', { class: 'stt-dialogue-cell stt-dialogue-cell-left' });
            const $right = $('<div>', { class: 'stt-dialogue-cell stt-dialogue-cell-right' });
            const $bubble = $('<div>', {
                class: `stt-dialogue-turn ${isRight ? 'stt-turn-right' : 'stt-turn-left'}`,
            });
            const $rail = $('<div>', { class: 'stt-turn-rail' }).append(
                $('<span>', { class: 'stt-turn-line' }),
                $('<span>', { class: 'stt-turn-time' }).text(String(turn.endLabel || ''))
            );
            const $card = $('<div>', { class: 'stt-turn-card' });
            const $meta = $('<div>', { class: 'stt-turn-meta' }).append(
                $('<span>').text(String(turn.rangeLabel || '')),
                $('<span>').text(String(turn.speakerLabel || ''))
            );
            $card.append($meta, $('<div>', { class: 'stt-turn-text', dir: 'auto' }).text(String(turn.text || '')));
            $bubble.append($rail, $card);
            if (isRight) {
                $right.append($bubble);
            } else {
                $left.append($bubble);
            }
            return $row.append($left, $right);
        }

        function renderConversation(conversation) {
            const speakers = conversation && Array.isArray(conversation.speakers) ? conversation.speakers : [];
            const timeline = conversation && Array.isArray(conversation.timeline) ? conversation.timeline : [];
            const $target = $modal.find('.stt-cdr-transcript-conversation').empty().removeAttr('hidden');
            $modal.find('.stt-cdr-transcript-loading').attr('hidden', true);
            $modal.find('.stt-cdr-transcript-error').attr('hidden', true).text('');
            if (timeline.length === 0) {
                $target.append($('<div>', { class: 'ui message' }).text(labels.empty));
                return;
            }

            const $board = $('<div>', { class: 'stt-dialogue-board' });
            const $headers = $('<div>', { class: 'stt-dialogue-headers' });
            speakers.forEach(function (speaker) {
                $headers.append(dialogueHeader(speaker));
            });
            const $timeline = $('<div>', { class: 'stt-dialogue-timeline' });
            timeline.forEach(function (turn) {
                if (turn && typeof turn === 'object') {
                    $timeline.append(dialogueTurn(turn));
                }
            });
            $target.append($board.append($headers, $timeline));
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
                renderConversation(response && response.transcript ? response.transcript.conversation : null);
            }).fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                if (xhr.status === 404) {
                    availability[callId] = 0;
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
            refreshButtons(dataTable);
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
