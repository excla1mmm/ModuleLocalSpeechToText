<div class="module-local-speech-to-text">
    <div class="ui clearing basic segment stt-header">
        <h2 class="ui left floated header">
            <img src="{{ logoImagePath }}" alt="">
            <div class="content">
                {{ t._('module_local_speech_to_text_Conversation') }}
                <div class="sub header">#{{ result.job_id }} · {{ result.STTJob.call_id }}</div>
            </div>
        </h2>
        {{ link_to(transcriptsPath, '<i class="file alternate outline icon"></i> '~t._('module_local_speech_to_text_Transcripts'), "class": "ui right floated button") }}
    </div>

    <div class="ui tiny statistics stt-conversation-meta">
        <div class="statistic">
            <div class="value">{{ result.language }}</div>
            <div class="label">{{ t._('module_local_speech_to_text_Language') }}</div>
        </div>
        <div class="statistic">
            <div class="value">{{ result.duration }}</div>
            <div class="label">{{ t._('module_local_speech_to_text_Duration') }}</div>
        </div>
        <div class="statistic">
            <div class="value">{{ date('H:i', result.created_at) }}</div>
            <div class="label">{{ t._('module_local_speech_to_text_CreatedAt') }}</div>
        </div>
    </div>

    {% if conversation['hasSegments'] %}
        <div class="stt-speaker-legend">
            {% for speaker in conversation['speakers'] %}
                <div class="stt-speaker-pill stt-speaker-{{ speaker['key'] }}">
                    <span class="stt-speaker-dot"></span>
                    {{ speaker['label'] }}
                </div>
            {% endfor %}
        </div>

        <div class="stt-conversation-timeline">
            {% for turn in conversation['timeline'] %}
                <article class="stt-turn stt-turn-{{ turn['speakerKey'] }}">
                    <div class="stt-turn-marker">
                        <span>{{ turn['startLabel'] }}</span>
                    </div>
                    <div class="stt-turn-bubble">
                        <div class="stt-turn-header">
                            <span>{{ turn['speakerLabel'] }}</span>
                            <time>{{ turn['rangeLabel'] }}</time>
                        </div>
                        <div class="stt-turn-text">{{ turn['text'] }}</div>
                    </div>
                </article>
            {% endfor %}
        </div>
    {% else %}
        <div class="ui message">{{ t._('module_local_speech_to_text_NoConversationSegments') }}</div>
    {% endif %}
</div>
