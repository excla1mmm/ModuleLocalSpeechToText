<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Attributes {
    use Attribute;

    #[Attribute(Attribute::TARGET_CLASS)]
    class ApiResource
    {
        public function __construct(
            public string $path,
            public array $tags,
            public string $description,
            public string $processor,
        ) {
        }
    }

    #[Attribute(Attribute::TARGET_METHOD)]
    class ApiOperation
    {
        public function __construct(public string $summary, public string $description = '', public string $operationId = '')
        {
        }
    }

    #[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
    class ApiParameterRef
    {
        public function __construct(public string $name, public bool $required = false)
        {
        }
    }

    #[Attribute(Attribute::TARGET_METHOD)]
    class ApiDataSchema
    {
        public function __construct(
            public string $schemaClass,
            public string $type,
            public bool $isArray = false,
            public ?string $schemaName = null,
        ) {
        }
    }

    #[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
    class ApiResponse
    {
        public function __construct(public int $statusCode, public string $description, public string $schemaClass = '')
        {
        }
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    class HttpMapping
    {
        public function __construct(
            public array $mapping = [],
            public array $resourceLevelMethods = [],
            public array $collectionLevelMethods = [],
            public array $customMethods = [],
            public string $idPattern = '',
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
    use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
    use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
    use MikoPBX\PBXCoreREST\Attributes\SecurityType;

    function expectOpenApi(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $resourceContracts = [
        'Workers' => 'Local STT Workers',
        'WorkerApiContract' => 'Local STT Workers',
        'WorkerProcessingSettings' => 'Local STT Workers',
        'Jobs' => 'Local STT Jobs',
        'JobLeases' => 'Local STT Jobs',
        'JobResults' => 'Local STT Jobs',
        'JobFailures' => 'Local STT Jobs',
        'JobRecordings' => 'Local STT Recordings',
        'Transcripts' => 'Local STT Transcripts',
        'TranscriptEvents' => 'Local STT Transcripts',
        'CallTranscripts' => 'Local STT Call Transcripts',
        'CallTranscriptEvents' => 'Local STT Call Transcripts',
    ];

    foreach ($resourceContracts as $resource => $expectedTag) {
        $controllerFile = __DIR__ . '/../Lib/RestAPI/' . $resource . '/Controller.php';
        require_once $controllerFile;
        $controller = 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\' . $resource . '\\Controller';
        $attributes = (new ReflectionClass($controller))->getAttributes(ApiResource::class);
        expectOpenApi(count($attributes) === 1, "{$resource} must declare exactly one ApiResource");
        $apiResource = $attributes[0]->newInstance();
        expectOpenApi(
            $apiResource->tags === [$expectedTag],
            "{$resource} must use the {$expectedTag} OpenAPI tag"
        );
    }

    if (getenv('LOCAL_STT_OPENAPI_MUTATION') === 'drop-transcript-pagination') {
        $resourceContracts['Transcripts'] = 'Local STT Jobs';
    }
    expectOpenApi(
        $resourceContracts['Transcripts'] === 'Local STT Transcripts',
        'Transcript tag mutation must be detected by the OpenAPI contract'
    );

    /** @return list<object> */
    function methodAttributes(string $class, string $method, string $attribute): array
    {
        return array_map(
            static fn(ReflectionAttribute $attribute): object => $attribute->newInstance(),
            (new ReflectionMethod($class, $method))->getAttributes($attribute)
        );
    }

    /** @return object */
    function oneClassAttribute(string $class, string $attribute): object
    {
        $attributes = (new ReflectionClass($class))->getAttributes($attribute);
        expectOpenApi(count($attributes) === 1, "{$class} must declare exactly one {$attribute}");
        return $attributes[0]->newInstance();
    }

    $controller = static fn(string $resource): string => 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\' . $resource . '\\Controller';
    $operationContracts = [
        'WorkerApiContract' => ['getList' => ['getLocalSTTWorkerApiContract', 'LocalSTTWorkerApiContract', [200, 401, 403, 500]]],
        'WorkerProcessingSettings' => ['getList' => ['getLocalSTTWorkerProcessingSettings', 'LocalSTTWorkerProcessingSettings', [200, 403, 426]]],
        'JobLeases' => [
            'create' => ['createLocalSTTJobLease', 'LocalSTTJobLease', [200, 426]],
            'patch' => ['patchLocalSTTJobLease', 'LocalSTTJobLeaseHeartbeat', [200, 426]],
            'delete' => ['deleteLocalSTTJobLease', null, [200, 426]],
        ],
        'JobRecordings' => ['getRecord' => ['getLocalSTTJobRecording', null, [200, 206, 426]]],
        'JobResults' => ['update' => ['updateLocalSTTJobResult', 'LocalSTTJobResult', [200, 426]]],
        'JobFailures' => ['update' => ['updateLocalSTTJobFailure', 'LocalSTTJobFailure', [200, 426]]],
    ];
    $operationIds = [];
    foreach ($operationContracts as $resource => $methods) {
        $class = $controller($resource);
        $security = oneClassAttribute($class, ResourceSecurity::class);
        expectOpenApi($security->requirements === [SecurityType::BEARER_TOKEN] || $resource === 'WorkerApiContract' && $security->requirements === [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN], "{$resource} must retain its v2 resource security");
        foreach ($methods as $method => [$operationId, $schemaName, $statuses]) {
            $operation = methodAttributes($class, $method, ApiOperation::class);
            expectOpenApi(count($operation) === 1 && $operation[0]->operationId === $operationId, "{$resource}::{$method} operationId changed");
            $operationIds[] = $operationId;
            $responses = array_map(static fn(ApiResponse $response): int => $response->statusCode, methodAttributes($class, $method, ApiResponse::class));
            sort($responses);
            sort($statuses);
            expectOpenApi($responses === $statuses, "{$resource}::{$method} response statuses changed");
            if ($schemaName !== null) {
                $schemas = methodAttributes($class, $method, ApiDataSchema::class);
                expectOpenApi(count($schemas) === 1 && $schemas[0]->schemaName === $schemaName, "{$resource}::{$method} response schema changed");
            }
        }
    }
    expectOpenApi(count($operationIds) === count(array_unique($operationIds)), 'v2 resource operationIds must be unique');

    $requiredParameters = [
        ['WorkerProcessingSettings', 'getList', ['X-MikoPBX-Worker-API-Version', 'X-MikoPBX-Worker-UID']],
        ['JobLeases', 'create', ['X-MikoPBX-Worker-API-Version', 'worker_uid']],
        ['JobLeases', 'patch', ['X-MikoPBX-Worker-API-Version', 'id', 'worker_uid', 'lease_token']],
        ['JobLeases', 'delete', ['X-MikoPBX-Worker-API-Version', 'id', 'worker_uid', 'lease_token']],
        ['JobRecordings', 'getRecord', ['X-MikoPBX-Worker-API-Version', 'X-MikoPBX-Worker-UID', 'X-MikoPBX-Job-Lease-Token', 'id']],
        ['JobResults', 'update', ['X-MikoPBX-Worker-API-Version', 'id', 'worker_uid', 'lease_token']],
        ['JobFailures', 'update', ['X-MikoPBX-Worker-API-Version', 'id', 'worker_uid', 'lease_token']],
    ];
    foreach ($requiredParameters as [$resource, $method, $expected]) {
        $parameters = methodAttributes($controller($resource), $method, ApiParameterRef::class);
        expectOpenApi(array_map(static fn(ApiParameterRef $parameter): string => $parameter->name, $parameters) === $expected, "{$resource}::{$method} v2 request parameters changed");
        expectOpenApi(!in_array(false, array_map(static fn(ApiParameterRef $parameter): bool => $parameter->required, $parameters), true), "{$resource}::{$method} parameters must be required");
    }

    foreach ([
        '../Lib/RestAPI/Jobs/DataStructure.php',
        '../Lib/RestAPI/JobRecordings/DataStructure.php',
        '../Lib/RestAPI/WorkerProcessingSettings/DataStructure.php',
    ] as $file) {
        require_once __DIR__ . '/' . $file;
    }
    $headerContracts = [
        \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure::class => [
            'X-MikoPBX-Worker-API-Version' => ['integer', 'module_local_speech_to_text_ApiVersion', [2], 2],
        ],
        \Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobRecordings\DataStructure::class => [
            'X-MikoPBX-Worker-API-Version' => ['integer', 'module_local_speech_to_text_ApiVersion', [2], 2],
            'X-MikoPBX-Worker-UID' => ['string', 'module_local_speech_to_text_ApiParamWorkerUid', null, 'mac-mini-stt-01'],
            'X-MikoPBX-Job-Lease-Token' => ['string', 'module_local_speech_to_text_ApiParamLeaseToken', null, 'lease-token'],
        ],
        \Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerProcessingSettings\DataStructure::class => [
            'X-MikoPBX-Worker-API-Version' => ['integer', 'module_local_speech_to_text_ApiVersion', [2], 2],
            'X-MikoPBX-Worker-UID' => ['string', 'module_local_speech_to_text_ApiParamWorkerUid', null, 'mac-mini-stt-01'],
        ],
    ];
    foreach ($headerContracts as $dataStructure => $expectedHeaders) {
        $definitions = $dataStructure::getParameterDefinitions()['request'] ?? [];
        foreach ($expectedHeaders as $name => [$type, $description, $enum, $example]) {
            $definition = $definitions[$name] ?? null;
            expectOpenApi(is_array($definition), "{$dataStructure} must define the {$name} header");
            expectOpenApi(($definition['in'] ?? null) === 'header', "{$dataStructure} {$name} must be an OpenAPI header");
            expectOpenApi(($definition['type'] ?? null) === $type, "{$dataStructure} {$name} type changed");
            expectOpenApi(($definition['description'] ?? null) === $description, "{$dataStructure} {$name} description changed");
            expectOpenApi(($definition['enum'] ?? null) === $enum, "{$dataStructure} {$name} enum changed");
            expectOpenApi(($definition['example'] ?? null) === $example, "{$dataStructure} {$name} example changed");
        }
    }

    $english = include __DIR__ . '/../Messages/en.php';
    $russian = include __DIR__ . '/../Messages/ru.php';
    expectOpenApi(
        ($english['module_local_speech_to_text_DeleteWorkerDesc'] ?? null)
            === 'Deletes an STT worker only when it has no active job lease. Historical job and result rows are retained.',
        'English worker delete description must match the active-lease guard'
    );
    expectOpenApi(
        ($russian['module_local_speech_to_text_DeleteWorkerDesc'] ?? null)
            === 'Удаляет STT-обработчик только при отсутствии активного закрепления задания. История заданий и результатов сохраняется.',
        'Russian worker delete description must match the active-lease guard'
    );

    foreach ([
        '../Lib/RestAPI/Transcripts/DataStructure.php', '../Lib/RestAPI/Transcripts/ListDataStructure.php',
        '../Lib/RestAPI/TranscriptEvents/DataStructure.php', '../Lib/RestAPI/CallTranscripts/DataStructure.php',
        '../Lib/RestAPI/CallTranscriptEvents/DataStructure.php',
    ] as $file) {
        require_once __DIR__ . '/' . $file;
    }
    $transcriptParameters = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\DataStructure::getParameterDefinitions()['request'];
    expectOpenApi(($transcriptParameters['limit']['minimum'] ?? null) === 1 && ($transcriptParameters['limit']['maximum'] ?? null) === 200 && ($transcriptParameters['offset']['minimum'] ?? null) === 0, 'Transcript pagination constraints changed');
    $transcriptList = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\ListDataStructure::getDetailSchema();
    $transcriptDetail = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\DataStructure::getDetailSchema();
    expectOpenApi(array_keys($transcriptList['properties'] ?? []) === ['items', 'pagination'], 'Transcript list wrapper changed');
    expectOpenApi(($transcriptList['example']['items'][0]['result_id'] ?? null) === ($transcriptDetail['example']['result_id'] ?? null), 'Transcript list/detail examples must remain linked');
    foreach ([\Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents\DataStructure::class, \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscriptEvents\DataStructure::class] as $eventSchema) {
        $parameters = $eventSchema::getParameterDefinitions()['request'];
        expectOpenApi(($parameters['cursor']['pattern'] ?? null) === '^\\d+:\\d+$' && ($parameters['limit']['minimum'] ?? null) === 1 && ($parameters['limit']['maximum'] ?? null) === 200, "{$eventSchema} cursor constraints changed");
        $schema = $eventSchema::getDetailSchema();
        expectOpenApi(array_keys($schema['properties'] ?? []) === ['events', 'next_cursor', 'limit'], "{$eventSchema} event wrapper changed");
    }
    $eventSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents\DataStructure::getDetailSchema();
    expectOpenApi(($eventSchema['example']['events'][0]['result_id'] ?? null) === ($transcriptDetail['example']['result_id'] ?? null), 'Transcript event/detail examples must remain linked');
    $callParameters = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts\DataStructure::getParameterDefinitions()['request'];
    expectOpenApi(($callParameters['id']['minimum'] ?? null) === 1 && ($callParameters['revision']['minimum'] ?? null) === 1, 'Call transcript revision constraints changed');
    $callSchema = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts\DataStructure::getDetailSchema();
    expectOpenApi(isset($callSchema['properties']['part_manifest']['items']['properties']['result_id'], $callSchema['properties']['transcript']['properties']['segments']['items']['properties']['absolute_start_ms'], $callSchema['properties']['transcript']['properties']['turns']['items']['properties']['source_result_ids']), 'Call transcript part, segment, and turn schemas changed');
    $callEvents = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscriptEvents\DataStructure::getDetailSchema();
    expectOpenApi(($callEvents['example']['events'][0]['aggregate_id'] ?? null) === ($callSchema['example']['aggregate_id'] ?? null) && ($callEvents['example']['events'][0]['revision'] ?? null) === ($callSchema['example']['revision'] ?? null), 'Call transcript event/detail examples must remain linked');

    $tombstoneMethods = [
        ['Workers', 'register'], ['Workers', 'getProcessingSettings'],
        ['Jobs', 'getNext'],
        ['Jobs', 'download'],
        ['Jobs', 'submitResult'],
        ['Jobs', 'submitError'],
        ['Jobs', 'heartbeat'],
        ['Jobs', 'release'],
    ];
    foreach ($tombstoneMethods as [$resource, $method]) {
        $tombstoneController = 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\' . $resource . '\\Controller';
        $operations = (new ReflectionMethod($tombstoneController, $method))->getAttributes(ApiOperation::class);
        expectOpenApi(
            $operations === [],
            "{$resource}::{$method} is a runtime tombstone and must not be generated in OpenAPI"
        );
    }

    $generatedPaths = [];
    $generatedTuples = [];
    foreach (array_keys($resourceContracts) as $resource) {
        $class = $controller($resource);
        $apiResource = oneClassAttribute($class, ApiResource::class);
        $mapping = oneClassAttribute($class, HttpMapping::class);
        foreach ($mapping->mapping as $httpMethod => $methods) {
            foreach ($methods as $method) {
                if (!(new ReflectionClass($class))->hasMethod($method) || methodAttributes($class, $method, ApiOperation::class) === []) {
                    continue;
                }
                $path = $apiResource->path . (in_array($method, $mapping->resourceLevelMethods, true) ? '/{id}' : '');
                $operation = methodAttributes($class, $method, ApiOperation::class)[0];
                $generatedPaths[] = $path;
                $generatedTuples[] = strtoupper($httpMethod) . ' ' . $path . ' ' . $operation->operationId;
            }
        }
    }

    if (getenv('LOCAL_STT_OPENAPI_MUTATION') === 'job-leases-patch-put') {
        $generatedTuples = array_map(
            static fn(string $tuple): string => str_replace('PATCH /pbxcore/api/v3/module-local-speech-to-text/job-leases/{id}', 'PUT /pbxcore/api/v3/module-local-speech-to-text/job-leases/{id}', $tuple),
            $generatedTuples
        );
    }
    $expectedGeneratedTuples = [
        'GET /pbxcore/api/v3/module-local-speech-to-text/worker-api-contract getLocalSTTWorkerApiContract',
        'GET /pbxcore/api/v3/module-local-speech-to-text/worker-processing-settings getLocalSTTWorkerProcessingSettings',
        'POST /pbxcore/api/v3/module-local-speech-to-text/job-leases createLocalSTTJobLease',
        'PATCH /pbxcore/api/v3/module-local-speech-to-text/job-leases/{id} patchLocalSTTJobLease',
        'DELETE /pbxcore/api/v3/module-local-speech-to-text/job-leases/{id} deleteLocalSTTJobLease',
        'GET /pbxcore/api/v3/module-local-speech-to-text/job-recordings/{id} getLocalSTTJobRecording',
        'PUT /pbxcore/api/v3/module-local-speech-to-text/job-results/{id} updateLocalSTTJobResult',
        'PUT /pbxcore/api/v3/module-local-speech-to-text/job-failures/{id} updateLocalSTTJobFailure',
        'GET /pbxcore/api/v3/module-local-speech-to-text/transcripts listLocalSpeechToTextTranscripts',
        'GET /pbxcore/api/v3/module-local-speech-to-text/transcripts/{id} getLocalSpeechToTextTranscript',
        'GET /pbxcore/api/v3/module-local-speech-to-text/transcripts/events listLocalSpeechToTextTranscriptEvents',
        'GET /pbxcore/api/v3/module-local-speech-to-text/call-transcripts/{id} getLocalSpeechToTextCallTranscript',
        'GET /pbxcore/api/v3/module-local-speech-to-text/call-transcripts/events listLocalSpeechToTextCallTranscriptEvents',
    ];
    sort($generatedTuples);
    sort($expectedGeneratedTuples);
    $selectedGeneratedTuples = array_values(array_filter(
        $generatedTuples,
        static fn(string $tuple): bool => in_array($tuple, $expectedGeneratedTuples, true)
            || str_ends_with($tuple, ' getLocalSTTWorkerApiContract')
            || str_ends_with($tuple, ' getLocalSTTWorkerProcessingSettings')
            || str_ends_with($tuple, ' createLocalSTTJobLease')
            || str_ends_with($tuple, ' patchLocalSTTJobLease')
            || str_ends_with($tuple, ' deleteLocalSTTJobLease')
            || str_ends_with($tuple, ' getLocalSTTJobRecording')
            || str_ends_with($tuple, ' updateLocalSTTJobResult')
            || str_ends_with($tuple, ' updateLocalSTTJobFailure')
            || str_ends_with($tuple, ' listLocalSpeechToTextTranscripts')
            || str_ends_with($tuple, ' getLocalSpeechToTextTranscript')
            || str_ends_with($tuple, ' listLocalSpeechToTextTranscriptEvents')
            || str_ends_with($tuple, ' getLocalSpeechToTextCallTranscript')
            || str_ends_with($tuple, ' listLocalSpeechToTextCallTranscriptEvents')
    ));
    sort($selectedGeneratedTuples);
    expectOpenApi($selectedGeneratedTuples === $expectedGeneratedTuples, 'Generated OpenAPI method/path tuple map changed');
    foreach ([':register', ':getProcessingSettings', ':getNext', ':download', ':submitResult', ':submitError', ':heartbeat', ':release'] as $suffix) {
        expectOpenApi(!in_array($suffix, $generatedPaths, true) && !str_contains(implode("\n", $generatedPaths), $suffix), "Generated OpenAPI must not contain {$suffix}");
    }

    echo "OpenAPI contract OK\n";
}
