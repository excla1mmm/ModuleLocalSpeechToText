<div class="ui basic segment module-local-speech-to-text" id="module-local-speech-to-text">
    <div class="ui top attached tabular menu" id="module-local-speech-to-text-menu">
        <a class="item{% if activeTab === 'settings' %} active{% endif %}" data-tab="settings">{{ t._('module_local_speech_to_text_SettingsTab') }}</a>
        <a class="item{% if activeTab === 'models' %} active{% endif %}" data-tab="models">{{ t._('module_local_speech_to_text_ModelsMarketplaceTab') }}</a>
        <a class="item{% if activeTab === 'queue' %} active{% endif %}" data-tab="queue">{{ t._('module_local_speech_to_text_QueueTab') }}</a>
        <a class="item{% if activeTab === 'workers' %} active{% endif %}" data-tab="workers">{{ t._('module_local_speech_to_text_WorkersTab') }}</a>
        <a class="item{% if activeTab === 'transcripts' %} active{% endif %}" data-tab="transcripts">{{ t._('module_local_speech_to_text_Transcripts') }}</a>
        <a class="item{% if activeTab === 'logs' %} active{% endif %}" data-tab="logs">{{ t._('module_local_speech_to_text_LogsTab') }}</a>
    </div>

    <div class="ui bottom attached tab segment{% if activeTab === 'settings' %} active{% endif %}" data-tab="settings">
        <form class="ui form large" id="module-local-speech-to-text-form" method="post" enctype="multipart/form-data" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/save') }}">
            <div class="three fields stt-settings-fields">
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_DefaultLanguage') }}</label>
                    {{ form.render('default_language') }}
                </div>
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_PollInterval') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_PollIntervalHint') }}" data-variation="wide"></i></label>
                    {{ form.render('poll_interval_sec') }}
                </div>
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_MaxRecordingDuration') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_MaxRecordingDurationHint') }}" data-variation="wide"></i></label>
                    {{ form.render('max_recording_duration_min') }}
                </div>
            </div>
            <div class="two fields stt-settings-fields">
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_CdrScanLimit') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_CdrScanLimitHint') }}" data-variation="wide"></i></label>
                    {{ form.render('cdr_scan_limit') }}
                </div>
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_ProcessingTimeout') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_ProcessingTimeoutHint') }}" data-variation="wide"></i></label>
                    {{ form.render('processing_timeout_sec') }}
                </div>
            </div>
            <div class="field">
                <label>{{ t._('module_local_speech_to_text_CdrHistoryDays') }}</label>
                <div class="ui segment stt-cdr-history-slider">
                    <div class="ui bottom aligned ticked labeled slider" id="stt-cdr-history-days-slider"></div>
                    {{ form.render('cdr_history_days') }}
                </div>
                <div class="stt-table-muted">{{ t._('module_local_speech_to_text_CdrHistoryDaysHint') }}</div>
            </div>
            <div class="field">
                <label>{{ t._('module_local_speech_to_text_TranscriptRetentionDays') }}</label>
                <div class="ui segment stt-cdr-history-slider stt-transcript-retention-slider">
                    <div class="ui bottom aligned ticked labeled slider" id="stt-transcript-retention-days-slider"></div>
                    {{ form.render('transcript_retention_days') }}
                </div>
                <div class="stt-table-muted">{{ t._('module_local_speech_to_text_TranscriptRetentionDaysHint') }}</div>
                <div class="ui negative hidden message stt-retention-conflict-message" role="alert" aria-hidden="true">
                    {{ t._('module_local_speech_to_text_TranscriptRetentionConflict') }}
                </div>
            </div>
            <div class="ui fluid styled accordion stt-advanced-settings-dropdown" hidden>
                <div class="title">
                    <i class="dropdown icon"></i>{{ t._('module_local_speech_to_text_AdvancedSettings') }}
                </div>
                <div class="content">
                    <div class="ui warning message stt-processing-settings-hint">
                        <i class="exclamation triangle icon"></i>
                        <div class="content">{{ t._('module_local_speech_to_text_ProcessingSettingsHint') }}</div>
                    </div>
                    <h4 class="ui dividing header">{{ t._('module_local_speech_to_text_DecodingSettings') }}</h4>
                    <div class="three fields">
                        <div class="field"><label>{{ t._('module_local_speech_to_text_Temperature') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_TemperatureHint') }}" data-variation="wide"></i></label>{{ form.render('temperature') }}</div>
                        <div class="field"><label>{{ t._('module_local_speech_to_text_TemperatureFallbackStep') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_TemperatureFallbackStepHint') }}" data-variation="wide"></i></label>{{ form.render('temperature_fallback_step') }}</div>
                        <div class="field"><label>{{ t._('module_local_speech_to_text_TemperatureFallbackCount') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_TemperatureFallbackCountHint') }}" data-variation="wide"></i></label>{{ form.render('temperature_fallback_count') }}</div>
                    </div>
                    <div class="three fields">
                        <div class="field"><label>{{ t._('module_local_speech_to_text_TopK') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_TopKHint') }}" data-variation="wide"></i></label>{{ form.render('top_k') }}</div>
                        <div class="field"><label>{{ t._('module_local_speech_to_text_CompressionRatioThreshold') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_CompressionRatioThresholdHint') }}" data-variation="wide"></i></label>{{ form.render('compression_ratio_threshold') }}</div>
                        <div class="field"><label>{{ t._('module_local_speech_to_text_LogProbabilityThreshold') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_LogProbabilityThresholdHint') }}" data-variation="wide"></i></label>{{ form.render('log_probability_threshold') }}</div>
                    </div>
                    <div class="two fields">
                        <div class="field"><label>{{ t._('module_local_speech_to_text_FirstTokenLogProbabilityThreshold') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_FirstTokenLogProbabilityThresholdHint') }}" data-variation="wide"></i></label>{{ form.render('first_token_log_probability_threshold') }}</div>
                        <div class="field"><label>{{ t._('module_local_speech_to_text_NoSpeechThreshold') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_NoSpeechThresholdHint') }}" data-variation="wide"></i></label>{{ form.render('no_speech_threshold') }}</div>
                    </div>
                    <h4 class="ui dividing header">{{ t._('module_local_speech_to_text_AudioProcessingSettings') }}</h4>
                    <div class="two fields">
                        <div class="field">
                            <div class="ui toggle checkbox">
                                {{ form.render('audio_normalization') }}
                                <label>{{ t._('module_local_speech_to_text_AudioNormalization') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_AudioNormalizationHint') }}" data-variation="wide"></i></label>
                            </div>
                        </div>
                        <div class="field">
                            <div class="ui toggle checkbox">
                                {{ form.render('voice_activity_detection') }}
                                <label>{{ t._('module_local_speech_to_text_VoiceActivityDetection') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_VoiceActivityDetectionHint') }}" data-variation="wide"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="two fields stt-vad-dependent-fields">
                        <div class="field stt-vad-dependent" data-profile-field="max_segment_duration_ms">
                            <label>{{ t._('module_local_speech_to_text_MaxSegmentDuration') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_MaxSegmentDurationHint') }}" data-variation="wide"></i></label>
                            {{ form.render('max_segment_duration_sec') }}
                        </div>
                        <div class="field stt-vad-dependent" data-profile-field="segment_overlap_ms">
                            <label>{{ t._('module_local_speech_to_text_SegmentOverlap') }} <i class="small info circle icon field-info-icon" data-content="{{ t._('module_local_speech_to_text_SegmentOverlapHint') }}" data-variation="wide"></i></label>
                            {{ form.render('segment_overlap_sec') }}
                        </div>
                    </div>
                </div>
            </div>
            <h4 class="ui dividing header">{{ t._('module_local_speech_to_text_VocabularyTermsTitle') }}</h4>
            <div class="ui info message stt-vocabulary-summary">
                <i class="info circle icon"></i>
                <div class="content">
                    {{ t._('module_local_speech_to_text_VocabularyTermsHint') }}
                    <strong id="stt-vocabulary-terms-count">{{ selectedVocabularyTermsCount|e }}</strong>
                </div>
            </div>
            <div class="field stt-vocabulary-terms-field">
                <div class="stt-vocabulary-actions">
                    <label class="ui basic labeled icon button stt-vocabulary-upload-button" for="stt-vocabulary-terms-file">
                        <i class="file alternate outline icon"></i>{{ t._('module_local_speech_to_text_UploadVocabularyTxt') }}
                    </label>
                    <input class="stt-vocabulary-file-input" type="file" id="stt-vocabulary-terms-file" name="vocabulary_terms_file" accept=".txt,text/plain">
                    <button class="ui basic labeled icon button stt-vocabulary-template-button" type="button" id="stt-vocabulary-template-download">
                        <i class="download icon"></i>{{ t._('module_local_speech_to_text_DownloadVocabularyTemplate') }}
                    </button>
                    <span class="stt-vocabulary-file-name" id="stt-vocabulary-file-name"></span>
                </div>
                {{ form.render('custom_vocabulary_terms') }}
                <div class="stt-table-muted">{{ t._('module_local_speech_to_text_CustomVocabularyTermsHint') }}</div>
            </div>
            <div class="ui clearing basic segment stt-form-actions">
                <button class="ui left labeled icon large positive right floated button prevent-word-wrap" type="submit" id="submitbutton">
                    <i class="save icon"></i>{{ t._('bt_SaveSettings') }}
                </button>
            </div>
        </form>
    </div>

    <div class="ui bottom attached tab segment{% if activeTab === 'models' %} active{% endif %}" data-tab="models">
        <form id="module-local-speech-to-text-model-form" method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/saveModel') }}" data-initial-selected-model="{{ selectedModel|e }}"></form>
        <h4 class="ui dividing header">{{ t._('module_local_speech_to_text_ModelsMarketplaceTab') }}</h4>
        <div class="ui info message">{{ t._('module_local_speech_to_text_ModelsMarketplaceHint') }}</div>
        <table class="ui selectable compact celled striped table stt-model-marketplace-table">
            <thead>
            <tr>
                <th class="collapsing"></th>
                <th>{{ t._('module_local_speech_to_text_Model') }}</th>
                <th>{{ t._('module_local_speech_to_text_ModelFit') }}</th>
                <th>{{ t._('module_local_speech_to_text_ModelTechnicalName') }}</th>
                <th class="collapsing"></th>
            </tr>
            </thead>
            <tbody>
            {% for row in modelMarketplaceRows %}
                <tr{% if row['selected'] %} class="positive"{% endif %}>
                    <td class="center aligned collapsing">
                        <div class="ui radio checkbox">
                            <input type="radio" form="module-local-speech-to-text-model-form" name="selected_model" value="{{ row['id']|e }}" {% if row['selected'] %}checked="checked"{% endif %}>
                            <label></label>
                        </div>
                    </td>
                    <td>
                        <div class="stt-model-title">{{ row['title']|e }}</div>
                        <div class="stt-model-summary">{{ row['description']|e }}</div>
                        {% if row['sourceUrl'] !== '' %}
                            <div class="stt-table-muted">
                                <a href="{{ row['sourceUrl']|e }}" target="_blank" rel="noopener noreferrer">{{ row['repo']|e }}</a>
                            </div>
                        {% endif %}
                    </td>
                    <td class="stt-model-fit">
                        {% if row['profile'] !== '' %}
                            <span class="ui tiny blue label">{{ row['profile']|e }}</span>
                        {% endif %}
                        {% if row['bestFor'] !== '' %}
                            <div class="stt-model-fit-main">{{ row['bestFor']|e }}</div>
                        {% endif %}
                        {% if row['tradeoff'] !== '' %}
                            <div class="stt-table-muted">{{ row['tradeoff']|e }}</div>
                        {% endif %}
                    </td>
                    <td>
                        <span class="stt-model-runtime">{{ row['runtime']|e }}</span>
                        {% if row['technical'] !== '' %}
                            <div class="stt-table-muted">{{ row['technical']|e }}</div>
                        {% endif %}
                    </td>
                    <td class="right aligned collapsing">
                        {% if row['custom'] %}
                            <form class="stt-delete-custom-model-form" method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/deleteCustomModel') }}" data-confirm="{{ t._('module_local_speech_to_text_DeleteCustomModelConfirm') }}">
                                <input type="hidden" name="selected_model" value="{{ row['id']|e }}">
                                <button class="ui compact icon button" type="submit" data-content="{{ t._('module_local_speech_to_text_DeleteCustomModel') }}">
                                    <i class="trash alternate outline icon"></i>
                                </button>
                            </form>
                        {% endif %}
                    </td>
                </tr>
            {% endfor %}
            </tbody>
        </table>
        <div class="ui clearing basic segment stt-form-actions">
            <button class="ui left labeled icon large positive right floated disabled button prevent-word-wrap" type="submit" form="module-local-speech-to-text-model-form" id="module-local-speech-to-text-model-save" disabled="disabled">
                <i class="save icon"></i>{{ t._('module_local_speech_to_text_SaveModel') }}
            </button>
        </div>
        <div class="ui fluid styled accordion stt-custom-model-dropdown">
            <div class="title">
                <i class="dropdown icon"></i>{{ t._('module_local_speech_to_text_AddCustomModel') }}
            </div>
            <div class="content">
                <form class="ui form stt-custom-model-form" method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/addCustomModel') }}">
                    <div class="three fields">
                        <div class="field">
                            <label>{{ t._('module_local_speech_to_text_ModelRepo') }}</label>
                            <input type="text" name="model_repo" placeholder="owner/repository">
                        </div>
                        <div class="field">
                            <label>{{ t._('module_local_speech_to_text_RuntimeModel') }}</label>
                            <input type="text" name="runtime_model" placeholder="model-folder">
                        </div>
                        <div class="field">
                            <label>{{ t._('module_local_speech_to_text_CustomModelTitle') }}</label>
                            <input type="text" name="model_title" placeholder="Display name">
                        </div>
                    </div>
                    <div class="three fields">
                        <div class="field">
                            <label>{{ t._('module_local_speech_to_text_CustomModelLanguage') }}</label>
                            <input type="text" name="model_language" placeholder="ru">
                        </div>
                        <div class="field">
                            <label>{{ t._('module_local_speech_to_text_CustomModelLicense') }}</label>
                            <input type="text" name="model_license" placeholder="Apache-2.0">
                        </div>
                        <div class="field">
                            <label>{{ t._('module_local_speech_to_text_CustomModelTags') }}</label>
                            <input type="text" name="model_tags" placeholder="custom, ru, tuned">
                        </div>
                    </div>
                    <div class="two fields">
                        <div class="field">
                            <label>{{ t._('module_local_speech_to_text_CustomModelSourceUrl') }}</label>
                            <input type="text" name="source_url" placeholder="https://huggingface.co/owner/repository">
                        </div>
                        <div class="field">
                            <label>{{ t._('module_local_speech_to_text_CustomModelDescription') }}</label>
                            <input type="text" name="model_description" placeholder="WhisperKit Core ML model">
                        </div>
                    </div>
                    <button class="ui left labeled icon blue button" type="submit">
                        <i class="add circle icon"></i>{{ t._('module_local_speech_to_text_AddCustomModelButton') }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="ui bottom attached tab segment{% if activeTab === 'queue' %} active{% endif %}" data-tab="queue">
        <h4 class="ui dividing header">{{ t._('module_local_speech_to_text_QueueStatusSummary') }}</h4>
        <table class="ui compact celled table stt-queue-summary-table">
            <thead>
            <tr>
                <th>{{ t._('module_local_speech_to_text_Status') }}</th>
                <th class="right aligned">{{ t._('module_local_speech_to_text_Count') }}</th>
                <th>{{ t._('module_local_speech_to_text_LastEvent') }}</th>
            </tr>
            </thead>
            <tbody>
            {% for row in queueSummaryRows %}
                <tr{% if row['count'] == 0 %} class="stt-queue-empty-row"{% endif %}>
                    <td>
                        <span class="ui tiny {{ row['labelClass'] }} label stt-queue-status-label">
                            <i class="{{ row['icon'] }} icon"></i>{{ row['label']|e }}
                        </span>
                    </td>
                    <td class="right aligned"><strong>{{ row['count'] }}</strong></td>
                    <td>
                        {% if row['lastEventLabel'] !== '' %}
                            {{ row['lastEventLabel']|e }}
                        {% else %}
                            <span class="stt-table-muted">-</span>
                        {% endif %}
                    </td>
                </tr>
            {% endfor %}
            </tbody>
        </table>
        <div class="ui clearing basic segment stt-queue-actions">
            <form method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/retryErrors') }}">
                <button class="ui left labeled icon primary right floated button{% if errorCount == 0 %} disabled{% endif %}" type="submit">
                    <i class="redo icon"></i>{{ t._('module_local_speech_to_text_RetryErrorsButton') }}
                </button>
            </form>
        </div>

        <h4 class="ui dividing header">{{ t._('module_local_speech_to_text_QueueFiles') }}</h4>
        <table class="ui selectable compact celled striped table stt-queue-files-table">
            <thead>
            <tr>
                <th>{{ t._('module_local_speech_to_text_RecordingDate') }}</th>
                <th>{{ t._('module_local_speech_to_text_Status') }}</th>
                <th>{{ t._('module_local_speech_to_text_Source') }}</th>
                <th>{{ t._('module_local_speech_to_text_CallId') }}</th>
                <th>{{ t._('module_local_speech_to_text_Worker') }}</th>
                <th class="right aligned">{{ t._('module_local_speech_to_text_Attempts') }}</th>
                <th>{{ t._('module_local_speech_to_text_CreatedAt') }}</th>
                <th>{{ t._('module_local_speech_to_text_UpdatedAt') }}</th>
            </tr>
            </thead>
            <tbody>
            {% for row in queueFileRows %}
                <tr>
                    <td class="stt-call-date-cell">
                        <div class="stt-call-date">{{ row['recordingDateLabel']|e }}</div>
                        {% if row['recordingTimeLabel'] !== '' %}
                            <div class="stt-table-muted">{{ row['recordingTimeLabel']|e }}</div>
                        {% endif %}
                    </td>
                    <td>
                        <span class="ui tiny {{ row['labelClass'] }} label stt-queue-status-label">
                            <i class="{{ row['icon'] }} icon"></i>{{ row['statusLabel']|e }}
                        </span>
                        {% if row['statusDetailLabel'] !== '' %}
                            <div class="stt-table-muted">{{ row['statusDetailLabel']|e }}</div>
                        {% endif %}
                    </td>
                    <td>{{ row['sourceLabel']|e }}</td>
                    <td><span class="stt-call-id">{{ row['callId']|e }}</span></td>
                    <td>{{ row['workerLabel']|e }}</td>
                    <td class="right aligned">{{ row['attemptsLabel']|e }}</td>
                    <td>{{ row['createdAtLabel']|e }}</td>
                    <td>{{ row['updatedAtLabel']|e }}</td>
                </tr>
            {% else %}
                <tr>
                    <td colspan="8" class="center aligned disabled">{{ t._('module_local_speech_to_text_QueueFilesEmpty') }}</td>
                </tr>
            {% endfor %}
            </tbody>
        </table>
    </div>

    <div class="ui bottom attached tab segment{% if activeTab === 'workers' %} active{% endif %}" data-tab="workers">
        <div class="ui segment stt-worker-download">
            <div class="ui middle aligned stackable grid">
                <div class="ten wide column">
                    <h4 class="ui header">
                        <i class="download icon"></i>
                        <span class="content">
                            {{ t._('module_local_speech_to_text_WorkerDownloadTitle') }}
                            <span class="sub header">{{ t._('module_local_speech_to_text_WorkerDownloadDescription') }}</span>
                        </span>
                    </h4>
                    <div class="stt-table-muted stt-worker-download-meta">
                        macOS &middot; Apple Silicon &middot; {{ t._('module_local_speech_to_text_Source') }}: <strong>files.miko.ru</strong>
                    </div>
                </div>
                <div class="six wide right aligned column stt-worker-download-action">
                    <button class="ui left labeled icon blue disabled button" type="button" disabled="disabled" aria-disabled="true">
                        <i class="download icon"></i>
                        {{ t._('module_local_speech_to_text_DownloadWorker') }}
                    </button>
                    <div class="stt-table-muted stt-worker-download-pending">
                        {{ t._('module_local_speech_to_text_WorkerDownloadPending') }}
                    </div>
                </div>
            </div>
        </div>
        <div class="ui segment stt-worker-api-key">
            <div class="ui stackable grid">
                <div class="ten wide column">
                    <h4 class="ui header">
                        <i class="key icon"></i>
                        <span class="content">{{ t._('module_local_speech_to_text_WorkerApiKeys') }}</span>
                    </h4>
                </div>
                <div class="six wide right aligned column">
                    <form method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/generateWorkerApiKey') }}">
                        <button class="ui left labeled icon blue button" type="submit">
                            <i class="add circle icon"></i>
                            {{ t._('module_local_speech_to_text_GenerateWorkerApiKey') }}
                        </button>
                    </form>
                </div>
            </div>
            {% if generatedApiKey !== '' %}
                <div class="ui info message stt-api-key-message">
                    <div class="header">{{ t._('module_local_speech_to_text_WorkerApiKeyShownOnce') }}</div>
                    <div class="ui action fluid input stt-copy-input">
                        <input type="text" readonly="readonly" value="{{ generatedApiKey|e }}">
                        <button class="ui icon button stt-copy-button" type="button" data-clipboard-text="{{ generatedApiKey|e }}" title="{{ t._('module_local_speech_to_text_Copy') }}">
                            <i class="copy icon"></i>
                        </button>
                    </div>
                </div>
            {% endif %}
            <table class="ui selectable compact celled striped table stt-worker-api-keys-table">
                <thead>
                <tr>
                    <th>{{ t._('module_local_speech_to_text_WorkerApiKey') }}</th>
                    <th>{{ t._('module_local_speech_to_text_WorkerApiKeyCreatedAt') }}</th>
                    <th>{{ t._('module_local_speech_to_text_WorkerApiKeyLastUsed') }}</th>
                    <th class="center aligned">{{ t._('module_local_speech_to_text_WorkerApiKeyBoundWorkers') }}</th>
                    <th class="collapsing"></th>
                </tr>
                </thead>
                <tbody>
                {% for row in workerApiKeyRows %}
                    <tr>
                        <td>
                            {% if row['missing'] %}
                                <span class="ui small warning label">{{ t._('module_local_speech_to_text_WorkerApiKeyNotFound') }} #{{ row['id']|e }}</span>
                            {% else %}
                                <span class="ui small label">{{ row['keyDisplay']|e }}</span>
                            {% endif %}
                        </td>
                        <td>{{ row['createdAtLabel']|e }}</td>
                        <td>{{ row['lastUsedAtLabel']|e }}</td>
                        <td class="center aligned">{{ row['boundWorkers']|e }}</td>
                        <td class="right aligned collapsing stt-api-key-actions">
                            <form class="stt-delete-api-key-form" method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/deleteWorkerApiKey') }}" data-confirm="{{ t._('module_local_speech_to_text_DeleteWorkerApiKeyConfirm') }}">
                                <input type="hidden" name="api_key_id" value="{{ row['id']|e }}">
                                <button class="ui compact icon button" type="submit" data-content="{{ t._('module_local_speech_to_text_DeleteWorkerApiKey') }}">
                                    <i class="trash alternate outline icon"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                {% else %}
                    <tr>
                        <td colspan="5" class="center aligned disabled">{{ t._('module_local_speech_to_text_WorkerApiKeysEmpty') }}</td>
                    </tr>
                {% endfor %}
                </tbody>
            </table>
        </div>
        {% if workers is empty %}
        <div class="ui info message stt-workers-onboarding">
            <div class="header">{{ t._('module_local_speech_to_text_WorkersOnboardingTitle') }}</div>
            <div class="ui ordered list">
                <div class="item">{{ t._('module_local_speech_to_text_WorkersOnboardingStep1') }}</div>
                <div class="item">{{ t._('module_local_speech_to_text_WorkersOnboardingStep2') }}</div>
                <div class="item">{{ t._('module_local_speech_to_text_WorkersOnboardingStep3') }}</div>
            </div>
        </div>
        {% endif %}
        <table class="ui selectable compact celled striped table stt-workers-table">
            <thead>
            <tr>
                <th>{{ t._('module_local_speech_to_text_Worker') }}</th>
                <th>{{ t._('module_local_speech_to_text_IP') }}</th>
                <th>{{ t._('module_local_speech_to_text_Model') }}</th>
                <th class="center aligned">{{ t._('module_local_speech_to_text_Status') }}</th>
                <th>{{ t._('module_local_speech_to_text_LastSeen') }}</th>
            </tr>
            </thead>
            <tbody>
            {% for worker in workers %}
                <tr>
                    <td>{{ worker.name|e }}</td>
                    <td>{{ worker.ip|e }}</td>
                    <td><span class="stt-model-text">{{ workerRuntimeModel|e }}</span></td>
                    <td class="center aligned">
                        {% if worker.status === 'online' %}
                            <span class="ui tiny green label">{{ t._('module_local_speech_to_text_Online') }}</span>
                        {% else %}
                            <span class="ui tiny grey label">{{ t._('module_local_speech_to_text_Offline') }}</span>
                        {% endif %}
                    </td>
                    <td>{{ date('Y-m-d H:i:s', worker.last_seen) }}</td>
                </tr>
            {% else %}
                <tr>
                    <td colspan="5" class="center aligned disabled">{{ t._('module_local_speech_to_text_NoWorkers') }}</td>
                </tr>
            {% endfor %}
            </tbody>
        </table>
    </div>

    <div class="ui bottom attached tab segment{% if activeTab === 'transcripts' %} active{% endif %}" data-tab="transcripts">
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
    </div>

    <div class="ui bottom attached tab segment{% if activeTab === 'logs' %} active{% endif %}" data-tab="logs">
        <div class="ui basic segment stt-system-logs-segment" id="system-logs-segment">
            <div class="ui active dimmer stt-logs-dimmer" id="get-logs-dimmer">
                <div class="ui indeterminate text loader">{{ t._('module_local_speech_to_text_LogsLoading') }}</div>
            </div>
            <div class="stt-log-header">
                <h3 class="ui header">{{ t._('module_local_speech_to_text_LogsTitle') }}</h3>
                <p>{{ t._('module_local_speech_to_text_LogsDescription') }}</p>
            </div>
            <form class="ui form stt-module-logs-form" id="stt-module-logs-form" data-url="{{ url(logsDataPath) }}">
                <div class="stt-log-toolbar">
                    <div class="quick-period-buttons" id="period-buttons">
                        <button type="button" class="period-btn active" data-period="3600">1h</button>
                        <button type="button" class="period-btn" data-period="10800">3h</button>
                        <button type="button" class="period-btn" data-period="43200">12h</button>
                        <button type="button" class="period-btn" data-period="86400">1d</button>
                        <button type="button" class="period-btn" data-period="0">{{ t._('module_local_speech_to_text_LogsPeriodAll') }}</button>
                    </div>
                    <div class="field stt-log-filter-field">
                        <label for="stt-log-level-filter">{{ t._('module_local_speech_to_text_LogsLevelFilter') }}</label>
                        <select id="stt-log-level-filter">
                            <option value="">{{ t._('module_local_speech_to_text_LogsLevelAll') }}</option>
                            <option value="debug">{{ t._('module_local_speech_to_text_LogLevelDebug') }}</option>
                            <option value="info">{{ t._('module_local_speech_to_text_LogLevelInfo') }}</option>
                            <option value="notice">{{ t._('module_local_speech_to_text_LogLevelNotice') }}</option>
                            <option value="warning">{{ t._('module_local_speech_to_text_LogLevelWarning') }}</option>
                            <option value="error">{{ t._('module_local_speech_to_text_LogLevelError') }}</option>
                        </select>
                    </div>
                    <div class="field stt-log-filter-field">
                        <label for="stt-log-component-filter">{{ t._('module_local_speech_to_text_LogsComponentFilter') }}</label>
                        <select id="stt-log-component-filter">
                            <option value="">{{ t._('module_local_speech_to_text_LogsComponentAll') }}</option>
                        </select>
                    </div>
                    <div class="field stt-log-search-field">
                        <label for="stt-log-search">{{ t._('module_local_speech_to_text_LogsSearch') }}</label>
                        <div class="ui icon input">
                            <input id="stt-log-search" type="search" autocomplete="off" placeholder="{{ t._('module_local_speech_to_text_LogsSearchPlaceholder') }}">
                            <i class="search icon"></i>
                        </div>
                    </div>
                    <button type="button" class="ui primary button stt-log-refresh" id="show-last-log">
                        <i class="refresh icon"></i>{{ t._('module_local_speech_to_text_LogsRefresh') }}
                    </button>
                </div>
            </form>
            <div class="stt-logs-meta" id="stt-module-logs-meta">
                <span id="stt-log-result-count"></span>
                <span id="stt-log-updated-at"></span>
            </div>
            <div class="ui message stt-log-state" id="stt-log-state" hidden></div>
            <div class="stt-log-results" id="stt-log-results" aria-live="polite"></div>
        </div>
    </div>
</div>

<div class="ui tiny modal" id="stt-confirm-modal" style="display:none">
    <div class="header">{{ t._('module_local_speech_to_text_ConfirmTitle') }}</div>
    <div class="content">
        <p class="stt-confirm-message"></p>
    </div>
    <div class="actions">
        <div class="ui cancel basic button">{{ t._('module_local_speech_to_text_ConfirmCancel') }}</div>
        <div class="ui red approve button">{{ t._('module_local_speech_to_text_ConfirmDelete') }}</div>
    </div>
</div>
