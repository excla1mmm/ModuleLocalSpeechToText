<div class="ui basic segment module-local-speech-to-text" id="module-local-speech-to-text-transcript">
    <div class="ui basic segment stt-action-buttons">
        {{ link_to(transcriptsPath, '<i class="file alternate outline icon"></i> '~t._('module_local_speech_to_text_Transcripts'), "class": "ui labeled icon button") }}
    </div>

    <div class="ui header">
        {{ t._('module_local_speech_to_text_Conversation') }}
        <div class="sub header">#{{ result.job_id|e }} - {{ result.STTJob.call_id|e }}</div>
    </div>

    {% include "Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/_summary_panel.volt" %}

    {% if showCallLegTabs %}
        <div class="stt-call-leg-review">
            <div class="ui secondary pointing menu stt-call-leg-tabs" role="tablist">
                <button type="button" class="active item" role="tab" aria-selected="true" data-stt-call-leg="customer">
                    <i class="comments outline icon"></i>
                    {{ t._('module_local_speech_to_text_CustomerConversationTab') }}
                </button>
                <button type="button" class="item" role="tab" aria-selected="false" data-stt-call-leg="internal">
                    <i class="users icon"></i>
                    {{ t._('module_local_speech_to_text_InternalConsultationTab') }}
                </button>
            </div>

            <div role="tabpanel" data-stt-call-leg-panel="customer">
                {% for stage in transcriptGroups['customer'] %}
                    {% include "Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/_transcript_stage.volt" %}
                {% endfor %}
            </div>
            <div role="tabpanel" data-stt-call-leg-panel="internal" hidden>
                {% for stage in transcriptGroups['internal'] %}
                    {% include "Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/_transcript_stage.volt" %}
                {% endfor %}
            </div>
        </div>
    {% else %}
        {% for stage in transcriptStages %}
            {% include "Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/_transcript_stage.volt" %}
        {% endfor %}
    {% endif %}
</div>

{% if canDeleteTranscript %}
    <div class="ui tiny modal" id="stt-delete-transcript-modal" role="dialog" aria-modal="true" aria-labelledby="stt-delete-transcript-title" style="display:none">
        <div class="header" id="stt-delete-transcript-title">{{ t._('module_local_speech_to_text_DeleteTranscript') }}</div>
        <div class="content">
            <ul class="ui list">
                <li>{{ t._('module_local_speech_to_text_DeleteTranscriptConsequence') }}</li>
                <li>{{ t._('module_local_speech_to_text_DeleteTranscriptKeepsCdr') }}</li>
                <li>{{ t._('module_local_speech_to_text_DeleteTranscriptBackupWarning') }}</li>
            </ul>
            <div class="ui checkbox">
                <input type="checkbox" id="stt-delete-transcript-acknowledge">
                <label for="stt-delete-transcript-acknowledge">{{ t._('module_local_speech_to_text_DeleteTranscriptConfirm') }}</label>
            </div>
        </div>
        <div class="actions">
            <button type="button" class="ui cancel basic button">{{ t._('module_local_speech_to_text_ConfirmCancel') }}</button>
            <button type="button" class="ui red approve button disabled" disabled>{{ t._('module_local_speech_to_text_ConfirmDelete') }}</button>
        </div>
    </div>
{% endif %}
