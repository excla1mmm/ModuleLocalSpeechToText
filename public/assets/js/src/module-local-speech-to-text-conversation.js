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
        var $legend = $('<div>', { class: 'stt-transcript-legend' });

        speakers.forEach(function (speaker) {
            $legend.append(
                $('<span>', { class: 'stt-transcript-legend-item stt-speaker-' + speakerClass(speaker.key) }).append(
                    $('<span>', { class: 'stt-speaker-dot' }),
                    document.createTextNode(String(speaker.label || ''))
                )
            );
        });

        return $legend;
    }

    function createOverview(conversation) {
        var speakers = Array.isArray(conversation.speakers) ? conversation.speakers : [];
        var $overview = $('<div>', { class: 'stt-transcript-overview' });
        var $overviewHeader = $('<div>', { class: 'stt-transcript-overview-header' }).append(
            $('<span>', { class: 'stt-transcript-duration' }).text(String(conversation.durationLabel || '0:00')),
            createLegend(speakers)
        );
        var $lanes = $('<div>', { class: 'stt-transcript-lanes' });

        speakers.forEach(function (speaker) {
            var key = speakerClass(speaker.key);
            var $lane = $('<div>', { class: 'stt-transcript-lane stt-speaker-' + key });
            var segments = Array.isArray(speaker.segments) ? speaker.segments : [];

            segments.forEach(function (turn) {
                $lane.append($('<span>', { class: 'stt-transcript-lane-segment' }).css({
                    left: safeNumber(turn.startPercent) + '%',
                    width: safeNumber(turn.widthPercent) + '%'
                }));
            });
            $lanes.append($lane);
        });

        return $overview.append(
            $overviewHeader,
            $lanes,
            $('<div>', { class: 'stt-transcript-scale' }).append(
                $('<span>').text('0:00'),
                $('<span>').text(String(conversation.durationLabel || '0:00'))
            )
        );
    }

    function createToolbar(speakers, labels) {
        var $filters = $('<div>', { class: 'stt-transcript-filters', role: 'group' });

        $filters.append($('<button>', {
            type: 'button',
            class: 'stt-transcript-filter active',
            'data-speaker': 'all',
            'aria-pressed': 'true'
        }).text(labels.all));
        speakers.forEach(function (speaker) {
            $filters.append($('<button>', {
                type: 'button',
                class: 'stt-transcript-filter',
                'data-speaker': speakerClass(speaker.key),
                'aria-pressed': 'false'
            }).text(String(speaker.label || '')));
        });

        return $('<div>', { class: 'stt-transcript-toolbar' }).append(
            $('<label>', { class: 'stt-transcript-search' }).append(
                $('<i>', { class: 'search icon' }),
                $('<input>', {
                    type: 'search',
                    placeholder: labels.search,
                    'aria-label': labels.search
                })
            ),
            $filters,
            $('<span>', { class: 'stt-transcript-count', 'aria-live': 'polite' })
        );
    }

    function createTurn(turn) {
        var key = speakerClass(turn.speakerKey);
        var $row = $('<article>', {
            class: 'stt-transcript-turn stt-turn-' + key,
            'data-speaker': key,
            'data-start': safeNumber(turn.start),
            'data-end': safeNumber(turn.end)
        });
        var $meta = $('<div>', { class: 'stt-turn-meta' }).append(
            $('<span>', { class: 'stt-speaker-dot' }),
            $('<strong>', { class: 'stt-turn-speaker' }).text(String(turn.speakerLabel || '')),
            $('<span>', { class: 'stt-turn-duration' }).text(String(turn.durationLabel || ''))
        );

        if (turn.overlap) {
            $meta.append($('<span>', { class: 'stt-turn-overlap', 'aria-hidden': 'true' }).text('∥'));
        }

        return $row.append(
            $('<time>', { class: 'stt-turn-time' }).text(String(turn.startLabel || '')),
            $('<div>', { class: 'stt-turn-content' }).append(
                $meta,
                $('<div>', { class: 'stt-turn-text', dir: 'auto' }).text(String(turn.text || ''))
            )
        );
    }

    function create(conversation, labels) {
        var data = conversation && typeof conversation === 'object' ? conversation : {};
        var speakers = Array.isArray(data.speakers) ? data.speakers : [];
        var timeline = Array.isArray(data.timeline) ? data.timeline : [];
        var text = Object.assign({ all: 'All', search: 'Search', empty: '' }, labels || {});
        var $root = $('<div>', { class: 'stt-transcript-view' });
        var $list = $('<div>', { class: 'stt-transcript-list' });

        timeline.forEach(function (turn) {
            if (!turn || typeof turn !== 'object') {
                return;
            }
            if (turn.gapBeforeLabel) {
                $list.append(
                    $('<div>', { class: 'stt-transcript-gap' }).append(
                        $('<span>').text('··· ' + String(turn.gapBeforeLabel))
                    )
                );
            }
            $list.append(createTurn(turn));
        });

        $root.append(
            createOverview(data),
            createToolbar(speakers, text),
            $list,
            $('<div>', { class: 'stt-transcript-empty ui message', hidden: true }).text(text.empty)
        );
        initialize($root);

        return $root;
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
}(window, jQuery));
