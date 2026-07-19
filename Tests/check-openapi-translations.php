<?php

declare(strict_types=1);

function expectOpenApiTranslation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expectedRussian = [
    'rest_tag_LocalSpeechToText' => 'Локальная транскрибация (STT)',
    'module_local_speech_to_text_TranscriptsApiDescription' => 'Завершённые расшифровки отдельных записей звонков',
    'module_local_speech_to_text_TranscriptEventsApiDescription' => 'Поток событий о завершённых расшифровках записей',
    'module_local_speech_to_text_CallTranscriptsApiDescription' => 'Объединённые расшифровки логических звонков с поддержкой ревизий',
    'module_local_speech_to_text_CallTranscriptEventsApiDescription' => 'Поток событий о готовых и обновлённых расшифровках звонков',
    'module_local_speech_to_text_ListTranscripts' => 'Получить список завершённых расшифровок',
    'module_local_speech_to_text_ListTranscriptsDesc' => 'Возвращает результаты распознавания отдельных записей с данными звонка и пагинацией.',
    'module_local_speech_to_text_GetTranscript' => 'Получить завершённую расшифровку',
    'module_local_speech_to_text_GetTranscriptDesc' => 'Возвращает один результат распознавания с нормализованными репликами, сегментами и техническими данными.',
    'module_local_speech_to_text_ListTranscriptEvents' => 'Получить события завершённых расшифровок',
    'module_local_speech_to_text_ListTranscriptEventsDesc' => 'Возвращает идемпотентный поток событий transcript.completed после указанного курсора.',
    'module_local_speech_to_text_GetCallTranscript' => 'Получить объединённую расшифровку звонка',
    'module_local_speech_to_text_GetCallTranscriptDesc' => 'Возвращает текущую или указанную неизменяемую ревизию расшифровки логического звонка.',
    'module_local_speech_to_text_ListCallTranscriptEvents' => 'Получить события готовых расшифровок звонков',
    'module_local_speech_to_text_ListCallTranscriptEventsDesc' => 'Возвращает идемпотентный поток событий о готовых и обновлённых ревизиях логических звонков.',
    'module_local_speech_to_text_ReleaseJob' => 'Освободить задание STT',
    'module_local_speech_to_text_ReleaseJobDesc' => 'Снимает закрепление задания за текущим STT-обработчиком и возвращает его в очередь.',
    'module_local_speech_to_text_WorkersApiDescription' => 'Регистрация STT-обработчиков и получение централизованных настроек распознавания',
    'module_local_speech_to_text_JobsApiDescription' => 'Очередь заданий STT: получение, скачивание записи, heartbeat и завершение обработки',
    'module_local_speech_to_text_RegisterWorker' => 'Зарегистрировать STT-обработчик',
    'module_local_speech_to_text_RegisterWorkerDesc' => 'Создаёт или обновляет STT-обработчик и привязывает его к разрешённому API-ключу. Поле model не переопределяет серверные настройки.',
    'module_local_speech_to_text_GetProcessingSettings' => 'Получить настройки STT-обработки',
    'module_local_speech_to_text_GetProcessingSettingsDesc' => 'Возвращает полный серверный профиль распознавания и сбрасывает признак изменения только для запросившего обработчика.',
    'module_local_speech_to_text_GetNextJob' => 'Получить следующее задание STT',
    'module_local_speech_to_text_GetNextJobDesc' => 'Закрепляет следующее ожидающее задание за обработчиком; если очередь пуста, возвращает job: null.',
    'module_local_speech_to_text_DownloadJobFile' => 'Скачать запись задания STT',
    'module_local_speech_to_text_DownloadJobFileDesc' => 'Передаёт запись только назначенному обработчику с действующим lease_token и поддерживает HTTP Range.',
    'module_local_speech_to_text_SubmitResult' => 'Отправить результат STT',
    'module_local_speech_to_text_SubmitResultDesc' => 'Проверяет временные метки сегментов, сохраняет расшифровку и завершает принадлежащее обработчику задание.',
    'module_local_speech_to_text_SubmitError' => 'Сообщить об ошибке STT',
    'module_local_speech_to_text_SubmitErrorDesc' => 'Регистрирует неудачную попытку и возвращает задание в очередь либо завершает его ошибкой после исчерпания попыток.',
    'module_local_speech_to_text_HeartbeatJob' => 'Продлить закрепление задания STT',
    'module_local_speech_to_text_HeartbeatJobDesc' => 'Продлевает действующий lease только для назначенного обработчика и защищает длительное распознавание от повторной выдачи.',
];

