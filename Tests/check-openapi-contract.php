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
}

namespace MikoPBX\PBXCoreREST\Controllers {
    abstract class BaseRestController
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
    use MikoPBX\PBXCoreREST\Attributes\ApiResource;

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

    $tombstoneMethods = [
        ['Workers', 'register'],
        ['Jobs', 'getNext'],
        ['Jobs', 'download'],
        ['Jobs', 'submitResult'],
        ['Jobs', 'submitError'],
        ['Jobs', 'heartbeat'],
        ['Jobs', 'release'],
    ];
    foreach ($tombstoneMethods as [$resource, $method]) {
        $controller = 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\' . $resource . '\\Controller';
        $operations = (new ReflectionMethod($controller, $method))->getAttributes(
            'MikoPBX\\PBXCoreREST\\Attributes\\ApiOperation'
        );
        expectOpenApi(
            $operations === [],
            "{$resource}::{$method} is a runtime tombstone and must not be generated in OpenAPI"
        );
    }

    echo "OpenAPI contract OK\n";
}
