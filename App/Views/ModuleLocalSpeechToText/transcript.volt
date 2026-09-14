<div class="ui basic segment module-local-speech-to-text" id="module-local-speech-to-text-transcript">
    <div class="ui basic segment stt-action-buttons">
        {{ link_to(transcriptsPath, '<i class="file alternate outline icon"></i> '~t._('module_local_speech_to_text_Transcripts'), "class": "ui labeled icon button") }}
    </div>

    <div class="ui header">
        {{ t._('module_local_speech_to_text_Conversation') }}
        <div class="sub header">#{{ result.job_id|e }} - {{ result.STTJob.call_id|e }}</div>
    </div>

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
