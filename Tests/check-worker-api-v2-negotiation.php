<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib {
    class PBXApiResult
    {
        public bool $success = false;
        public int $httpCode = 0;
        public array $data = [];
        public array $messages = [];
        public string $processor = '';
        public string $function = '';
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

namespace MikoPBX\PBXCoreREST\Attributes {
    use Attribute;

    #[Attribute(Attribute::TARGET_CLASS)]
    class ApiResource
    {
        public function __construct(
            public string $path,
            public array $tags = [],
            public string $description = '',
            public ?string $processor = null,
        ) {
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
            public string|array|null $idPattern = null,
        ) {
        }
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    class ResourceSecurity
    {
        public function __construct(public string $resource, public array $requirements = [])
        {
        }
    }

    #[Attribute(Attribute::TARGET_METHOD)]
    class ApiDataSchema
    {
        public function __construct(
            public string $schemaClass,
            public string $type = 'detail',
            public ?string $schemaName = null,
            public bool $isArray = false,
        ) {
        }
    }

    #[Attribute(Attribute::TARGET_METHOD)]
    class ApiOperation
    {
        public function __construct(
            public string $summary,
            public string $description = '',
            public ?string $operationId = null,
        ) {
        }
    }

    #[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
    class ApiResponse
    {
        public function __construct(
            public int $statusCode,
            public string $description,
            public ?string $schema = null,
        ) {
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

namespace Phalcon\Di {
    class Injectable
    {
    }
}

namespace MikoPBX\Common\Models {
    final class ApiKeys
    {
        public int $id = 42;
        public string $full_permissions = '0';
        public string $allowed_paths = '{"/api/v3/module-local-speech-to-text/workers":"write"}';

        public static function findFirstById(int $id): ?self
        {
            return $id === 42 ? new self() : null;
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    final class WorkerApiKeyService
    {
        public const array WORKER_ALLOWED_PATHS = [
            '/api/v3/module-local-speech-to-text/workers' => 'write',
        ];
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib {
    class LocalSpeechToTextMain
    {
        public const string API_BASE_PATH = '/pbxcore/api/v3/module-local-speech-to-text';
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTSetting
    {
        public static int $lastCheckedId = -1;

        public static function isWorkerApiKeyIdAllowed(int $id): bool
        {
            self::$lastCheckedId = $id;

            return $id === 42;
        }
    }
}

namespace {
    use MikoPBX\PBXCoreREST\Attributes\ApiResource;
    use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
    use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
    use MikoPBX\PBXCoreREST\Attributes\SecurityType;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerApiContract\Controller;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerApiContract\DataStructure;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerApiContract\Processor;
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;

    /** @param mixed $actual */
    function expectV2(bool $condition, string $message, mixed $actual = null): void
    {
        if (!$condition) {
            $suffix = $actual === null ? '' : "\nActual: " . var_export($actual, true);
            throw new RuntimeException($message . $suffix);
        }
    }

    /**
     * @return array{result: mixed, output: string, exception: ?Throwable}
     */
    function captureMalformedV2(callable $operation): array
    {
        $result = null;
        $exception = null;
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $result = $operation();
        } catch (Throwable $error) {
            $exception = $error;
        } finally {
            restore_error_handler();
            $output = (string)ob_get_clean();
        }

        return ['result' => $result, 'output' => $output, 'exception' => $exception];
    }

    function expectBodyOnlyWorkerApiV2Source(string $source, string $label): void
    {
        expectV2(!str_contains($source, 'responseHeaders'), "{$label} must not depend on PBXApiResult response headers");
        expectV2(
            preg_match('/[\'\"]Upgrade(?:[\'\"]|\s*:)/i', $source) !== 1,
            "{$label} must not emit or depend on an Upgrade header",
        );
        expectV2(
            preg_match('/[\'\"]Cache-Control(?:[\'\"]|\s*:)/i', $source) !== 1,
            "{$label} must not emit or depend on a Cache-Control header",
        );
    }

    $root = dirname(__DIR__);
    $requiredFiles = [
        '/Lib/Services/WorkerApiContract.php',
        '/Lib/RestAPI/Common/WorkerRequestContext.php',
        '/Lib/RestAPI/WorkerApiContract/Processor.php',
        '/Lib/RestAPI/WorkerApiContract/DataStructure.php',
        '/Lib/RestAPI/WorkerApiContract/Controller.php',
    ];
    foreach ($requiredFiles as $relativeFile) {
        expectV2(is_file($root . $relativeFile), "Missing Task 2 production file: {$relativeFile}");
        $productionSource = (string)file_get_contents($root . $relativeFile);
        expectBodyOnlyWorkerApiV2Source($productionSource, $relativeFile);
        require_once $root . $relativeFile;
    }

    foreach ([
        '$result->responseHeaders = [];',
        "header('Upgrade: MikoPBX-Worker-API/2');",
        "header('Cache-Control: no-store');",
    ] as $forbiddenSource) {
        try {
            expectBodyOnlyWorkerApiV2Source($forbiddenSource, 'mutation fixture');
            throw new RuntimeException('forbidden body-only source mutation was accepted');
        } catch (RuntimeException $error) {
            expectV2(
                !str_contains($error->getMessage(), 'accepted'),
                'body-only production source guard did not reject the intended mutation',
            );
        }
    }

    $moduleMetadata = json_decode((string)file_get_contents($root . '/module.json'), true, flags: JSON_THROW_ON_ERROR);
    $packagedVersion = (string)($moduleMetadata['version'] ?? '');

    $contract = WorkerApiContract::contractResult();
    expectV2($contract->success === true, 'contract request must succeed');
    expectV2($contract->httpCode === 200, 'contract request must return HTTP 200', $contract->httpCode);
    expectV2($contract->data === [
        'contract' => 'MikoPBX-Worker-API',
        'api_version' => 2,
        'module' => 'ModuleLocalSpeechToText',
        'module_version' => $packagedVersion,
        'capabilities' => [
            'worker-crud',
            'worker-processing-settings',
            'job-leases',
            'job-recordings',
            'job-results',
            'job-failures',
        ],
    ], 'contract response shape changed', $contract->data);

    expectV2(
        WorkerApiContract::requireVersion(['httpHeaders' => ['x-mikopbx-worker-api-version' => '2']]) === null,
        'integer worker API v2 header must be accepted',
    );

    $invalidHeaders = [
        [],
        ['x-mikopbx-worker-api-version' => ''],
        ['x-mikopbx-worker-api-version' => 'abc'],
        ['x-mikopbx-worker-api-version' => '1'],
        ['x-mikopbx-worker-api-version' => '3'],
        ['X-MikoPBX-Worker-API-Version' => '2'],
    ];
    foreach ($invalidHeaders as $headers) {
        $error = WorkerApiContract::requireVersion(['httpHeaders' => $headers]);
        expectV2($error?->success === false, 'invalid version must fail');
        expectV2($error?->httpCode === 426, 'invalid version must return 426', $error?->httpCode);
        expectV2(($error?->data['error_code'] ?? '') === 'worker_upgrade_required', 'wrong error code', $error?->data);
        expectV2(($error?->data['module'] ?? '') === 'ModuleLocalSpeechToText', 'upgrade error module changed', $error?->data);
        expectV2(($error?->data['module_version'] ?? '') === $packagedVersion, 'upgrade error module version changed', $error?->data);
        expectV2(($error?->data['required_worker_api_version'] ?? null) === 2, 'required version missing', $error?->data);
    }

    $context = WorkerRequestContext::data([
        'data' => [
            'worker_uid' => 'body-worker',
            '_remote_addr' => 'spoofed',
            '_auth_type' => 'spoofed',
            '_api_key_id' => 999,
            '_http_method' => 'SPOOFED',
            '_worker_uid_header' => 'spoofed-worker',
            '_lease_token_header' => 'spoofed-token',
        ],
        'sessionContext' => [
            'remote_addr' => '10.0.0.42',
            'auth_type' => 'bearer_token',
            'token_id' => '42',
        ],
        'httpMethod' => 'PATCH',
        'httpHeaders' => [
            'x-mikopbx-worker-uid' => 'header-worker',
            'x-mikopbx-job-lease-token' => 'header-token',
            'X-MikoPBX-Worker-UID' => 'wrong-case-worker',
        ],
    ]);
    expectV2($context['worker_uid'] === 'body-worker', 'request context must preserve ordinary request data');
    expectV2($context['_remote_addr'] === '10.0.0.42', 'request context must take remote address from session context');
    expectV2($context['_auth_type'] === 'bearer_token', 'request context must take auth type from session context');
    expectV2($context['_api_key_id'] === 42, 'request context must take API key id from session context');
    expectV2($context['_http_method'] === 'PATCH', 'request context must take the HTTP method from the envelope');
    expectV2($context['_worker_uid_header'] === 'header-worker', 'request context must use the canonical worker UID header');
    expectV2($context['_lease_token_header'] === 'header-token', 'request context must use the canonical lease token header');
    expectV2($context['worker_uid'] === 'body-worker', 'JSON write callers must retain body worker_uid even when an optional UID header is present');
    expectV2(WorkerRequestContext::isRestrictedWorkerKey($context), 'configured bearer worker key must be restricted');
    expectV2(!WorkerRequestContext::isRestrictedWorkerKey(array_replace($context, ['_auth_type' => 'jwt'])), 'non-worker auth must not be restricted');
    expectV2(!WorkerRequestContext::isRestrictedWorkerKey(array_replace($context, ['_api_key_id' => 7])), 'unconfigured bearer key must not be restricted');

    $headerMalformations = [
        [
            'headers' => [
                'x-mikopbx-worker-uid' => ['secret-worker-array-sentinel'],
                'x-mikopbx-job-lease-token' => ['secret-lease-array-sentinel'],
            ],
            'sentinels' => ['secret-worker-array-sentinel', 'secret-lease-array-sentinel'],
        ],
        [
            'headers' => [
                'x-mikopbx-worker-uid' => (object)['secret' => 'secret-worker-object-sentinel'],
                'x-mikopbx-job-lease-token' => (object)['secret' => 'secret-lease-object-sentinel'],
            ],
            'sentinels' => ['secret-worker-object-sentinel', 'secret-lease-object-sentinel'],
        ],
    ];
    foreach ($headerMalformations as $malformation) {
        $outcome = captureMalformedV2(static fn(): array => WorkerRequestContext::data([
            'httpHeaders' => $malformation['headers'],
        ]));
        $diagnostics = $outcome['output'] . ($outcome['exception']?->getMessage() ?? '');
        foreach ($malformation['sentinels'] as $sentinel) {
            expectV2(!str_contains($diagnostics, $sentinel), 'malformed ownership header secret leaked into diagnostics');
        }
        expectV2($outcome['exception'] === null, 'malformed ownership headers must not warn or throw', $outcome['exception']?->getMessage());
        expectV2($outcome['output'] === '', 'malformed ownership headers must not emit output');
        expectV2(($outcome['result']['_worker_uid_header'] ?? null) === '', 'non-scalar worker UID header must normalize to empty string');
        expectV2(($outcome['result']['_lease_token_header'] ?? null) === '', 'non-scalar lease token header must normalize to empty string');
    }

    foreach ([
        ['value' => ['secret-api-key-array-sentinel'], 'sentinel' => 'secret-api-key-array-sentinel'],
        ['value' => (object)['secret' => 'secret-api-key-object-sentinel'], 'sentinel' => 'secret-api-key-object-sentinel'],
    ] as $malformation) {
        \Modules\ModuleLocalSpeechToText\Models\STTSetting::$lastCheckedId = -1;
        $outcome = captureMalformedV2(static fn(): bool => WorkerRequestContext::isRestrictedWorkerKey([
            '_auth_type' => 'bearer_token',
            '_api_key_id' => $malformation['value'],
        ]));
        $diagnostics = $outcome['output'] . ($outcome['exception']?->getMessage() ?? '');
        expectV2(!str_contains($diagnostics, $malformation['sentinel']), 'malformed API key secret leaked into diagnostics');
        expectV2($outcome['exception'] === null, 'malformed API key id must not warn or throw', $outcome['exception']?->getMessage());
        expectV2($outcome['output'] === '', 'malformed API key id must not emit output');
        expectV2($outcome['result'] === false, 'malformed API key id must never identify a restricted worker key');
        expectV2(
            \Modules\ModuleLocalSpeechToText\Models\STTSetting::$lastCheckedId === 0,
            'malformed API key id must normalize to integer zero',
            \Modules\ModuleLocalSpeechToText\Models\STTSetting::$lastCheckedId,
        );
    }

    $stripped = WorkerRequestContext::data(['data' => $context]);
    foreach (['_remote_addr', '_auth_type', '_api_key_id', '_http_method'] as $field) {
        expectV2($stripped[$field] === ($field === '_api_key_id' ? 0 : ''), "spoofed {$field} must be replaced by an empty trusted value", $stripped);
    }
    expectV2($stripped['_worker_uid_header'] === '', 'worker UID header must not be accepted from body data');
    expectV2($stripped['_lease_token_header'] === '', 'lease token header must not be accepted from body data');

    $processorResult = Processor::callBack(['action' => 'getList']);
    expectV2($processorResult->data === $contract->data, 'contract processor must return the shared contract result');
    expectV2($processorResult->processor === Processor::class . '::callBack', 'contract processor metadata changed');
    expectV2($processorResult->function === 'getList', 'contract processor function metadata changed');
    $unknownResult = Processor::callBack(['action' => 'getRecord']);
    expectV2($unknownResult->success === false, 'unknown contract action must fail');
    expectV2($unknownResult->httpCode === 404, 'unknown contract action must return HTTP 404', $unknownResult->httpCode);

    $schema = DataStructure::getDetailSchema();
    expectV2(($schema['required'] ?? []) === ['contract', 'api_version', 'module', 'module_version', 'capabilities'], 'contract schema required fields changed', $schema);
    expectV2(array_keys($schema['properties'] ?? []) === ['contract', 'api_version', 'module', 'module_version', 'capabilities'], 'contract schema properties changed', $schema);
    expectV2(($schema['properties']['contract']['enum'] ?? []) === ['MikoPBX-Worker-API'], 'contract schema identifier changed', $schema);
    expectV2(($schema['properties']['api_version']['enum'] ?? []) === [2], 'contract schema API version changed', $schema);

    $controller = new ReflectionClass(Controller::class);
    /** @var ApiResource $resource */
    $resource = $controller->getAttributes(ApiResource::class)[0]->newInstance();
    expectV2($resource->path === '/pbxcore/api/v3/module-local-speech-to-text/worker-api-contract', 'contract resource path changed', $resource->path);
    expectV2($resource->tags === ['Local STT Workers'], 'contract resource tag changed', $resource->tags);
    /** @var HttpMapping $mapping */
    $mapping = $controller->getAttributes(HttpMapping::class)[0]->newInstance();
    expectV2($mapping->mapping === ['GET' => ['getList']], 'contract route mapping changed', $mapping->mapping);
    expectV2($mapping->resourceLevelMethods === [], 'contract singleton must not have item methods');
    expectV2($mapping->collectionLevelMethods === ['getList'], 'contract singleton must be collection-level');
    expectV2($mapping->customMethods === [], 'contract singleton must not be a custom route');
    /** @var ResourceSecurity $security */
    $security = $controller->getAttributes(ResourceSecurity::class)[0]->newInstance();
    expectV2($security->requirements === [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN], 'contract resource security changed', $security->requirements);

    echo "Worker API v2 negotiation contract OK\n";
}
