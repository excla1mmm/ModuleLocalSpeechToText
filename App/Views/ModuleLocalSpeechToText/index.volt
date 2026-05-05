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
        <table class="ui selectable compact celled striped table stt-queue-table">
            <thead>
            <tr>
                <th>{{ t._('module_local_speech_to_text_Status') }}</th>
                <th class="right aligned">{{ t._('module_local_speech_to_text_Count') }}</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><i class="clock outline icon"></i>{{ t._('module_local_speech_to_text_Pending') }}</td>
                <td class="right aligned">{{ pendingCount }}</td>
            </tr>
            <tr>
                <td><i class="spinner icon"></i>{{ t._('module_local_speech_to_text_Processing') }}</td>
                <td class="right aligned">{{ processingCount }}</td>
            </tr>
            <tr>
                <td><i class="check circle outline icon"></i>{{ t._('module_local_speech_to_text_DoneToday') }}</td>
                <td class="right aligned">{{ doneTodayCount }}</td>
            </tr>
            <tr>
                <td><i class="exclamation triangle icon"></i>{{ t._('module_local_speech_to_text_Errors') }}</td>
                <td class="right aligned">{{ errorCount }}</td>
            </tr>
            </tbody>
        </table>
        <div class="ui clearing basic segment stt-queue-actions">
            <form method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/retryErrors') }}">
                <button class="ui left labeled icon primary right floated button{% if errorCount == 0 %} disabled{% endif %}" type="submit">
                    <i class="redo icon"></i>{{ t._('module_local_speech_to_text_RetryErrorsButton') }}
                </button>
            </form>
        </div>
    </div>

    <div class="ui bottom attached tab segment{% if activeTab === 'workers' %} active{% endif %}" data-tab="workers">
        <div class="ui segment stt-worker-api-key">
            <div class="ui stackable grid">
                <div class="ten wide column">
                    <h4 class="ui header">
                        <i class="key icon"></i>
                        <span class="content">{{ t._('module_local_speech_to_text_WorkerApiKey') }}</span>
                    </h4>
                    {% if workerApiKey %}
                        <div class="ui small label">{{ workerApiKey.key_display|e }}</div>
                    {% else %}
                        <span class="ui grey text">{{ t._('module_local_speech_to_text_WorkerApiKeyMissing') }}</span>
                    {% endif %}
                </div>
                <div class="six wide right aligned column">
                    <form method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/generateWorkerApiKey') }}">
                        <button class="ui left labeled icon primary button" type="submit">
                            <i class="sync alternate icon"></i>
                            {% if workerApiKey %}
                                {{ t._('module_local_speech_to_text_RegenerateWorkerApiKey') }}
                            {% else %}
                                {{ t._('module_local_speech_to_text_GenerateWorkerApiKey') }}
                            {% endif %}
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
                    <td><span class="stt-model-text">{{ worker.model|e }}</span></td>
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
                    <td>#{{ result.job_id|e }}</td>
                    <td>{{ result.STTJob.call_id|e }}</td>
                    <td>{{ result.language|e }}</td>
                    <td>{{ result.duration|e }}</td>
                    <td><span class="stt-model-text">{{ result.model_used|e }}</span></td>
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
</div>