$expectedEnglish = [
    'rest_tag_LocalSpeechToText' => 'Local speech-to-text (STT)',
    'module_local_speech_to_text_TranscriptsApiDescription' => 'Completed transcriptions of individual call recordings',
    'module_local_speech_to_text_TranscriptEventsApiDescription' => 'Event stream for completed recording transcriptions',
    'module_local_speech_to_text_CallTranscriptsApiDescription' => 'Aggregated logical-call transcriptions with immutable revisions',
    'module_local_speech_to_text_CallTranscriptEventsApiDescription' => 'Event stream for completed and updated call transcriptions',
    'module_local_speech_to_text_ListTranscripts' => 'List completed transcriptions',
    'module_local_speech_to_text_ListTranscriptsDesc' => 'Returns recording-level recognition results with call metadata and pagination.',
    'module_local_speech_to_text_GetTranscript' => 'Get completed transcription',
    'module_local_speech_to_text_GetTranscriptDesc' => 'Returns one recognition result with normalized turns, segments, and technical metadata.',
    'module_local_speech_to_text_ListTranscriptEvents' => 'List completed transcription events',
    'module_local_speech_to_text_ListTranscriptEventsDesc' => 'Returns the idempotent transcript.completed event stream after the supplied cursor.',
    'module_local_speech_to_text_GetCallTranscript' => 'Get aggregated call transcript',
    'module_local_speech_to_text_GetCallTranscriptDesc' => 'Returns the current or requested immutable revision of a logical-call transcript.',
    'module_local_speech_to_text_ListCallTranscriptEvents' => 'List call transcript events',
    'module_local_speech_to_text_ListCallTranscriptEventsDesc' => 'Returns the idempotent event stream for completed and updated logical-call revisions.',
    'module_local_speech_to_text_ReleaseJob' => 'Release STT job',
    'module_local_speech_to_text_ReleaseJobDesc' => 'Releases the current STT worker lease and returns the job to the queue.',
    'module_local_speech_to_text_WorkersApiDescription' => 'STT worker registration and centralized recognition settings',
    'module_local_speech_to_text_JobsApiDescription' => 'STT job queue: claim, recording download, heartbeat, and completion lifecycle',
    'module_local_speech_to_text_RegisterWorker' => 'Register STT worker',
    'module_local_speech_to_text_RegisterWorkerDesc' => 'Creates or refreshes an STT worker and binds it to an allowed API key. The model field does not override server settings.',
    'module_local_speech_to_text_GetProcessingSettings' => 'Get STT processing settings',
    'module_local_speech_to_text_GetProcessingSettingsDesc' => 'Returns the complete server-controlled recognition profile and clears the change flag only for the requesting worker.',
    'module_local_speech_to_text_GetNextJob' => 'Get next STT job',
    'module_local_speech_to_text_GetNextJobDesc' => 'Leases the next pending job to the worker; when the queue is empty, it returns job: null.',
    'module_local_speech_to_text_DownloadJobFile' => 'Download STT job recording',
    'module_local_speech_to_text_DownloadJobFileDesc' => 'Streams the recording only to its assigned worker with a valid lease_token and supports HTTP Range.',
    'module_local_speech_to_text_SubmitResult' => 'Submit STT result',
    'module_local_speech_to_text_SubmitResultDesc' => 'Validates segment timestamps, stores the transcript, and completes the job owned by the worker.',
    'module_local_speech_to_text_SubmitError' => 'Report STT processing error',
    'module_local_speech_to_text_SubmitErrorDesc' => 'Records a failed attempt and requeues the job or marks it as failed after all attempts are exhausted.',
    'module_local_speech_to_text_HeartbeatJob' => 'Extend STT job lease',
    'module_local_speech_to_text_HeartbeatJobDesc' => 'Extends the active lease only for the assigned worker and prevents long recognition from being issued again.',
];

