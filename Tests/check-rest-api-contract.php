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
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\DataStructure as TranscriptsDataStructure;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\Processor as TranscriptsProcessor;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerProcessingSettings\DataStructure as WorkerProcessingSettingsDataStructure;

    require_once __DIR__ . '/../Lib/RestAPI/Jobs/DataStructure.php';
    require_once __DIR__ . '/../Lib/RestAPI/Common/WorkerRequestContext.php';
    require_once __DIR__ . '/../Lib/RestAPI/WorkerProcessingSettings/DataStructure.php';
    require_once __DIR__ . '/../Lib/RestAPI/Transcripts/DataStructure.php';
    require_once __DIR__ . '/../Lib/RestAPI/Jobs/Processor.php';
    require_once __DIR__ . '/../Lib/RestAPI/Transcripts/Processor.php';

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
    $processingSchema = WorkerProcessingSettingsDataStructure::getDetailSchema();
    expectSameRestApiContract('integer', $processingSchema['properties']['schema_version']['type'] ?? null, 'Processing settings schema version must be an integer');
    expectSameRestApiContract('object', $processingSchema['properties']['processing_settings']['type'] ?? null, 'Processing settings must be an object');
    $processingProperties = $processingSchema['properties']['processing_settings']['properties'] ?? [];
    foreach ([
        'temperature',
        'temperature_fallback_step',
        'compression_ratio_threshold',
        'log_probability_threshold',
        'first_token_log_probability_threshold',
        'no_speech_threshold',
    ] as $property) {
        expectSameRestApiContract('number', $processingProperties[$property]['type'] ?? null, "{$property} must be a number");
    }
    foreach (['temperature_fallback_count', 'top_k', 'max_segment_duration_ms', 'segment_overlap_ms'] as $property) {
        expectSameRestApiContract('integer', $processingProperties[$property]['type'] ?? null, "{$property} must be an integer");
    }
    foreach (['audio_normalization', 'voice_activity_detection'] as $property) {
        expectSameRestApiContract('boolean', $processingProperties[$property]['type'] ?? null, "{$property} must be a boolean");
    }
    expectSameRestApiContract(
        array_keys($processingProperties),
        $processingSchema['properties']['processing_settings']['required'] ?? [],
        'The complete processing profile must be required'
    );

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
    $data = WorkerRequestContext::data($trustedRequest);
    expectSameRestApiContract('worker-a', $data['worker_uid'] ?? null, 'Canonical request data must preserve request data fields');
    expectSameRestApiContract('10.0.0.5', $data['_remote_addr'] ?? null, 'Canonical request data must trust remote_addr only from sessionContext');
    expectSameRestApiContract('BearerToken', $data['_auth_type'] ?? null, 'Canonical request data must trust auth_type only from sessionContext');
    expectSameRestApiContract(42, $data['_api_key_id'] ?? null, 'Canonical request data must trust token_id only from sessionContext');
    $stripped = WorkerRequestContext::data(['data' => $trustedRequest['data']]);
    expectSameRestApiContract('', $stripped['_remote_addr'] ?? null, 'Canonical request data must strip spoofed _remote_addr without sessionContext');
    expectSameRestApiContract('', $stripped['_auth_type'] ?? null, 'Canonical request data must strip spoofed _auth_type without sessionContext');
    expectSameRestApiContract(0, $stripped['_api_key_id'] ?? null, 'Canonical request data must strip spoofed _api_key_id without sessionContext');

    echo "REST API contract OK\n";
}
