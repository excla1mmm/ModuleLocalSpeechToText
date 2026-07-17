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
    use MikoPBX\PBXCoreREST\Attributes\ApiParameterRef;
    use MikoPBX\PBXCoreREST\Attributes\ApiResponse;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscriptEvents\Controller as CallTranscriptEventsController;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\Controller as JobsController;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents\Controller as TranscriptEventsController;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\Controller as TranscriptsController;

    $coreAttributes = dirname(__DIR__, 3) . '/Core/src/PBXCoreREST/Attributes';
    require_once $coreAttributes . '/ApiDataSchema.php';
    require_once $coreAttributes . '/ApiResponse.php';

    foreach ([
        '../Lib/RestAPI/Jobs/DataStructure.php',
        '../Lib/RestAPI/Jobs/SubmitResultResponseDataStructure.php',
        '../Lib/RestAPI/Jobs/SubmitErrorResponseDataStructure.php',
        '../Lib/RestAPI/Jobs/HeartbeatResponseDataStructure.php',
        '../Lib/RestAPI/Jobs/ReleaseResponseDataStructure.php',
        '../Lib/RestAPI/Transcripts/DataStructure.php',
        '../Lib/RestAPI/Transcripts/ListDataStructure.php',
        '../Lib/RestAPI/TranscriptEvents/DataStructure.php',
        '../Lib/RestAPI/CallTranscriptEvents/DataStructure.php',
        '../Lib/RestAPI/Jobs/Controller.php',
        '../Lib/RestAPI/Transcripts/Controller.php',
        '../Lib/RestAPI/TranscriptEvents/Controller.php',
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
    $durationHint = $jobSchema['properties']['job']['oneOf'][0]['properties']['duration_hint'] ?? [];
    expectOpenApi(
        ($durationHint['type'] ?? null) === 'number'
            && ($durationHint['nullable'] ?? null) === true
            && str_contains((string)($durationHint['description'] ?? ''), 'seconds'),
        'Claimed job duration_hint must document nullable CDR seconds'
    );

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

    echo "OpenAPI contract OK\n";
}
