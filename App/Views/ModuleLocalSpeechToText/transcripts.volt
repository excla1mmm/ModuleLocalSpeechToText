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

    <div class="stt-transcripts-table-wrap">
        <table class="ui selectable table stt-transcripts-table">
            <thead>
                <tr>
                    <th aria-label="{{ t._('module_local_speech_to_text_Direction') }}"></th>
                    <th>{{ t._('module_local_speech_to_text_CallDate') }}</th>
                    <th>{{ t._('module_local_speech_to_text_Client') }}</th>
                    <th>{{ t._('module_local_speech_to_text_Employees') }}</th>
                    <th>{{ t._('module_local_speech_to_text_Duration') }}</th>
                    <th>{{ t._('module_local_speech_to_text_UpdatedAt') }}</th>
                </tr>
            </thead>
            <tbody>
            {% for row in transcriptRows %}
                {% set result = row['result'] %}
                <tr>
                    <td class="stt-direction-cell">
                        <a href="{{ url(transcriptPath~'?id='~result.id) }}" class="stt-direction-link" title="{{ row['directionLabel']|e }}" aria-label="{{ t._('module_local_speech_to_text_OpenConversation') }}: {{ row['directionLabel']|e }}">
                            {{ row['directionIcon']|e }}
                        </a>
                    </td>
                    <td class="stt-call-date-cell">
                        <div class="stt-transcript-primary">{{ row['callDateLabel']|e }}</div>
                        {% if row['callTimeLabel'] !== '' %}
                            <div class="stt-table-muted">{{ row['callTimeLabel']|e }}</div>
                        {% endif %}
                    </td>
                    <td>
                        <div class="stt-transcript-primary">{{ row['clientLabel']|e }}</div>
                        <div class="stt-table-muted">{{ row['directionLabel']|e }}</div>
                    </td>
                    <td><div class="stt-transcript-primary">{{ row['employeesLabel']|e }}</div></td>
                    <td><div class="stt-duration-value">{{ row['callDurationLabel']|e }}</div></td>
                    <td>
                        <div class="stt-updated-value">{{ row['createdDateLabel']|e }}</div>
                        {% if row['createdTimeLabel'] !== '' %}
                            <div class="stt-table-muted">{{ row['createdTimeLabel']|e }}</div>
                        {% endif %}
                    </td>
                </tr>
            {% else %}
                <tr>
                    <td colspan="6" class="center aligned disabled">{{ t._('module_local_speech_to_text_NoTranscripts') }}</td>
                </tr>
            {% endfor %}
            </tbody>
        </table>
    </div>
</div>
