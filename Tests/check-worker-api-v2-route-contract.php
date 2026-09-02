<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Attributes {
    use Attribute;

    #[Attribute(Attribute::TARGET_CLASS)]
    class ApiResource
    {
        public function __construct(public string $path, public array $tags = [], public string $description = '', public ?string $processor = null)
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
            public string|array|null $idPattern = null,
        ) {
        }
    }

    #[Attribute(Attribute::TARGET_METHOD)]
    class ApiOperation
    {
        public function __construct(public string $summary, public string $description = '', public ?string $operationId = null)
        {
        }
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    class ResourceSecurity
    {
        public function __construct(public string $resource, public array $requirements = [])
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

namespace Modules\ModuleLocalSpeechToText\Lib {
    class LocalSpeechToTextMain
    {
        public const string API_BASE_PATH = '/pbxcore/api/v3/module-local-speech-to-text';
    }
}

namespace {
    use MikoPBX\PBXCoreREST\Attributes\ApiOperation;
    use MikoPBX\PBXCoreREST\Attributes\ApiResource;
    use MikoPBX\PBXCoreREST\Attributes\HttpMapping;

    #[HttpMapping(idPattern: '[A-Za-z0-9._-]+')]
    final class WorkerIdPatternMutationFixture
    {
    }

    /** @param mixed $actual */
    function expectWorkerApiV2Route(bool $condition, string $message, mixed $actual = null): void
    {
        if (!$condition) {
            $suffix = $actual === null ? '' : "\nActual: " . var_export($actual, true);
            throw new RuntimeException($message . $suffix);
        }
    }

    /** @param array<string, array<string>|string> $mapping
     * @return list<array{method: string, path: string, action: string}>
     */
    function expandWorkerApiV2Mapping(string $path, string $idName, array $mapping, array $resourceLevel, array $collectionLevel): array
    {
        $routes = [];
        foreach ($mapping as $httpMethod => $operations) {
            foreach (is_string($operations) ? [$operations] : $operations as $action) {
                $isResource = in_array($action, $resourceLevel, true);
                $isCollection = in_array($action, $collectionLevel, true);
                expectWorkerApiV2Route($isResource || $isCollection, "{$path} {$action} must be routable at resource or collection level");
                if ($isResource) {
                    $routes[] = ['method' => strtoupper($httpMethod), 'path' => $path . '/{' . $idName . '}', 'action' => $action];
                }
                if ($isCollection) {
                    $routes[] = ['method' => strtoupper($httpMethod), 'path' => $path, 'action' => $action];
                }
            }
        }

        return $routes;
    }

    /** @param list<string> $expected
     * @param list<string> $actual
     */
    function assertExactWorkerApiV2Routes(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);
        expectWorkerApiV2Route($actual === $expected, 'Reflected v2 ApiResource/HttpMapping operation set changed', ['expected' => $expected, 'actual' => $actual]);
    }

    function assertNumericWorkerItemPattern(string|array|null $idPattern): void
    {
        expectWorkerApiV2Route(
            $idPattern === '[0-9]+',
            'Worker item CRUD must use the reflected numeric idPattern [0-9]+',
            $idPattern,
        );
    }

    /** @return list<array{method: string, path: string, action: string, controller: string, processor: string, operationCount: int, custom: bool, idPattern: string|array|null}> */
    function reflectedWorkerApiV2Operations(string $root, array $v2Resources, array $nonWorkerResources): array
    {
        $before = get_declared_classes();
        $controllerFiles = glob($root . '/Lib/RestAPI/*/Controller.php') ?: [];
        sort($controllerFiles);
        foreach ($controllerFiles as $controllerFile) {
            require_once $controllerFile;
        }

        $basePath = Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain::API_BASE_PATH;
        $operations = [];
        foreach (array_diff(get_declared_classes(), $before) as $controller) {
            $reflection = new ReflectionClass($controller);
            $resourceAttributes = $reflection->getAttributes(ApiResource::class);
            if (count($resourceAttributes) !== 1) {
                continue;
            }
            /** @var ApiResource $resource */
            $resource = $resourceAttributes[0]->newInstance();
            $path = str_starts_with($resource->path, $basePath) ? substr($resource->path, strlen($basePath)) : '';
            if (in_array($path, $nonWorkerResources, true)) {
                continue;
            }
            $idName = $v2Resources[$path] ?? 'id';

            $mappingAttributes = $reflection->getAttributes(HttpMapping::class);
            expectWorkerApiV2Route(count($mappingAttributes) === 1, "{$controller} must declare one HttpMapping attribute");
            /** @var HttpMapping $mapping */
            $mapping = $mappingAttributes[0]->newInstance();
            expectWorkerApiV2Route($mapping->customMethods === [], "{$controller} must not expose removed custom worker actions");

            foreach (expandWorkerApiV2Mapping($path, $idName, $mapping->mapping, $mapping->resourceLevelMethods, $mapping->collectionLevelMethods) as $route) {
                expectWorkerApiV2Route($reflection->hasMethod($route['action']), "{$controller} mapping action {$route['action']} is not implemented");
                $method = $reflection->getMethod($route['action']);
                if ($method->getDeclaringClass()->getName() !== $controller) {
                    continue;
                }
                $operations[] = $route + [
                    'controller' => $controller,
                    'processor' => (string)$resource->processor,
                    'operationCount' => count($method->getAttributes(ApiOperation::class)),
                    'custom' => in_array($route['action'], $mapping->customMethods, true),
                    'idPattern' => $mapping->idPattern,
                ];
            }
        }

        return $operations;
    }

    function processorSourceWorkerApiV2Route(string $root, string $processor): string
    {
        $prefix = 'Modules\\ModuleLocalSpeechToText\\';
        expectWorkerApiV2Route(str_starts_with($processor, $prefix), "Processor must belong to this module: {$processor}");
        $file = $root . '/' . str_replace('\\', '/', substr($processor, strlen($prefix))) . '.php';
        $source = file_get_contents($file);
        expectWorkerApiV2Route($source !== false, "Unable to read processor {$file}");

        return $source;
    }

    if (getenv('WORKER_API_V2_ROUTE_MUTATION') === '1') {
        $routes = expandWorkerApiV2Mapping('/job-leases', 'job_id', ['POST' => ['create'], 'GET' => ['create']], [], ['create']);
        expectWorkerApiV2Route(count($routes) === 2, 'mutation fixture must create an unexpected GET mutation');
        expectWorkerApiV2Route($routes[1]['method'] !== 'POST', 'mutation fixture did not preserve the bad GET route');
        try {
            assertExactWorkerApiV2Routes(['POST /job-leases create'], array_map(
                static fn(array $route): string => $route['method'] . ' ' . $route['path'] . ' ' . $route['action'],
                $routes,
            ));
            throw new RuntimeException('extra mapped mutation route was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Route(!str_contains($error->getMessage(), 'accepted'), 'route mutation check did not fail for the intended reason');
        }
        try {
            assertExactWorkerApiV2Routes(['POST /job-leases create'], ['POST /job-leases create', 'POST /job-leases create', 'GET /hidden-worker-resource getList']);
            throw new RuntimeException('duplicate and hidden-resource mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Route(!str_contains($error->getMessage(), 'accepted'), 'duplicate-resource mutation check did not fail for the intended reason');
        }
        foreach ([null, 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\Unused\\Processor'] as $declaredProcessor) {
            try {
                expectWorkerApiV2Route($declaredProcessor === 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\JobLeases\\Processor', 'declared processor must be exact and non-null');
                throw new RuntimeException('wrong declared processor mutation was accepted');
            } catch (RuntimeException $error) {
                expectWorkerApiV2Route(!str_contains($error->getMessage(), 'accepted'), 'declared processor mutation did not fail for the intended reason');
            }
        }
        foreach ([
            [
                'GET /workers/{worker_uid} getRecord',
                'GET /workers/{id} getRecord',
                'worker item identifier mutation',
            ],
            [
                'GET /worker-processing-settings/{worker_uid} getRecord',
                'GET /worker-processing-settings getList',
                'processing-settings item-route mutation',
            ],
        ] as [$mutated, $canonical, $label]) {
            try {
                assertExactWorkerApiV2Routes([$canonical], [$mutated]);
                throw new RuntimeException("{$label} was accepted");
            } catch (RuntimeException $error) {
                expectWorkerApiV2Route(!str_contains($error->getMessage(), 'accepted'), "{$label} did not fail for the intended reason");
            }
        }
        assertNumericWorkerItemPattern('[0-9]+');
        $fixtureMapping = (new ReflectionClass(WorkerIdPatternMutationFixture::class))->getAttributes(HttpMapping::class)[0]->newInstance();
        foreach ([$fixtureMapping->idPattern, '', null, ['[0-9]+']] as $badPattern) {
            try {
                assertNumericWorkerItemPattern($badPattern);
                throw new RuntimeException('non-numeric reflected worker idPattern mutation was accepted');
            } catch (RuntimeException $error) {
                expectWorkerApiV2Route(!str_contains($error->getMessage(), 'accepted'), 'worker idPattern mutation did not fail for the intended reason');
            }
        }
        echo "Worker API v2 route mutation checks OK\n";
        exit(0);
    }

    $v2Resources = [
        '/worker-api-contract' => 'id',
        '/workers' => 'id',
        '/worker-processing-settings' => 'id',
        '/job-leases' => 'job_id',
        '/job-recordings' => 'job_id',
        '/job-results' => 'job_id',
        '/job-failures' => 'job_id',
        '/jobs' => 'id',
    ];
    $expectedProcessors = [
        '/worker-api-contract' => 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\WorkerApiContract\\Processor',
        '/workers' => 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\Workers\\Processor',
        '/worker-processing-settings' => 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\WorkerProcessingSettings\\Processor',
        '/job-leases' => 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\JobLeases\\Processor',
        '/job-recordings' => 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\JobRecordings\\Processor',
        '/job-results' => 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\JobResults\\Processor',
        '/job-failures' => 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\JobFailures\\Processor',
        '/jobs' => 'Modules\\ModuleLocalSpeechToText\\Lib\\RestAPI\\Jobs\\Processor',
    ];
    $expected = [
        'GET /worker-api-contract getList',
        'GET /workers getList', 'POST /workers create', 'GET /workers/{id} getRecord',
        'PUT /workers/{id} update', 'PATCH /workers/{id} patch', 'DELETE /workers/{id} delete',
        'GET /worker-processing-settings getList',
        'POST /job-leases create', 'PATCH /job-leases/{job_id} patch', 'DELETE /job-leases/{job_id} delete',
        'GET /job-recordings/{job_id} getRecord', 'PUT /job-results/{job_id} update', 'PUT /job-failures/{job_id} update',
        'GET /jobs getList', 'GET /jobs/{id} getRecord', 'POST /jobs create', 'PATCH /jobs/{id} patch',
        'PATCH /jobs patchCollection', 'DELETE /jobs/{id} delete', 'DELETE /jobs deleteCollection',
    ];
    $root = dirname(__DIR__);
    $nonWorkerResources = ['/transcripts', '/call-transcripts', '/call-transcripts/events'];
    $operations = reflectedWorkerApiV2Operations($root, $v2Resources, $nonWorkerResources);
    $allowed = $expected;
    $actual = [];
    foreach ($operations as $operation) {
        $key = $operation['method'] . ' ' . $operation['path'] . ' ' . $operation['action'];
        expectWorkerApiV2Route(in_array($key, $allowed, true), "Unexpected reflected worker route tuple {$key}");
        $resourcePath = preg_replace('/\/\{[^}]+\}$/', '', $operation['path']);
        if (isset($expectedProcessors[$resourcePath])) {
            expectWorkerApiV2Route($operation['processor'] === $expectedProcessors[$resourcePath], "{$key} must declare the exact v2 processor", $operation['processor']);
            processorSourceWorkerApiV2Route($root, $operation['processor']);
        }
        if ($operation['operationCount'] === 1) {
            $actual[] = $operation['method'] . ' ' . $operation['path'] . ' ' . $operation['action'];
        }
    }
    assertExactWorkerApiV2Routes($expected, $actual);

    foreach ($operations as $operation) {
        $key = $operation['method'] . ' ' . $operation['path'] . ' ' . $operation['action'];
        if (!in_array($key, $expected, true)) {
            continue;
        }
        if (in_array($key, [
            'GET /workers/{id} getRecord',
            'PUT /workers/{id} update',
            'PATCH /workers/{id} patch',
            'DELETE /workers/{id} delete',
        ], true)) {
            assertNumericWorkerItemPattern($operation['idPattern']);
        }
        expectWorkerApiV2Route(!$operation['custom'], "v2 tuple {$key} must not be routed as a custom action");
        expectWorkerApiV2Route($operation['operationCount'] === 1, "{$key} must expose exactly one ApiOperation");
        if ($operation['method'] === 'GET') {
            expectWorkerApiV2Route(!preg_match('/(?:create|claim|lease|submit|fail|heartbeat|release|update|patch|delete)/i', $operation['action']), "{$key} maps GET to a mutation");
        }
    }

    echo "Worker API v2 route contract OK\n";
}
