{% if summarizationEnabled %}
    <div class="stt-summary-panel stt-summary-{{ summaryCard['state']|e }}" data-stt-summary-state="{{ summaryCard['state']|e }}">
        <div class="stt-summary-label"><i class="align left icon"></i>{{ t._('module_local_speech_to_text_Summarization') }}</div>
        {% if summaryCard['state'] === 'done' %}
            {% if summaryCard['topic'] !== '' %}<div class="stt-summary-topic" dir="auto">{{ summaryCard['topic']|e }}</div>{% endif %}
            <div class="stt-summary-text" dir="auto">{{ summaryCard['summary']|e }}</div>
        {% elseif summaryCard['state'] === 'pending' %}
            <div class="stt-summary-status">{{ t._('module_local_speech_to_text_SummaryPending') }}</div>
        {% elseif summaryCard['state'] === 'skipped' %}
            <div class="stt-summary-status">{{ t._('module_local_speech_to_text_SummarySkipped') }}</div>
        {% elseif summaryCard['state'] === 'error' %}
            <div class="stt-summary-status">{{ t._('module_local_speech_to_text_SummaryFailed') }}</div>
        {% else %}
            <div class="stt-summary-status">{{ t._('module_local_speech_to_text_SummaryUnavailable') }}</div>
        {% endif %}
    </div>
{% endif %}
