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

    /** @return list<array{method: string, path: string, action: string, controller: string, processor: string, operationCount: int, custom: bool}> */
    function reflectedWorkerApiV2Operations(string $root, array $v2Resources, array $legacyActions, array $nonWorkerResources): array
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
            foreach ($mapping->customMethods as $customAction) {
                expectWorkerApiV2Route(
                    in_array($customAction, $legacyActions, true),
                    "{$controller} must not expose a non-legacy v2 custom action {$customAction}",
                );
            }

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
        expectWorkerApiV2Route($source !== false, "Unable to read tombstone processor {$file}");

        return $source;
    }

    /** @return string */
    function functionBodyWorkerApiV2Route(string $source, string $function): string
    {
        $start = strpos($source, "function {$function}(");
        expectWorkerApiV2Route($start !== false, "Processor must declare {$function}()");
        $open = strpos($source, '{', $start);
        expectWorkerApiV2Route($open !== false, "Processor {$function}() has no body");
        $depth = 0;
        for ($offset = $open, $length = strlen($source); $offset < $length; $offset++) {
            if ($source[$offset] === '{') {
                $depth++;
            } elseif ($source[$offset] === '}' && --$depth === 0) {
                return substr($source, $open + 1, $offset - $open - 1);
            }
        }
        throw new RuntimeException("Processor {$function}() body is not balanced");
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
        $badTombstone = 'function callBack(array $request) { return LocalSpeechToTextMain::getNextJob([]); }';
        expectWorkerApiV2Route(!str_contains($badTombstone, 'WorkerApiContract::upgradeRequired'), 'mutation fixture must omit the upgrade tombstone');
        $lateTombstone = 'function callBack(array $request) { $gate = new ModuleLifecycleGate(); return match($action) { JobAction::GET_NEXT => LegacyRouteRejector::worker() }; }';
        $branch = strpos($lateTombstone, 'JobAction::GET_NEXT');
        $setup = strpos($lateTombstone, 'ModuleLifecycleGate');
        try {
            expectWorkerApiV2Route($branch < $setup, 'legacy tombstone must reject before setup');
            throw new RuntimeException('pre-tombstone mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Route(!str_contains($error->getMessage(), 'accepted'), 'pre-tombstone mutation check did not fail for the intended reason');
        }
        echo "Worker API v2 route mutation checks OK\n";
        exit(0);
    }

    $v2Resources = [
        '/worker-api-contract' => 'id',
        '/workers' => 'worker_uid',
        '/worker-processing-settings' => 'worker_uid',
        '/job-leases' => 'job_id',
        '/job-recordings' => 'job_id',
        '/job-results' => 'job_id',
        '/job-failures' => 'job_id',
        '/jobs' => 'id',
    ];
    $expected = [
        'GET /worker-api-contract getList',
        'GET /workers getList', 'POST /workers create', 'GET /workers/{worker_uid} getRecord',
        'PUT /workers/{worker_uid} update', 'PATCH /workers/{worker_uid} patch', 'DELETE /workers/{worker_uid} delete',
        'GET /worker-processing-settings/{worker_uid} getRecord',
        'POST /job-leases create', 'PATCH /job-leases/{job_id} patch', 'DELETE /job-leases/{job_id} delete',
        'GET /job-recordings/{job_id} getRecord', 'PUT /job-results/{job_id} update', 'PUT /job-failures/{job_id} update',
        'GET /jobs getList', 'GET /jobs/{id} getRecord', 'POST /jobs create', 'PATCH /jobs/{id} patch',
        'PATCH /jobs patch', 'DELETE /jobs/{id} delete', 'DELETE /jobs delete',
    ];
    $legacy = [
        'POST /workers register', 'GET /workers getProcessingSettings', 'GET /jobs getNext',
        'GET /jobs/{id} download', 'POST /jobs/{id} submitResult', 'POST /jobs/{id} submitError',
        'POST /jobs/{id} heartbeat', 'POST /jobs/{id} release',
    ];

    $root = dirname(__DIR__);
    $nonWorkerResources = ['/transcripts', '/transcripts/events', '/call-transcripts', '/call-transcripts/events'];
    $operations = reflectedWorkerApiV2Operations($root, $v2Resources, array_map(
        static fn(string $route): string => substr($route, strrpos($route, ' ') + 1),
        $legacy,
    ), $nonWorkerResources);
    $allowed = array_merge($expected, $legacy);
    $actual = [];
    foreach ($operations as $operation) {
        $key = $operation['method'] . ' ' . $operation['path'] . ' ' . $operation['action'];
        expectWorkerApiV2Route(in_array($key, $allowed, true), "Unexpected reflected worker route tuple {$key}");
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
        expectWorkerApiV2Route(!$operation['custom'], "v2 tuple {$key} must not be routed as a custom action");
        expectWorkerApiV2Route($operation['operationCount'] === 1, "{$key} must expose exactly one ApiOperation");
        if ($operation['method'] === 'GET') {
            expectWorkerApiV2Route(!preg_match('/(?:create|claim|lease|submit|fail|heartbeat|release|update|patch|delete)/i', $operation['action']), "{$key} maps GET to a mutation");
        }
    }

    $legacyByAction = [];
    foreach ($operations as $operation) {
        $key = $operation['method'] . ' ' . $operation['path'] . ' ' . $operation['action'];
        if (in_array($key, $legacy, true)) {
            $legacyByAction[$key] = $operation;
        }
    }
    $legacyEnumCases = [
        'POST /workers register' => 'WorkerAction::REGISTER',
        'GET /workers getProcessingSettings' => 'WorkerAction::GET_PROCESSING_SETTINGS',
        'GET /jobs getNext' => 'JobAction::GET_NEXT',
        'GET /jobs/{id} download' => 'JobAction::DOWNLOAD',
        'POST /jobs/{id} submitResult' => 'JobAction::SUBMIT_RESULT',
        'POST /jobs/{id} submitError' => 'JobAction::SUBMIT_ERROR',
        'POST /jobs/{id} heartbeat' => 'JobAction::HEARTBEAT',
        'POST /jobs/{id} release' => 'JobAction::RELEASE',
    ];
    foreach ($legacy as $key) {
        $matches = array_values(array_filter($operations, static fn(array $operation): bool => $operation['method'] . ' ' . $operation['path'] . ' ' . $operation['action'] === $key));
        expectWorkerApiV2Route(count($matches) === 1, "Legacy worker route must appear exactly once with its original HTTP method: {$key}", count($matches));
        expectWorkerApiV2Route(isset($legacyByAction[$key]), "Legacy worker route must remain routable with its original HTTP method: {$key}");
        $operation = $legacyByAction[$key];
        expectWorkerApiV2Route($operation['custom'], "Legacy route {$key} must remain a custom tombstone action");
        expectWorkerApiV2Route($operation['operationCount'] === 0, "Legacy route {$key} must not expose ApiOperation");
        $callback = functionBodyWorkerApiV2Route(processorSourceWorkerApiV2Route($root, $operation['processor']), 'callBack');
        $armPattern = '/' . preg_quote($legacyEnumCases[$key], '/') . '\s*=>\s*LegacyRouteRejector::worker\(\)/';
        expectWorkerApiV2Route(
            preg_match_all($armPattern, $callback) === 1,
            "Legacy route {$key} must directly return LegacyRouteRejector::worker() from its action branch",
        );
        $branchPosition = strpos($callback, $legacyEnumCases[$key]);
        $setupPositions = array_filter([
            strpos($callback, 'ModuleLifecycleGate'), strpos($callback, 'ModuleDatabasePragmas'),
            strpos($callback, '::main('), strpos($callback, 'LocalSpeechToTextMain::'),
        ], static fn(int|false $position): bool => $position !== false);
        expectWorkerApiV2Route(
            $setupPositions === [] || $branchPosition < min($setupPositions),
            "Legacy route {$key} must reject before lifecycle or business setup",
        );
    }

    echo "Worker API v2 route contract OK\n";
}
