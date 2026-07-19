<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Attributes {
    use Attribute;

    #[Attribute(Attribute::TARGET_METHOD)]
    class ApiOperation
    {
        public function __construct(
            public string $summary,
            public string $description,
            public string $operationId
        ) {
        }
    }

    #[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
    class ApiParameterRef
    {
        public function __construct(public string $name, public bool $required = false)
        {
        }
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    class ApiResource
    {
        public function __construct(
            public string $path,
            public array $tags,
            public string $description,
            public string $processor
        ) {
        }
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    class HttpMapping
    {
        public function __construct(
            public array $mapping,
            public array $resourceLevelMethods,
            public array $collectionLevelMethods,
            public array $customMethods,
            public string $idPattern
        ) {
        }
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    class ResourceSecurity
    {
        public function __construct(public string $resource, public array $requirements)
        {
        }
    }

    final class SecurityType
    {
        public const string LOCALHOST = 'localhost';
        public const string BEARER_TOKEN = 'bearer';
    }
}

namespace MikoPBX\PBXCoreREST\Controllers {
    abstract class BaseRestController
    {
    }
}

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
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib {
    class LocalSpeechToTextMain
    {
        public const string API_BASE_PATH = '/pbxcore/api/v3/module-local-speech-to-text';
    }
}

namespace {
    use MikoPBX\PBXCoreREST\Attributes\ApiDataSchema;
    use MikoPBX\PBXCoreREST\Attributes\ApiOperation;
    use MikoPBX\PBXCoreREST\Attributes\ApiParameterRef;
    use MikoPBX\PBXCoreREST\Attributes\ApiResource;
    use MikoPBX\PBXCoreREST\Attributes\ApiResponse;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscriptEvents\Controller as CallTranscriptEventsController;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts\Controller as CallTranscriptsController;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\Controller as JobsController;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents\Controller as TranscriptEventsController;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\Controller as TranscriptsController;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\Controller as WorkersController;

    $coreAttributes = dirname(__DIR__, 3) . '/Core/src/PBXCoreREST/Attributes';
    require_once $coreAttributes . '/ApiDataSchema.php';
    require_once $coreAttributes . '/ApiResponse.php';

    foreach ([
        '../Lib/RestAPI/Jobs/DataStructure.php',
        '../Lib/RestAPI/Jobs/SubmitResultResponseDataStructure.php',
        '../Lib/RestAPI/Jobs/SubmitErrorResponseDataStructure.php',
        '../Lib/RestAPI/Jobs/HeartbeatResponseDataStructure.php',
        '../Lib/RestAPI/Jobs/ReleaseResponseDataStructure.php',
        '../Lib/RestAPI/Workers/DataStructure.php',
        '../Lib/RestAPI/Workers/ProcessingSettingsResponseDataStructure.php',
        '../Lib/RestAPI/Workers/Controller.php',
        '../Lib/RestAPI/Transcripts/DataStructure.php',
        '../Lib/RestAPI/Transcripts/ListDataStructure.php',
        '../Lib/RestAPI/TranscriptEvents/DataStructure.php',
        '../Lib/RestAPI/CallTranscripts/DataStructure.php',
        '../Lib/RestAPI/CallTranscriptEvents/DataStructure.php',
        '../Lib/RestAPI/Jobs/Controller.php',
        '../Lib/RestAPI/Transcripts/Controller.php',
        '../Lib/RestAPI/TranscriptEvents/Controller.php',
        '../Lib/RestAPI/CallTranscripts/Controller.php',
        '../Lib/RestAPI/CallTranscriptEvents/Controller.php',
    ] as $file) {
        require_once __DIR__ . '/' . $file;
    }

    function expectOpenApi(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /** @return list<object> */
    function methodAttributes(string $class, string $method, string $attribute): array
    {
        return array_map(
            static fn(ReflectionAttribute $item): object => $item->newInstance(),
            (new ReflectionMethod($class, $method))->getAttributes($attribute)
        );
    }

    /** @return list<object> */
    function classAttributes(string $class, string $attribute): array
    {
        return array_map(
            static fn(ReflectionAttribute $item): object => $item->newInstance(),
            (new ReflectionClass($class))->getAttributes($attribute)
        );
    }

    $operationContracts = [
        JobsController::class => [
            'getNext' => 'getNextLocalSpeechToTextJob',
            'download' => 'downloadLocalSpeechToTextJobFile',
            'submitResult' => 'submitLocalSpeechToTextResult',
            'submitError' => 'submitLocalSpeechToTextError',
            'heartbeat' => 'heartbeatLocalSpeechToTextJob',
            'release' => 'releaseLocalSpeechToTextJob',
        ],
        WorkersController::class => [
            'register' => 'registerLocalSTTWorker',
            'getProcessingSettings' => 'getLocalSTTWorkerProcessingSettings',
        ],
        TranscriptsController::class => [
            'getList' => 'listLocalSpeechToTextTranscripts',
            'getRecord' => 'getLocalSpeechToTextTranscript',
        ],
        TranscriptEventsController::class => [
            'getList' => 'listLocalSpeechToTextTranscriptEvents',
        ],
        CallTranscriptsController::class => [
            'getRecord' => 'getLocalSpeechToTextCallTranscript',
        ],
        CallTranscriptEventsController::class => [
            'getList' => 'listLocalSpeechToTextCallTranscriptEvents',
        ],
    ];

    $operationIds = [];
    foreach ($operationContracts as $controller => $methods) {
        $resources = classAttributes($controller, ApiResource::class);
        expectOpenApi(count($resources) === 1, "{$controller} must have one API resource attribute");
        expectOpenApi(
            $resources[0]->tags === ['Local Speech To Text'],
            "{$controller} must use the shared Local Speech To Text tag"
        );
        expectOpenApi(
            str_starts_with($resources[0]->description, 'module_local_speech_to_text_'),
            "{$controller} resource description must use a module translation key"
        );

        foreach ($methods as $method => $expectedOperationId) {
            $operations = methodAttributes($controller, $method, ApiOperation::class);
            expectOpenApi(count($operations) === 1, "{$controller}::{$method} must have one API operation");
            expectOpenApi(
                $operations[0]->operationId === $expectedOperationId,
                "{$controller}::{$method} operationId changed"
            );
            expectOpenApi(
                str_starts_with($operations[0]->summary, 'module_local_speech_to_text_')
                    && str_starts_with($operations[0]->description, 'module_local_speech_to_text_'),
                "{$controller}::{$method} summary and description must use module translation keys"
            );
            $operationIds[] = $operations[0]->operationId;

            foreach (methodAttributes($controller, $method, ApiResponse::class) as $response) {
                expectOpenApi(
                    $response->description !== 'rest_response_200_ok',
                    "{$controller}::{$method} must not expose the undefined rest_response_200_ok key"
                );
                expectOpenApi(
                    str_starts_with($response->description, 'rest_response_')
                        || str_starts_with($response->description, 'module_local_speech_to_text_'),
                    "{$controller}::{$method} response {$response->statusCode} must use a translation key"
                );
            }
            foreach (methodAttributes($controller, $method, ApiDataSchema::class) as $schema) {
                expectOpenApi(
                    $schema->schemaName !== null && str_starts_with($schema->getSchemaName(), 'LocalSTT'),
                    "{$controller}::{$method} must use an explicit LocalSTT component name"
                );
            }
        }
    }
    expectOpenApi(count($operationIds) === 13, 'Local STT Swagger must expose exactly 13 operations');
    expectOpenApi(count(array_unique($operationIds)) === 13, 'Local STT operationIds must be unique');

    $responseStatusContracts = [
        [JobsController::class, 'getNext', [200, 401, 403, 404, 422, 500]],
        [JobsController::class, 'download', [200, 206, 401, 403, 404, 409, 413, 422, 500]],
        [JobsController::class, 'submitResult', [200, 401, 403, 404, 409, 422, 500]],
        [JobsController::class, 'submitError', [200, 401, 403, 404, 409, 422, 500]],
        [JobsController::class, 'heartbeat', [200, 401, 403, 404, 409, 422, 500]],
        [JobsController::class, 'release', [200, 401, 403, 404, 409, 422, 500]],
        [WorkersController::class, 'register', [200, 401, 403, 409, 422, 500]],
        [WorkersController::class, 'getProcessingSettings', [200, 401, 403, 404, 422, 500]],
        [TranscriptsController::class, 'getList', [200, 401, 403, 500]],
        [TranscriptsController::class, 'getRecord', [200, 401, 403, 404, 500]],
        [TranscriptEventsController::class, 'getList', [200, 401, 403, 500]],
        [CallTranscriptsController::class, 'getRecord', [200, 401, 403, 404, 500]],
        [CallTranscriptEventsController::class, 'getList', [200, 401, 403, 500]],
    ];
    foreach ($responseStatusContracts as [$controller, $method, $expectedStatuses]) {
        $statuses = array_map(
            static fn(object $response): int => $response->statusCode,
            methodAttributes($controller, $method, ApiResponse::class)
        );
        sort($statuses);
        $sortedExpected = $expectedStatuses;
        sort($sortedExpected);
        expectOpenApi(
            $statuses === $sortedExpected,
            "{$controller}::{$method} must document all runtime and authentication response statuses"
        );
    }

    $detailSchemaContracts = [
        [JobsController::class, 'getNext', 'LocalSTTJob'],
        [WorkersController::class, 'register', 'LocalSTTWorker'],
        [TranscriptsController::class, 'getRecord', 'LocalSTTTranscript'],
        [CallTranscriptsController::class, 'getRecord', 'LocalSTTCallTranscript'],
    ];
    foreach ($detailSchemaContracts as [$controller, $method, $expectedSchemaName]) {
        $schemas = methodAttributes($controller, $method, ApiDataSchema::class);
        expectOpenApi(count($schemas) === 1, "{$controller}::{$method} must have one detail schema");
        expectOpenApi(
            $schemas[0]->schemaName === $expectedSchemaName,
            "{$controller}::{$method} must use {$expectedSchemaName} instead of a generic schema name"
        );
        expectOpenApi(
            str_starts_with($schemas[0]->getSchemaName(), 'LocalSTT'),
            "{$controller}::{$method} must register a LocalSTT-prefixed component"
        );
    }

    $downloadMethod = new ReflectionMethod(JobsController::class, 'download');
    expectOpenApi(
        $downloadMethod->getAttributes(ApiDataSchema::class) === [],
        'Binary download must not use a JSON ApiDataSchema'
    );

    $downloadResponses = [];
    foreach (methodAttributes(JobsController::class, 'download', ApiResponse::class) as $response) {
        $downloadResponses[$response->statusCode] = $response->toOpenApi();
    }
    foreach ([200, 206, 404, 413] as $status) {
        expectOpenApi(isset($downloadResponses[$status]), "Download OpenAPI must document HTTP {$status}");
    }
    foreach ([200, 206] as $status) {
        foreach (['audio/ogg', 'audio/webm', 'audio/wav', 'audio/mpeg', 'application/octet-stream'] as $mime) {
            $schema = $downloadResponses[$status]['content'][$mime]['schema'] ?? [];
            expectOpenApi(
                ($schema['type'] ?? null) === 'string' && ($schema['format'] ?? null) === 'binary',
                "Download HTTP {$status} must generate a binary schema for {$mime}"
            );
        }
    }
    expectOpenApi(
        isset($downloadResponses[206]['headers']['Content-Range'], $downloadResponses[206]['headers']['Accept-Ranges']),
        'Partial download must document Content-Range and Accept-Ranges headers'
    );
    foreach ([404, 413] as $status) {
        expectOpenApi(
            ($downloadResponses[$status]['content']['application/json']['schema']['$ref'] ?? null)
                === '#/components/schemas/PBXApiResult',
            "Download HTTP {$status} must use the JSON PBXApiResult envelope"
        );
    }

    $submitParameters = methodAttributes(JobsController::class, 'submitResult', ApiParameterRef::class);
    expectOpenApi(
        in_array('technical_info', array_map(static fn(object $parameter): string => $parameter->name, $submitParameters), true),
        'submitResult must document technical_info'
    );

    $settingsParameters = methodAttributes(WorkersController::class, 'getProcessingSettings', ApiParameterRef::class);
    expectOpenApi(
        count($settingsParameters) === 1
            && $settingsParameters[0]->name === 'worker_uid'
            && $settingsParameters[0]->required === true,
        'Processing-settings endpoint must require worker_uid'
    );
    $settingsResponses = array_map(
        static fn(object $response): int => $response->statusCode,
        methodAttributes(WorkersController::class, 'getProcessingSettings', ApiResponse::class)
    );
    sort($settingsResponses);
    expectOpenApi(
        $settingsResponses === [200, 401, 403, 404, 422, 500],
        'Processing-settings endpoint must document 200/401/403/404/422/500 responses'
    );
    $settingsSchemas = methodAttributes(WorkersController::class, 'getProcessingSettings', ApiDataSchema::class);
    expectOpenApi(
        count($settingsSchemas) === 1
            && $settingsSchemas[0]->schemaName === 'LocalSTTWorkerProcessingSettingsResponse'
            && $settingsSchemas[0]->type === 'custom',
        'Processing-settings endpoint must expose its exact response schema'
    );

    $mutationContracts = [
        'submitResult' => ['array', []],
        'submitError' => ['object', ['status', 'attempts']],
        'heartbeat' => ['object', ['status', 'lease_expires_at']],
        'release' => ['object', ['status']],
    ];
    $mutationSchemaClasses = [];
    foreach ($mutationContracts as $method => [$type, $properties]) {
        $attributes = methodAttributes(JobsController::class, $method, ApiDataSchema::class);
        expectOpenApi(count($attributes) === 1, "{$method} must have one response data schema");
        /** @var ApiDataSchema $schemaAttribute */
        $schemaAttribute = $attributes[0];
        expectOpenApi($schemaAttribute->type === 'custom', "{$method} must use its own custom response schema");
        $mutationSchemaClasses[] = $schemaAttribute->schemaClass;
        $schema = $schemaAttribute->schemaClass::getDetailSchema();
        expectOpenApi(($schema['type'] ?? null) === $type, "{$method} response schema has the wrong type");
        expectOpenApi(
            array_keys($schema['properties'] ?? []) === $properties,
            "{$method} response schema does not match its runtime data"
        );
    }
    expectOpenApi(
        count(array_unique($mutationSchemaClasses)) === count($mutationSchemaClasses),
        'Each job mutation must have a distinct response schema class'
    );

    $jobSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure::getDetailSchema();
    $jobParameters = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure::getParameterDefinitions()['request'];
    foreach (['id', 'worker_uid', 'lease_token', 'transcript', 'language', 'duration_ms', 'model_used', 'technical_info', 'error_msg'] as $parameterName) {
        expectOpenApi(
            str_starts_with((string)($jobParameters[$parameterName]['description'] ?? ''), 'module_local_speech_to_text_Api'),
            "Job parameter {$parameterName} must use a localized OpenAPI description"
        );
    }
    $transcriptExample = $jobParameters['transcript']['example'] ?? null;
    expectOpenApi(
        is_array($transcriptExample)
            && isset($transcriptExample[0]['segment_id'], $transcriptExample[0]['start_ms'], $transcriptExample[0]['end_ms'], $transcriptExample[0]['text']),
        'submitResult must include a complete transcript segment example'
    );
    expectOpenApi(
        ($jobParameters['duration_ms']['minimum'] ?? null) === 0,
        'duration_ms must document its non-negative constraint'
    );
    expectOpenApi(
        ($jobSchema['properties']['has_settings_changed']['type'] ?? null) === 'boolean',
        'Next-job OpenAPI schema must expose has_settings_changed as a boolean'
    );
    $durationHint = $jobSchema['properties']['job']['oneOf'][0]['properties']['duration_hint'] ?? [];
    expectOpenApi(
        ($durationHint['type'] ?? null) === 'number'
            && ($durationHint['nullable'] ?? null) === true
            && ($durationHint['description'] ?? null) === 'module_local_speech_to_text_ApiFieldDurationHint',
        'Claimed job duration_hint must document nullable CDR seconds'
    );
    expectOpenApi(
        isset($jobSchema['example']['job']['id'], $jobSchema['example']['job']['lease_token'])
            && array_key_exists('has_settings_changed', $jobSchema['example']),
        'Next-job schema must include a coherent claimed-job response example'
    );

    $workerParameters = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\DataStructure::getParameterDefinitions()['request'];
    foreach (['worker_uid', 'name', 'model'] as $parameterName) {
        expectOpenApi(
            str_starts_with((string)($workerParameters[$parameterName]['description'] ?? ''), 'module_local_speech_to_text_Api'),
            "Worker parameter {$parameterName} must use a localized OpenAPI description"
        );
    }
    expectOpenApi(
        ($workerParameters['model']['description'] ?? null) === 'module_local_speech_to_text_ApiParamWorkerModel',
        'Worker model parameter must document its compatibility-only behavior'
    );
    $workerSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\DataStructure::getDetailSchema();
    expectOpenApi(
        isset($workerSchema['example']['worker_id'], $workerSchema['example']['poll_interval']),
        'Worker registration schema must include a response example'
    );

    $processingSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\ProcessingSettingsResponseDataStructure::getDetailSchema();
    expectOpenApi(
        isset($processingSchema['example']['schema_version'], $processingSchema['example']['processing_settings']['temperature']),
        'Processing-settings schema must include a complete response example'
    );
    foreach ($processingSchema['properties']['processing_settings']['properties'] ?? [] as $field => $schema) {
        expectOpenApi(
            str_starts_with((string)($schema['description'] ?? ''), 'module_local_speech_to_text_Api'),
            "Processing setting {$field} must use a localized OpenAPI description"
        );
        expectOpenApi(array_key_exists('example', $schema), "Processing setting {$field} must include an example");
    }

    $listContracts = [
        [TranscriptsController::class, ['items', 'pagination']],
        [TranscriptEventsController::class, ['events', 'next_cursor', 'limit']],
        [CallTranscriptEventsController::class, ['events', 'next_cursor', 'limit']],
    ];
    foreach ($listContracts as [$controller, $properties]) {
        $attributes = methodAttributes($controller, 'getList', ApiDataSchema::class);
        expectOpenApi(count($attributes) === 1, "{$controller}::getList must have one data schema");
        /** @var ApiDataSchema $schemaAttribute */
        $schemaAttribute = $attributes[0];
        expectOpenApi($schemaAttribute->type === 'custom', "{$controller}::getList must document the wrapper object");
        $schema = $schemaAttribute->schemaClass::getDetailSchema();
        expectOpenApi(
            array_keys($schema['properties'] ?? []) === $properties,
            "{$controller}::getList schema does not match its runtime wrapper"
        );
    }

    $transcriptParameters = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\DataStructure::getParameterDefinitions()['request'];
    expectOpenApi(
        ($transcriptParameters['limit']['minimum'] ?? null) === 1
            && ($transcriptParameters['limit']['maximum'] ?? null) === 200
            && ($transcriptParameters['offset']['minimum'] ?? null) === 0,
        'Transcript list must document pagination constraints'
    );
    foreach ($transcriptParameters as $name => $parameter) {
        expectOpenApi(
            str_starts_with((string)($parameter['description'] ?? ''), 'module_local_speech_to_text_Api'),
            "Transcript parameter {$name} must use a localized OpenAPI description"
        );
    }
    $transcriptListSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\ListDataStructure::getDetailSchema();
    expectOpenApi(
        isset($transcriptListSchema['example']['items'][0]['result_id'], $transcriptListSchema['example']['pagination']['total']),
        'Transcript list must include a recording-level result and pagination example'
    );
    $transcriptDetailSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\DataStructure::getDetailSchema();
    expectOpenApi(
        isset($transcriptDetailSchema['example']['result_id'], $transcriptDetailSchema['example']['transcript']['turns'][0]['turn_id']),
        'Transcript detail must include a normalized-turn example linked by result_id'
    );

    $eventParameters = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents\DataStructure::getParameterDefinitions()['request'];
    expectOpenApi(
        ($eventParameters['cursor']['pattern'] ?? null) === '^\\d+:\\d+$'
            && ($eventParameters['limit']['minimum'] ?? null) === 1
            && ($eventParameters['limit']['maximum'] ?? null) === 200,
        'Transcript event stream must document cursor and limit constraints'
    );
    $transcriptEventSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents\DataStructure::getDetailSchema();
    expectOpenApi(
        ($transcriptEventSchema['example']['events'][0]['result_id'] ?? null)
            === ($transcriptDetailSchema['example']['result_id'] ?? null),
        'Transcript event example must link to the transcript detail example'
    );

    $callParameters = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts\DataStructure::getParameterDefinitions()['request'];
    expectOpenApi(
        ($callParameters['id']['minimum'] ?? null) === 1
            && ($callParameters['revision']['minimum'] ?? null) === 1,
        'Logical-call detail must document positive aggregate and revision identifiers'
    );
    $callSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts\DataStructure::getDetailSchema();
    expectOpenApi(isset($callSchema['properties']['created_at']), 'Logical-call schema must expose runtime created_at');
    expectOpenApi(
        isset($callSchema['properties']['part_manifest']['items']['properties']['result_id'])
            && isset($callSchema['properties']['transcript']['properties']['segments']['items']['properties']['absolute_start_ms'])
            && isset($callSchema['properties']['transcript']['properties']['turns']['items']['properties']['source_result_ids']),
        'Logical-call schema must describe its part manifest, segments, and turns'
    );

    $callEventParameters = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscriptEvents\DataStructure::getParameterDefinitions()['request'];
    expectOpenApi(
        ($callEventParameters['cursor']['pattern'] ?? null) === '^\\d+:\\d+$'
            && ($callEventParameters['limit']['maximum'] ?? null) === 200,
        'Logical-call event stream must document cursor and limit constraints'
    );
    $callEventSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscriptEvents\DataStructure::getDetailSchema();
    expectOpenApi(
        ($callEventSchema['example']['events'][0]['aggregate_id'] ?? null)
            === ($callSchema['example']['aggregate_id'] ?? null)
            && ($callEventSchema['example']['events'][0]['revision'] ?? null)
            === ($callSchema['example']['revision'] ?? null),
        'Logical-call event example must link aggregate_id and revision to the detail example'
    );

    echo "OpenAPI contract OK\n";
}