$root = dirname(__DIR__);
$russian = include $root . '/Messages/ru.php';
$english = include $root . '/Messages/en.php';
foreach ($expectedRussian as $key => $value) {
    expectOpenApiTranslation(($russian[$key] ?? null) === $value, "Russian OpenAPI translation mismatch: {$key}");
}
foreach ($expectedEnglish as $key => $value) {
    expectOpenApiTranslation(($english[$key] ?? null) === $value, "English OpenAPI translation mismatch: {$key}");
}

$messageFiles = glob($root . '/Messages/*.php') ?: [];
expectOpenApiTranslation(count($messageFiles) === 29, 'Expected all 29 module translation catalogs');
$schemaFiles = array_merge(
    glob($root . '/Lib/RestAPI/Jobs/*DataStructure.php') ?: [],
    glob($root . '/Lib/RestAPI/Workers/*DataStructure.php') ?: [],
    glob($root . '/Lib/RestAPI/Transcripts/*DataStructure.php') ?: [],
    glob($root . '/Lib/RestAPI/TranscriptEvents/*DataStructure.php') ?: [],
    glob($root . '/Lib/RestAPI/CallTranscripts/*DataStructure.php') ?: [],
    glob($root . '/Lib/RestAPI/CallTranscriptEvents/*DataStructure.php') ?: []
);
$referencedSchemaKeys = [];
foreach ($schemaFiles as $schemaFile) {
    $source = file_get_contents($schemaFile);
    expectOpenApiTranslation(is_string($source), 'Unable to read ' . basename($schemaFile));
    preg_match_all("/'description'\s*=>\s*'([^']+)'/", $source, $descriptionMatches);
    foreach ($descriptionMatches[1] ?? [] as $description) {
        expectOpenApiTranslation(
            str_starts_with($description, 'module_local_speech_to_text_Api'),
            basename($schemaFile) . " contains a non-localized OpenAPI description: {$description}"
        );
        $referencedSchemaKeys[$description] = true;
    }
}
$controllerFiles = glob($root . '/Lib/RestAPI/*/Controller.php') ?: [];
foreach ($controllerFiles as $controllerFile) {
    $source = file_get_contents($controllerFile);
    expectOpenApiTranslation(is_string($source), 'Unable to read ' . basename(dirname($controllerFile)) . '/Controller.php');
    preg_match_all('/module_local_speech_to_text_[A-Z][A-Za-z0-9_]+/', $source, $controllerKeyMatches);
    foreach ($controllerKeyMatches[0] ?? [] as $key) {
        $referencedSchemaKeys[$key] = true;
    }
}
foreach (array_keys($referencedSchemaKeys) as $key) {
    expectOpenApiTranslation(isset($russian[$key]), "Russian catalog is missing referenced OpenAPI key: {$key}");
    expectOpenApiTranslation(isset($english[$key]), "English catalog is missing referenced OpenAPI key: {$key}");
}

$sourceKeys = array_keys($russian);
$openApiKeys = array_values(array_unique(array_merge(
    array_keys($expectedEnglish),
    array_keys($referencedSchemaKeys)
)));
foreach ($messageFiles as $messageFile) {
    $messages = include $messageFile;
    expectOpenApiTranslation(is_array($messages), 'Invalid translation catalog: ' . basename($messageFile));
    expectOpenApiTranslation(
        array_keys($messages) === $sourceKeys,
        basename($messageFile) . ' translation keys differ from ru.php'
    );
    if (!str_ends_with($messageFile, '/ru.php') && !str_ends_with($messageFile, '/en.php')) {
        foreach ($openApiKeys as $key) {
            expectOpenApiTranslation(
                isset($messages[$key]) && trim((string)$messages[$key]) !== '',
                basename($messageFile) . " has an empty OpenAPI translation for {$key}"
            );
            expectOpenApiTranslation(
                $messages[$key] !== $english[$key],
                basename($messageFile) . " still uses the English OpenAPI fallback for {$key}"
            );
        }
    }
}

echo "OpenAPI translations contract OK\n";
