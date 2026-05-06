<div class="ui basic segment module-local-speech-to-text" id="module-local-speech-to-text">
    <div class="ui top attached tabular menu" id="module-local-speech-to-text-menu">
        <a class="item{% if activeTab === 'settings' %} active{% endif %}" data-tab="settings">{{ t._('module_local_speech_to_text_SettingsTab') }}</a>
        <a class="item{% if activeTab === 'queue' %} active{% endif %}" data-tab="queue">{{ t._('module_local_speech_to_text_QueueTab') }}</a>
        <a class="item{% if activeTab === 'workers' %} active{% endif %}" data-tab="workers">{{ t._('module_local_speech_to_text_WorkersTab') }}</a>
        <a class="item{% if activeTab === 'transcripts' %} active{% endif %}" data-tab="transcripts">{{ t._('module_local_speech_to_text_Transcripts') }}</a>
    </div>

    <div class="ui bottom attached tab segment{% if activeTab === 'settings' %} active{% endif %}" data-tab="settings">
        <form class="ui form large" id="module-local-speech-to-text-form" method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/save') }}">
            <div class="three fields">
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_DefaultLanguage') }}</label>
                    {{ form.render('default_language') }}
                </div>
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_PollInterval') }}</label>
                    {{ form.render('poll_interval_sec') }}
                </div>
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_MaxFileSize') }}</label>
                    {{ form.render('max_file_size_mb') }}
                </div>
            </div>
            <div class="two fields">
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_CdrScanLimit') }}</label>
                    {{ form.render('cdr_scan_limit') }}
                </div>
                <div class="field">
                    <label>{{ t._('module_local_speech_to_text_ProcessingTimeout') }}</label>
                    {{ form.render('processing_timeout_sec') }}
                </div>
            </div>
            <div class="field">
                <label>{{ t._('module_local_speech_to_text_SelectedModel') }}</label>
                <select name="selected_model" class="ui fluid dropdown">
                    {% for modelId, model in modelPresets %}
                        <option value="{{ modelId|e }}" {% if modelId === selectedModel %}selected="selected"{% endif %}>
                            {{ t._(model['label']) }} - {{ t._(model['description']) }}
                        </option>
                    {% endfor %}
                </select>
            </div>
            <div class="ui clearing basic segment stt-form-actions">
                <button class="ui left labeled icon large positive right floated button prevent-word-wrap" type="submit" id="submitbutton">
                    <i class="save icon"></i>{{ t._('bt_SaveSettings') }}
                </button>
            </div>
        </form>
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
                    <div class="ui action fluid input stt-copy-input">
                        <input type="text" readonly="readonly" value="{{ workerInstallCommand|e }}">
                        <button class="ui icon button stt-copy-button" type="button" data-clipboard-text="{{ workerInstallCommand|e }}" title="{{ t._('module_local_speech_to_text_Copy') }}">
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
                            <span class="ui small label">{{ row['keyDisplay']|e }}</span>
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
