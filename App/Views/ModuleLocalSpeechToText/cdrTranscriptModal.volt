<div class="ui large modal module-local-speech-to-text stt-cdr-transcript-modal"
     id="stt-cdr-transcript-modal"
     data-label-button="{{ t._('module_local_speech_to_text_CdrTranscriptButton')|e }}"
     data-label-load-error="{{ t._('module_local_speech_to_text_CdrTranscriptLoadError')|e }}"
     data-label-not-found="{{ t._('module_local_speech_to_text_CdrTranscriptNotFound')|e }}"
     data-label-empty="{{ t._('module_local_speech_to_text_NoConversationSegments')|e }}">
    <i class="close icon"></i>
    <div class="header stt-cdr-transcript-header">
        <span class="stt-cdr-transcript-header-icon" aria-hidden="true"><i class="file alternate outline icon"></i></span>
        <div class="stt-cdr-transcript-header-text">
            <div class="stt-cdr-transcript-title">{{ t._('module_local_speech_to_text_CdrTranscriptModalTitle') }}</div>
            <div class="stt-cdr-transcript-call-meta" hidden>
                <span data-field="date"></span>
                <span class="stt-cdr-transcript-parties"><span data-field="source"></span><span data-field="destination"></span></span>
            </div>
        </div>
    </div>
    <div class="content">
        <div class="stt-cdr-transcript-loading" role="status" aria-live="polite">
            <div class="ui active centered inline loader stt-cdr-transcript-loader"></div>
            <div>{{ t._('module_local_speech_to_text_CdrTranscriptLoading') }}</div>
        </div>
        <div class="ui message stt-cdr-transcript-error"
             role="alert"
             aria-live="assertive"
             hidden></div>
        <div class="stt-cdr-transcript-conversation" hidden></div>
    </div>
</div>
