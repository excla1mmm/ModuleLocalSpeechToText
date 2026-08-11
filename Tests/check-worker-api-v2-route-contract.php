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

        /** @return list<string> */
        public function getOperationsForMethod(string $httpMethod): array
        {
            $operations = $this->mapping[strtoupper($httpMethod)] ?? [];

            return is_string($operations) ? [$operations] : $operations;
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

    /** @return list<array{method: string, path: string, controller: string, action: string, operationCount: int}> */
    function reflectedRoutesWorkerApiV2(string $root): array
    {
        $before = get_declared_classes();
        $controllerFiles = glob($root . '/Lib/RestAPI/*/Controller.php') ?: [];
        sort($controllerFiles);
        foreach ($controllerFiles as $controllerFile) {
            require_once $controllerFile;
        }

        $basePath = Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain::API_BASE_PATH;
        $routes = [];
        foreach (array_diff(get_declared_classes(), $before) as $controller) {
            $reflection = new ReflectionClass($controller);
            $resourceAttributes = $reflection->getAttributes(ApiResource::class);
            if (count($resourceAttributes) !== 1) {
                continue;
            }

            /** @var ApiResource $resource */
            $resource = $resourceAttributes[0]->newInstance();
            if (!str_starts_with($resource->path, $basePath . '/')) {
                continue;
            }

            $mappingAttributes = $reflection->getAttributes(HttpMapping::class);
            expectWorkerApiV2Route(count($mappingAttributes) === 1, "{$controller} must declare one HttpMapping attribute");
            /** @var HttpMapping $mapping */
            $mapping = $mappingAttributes[0]->newInstance();

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $controller) {
                    continue;
                }

                $doc = $method->getDocComment() ?: '';
                if (!preg_match('/@route\\s+(GET|POST|PUT|PATCH|DELETE)\\s+([^\\s*]+)/', $doc, $match)) {
                    continue;
                }

                $httpMethod = $match[1];
                $path = $match[2];
                expectWorkerApiV2Route(
                    str_starts_with($path, $basePath . '/'),
                    "{$controller}::{$method->getName()} route must stay under the module API base path",
                    $path,
                );
                expectWorkerApiV2Route(
                    in_array($method->getName(), $mapping->getOperationsForMethod($httpMethod), true),
                    "{$controller}::{$method->getName()} @route method must match HttpMapping",
                    $mapping->mapping,
                );

                $routes[] = [
                    'method' => $httpMethod,
                    'path' => substr($path, strlen($basePath)),
                    'controller' => $controller,
                    'action' => $method->getName(),
                    'operationCount' => count($method->getAttributes(ApiOperation::class)),
                ];
            }
        }

        return $routes;
    }

    $root = dirname(__DIR__);
    $v2 = [
        ['GET', '/worker-api-contract'],
        ['GET', '/workers'], ['POST', '/workers'], ['GET', '/workers/{worker_uid}'],
        ['PUT', '/workers/{worker_uid}'], ['PATCH', '/workers/{worker_uid}'], ['DELETE', '/workers/{worker_uid}'],
        ['GET', '/worker-processing-settings/{worker_uid}'],
        ['POST', '/job-leases'], ['PATCH', '/job-leases/{job_id}'], ['DELETE', '/job-leases/{job_id}'],
        ['GET', '/job-recordings/{job_id}'], ['PUT', '/job-results/{job_id}'], ['PUT', '/job-failures/{job_id}'],
        ['GET', '/jobs'], ['GET', '/jobs/{id}'], ['POST', '/jobs'], ['PATCH', '/jobs/{id}'],
        ['PATCH', '/jobs'], ['DELETE', '/jobs/{id}'], ['DELETE', '/jobs'],
    ];
    $legacyWorker = [
        'workers:register', 'workers:getProcessingSettings', 'jobs:getNext',
        'jobs/{id}:download', 'jobs/{id}:submitResult', 'jobs/{id}:submitError',
        'jobs/{id}:heartbeat', 'jobs/{id}:release',
    ];

    $routes = reflectedRoutesWorkerApiV2($root);
    foreach ($v2 as [$httpMethod, $path]) {
        $matches = array_values(array_filter(
            $routes,
            static fn(array $route): bool => $route['method'] === $httpMethod && $route['path'] === $path,
        ));
        expectWorkerApiV2Route(count($matches) === 1, "Missing or duplicate v2 route {$httpMethod} {$path}", $matches);
        expectWorkerApiV2Route(
            $matches[0]['operationCount'] === 1,
            "v2 route {$httpMethod} {$path} must expose exactly one ApiOperation",
            $matches[0]['operationCount'],
        );

        if ($httpMethod === 'GET') {
            expectWorkerApiV2Route(
                preg_match('/(?:create|claim|lease|submit|fail|heartbeat|release|update|patch|delete)/i', $matches[0]['action']) !== 1,
                "GET {$path} must not map to a mutating worker action",
                $matches[0]['action'],
            );
        }
    }

    foreach ($legacyWorker as $legacyPath) {
        $matches = array_values(array_filter(
            $routes,
            static fn(array $route): bool => $route['path'] === '/' . $legacyPath,
        ));
        expectWorkerApiV2Route($matches !== [], "Legacy worker route /{$legacyPath} must remain routable");
        foreach ($matches as $match) {
            expectWorkerApiV2Route(
                $match['operationCount'] === 0,
                "Legacy worker route /{$legacyPath} must be a tombstone without ApiOperation",
                $match['operationCount'],
            );
        }
    }

    echo "Worker API v2 route contract OK\n";
}
