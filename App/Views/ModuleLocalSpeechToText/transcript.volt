<div class="ui basic segment module-local-speech-to-text" id="module-local-speech-to-text-transcript">
    <div class="ui basic segment stt-action-buttons">
        {{ link_to(transcriptsPath, '<i class="file alternate outline icon"></i> '~t._('module_local_speech_to_text_Transcripts'), "class": "ui labeled icon button") }}
    </div>

    <div class="ui header">
        {{ t._('module_local_speech_to_text_Conversation') }}
        <div class="sub header">#{{ result.job_id|e }} - {{ result.STTJob.call_id|e }}</div>
    </div>

    <div class="stt-recording-panel">
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
        <div class="stt-transcript-view">
            <div class="stt-transcript-overview">
                <div class="stt-transcript-overview-header">
                    {% if recording['hasPlayback'] %}
                        <div class="stt-transcript-player" data-duration="{{ recording['durationSeconds']|e }}">
                            <audio class="stt-transcript-audio" preload="metadata" data-src="{{ recording['playbackUrl']|e }}"></audio>
                            <button type="button" class="stt-player-play" aria-label="{{ t._('module_local_speech_to_text_PlayRecording')|e }}" data-content="{{ t._('module_local_speech_to_text_PlayRecording')|e }}">
                                <i class="play icon"></i>
                            </button>
                            <span class="stt-player-duration">00:00 / {% if recording['durationLabel'] !== '' %}{{ recording['durationLabel']|e }}{% else %}{{ conversation['durationLabel']|e }}{% endif %}</span>
                            {% if recording['downloadUrl'] !== '' %}
                                <a class="stt-player-download" href="{{ recording['downloadUrl']|e }}" data-content="{{ t._('module_local_speech_to_text_DownloadRecording')|e }}">
                                    <i class="download icon"></i>
                                </a>
                            {% endif %}
                        </div>
                    {% else %}
                        <span class="stt-transcript-duration">{{ conversation['durationLabel']|e }}</span>
                    {% endif %}
                    <div class="stt-transcript-legend">
                        {% for speaker in conversation['speakers'] %}
                            <span class="stt-transcript-legend-item stt-speaker-{{ speaker['key']|e }}">
                                <span class="stt-speaker-dot"></span>
                                {{ speaker['label']|e }}
                            </span>
                        {% endfor %}
                    </div>
                </div>

                <div class="stt-transcript-seek{% if recording['hasPlayback'] %} is-playable{% endif %}">
                    <div class="stt-transcript-lanes">
                        {% for speaker in conversation['speakers'] %}
                            <div class="stt-transcript-lane stt-speaker-{{ speaker['key']|e }}">
                                {% for turn in speaker['segments'] %}
                                    <span class="stt-transcript-lane-segment" style="left: {{ turn['startPercent']|e }}%; width: {{ turn['widthPercent']|e }}%"></span>
                                {% endfor %}
                            </div>
                        {% endfor %}
                    </div>
                    {% if recording['hasPlayback'] %}
                        <span class="stt-transcript-playhead"></span>
                        <span class="stt-transcript-seek-tooltip">0:00</span>
                        <input class="stt-transcript-range" type="range" min="0" max="1000" value="0" aria-label="{{ t._('module_local_speech_to_text_PlayRecording')|e }}">
                    {% endif %}
                </div>
                <div class="stt-transcript-scale">
                    <span>0:00</span>
                    <span>{{ conversation['durationLabel']|e }}</span>
                </div>
            </div>

            <div class="stt-transcript-toolbar">
                <label class="stt-transcript-search">
                    <i class="search icon"></i>
                    <input type="search" placeholder="{{ t._('module_local_speech_to_text_LogsSearch')|e }}" aria-label="{{ t._('module_local_speech_to_text_LogsSearch')|e }}">
                </label>
                <div class="stt-transcript-filters" role="group">
                    <button type="button" class="stt-transcript-filter active" data-speaker="all" aria-pressed="true">{{ t._('module_local_speech_to_text_CdrHistoryAll') }}</button>
                    {% for speaker in conversation['speakers'] %}
                        <button type="button" class="stt-transcript-filter" data-speaker="{{ speaker['key']|e }}" aria-pressed="false">{{ speaker['label']|e }}</button>
                    {% endfor %}
                </div>
                <span class="stt-transcript-count" aria-live="polite">{{ conversation['turnCount']|e }}</span>
            </div>

            <div class="stt-transcript-list">
                {% for turn in conversation['timeline'] %}
                    {% if turn['gapBeforeLabel'] !== '' %}
                        <div class="stt-transcript-gap"><span>··· {{ turn['gapBeforeLabel']|e }}</span></div>
                    {% endif %}
                    <article class="stt-transcript-turn stt-turn-{{ turn['speakerKey']|e }}{% if recording['hasPlayback'] %} stt-playable-turn{% endif %}"
                             data-speaker="{{ turn['speakerKey']|e }}"
                             data-start="{{ turn['start']|e }}"
                             data-end="{{ turn['end']|e }}"{% if recording['hasPlayback'] %} tabindex="0"{% endif %}>
                        <time class="stt-turn-time">{{ turn['startLabel']|e }}</time>
                        <div class="stt-turn-content">
                            <div class="stt-turn-meta">
                                <span class="stt-speaker-dot"></span>
                                <strong class="stt-turn-speaker">{{ turn['speakerLabel']|e }}</strong>
                                <span class="stt-turn-duration">{{ turn['durationLabel']|e }}</span>
                            </div>
                            <div class="stt-turn-text" dir="auto">{{ turn['text']|e }}</div>
                        </div>
                    </article>
                {% endfor %}
            </div>
            <div class="stt-transcript-empty ui message" hidden>{{ t._('module_local_speech_to_text_NoConversationSegments') }}</div>
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
