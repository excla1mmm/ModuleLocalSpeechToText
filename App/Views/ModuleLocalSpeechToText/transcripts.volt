<div class="ui basic segment module-local-speech-to-text" id="module-local-speech-to-text-transcripts">
    <div class="ui basic segment stt-action-buttons">
        {{ link_to(dashboardPath, '<i class="dashboard icon"></i> '~t._('module_local_speech_to_text_Dashboard'), "class": "ui labeled icon button") }}
    </div>

    <table class="ui selectable compact celled striped table stt-transcripts-table">
        <thead>
            <tr>
                <th>{{ t._('module_local_speech_to_text_Job') }}</th>
                <th>{{ t._('module_local_speech_to_text_CallId') }}</th>
                <th>{{ t._('module_local_speech_to_text_Language') }}</th>
                <th>{{ t._('module_local_speech_to_text_Duration') }}</th>
                <th>{{ t._('module_local_speech_to_text_Model') }}</th>
                <th>{{ t._('module_local_speech_to_text_CreatedAt') }}</th>
                <th>{{ t._('module_local_speech_to_text_Conversation') }}</th>
            </tr>
        </thead>
        <tbody>
        {% for result in results %}
            <tr class="stt-clickable-row" onclick="window.location.href='{{ url(transcriptPath~'?id='~result.id) }}'">
                <td>#{{ result.job_id }}</td>
                <td>{{ result.STTJob.call_id }}</td>
                <td>{{ result.language }}</td>
                <td>{{ result.duration }}</td>
                <td><span class="stt-model-text">{{ result.model_used }}</span></td>
                <td>{{ date('Y-m-d H:i:s', result.created_at) }}</td>
                <td class="right aligned">
                    <a href="{{ url(transcriptPath~'?id='~result.id) }}" class="ui compact icon button" onclick="event.stopPropagation();" title="{{ t._('module_local_speech_to_text_OpenConversation') }}">
                        <i class="eye icon"></i>
                    </a>
                </td>
            </tr>
        {% else %}
            <tr>
                <td colspan="7" class="center aligned disabled">{{ t._('module_local_speech_to_text_NoTranscripts') }}</td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
</div>
