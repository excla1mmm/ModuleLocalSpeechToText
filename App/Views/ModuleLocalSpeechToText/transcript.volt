<div class="ui basic segment module-local-speech-to-text" id="module-local-speech-to-text-transcript">
    <div class="ui basic segment stt-action-buttons">
        {{ link_to(transcriptsPath, '<i class="file alternate outline icon"></i> '~t._('module_local_speech_to_text_Transcripts'), "class": "ui labeled icon button") }}
    </div>

    <div class="ui header">
        {{ t._('module_local_speech_to_text_Conversation') }}
        <div class="sub header">#{{ result.job_id }} - {{ result.STTJob.call_id }}</div>
    </div>

    <table class="ui very compact definition table stt-conversation-summary">
        <tbody>
        <tr>
            <td class="three wide">{{ t._('module_local_speech_to_text_Language') }}</td>
            <td>{{ result.language }}</td>
            <td class="three wide">{{ t._('module_local_speech_to_text_Duration') }}</td>
            <td>{{ result.duration }}</td>
            <td class="three wide">{{ t._('module_local_speech_to_text_CreatedAt') }}</td>
            <td>{{ date('Y-m-d H:i:s', result.created_at) }}</td>
        </tr>
        <tr>
            <td>{{ t._('module_local_speech_to_text_Model') }}</td>
            <td colspan="5"><span class="stt-model-text">{{ result.model_used }}</span></td>
        </tr>
        </tbody>
    </table>

    {% if conversation['hasSegments'] %}
        <div class="stt-dialogue-board">
            <div class="stt-dialogue-headers">
                {% for speaker in conversation['speakers'] %}
                    <div class="stt-dialogue-header stt-speaker-{{ speaker['key'] }}">
                        <span class="stt-speaker-dot"></span>
                        {{ speaker['label'] }}
                    </div>
                {% endfor %}
            </div>

            <div class="stt-dialogue-timeline">
                {% for turn in conversation['timeline'] %}
                    <div class="stt-dialogue-row {% if turn['speakerKey'] === 'right' %}stt-dialogue-row-right{% else %}stt-dialogue-row-left{% endif %}">
                        <div class="stt-dialogue-cell stt-dialogue-cell-left">
                            {% if turn['speakerKey'] === 'right' %}
                            {% else %}
                                <div class="stt-dialogue-turn stt-turn-left">
                                    <div class="stt-turn-rail">
                                        <span class="stt-turn-line"></span>
                                        <span class="stt-turn-time">{{ turn['startLabel'] }}</span>
                                    </div>
                                    <div class="stt-turn-card">
                                        <div class="stt-turn-meta">
                                            <span>{{ turn['rangeLabel'] }}</span>
                                            <span>{{ turn['speakerLabel'] }}</span>
                                        </div>
                                        <div class="stt-turn-text" dir="auto">{{ turn['text'] }}</div>
                                    </div>
                                </div>
                            {% endif %}
                        </div>

                        <div class="stt-dialogue-cell stt-dialogue-cell-right">
                            {% if turn['speakerKey'] === 'right' %}
                                <div class="stt-dialogue-turn stt-turn-right">
                                    <div class="stt-turn-rail">
                                        <span class="stt-turn-line"></span>
                                        <span class="stt-turn-time">{{ turn['startLabel'] }}</span>
                                    </div>
                                    <div class="stt-turn-card">
                                        <div class="stt-turn-meta">
                                            <span>{{ turn['rangeLabel'] }}</span>
                                            <span>{{ turn['speakerLabel'] }}</span>
                                        </div>
                                        <div class="stt-turn-text" dir="auto">{{ turn['text'] }}</div>
                                    </div>
                                </div>
                            {% endif %}
                        </div>
                    </div>
                {% endfor %}
            </div>
        </div>
    {% else %}
        <div class="ui message">{{ t._('module_local_speech_to_text_NoConversationSegments') }}</div>
    {% endif %}
</div>
