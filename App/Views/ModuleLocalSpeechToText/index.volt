<div class="module-local-speech-to-text">
    <div class="ui clearing basic segment stt-header">
        <h2 class="ui left floated header">
            <img src="{{ logoImagePath }}" alt="">
            <div class="content">
                {{ t._('BreadcrumbModuleLocalSpeechToText') }}
                <div class="sub header">{{ t._('SubHeaderModuleLocalSpeechToText') }}</div>
            </div>
        </h2>
        {{ link_to(transcriptsPath, '<i class="file alternate outline icon"></i> '~t._('module_local_speech_to_text_Transcripts'), "class": "ui right floated button") }}
    </div>

    <form class="ui form large grey segment" id="module-local-speech-to-text-form" method="post" action="{{ url('module-local-speech-to-text/module-local-speech-to-text/save') }}">
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
        <button class="ui primary button" type="submit" id="submitbutton">{{ t._('bt_Save') }}</button>
    </form>

    <div class="ui four small statistics stt-statistics">
        <div class="statistic">
            <div class="value">{{ pendingCount }}</div>
            <div class="label">{{ t._('module_local_speech_to_text_Pending') }}</div>
        </div>
        <div class="statistic">
            <div class="value">{{ processingCount }}</div>
            <div class="label">{{ t._('module_local_speech_to_text_Processing') }}</div>
        </div>
        <div class="statistic">
            <div class="value">{{ doneTodayCount }}</div>
            <div class="label">{{ t._('module_local_speech_to_text_DoneToday') }}</div>
        </div>
        <div class="statistic">
            <div class="value">{{ errorCount }}</div>
            <div class="label">{{ t._('module_local_speech_to_text_Errors') }}</div>
        </div>
    </div>

    <table class="ui very compact celled table stt-workers-table">
        <thead>
            <tr>
                <th>{{ t._('module_local_speech_to_text_Worker') }}</th>
                <th>{{ t._('module_local_speech_to_text_IP') }}</th>
                <th>{{ t._('module_local_speech_to_text_Model') }}</th>
                <th>{{ t._('module_local_speech_to_text_Status') }}</th>
                <th>{{ t._('module_local_speech_to_text_LastSeen') }}</th>
            </tr>
        </thead>
        <tbody>
        {% for worker in workers %}
            <tr>
                <td>{{ worker.name }}</td>
                <td>{{ worker.ip }}</td>
                <td>{{ worker.model }}</td>
                <td>
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
                <td colspan="5" class="center aligned">{{ t._('module_local_speech_to_text_NoWorkers') }}</td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
</div>
