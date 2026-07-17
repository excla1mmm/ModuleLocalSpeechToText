<div class="ui basic segment module-local-speech-to-text" id="module-local-speech-to-text-transcript">
    <div class="ui basic segment stt-action-buttons">
        {{ link_to(transcriptsPath, '<i class="file alternate outline icon"></i> '~t._('module_local_speech_to_text_Transcripts'), "class": "ui labeled icon button") }}
    </div>

    <div class="ui header">
        {{ t._('module_local_speech_to_text_Conversation') }}
        <div class="sub header">#{{ result.job_id|e }} - {{ result.STTJob.call_id|e }}</div>
    </div>

    <div class="stt-recording-panel">
        <div class="stt-recording-player">
            <div>
                <div class="stt-recording-title">
                    <i class="file audio outline icon"></i>
                    <span>{{ t._('module_local_speech_to_text_CallRecording') }}</span>
                </div>
            </div>
            <div class="stt-recording-actions">
                {% if recording['hasPlayback'] %}
                    <div class="stt-cdr-player" id="stt-transcript-player" data-duration="{{ recording['durationSeconds']|e }}">
                        <audio id="stt-transcript-audio" preload="none" data-src="{{ recording['playbackUrl']|e }}"></audio>
                        <button type="button" class="ui compact basic icon button stt-player-play" data-content="{{ t._('module_local_speech_to_text_PlayRecording') }}">
                            <i class="play icon"></i>
                        </button>
                        <div class="stt-player-range-wrap">
                            <div class="ui range cdr-player stt-player-range"></div>
                        </div>
                        <span class="stt-player-duration cdr-duration">00:00/{% if recording['durationLabel'] !== '' %}{{ recording['durationLabel']|e }}{% else %}--:--{% endif %}</span>
                        {% if recording['downloadUrl'] !== '' %}
                            <a class="ui compact basic icon button stt-player-download" href="{{ recording['downloadUrl']|e }}" data-content="{{ t._('module_local_speech_to_text_DownloadRecording') }}">
                                <i class="download icon"></i>
                            </a>
                        {% endif %}
                    </div>
                {% else %}
                    <span class="ui small grey label">{{ t._('module_local_speech_to_text_RecordingPlaybackUnavailable') }}</span>
                {% endif %}
            </div>
        </div>

        <div class="stt-recording-details">
            {% for row in recording['rows'] %}
                <div class="stt-recording-detail">
                    <div class="stt-recording-detail-label">{{ row['label']|e }}</div>
                    <div class="stt-recording-detail-value">{{ row['value']|e }}</div>
                </div>
            {% endfor %}
        </div>
    </div>

    {% if conversation['hasSegments'] %}
        <div class="stt-dialogue-board">
            <div class="stt-dialogue-headers">
                {% for speaker in conversation['speakers'] %}
                    <div class="stt-dialogue-header stt-speaker-{{ speaker['key']|e }}">
                        <span class="stt-speaker-dot"></span>
                        {{ speaker['label']|e }}
                    </div>
                {% endfor %}
            </div>

            <div class="stt-dialogue-timeline">
                {% for turn in conversation['timeline'] %}
                    <div class="stt-dialogue-row {% if turn['speakerKey'] === 'right' %}stt-dialogue-row-right{% else %}stt-dialogue-row-left{% endif %}">
                        <div class="stt-dialogue-cell stt-dialogue-cell-left">
                            {% if turn['speakerKey'] === 'right' %}
                            {% else %}
                                <div class="stt-dialogue-turn stt-turn-left{% if recording['hasPlayback'] %} stt-playable-turn{% endif %}" data-start="{{ turn['start']|e }}" data-end="{{ turn['end']|e }}"{% if recording['hasPlayback'] %} tabindex="0"{% endif %}>
                                    <div class="stt-turn-rail">
                                        <span class="stt-turn-line"></span>
                                        <span class="stt-turn-time">{{ turn['endLabel']|e }}</span>
                                    </div>
                                    <div class="stt-turn-card">
                                        <div class="stt-turn-meta">
                                            <span>{{ turn['rangeLabel']|e }}</span>
                                            <span>{{ turn['speakerLabel']|e }}</span>
                                        </div>
                                        <div class="stt-turn-text" dir="auto">{{ turn['text']|e }}</div>
                                    </div>
                                </div>
                            {% endif %}
                        </div>

                        <div class="stt-dialogue-cell stt-dialogue-cell-right">
                            {% if turn['speakerKey'] === 'right' %}
                                <div class="stt-dialogue-turn stt-turn-right{% if recording['hasPlayback'] %} stt-playable-turn{% endif %}" data-start="{{ turn['start']|e }}" data-end="{{ turn['end']|e }}"{% if recording['hasPlayback'] %} tabindex="0"{% endif %}>
                                    <div class="stt-turn-rail">
                                        <span class="stt-turn-line"></span>
                                        <span class="stt-turn-time">{{ turn['endLabel']|e }}</span>
                                    </div>
                                    <div class="stt-turn-card">
                                        <div class="stt-turn-meta">
                                            <span>{{ turn['rangeLabel']|e }}</span>
                                            <span>{{ turn['speakerLabel']|e }}</span>
                                        </div>
                                        <div class="stt-turn-text" dir="auto">{{ turn['text']|e }}</div>
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

    {% if transcriptJson !== '' %}
        <details class="stt-transcript-json">
            <summary>{{ t._('module_local_speech_to_text_TranscriptJson') }}</summary>
            <pre>{{ transcriptJson|e }}</pre>
        </details>
    {% endif %}
</div>
