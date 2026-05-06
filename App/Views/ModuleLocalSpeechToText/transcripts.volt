<div class="ui basic segment module-local-speech-to-text" id="module-local-speech-to-text-transcripts">
    <div class="ui basic segment stt-action-buttons">
        {{ link_to(dashboardPath, '<i class="dashboard icon"></i> '~t._('module_local_speech_to_text_Dashboard'), "class": "ui labeled icon button") }}
    </div>

    <form class="ui form stt-transcripts-filter-form" id="stt-transcripts-filter-form" method="get" action="{{ url(transcriptFilterAction) }}">
        {% if transcriptFilterKeepTab %}
            <input type="hidden" name="tab" value="transcripts">
        {% endif %}
        <input type="hidden" name="date_from" value="{{ transcriptFilter['dateFrom']|e }}">
        <input type="hidden" name="date_to" value="{{ transcriptFilter['dateTo']|e }}">
        <div class="ui stackable grid">
            <div class="five wide column">
                <label>{{ t._('module_local_speech_to_text_TranscriptDateRange') }}</label>
                <div class="ui fluid input left icon">
                    <i class="calendar alternate outline icon"></i>
                    <input type="text" id="stt-transcripts-date-range-selector" value="{{ transcriptFilter['displayRange']|e }}" autocomplete="off">
                </div>
            </div>
        </div>
    </form>

    <table class="ui selectable compact celled striped table stt-transcripts-table">
        <thead>
            <tr>
                <th>{{ t._('module_local_speech_to_text_CallDate') }}</th>
                <th>{{ t._('module_local_speech_to_text_CallId') }}</th>
                <th>{{ t._('module_local_speech_to_text_RecordingFile') }}</th>
                <th>{{ t._('module_local_speech_to_text_Language') }}</th>
                <th>{{ t._('module_local_speech_to_text_Duration') }}</th>
                <th>{{ t._('module_local_speech_to_text_Model') }}</th>
                <th>{{ t._('module_local_speech_to_text_TranscriptCreatedAt') }}</th>
                <th>{{ t._('module_local_speech_to_text_Conversation') }}</th>
            </tr>
        </thead>
        <tbody>
        {% set currentDateKey = '' %}
        {% for row in transcriptRows %}
            {% set result = row['result'] %}
            {% if row['dateKey'] !== currentDateKey %}
                <tr class="stt-date-group-row">
                    <td colspan="8">
                        <i class="calendar alternate outline icon"></i>{{ row['dateGroupLabel']|e }}
                    </td>
                </tr>
                {% set currentDateKey = row['dateKey'] %}
            {% endif %}
            <tr>
                <td class="stt-call-date-cell">
                    <div class="stt-call-date">{{ row['callDateLabel']|e }}</div>
                    {% if row['callTimeLabel'] !== '' %}
                        <div class="stt-table-muted">{{ row['callTimeLabel']|e }}</div>
                    {% endif %}
                </td>
                <td>
                    <div>{{ row['callId']|e }}</div>
                    <div class="stt-table-muted">#{{ result.job_id|e }}</div>
                </td>
                <td><span class="stt-recording-file">{{ row['recordingName']|e }}</span></td>
                <td>{{ result.language|e }}</td>
                <td>{{ result.duration|e }}</td>
                <td><span class="stt-model-text">{{ result.model_used|e }}</span></td>
                <td>{{ row['createdAtLabel']|e }}</td>
                <td class="right aligned">
                    <a href="{{ url(transcriptPath~'?id='~result.id) }}" class="ui compact icon button stt-open-conversation" title="{{ t._('module_local_speech_to_text_OpenConversation') }}">
                        <i class="eye icon"></i>
                    </a>
                </td>
            </tr>
        {% else %}
            <tr>
                <td colspan="8" class="center aligned disabled">{{ t._('module_local_speech_to_text_NoTranscripts') }}</td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
</div>
