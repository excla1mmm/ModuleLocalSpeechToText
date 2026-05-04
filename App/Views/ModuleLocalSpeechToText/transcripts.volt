<div class="module-local-speech-to-text">
    <div class="ui clearing basic segment stt-header">
        <h2 class="ui left floated header">
            <img src="{{ logoImagePath }}" alt="">
            <div class="content">
                {{ t._('module_local_speech_to_text_Transcripts') }}
                <div class="sub header">{{ t._('module_local_speech_to_text_TranscriptsSubHeader') }}</div>
            </div>
        </h2>
        {{ link_to(dashboardPath, '<i class="dashboard icon"></i> '~t._('module_local_speech_to_text_Dashboard'), "class": "ui right floated button") }}
    </div>

    <table class="ui very compact celled table stt-transcripts-table">
        <thead>
            <tr>
                <th>{{ t._('module_local_speech_to_text_Job') }}</th>
                <th>{{ t._('module_local_speech_to_text_CallId') }}</th>
                <th>{{ t._('module_local_speech_to_text_Language') }}</th>
                <th>{{ t._('module_local_speech_to_text_Duration') }}</th>
                <th>{{ t._('module_local_speech_to_text_Model') }}</th>
                <th>{{ t._('module_local_speech_to_text_CreatedAt') }}</th>
                <th>{{ t._('module_local_speech_to_text_Transcript') }}</th>
            </tr>
        </thead>
        <tbody>
        {% for result in results %}
            <tr>
                <td>#{{ result.job_id }}</td>
                <td>{{ result.STTJob.call_id }}</td>
                <td>{{ result.language }}</td>
                <td>{{ result.duration }}</td>
                <td>{{ result.model_used }}</td>
                <td>{{ date('Y-m-d H:i:s', result.created_at) }}</td>
                <td><pre class="stt-transcript-json">{{ result.transcript }}</pre></td>
            </tr>
        {% else %}
            <tr>
                <td colspan="7" class="center aligned">{{ t._('module_local_speech_to_text_NoTranscripts') }}</td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
</div>
