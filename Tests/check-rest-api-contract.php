<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib\Common {
    abstract class AbstractDataStructure
    {
    }

    interface OpenApiSchemaProvider
    {
    }
}

namespace MikoPBX\PBXCoreREST\Lib {
    class PBXApiResult
    {
        public bool $success = false;
        public array $data = [];
        public array $messages = [];
        public int $httpCode = 0;
        public string $processor = '';
        public string $function = '';
    }
}

namespace Phalcon\Di {
    class Injectable
    {
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure as JobsDataStructure;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\Processor as JobsProcessor;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents\DataStructure as TranscriptEventsDataStructure;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents\Processor as TranscriptEventsProcessor;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\DataStructure as TranscriptsDataStructure;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\Processor as TranscriptsProcessor;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\DataStructure as WorkersDataStructure;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\Processor as WorkersProcessor;

    require_once __DIR__ . '/../Lib/RestAPI/Jobs/DataStructure.php';
    require_once __DIR__ . '/../Lib/RestAPI/Workers/DataStructure.php';
    require_once __DIR__ . '/../Lib/RestAPI/Transcripts/DataStructure.php';
    require_once __DIR__ . '/../Lib/RestAPI/TranscriptEvents/DataStructure.php';
    require_once __DIR__ . '/../Lib/RestAPI/Jobs/Processor.php';
    require_once __DIR__ . '/../Lib/RestAPI/Workers/Processor.php';
    require_once __DIR__ . '/../Lib/RestAPI/Transcripts/Processor.php';
    require_once __DIR__ . '/../Lib/RestAPI/TranscriptEvents/Processor.php';

    function expectSameRestApiContract(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    function expectTrueRestApiContract(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @return array<string, mixed>
     */
    function privateRequestDataRestApiContract(string $processorClass, array $request): array
    {
        $method = new ReflectionMethod($processorClass, 'requestData');

        $result = $method->invoke(null, $request);
        if (!is_array($result)) {
            throw new RuntimeException($processorClass . '::requestData must return an array');
        }

        return $result;
    }

    $jobParameters = JobsDataStructure::getParameterDefinitions();
    expectSameRestApiContract('integer', $jobParameters['request']['duration_ms']['type'] ?? null, 'Jobs API must accept duration_ms as an integer');
    expectSameRestApiContract(false, $jobParameters['request']['duration_ms']['required'] ?? null, 'duration_ms must be optional');
    expectTrueRestApiContract(!isset($jobParameters['request']['duration']), 'Jobs API must not document legacy duration seconds input');
    expectSameRestApiContract('array', $jobParameters['request']['transcript']['type'] ?? null, 'Jobs API must accept transcript arrays');
    expectTrueRestApiContract(
        str_contains((string)($jobParameters['request']['transcript']['description'] ?? ''), 'start_ms')
            && str_contains((string)($jobParameters['request']['transcript']['description'] ?? ''), 'end_ms'),
        'Jobs API transcript docs must require millisecond segment timestamps'
    );
    expectSameRestApiContract('object', $jobParameters['request']['technical_info']['type'] ?? null, 'Jobs API must accept worker technical_info objects');

    $jobSchema = JobsDataStructure::getDetailSchema();
    $jobProperties = $jobSchema['properties']['job']['oneOf'][0]['properties'] ?? [];
    foreach ([
        'id',
        'call_id',
        'file_url',
        'lease_token',
        'lease_expires_at',
        'language_hint',
        'model_id',
        'model_repo',
        'model_engine',
        'model_artifact_type',
        'whisperkit_model',
        'model_display_name',
        'model_source_url',
        'vocabulary_terms',
    ] as $property) {
        expectTrueRestApiContract(isset($jobProperties[$property]), "Claimed job schema must expose {$property}");
    }

    $workerParameters = WorkersDataStructure::getParameterDefinitions();
    expectSameRestApiContract(true, $workerParameters['request']['worker_uid']['required'] ?? null, 'Worker registration must require worker_uid');
    expectSameRestApiContract('integer', $workerParameters['response']['poll_interval']['type'] ?? null, 'Worker registration must return poll_interval');

    $transcriptDetail = TranscriptsDataStructure::getDetailSchema();
    $technicalInfo = $transcriptDetail['properties']['technical_info'] ?? [];
    expectSameRestApiContract('object', $technicalInfo['type'] ?? null, 'Transcript detail must expose decoded technical_info');
    expectTrueRestApiContract(
        isset($technicalInfo['properties']['voice_analytics'])
            && !isset($technicalInfo['properties']['voice_analytics_v1']),
        'Transcript detail schema must document voice_analytics without the legacy v1 suffix'
    );
    expectTrueRestApiContract(
        isset($transcriptDetail['properties']['transcript']['properties']['turns'])
            && isset($transcriptDetail['properties']['transcript']['properties']['segments']),
        'Transcript detail schema must expose both coalesced turns and raw segments'
    );

    $eventsParameters = TranscriptEventsDataStructure::getParameterDefinitions();
    expectSameRestApiContract('cursor', array_key_first($eventsParameters['request']), 'Transcript events must be cursor-first for polling clients');
    $eventItem = TranscriptEventsDataStructure::getListItemSchema();
    foreach (['cursor', 'event_type', 'result_id', 'job_id', 'call_id', 'created_at', 'update_type'] as $property) {
        expectTrueRestApiContract(isset($eventItem['properties'][$property]), "Transcript event schema must expose {$property}");
    }

    $trustedRequest = [
        'data' => [
            'worker_uid' => 'worker-a',
            '_remote_addr' => 'spoofed-ip',
            '_auth_type' => 'spoofed-auth',
            '_api_key_id' => 999,
        ],
        'sessionContext' => [
            'remote_addr' => '10.0.0.5',
            'auth_type' => 'BearerToken',
            'token_id' => '42',
        ],
    ];
    foreach ([JobsProcessor::class, WorkersProcessor::class, TranscriptsProcessor::class, TranscriptEventsProcessor::class] as $processorClass) {
        $data = privateRequestDataRestApiContract($processorClass, $trustedRequest);
        expectSameRestApiContract('worker-a', $data['worker_uid'] ?? null, $processorClass . ' must preserve request data fields');
        expectSameRestApiContract('10.0.0.5', $data['_remote_addr'] ?? null, $processorClass . ' must trust remote_addr only from sessionContext');
        expectSameRestApiContract('BearerToken', $data['_auth_type'] ?? null, $processorClass . ' must trust auth_type only from sessionContext');
        expectSameRestApiContract(42, $data['_api_key_id'] ?? null, $processorClass . ' must trust token_id only from sessionContext');

        $stripped = privateRequestDataRestApiContract($processorClass, ['data' => $trustedRequest['data']]);
        expectTrueRestApiContract(!isset($stripped['_remote_addr']), $processorClass . ' must strip spoofed _remote_addr without sessionContext');
        expectTrueRestApiContract(!isset($stripped['_auth_type']), $processorClass . ' must strip spoofed _auth_type without sessionContext');
        expectTrueRestApiContract(!isset($stripped['_api_key_id']), $processorClass . ' must strip spoofed _api_key_id without sessionContext');
    }

    $unknownJobAction = JobsProcessor::callBack(['action' => 'unknownAction']);
    expectSameRestApiContract('unknownAction', $unknownJobAction->function, 'Unknown job action result must preserve the requested function name');
    expectTrueRestApiContract(
        str_contains((string)($unknownJobAction->messages['error'][0] ?? ''), 'Unknown action - unknownAction'),
        'Unknown job action must report a clear processor error'
    );

    echo "REST API contract OK\n";
}
