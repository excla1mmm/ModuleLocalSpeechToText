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

namespace Phalcon\Di {
    class Injectable
    {
    }
}

namespace MikoPBX\Common\Models {
    class ApiKeys
    {
        /** @var array<int, self> */
        public static array $rows = [];

        public int $id = 0;
        public string $full_permissions = '0';
        public string $allowed_paths = '{}';

        public static function findFirstById(int $id): ?self
        {
            return self::$rows[$id] ?? null;
        }
    }
}

namespace MikoPBX\Common\Providers {
    class AclProvider
    {
        public const string ROLE_ADMINS = 'admins';
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    class STTSetting
    {
        /** @var list<int> */
        public static array $allowedWorkerApiKeyIds = [77, 99];

        /** @var list<int> */
        public static array $knownWorkerApiKeyIds = [77, 99, 123];

        public static function isWorkerApiKeyIdAllowed(int $apiKeyId): bool
        {
            return in_array($apiKeyId, self::$allowedWorkerApiKeyIds, true);
        }

        public static function isWorkerApiKeyIdKnown(int $apiKeyId): bool
        {
            return in_array($apiKeyId, self::$knownWorkerApiKeyIds, true);
        }
    }

    class STTWorker
    {
        public const string STATUS_ONLINE = 'online';
        public const string STATUS_OFFLINE = 'offline';

        /** @var array<int, self> */
        public static array $rows = [];
        public static int $nextId = 1;

        public ?int $id = null;
        public ?string $worker_uid = '';
        public ?string $name = '';
        public ?string $ip = '';
        public ?string $status = self::STATUS_OFFLINE;
        public ?string $model = '';
        public ?int $api_key_id = null;
        public ?int $api_version = 1;
        public ?string $app_version = '';
        public ?string $last_seen = null;
        public ?string $created_at = '0';
        public ?string $updated_at = '0';

        public static function findFirstById(int $id): ?self
        {
            return self::$rows[$id] ?? null;
        }

        public static function findFirst(array $parameters): ?self
        {
            foreach (self::find($parameters) as $worker) {
                return $worker;
            }

            return null;
        }

        /** @return list<self> */
        public static function find(array $parameters = []): array
        {
            $rows = array_values(array_filter(
                self::$rows,
                static function (self $worker) use ($parameters): bool {
                    $bind = is_array($parameters['bind'] ?? null) ? $parameters['bind'] : [];
                    foreach (['status', 'worker_uid', 'api_version', 'app_version', 'api_key_id'] as $field) {
                        if (array_key_exists($field, $bind) && $worker->{$field} != $bind[$field]) {
                            return false;
                        }
                    }
                    if (array_key_exists('other_worker_uid', $bind) && $worker->worker_uid === $bind['other_worker_uid']) {
                        return false;
                    }

                    return true;
                }
            ));
            usort($rows, static fn(self $left, self $right): int => (int)$left->id <=> (int)$right->id);
            $offset = max(0, (int)($parameters['offset'] ?? 0));
            $limit = max(0, (int)($parameters['limit'] ?? count($rows)));

            return array_slice($rows, $offset, $limit);
        }

        public static function count(array $parameters = []): int
        {
            unset($parameters['limit'], $parameters['offset']);

            return count(self::find($parameters));
        }

        public function save(): bool
        {
            if ($this->id === null) {
                $this->id = self::$nextId++;
            }
            self::$rows[$this->id] = $this;

            return true;
        }

        public function delete(): bool
        {
            unset(self::$rows[(int)$this->id]);

            return true;
        }

        public static function deleteIfNoActiveLease(int $workerId): bool
        {
            foreach (STTJob::$rows as $job) {
                if ($job['worker_id'] === $workerId
                    && $job['status'] === STTJob::STATUS_PROCESSING
                    && $job['lease_token'] !== null) {
                    return false;
                }
            }
            if (!isset(self::$rows[$workerId])) {
                return false;
            }
            unset(self::$rows[$workerId]);

            return true;
        }

        public function getMessages(): array
        {
            return [];
        }

        public function getWriteConnection(): object
        {
            return new class {
            };
        }
    }

    class STTJob
    {
        public const string STATUS_PROCESSING = 'processing';

        /** @var list<array{worker_id: int, status: string, lease_token: ?string}> */
        public static array $rows = [];

        public static function count(array $parameters): int
        {
            $bind = is_array($parameters['bind'] ?? null) ? $parameters['bind'] : [];

            return count(array_filter(self::$rows, static fn(array $job): bool =>
                $job['worker_id'] === (int)($bind['worker_id'] ?? 0)
                && $job['status'] === (string)($bind['status'] ?? '')
                && $job['lease_token'] !== null
            ));
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    class ModuleLifecycleGate
    {
        public static int $acquireCalls = 0;
        public static int $releaseCalls = 0;

        public function acquireWriteLease(): object
        {
            self::$acquireCalls++;

            return (object)['lease' => true];
        }

        public function releaseWriteLease(object $lease): void
        {
            self::$releaseCalls++;
        }
    }

    class ModuleDatabasePragmas
    {
        public function configureRuntime(object $connection): void
        {
        }
    }

    class ModuleDatabaseWriterMutex
    {
        public function synchronized(callable $operation): mixed
        {
            return $operation();
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib {
    use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

    class LocalSpeechToTextMain
    {
        public const string API_BASE_PATH = '/pbxcore/api/v3/module-local-speech-to-text';
        public static int $registrationCalls = 0;

        public static function registerWorker(array $data): PBXApiResult
        {
            self::$registrationCalls++;
            if (
                ($data['_auth_type'] ?? null) === 'bearer_token'
                && !STTSetting::isWorkerApiKeyIdAllowed((int)($data['_api_key_id'] ?? 0))
            ) {
                $forbidden = new PBXApiResult();
                $forbidden->httpCode = 403;
                $forbidden->data = ['error_code' => 'worker_api_key_forbidden'];

                return $forbidden;
            }
            $workerUid = trim((string)($data['worker_uid'] ?? ''));
            $worker = STTWorker::findFirst([
                'conditions' => 'worker_uid = :worker_uid:',
                'bind' => ['worker_uid' => $workerUid],
            ]);
            if (!$worker instanceof STTWorker) {
                $worker = new STTWorker();
                $worker->worker_uid = $workerUid;
                $worker->created_at = '100';
            }
            $worker->name = trim((string)($data['name'] ?? $workerUid));
            $worker->model = trim((string)($data['model'] ?? 'server-model'));
            $worker->ip = trim((string)($data['_remote_addr'] ?? ''));
            $worker->status = STTWorker::STATUS_ONLINE;
            $worker->api_key_id = (int)($data['_api_key_id'] ?? 0) ?: $worker->api_key_id;
            $worker->api_version = WorkerApiContract::API_VERSION;
            $worker->app_version = trim((string)($data['app_version'] ?? ''));
            $worker->last_seen = '200';
            $worker->updated_at = '200';
            $worker->save();

            $result = new PBXApiResult();
            $result->success = true;
            $result->httpCode = 200;
            $result->data = [
                'id' => (int)$worker->id,
            ];

            return $result;
        }
    }
}

namespace {
    use MikoPBX\Common\Models\ApiKeys;
    use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
    use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\Processor;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
    use Modules\ModuleLocalSpeechToText\Lib\Services\STTWorkerResourceService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

    /** @param mixed $actual */
    function expectWorkerCrud(bool $condition, string $message, mixed $actual = null): void
    {
        if (!$condition) {
            $suffix = $actual === null ? '' : "\nActual: " . var_export($actual, true);
            throw new RuntimeException($message . $suffix);
        }
    }

    function workerFixture(int $id, string $uid, string $status, string $apiVersion, string $appVersion, ?int $apiKeyId): STTWorker
    {
        $worker = new STTWorker();
        $worker->id = $id;
        $worker->worker_uid = $uid;
        $worker->name = strtoupper($uid);
        $worker->ip = '192.0.2.' . $id;
        $worker->status = $status;
        $worker->model = 'model-' . $id;
        $worker->api_key_id = $apiKeyId;
        $worker->api_version = (int)$apiVersion;
        $worker->app_version = $appVersion;
        $worker->last_seen = (string)(1000 + $id);
        $worker->created_at = (string)(900 + $id);
        $worker->updated_at = (string)(1100 + $id);
        $worker->save();

        return $worker;
    }

    /** @return array<string, mixed> */
    function requestForWorkerCrud(
        string $action,
        array $data = [],
        bool $restricted = false,
        bool $withVersion = true,
        int $apiKeyId = 77,
    ): array
    {
        $request = [
            'action' => $action,
            'data' => $data,
            'httpMethod' => match ($action) {
                'create', 'register' => 'POST',
                'update' => 'PUT',
                'patch' => 'PATCH',
                'delete' => 'DELETE',
                default => 'GET',
            },
            'sessionContext' => [
                'remote_addr' => '198.51.100.10',
                // Empty auth_type mirrors a Core SecurityType::LOCALHOST
                // request (no sessionContext at all), which
                // WorkerRequestContext::isAdministrator() treats as
                // administrator - see that method's docblock.
                'auth_type' => $restricted ? 'bearer_token' : '',
                'token_id' => $restricted ? $apiKeyId : 0,
                'role' => $restricted ? '' : 'admins',
            ],
            'httpHeaders' => [],
        ];
        if ($withVersion) {
            $request['httpHeaders'][WorkerApiContract::VERSION_HEADER] = (string)WorkerApiContract::API_VERSION;
        }

        return $request;
    }

    $root = dirname(__DIR__);
    $serviceFile = $root . '/Lib/Services/STTWorkerResourceService.php';
    expectWorkerCrud(is_file($serviceFile), 'STTWorkerResourceService CRUD implementation is missing');

    require_once $root . '/Lib/Services/WorkerCapabilities.php';
    require_once $root . '/Lib/Services/WorkerApiContract.php';
    require_once $root . '/Lib/Services/WorkerApiKeyService.php';
    require_once $root . '/Lib/RestAPI/Common/WorkerRequestContext.php';
    require_once $serviceFile;
    require_once $root . '/Lib/RestAPI/Workers/Processor.php';

    STTWorker::$rows = [];
    STTWorker::$nextId = 1;
    workerFixture(1, 'worker-a', 'online', '2', '2.5.0', 77);
    workerFixture(2, 'worker-b', 'offline', '2', '2.4.0', 88);
    workerFixture(3, 'worker-c', 'online', '1', '1.9.0', null);
    STTWorker::$nextId = 4;
    foreach ([77, 99] as $workerApiKeyId) {
        $workerApiKey = new ApiKeys();
        $workerApiKey->id = $workerApiKeyId;
        $workerApiKey->allowed_paths = json_encode(WorkerApiKeyService::WORKER_ALLOWED_PATHS);
        ApiKeys::$rows[$workerApiKeyId] = $workerApiKey;
    }
    $service = new STTWorkerResourceService();

    $filtered = $service->getList([
        'status' => 'online',
        'worker_uid' => 'worker-a',
        'api_version' => 2,
        'app_version' => '2.5.0',
        'limit' => 1,
        'offset' => 0,
    ]);
    expectWorkerCrud($filtered->success && $filtered->httpCode === 200, 'Filtered worker list failed');
    expectWorkerCrud(count($filtered->data) === 1, 'Worker filters must combine exactly', $filtered->data);
    expectWorkerCrud(
        array_keys($filtered->data[0] ?? []) === [
            'id', 'worker_uid', 'name', 'ip', 'status', 'model', 'api_key_id', 'api_version',
            'app_version', 'capabilities', 'compatible', 'last_seen', 'created_at', 'updated_at',
        ],
        'Worker responses must expose the exact public resource fields',
        $filtered->data[0] ?? null,
    );

    $paged = $service->getList(['limit' => 1, 'offset' => 1]);
    expectWorkerCrud(($paged->data[0]['id'] ?? null) === 2, 'Worker list must apply numeric limit and offset');

    $detail = $service->getRecord(2);
    expectWorkerCrud($detail->success && ($detail->data['id'] ?? null) === 2, 'Numeric worker detail lookup failed');
    expectWorkerCrud($service->getRecord(999)->httpCode === 404, 'Missing numeric worker detail must return 404');

    $firstCreate = $service->create(['worker_uid' => 'worker-new', 'name' => 'First', 'app_version' => '3.0.0']);
    $secondCreate = $service->create(['worker_uid' => 'worker-new', 'name' => 'Second', 'app_version' => '3.0.1']);
    expectWorkerCrud(
        $firstCreate->success
            && $secondCreate->success
            && ($firstCreate->data['id'] ?? null) === ($secondCreate->data['id'] ?? null),
        'POST worker registration must be idempotent by worker_uid',
        [$firstCreate->data, $secondCreate->data],
    );

    $updated = $service->update(2, ['name' => 'Replacement', 'model' => 'replacement-model', 'status' => 'online']);
    expectWorkerCrud($updated->success, 'Administrative PUT failed', $updated->messages);
    expectWorkerCrud(
        ($updated->data['name'] ?? null) === 'Replacement'
            && ($updated->data['model'] ?? null) === 'replacement-model'
            && ($updated->data['status'] ?? null) === 'online'
            && ($updated->data['worker_uid'] ?? null) === 'worker-b'
            && ($updated->data['api_key_id'] ?? null) === 88,
        'Administrative PUT must fully replace mutable fields and preserve identity/key binding',
        $updated->data,
    );
    expectWorkerCrud(
        $service->update(2, ['name' => 'Incomplete'])->httpCode === 422,
        'Administrative PUT must require every mutable replacement field',
    );
    $realEnvelopePut = $service->update(2, [
        'name' => 'Core Envelope',
        'model' => 'core-model',
        'status' => 'online',
        'httpMethod' => 'PUT',
    ]);
    expectWorkerCrud($realEnvelopePut->success, "Core's exact data[httpMethod] metadata must not invalidate PUT");

    $patched = $service->patch(2, ['id' => 2, 'name' => 'Patched', 'httpMethod' => 'PATCH']);
    expectWorkerCrud($patched->success && ($patched->data['name'] ?? null) === 'Patched', 'PATCH allow-list update failed');
    foreach (['worker_uid', 'api_key_id', 'api_version', 'app_version', 'created_at', 'unknown'] as $forbiddenField) {
        $rejected = $service->patch(2, [$forbiddenField => 'mutation']);
        expectWorkerCrud($rejected->httpCode === 422, "PATCH must reject {$forbiddenField}", $rejected->data);
    }
    expectWorkerCrud(
        $service->patch(2, ['_idFromRequestBody' => 999, 'name' => 'No'])->httpCode === 422,
        'PATCH must reject a body id even though the path id is routed separately',
    );

    STTJob::$rows = [
        ['worker_id' => 1, 'status' => STTJob::STATUS_PROCESSING, 'lease_token' => 'active-lease'],
        ['worker_id' => 2, 'status' => 'done', 'lease_token' => null],
    ];
    $jobsBeforeDelete = STTJob::$rows;
    $activeDelete = $service->delete(1);
    expectWorkerCrud(
        $activeDelete->httpCode === 409
            && ($activeDelete->data['error_code'] ?? null) === 'worker_has_active_lease'
            && isset(STTWorker::$rows[1]),
        'Worker with an active lease must not be deleted',
        $activeDelete->data,
    );
    $deleted = $service->delete(2);
    expectWorkerCrud($deleted->success && !isset(STTWorker::$rows[2]), 'Inactive worker delete failed');
    expectWorkerCrud(STTJob::$rows === $jobsBeforeDelete, 'Worker delete must retain historical job/result ownership rows');

    $restrictedExpected = [
        'getList' => 403, 'create' => 200, 'getRecord' => 403, 'update' => 403,
        'patch' => 403, 'delete' => 403,
    ];
    $workerAId = 1;
    foreach ($restrictedExpected as $action => $expectedStatus) {
        $beforeLeaseCalls = ModuleLifecycleGate::$acquireCalls;
        $data = match ($action) {
            'create' => ['worker_uid' => 'worker-a', 'name' => 'Self', 'app_version' => '3.1.0'],
            'update' => ['id' => $workerAId, 'name' => 'No', 'model' => 'No', 'status' => 'offline'],
            'patch' => ['id' => $workerAId, 'name' => 'No'],
            default => ['id' => $workerAId],
        };
        $result = Processor::callBack(requestForWorkerCrud($action, $data, true));
        expectWorkerCrud($result->httpCode === $expectedStatus, "Restricted {$action} status changed", $result->httpCode);
        if ($action !== 'create') {
            expectWorkerCrud(
                ModuleLifecycleGate::$acquireCalls === $beforeLeaseCalls,
                "Restricted {$action} denial must occur before lifecycle/service calls",
            );
        }
    }

    $beforeRegistration = LocalSpeechToTextMain::$registrationCalls;
    $missingVersion = Processor::callBack(requestForWorkerCrud(
        'create',
        ['worker_uid' => 'worker-a', 'app_version' => '3.1.0'],
        true,
        false,
    ));
    expectWorkerCrud($missingVersion->httpCode === 426, 'Restricted POST must require worker API version 2');
    expectWorkerCrud(LocalSpeechToTextMain::$registrationCalls === $beforeRegistration, 'Version denial must occur before registration');

    $wrongUid = Processor::callBack(requestForWorkerCrud('create', ['worker_uid' => 'worker-c'], true));
    expectWorkerCrud(
        $wrongUid->httpCode === 409
            && ($wrongUid->data['error_code'] ?? null) === 'worker_api_key_already_bound',
        'Restricted key must not claim a second worker identity'
    );
    expectWorkerCrud(LocalSpeechToTextMain::$registrationCalls === $beforeRegistration, 'Binding denial must occur before registration');

    workerFixture(6, 'stale-bound-worker', 'offline', '1', '1.0.0', 88);
    $staleBinding = Processor::callBack(requestForWorkerCrud(
        'create',
        ['worker_uid' => 'stale-bound-worker'],
        true,
        true,
        99,
    ));
    expectWorkerCrud($staleBinding->httpCode === 403, 'Restricted key must match any existing worker API-key binding exactly');

    $exclusiveConflict = workerFixture(5, 'worker-key-owner', 'online', '2', '3.0.0', 77);
    $exclusive = Processor::callBack(requestForWorkerCrud('create', ['worker_uid' => 'new-uid'], true));
    expectWorkerCrud($exclusive->httpCode === 409, 'Restricted key must not register a second worker_uid');
    unset(STTWorker::$rows[(int)$exclusiveConflict->id]);

    workerFixture(22, 'legacy-clean', 'offline', '1', '1.0.0', 99);
    $cleanLegacyUpgrade = Processor::callBack(requestForWorkerCrud(
        'create',
        ['worker_uid' => 'legacy-clean', 'app_version' => '2.0.0'],
        true,
        true,
        99,
    ));
    expectWorkerCrud(
        $cleanLegacyUpgrade->httpCode === 200
            && ($cleanLegacyUpgrade->data['id'] ?? null) === 22
            && (STTWorker::$rows[22]->api_version ?? null) === 2
            && (STTWorker::$rows[22]->status ?? null) === STTWorker::STATUS_ONLINE,
        'A clean single legacy binding must idempotently transition to v2',
        [$cleanLegacyUpgrade->httpCode, $cleanLegacyUpgrade->data],
    );
    unset(STTWorker::$rows[22]);

    workerFixture(20, 'legacy-ambiguous-a', 'offline', '1', '1.0.0', 99);
    workerFixture(21, 'legacy-ambiguous-b', 'offline', '1', '1.0.0', 99);
    $ambiguousRowsBefore = serialize(STTWorker::$rows);
    $ambiguousCallsBefore = LocalSpeechToTextMain::$registrationCalls;
    foreach (['legacy-ambiguous-a', 'legacy-ambiguous-b', 'legacy-ambiguous-new'] as $workerUid) {
        $ambiguous = Processor::callBack(requestForWorkerCrud(
            'create',
            ['worker_uid' => $workerUid, 'app_version' => '2.0.0'],
            true,
            true,
            99,
        ));
        expectWorkerCrud(
            $ambiguous->httpCode === 409
                && ($ambiguous->data['error_code'] ?? null) === 'worker_api_key_already_bound',
            "Ambiguous legacy key must reject POST for {$workerUid} with 409",
            [$ambiguous->httpCode, $ambiguous->data],
        );
    }
    expectWorkerCrud(
        serialize(STTWorker::$rows) === $ambiguousRowsBefore
            && LocalSpeechToTextMain::$registrationCalls === $ambiguousCallsBefore,
        'Ambiguous legacy key POST attempts must not mutate or dispatch registration',
    );
    unset(STTWorker::$rows[20], STTWorker::$rows[21]);

    $adminRequests = [
        'getList' => [],
        'create' => ['worker_uid' => 'admin-created', 'name' => 'Admin', 'app_version' => '4.0.0'],
        'getRecord' => ['id' => 1],
        'update' => ['id' => 1, 'name' => 'Admin Replace', 'model' => 'admin-model', 'status' => 'online'],
        'patch' => ['id' => 1, 'name' => 'Admin Patch'],
        'delete' => ['id' => 3],
    ];
    foreach ($adminRequests as $action => $data) {
        $result = Processor::callBack(requestForWorkerCrud($action, $data, false, false));
        expectWorkerCrud(
            $result->httpCode >= 200 && $result->httpCode < 300,
            "Administrator {$action} must remain ungated by worker version/access checks",
            [$result->httpCode, $result->data, $result->messages],
        );
    }

    $explicitAdminKey = new ApiKeys();
    $explicitAdminKey->id = 555;
    $explicitAdminKey->full_permissions = '1';
    ApiKeys::$rows[555] = $explicitAdminKey;

    $adminBearerRequest = requestForWorkerCrud(
        'create',
        ['worker_uid' => 'admin-bearer-created', 'name' => 'Bearer admin', 'app_version' => '4.1.0'],
        false,
        false,
    );
    $adminBearerRequest['sessionContext']['auth_type'] = 'bearer_token';
    $adminBearerRequest['sessionContext']['token_id'] = 555;
    $adminBearer = Processor::callBack($adminBearerRequest);
    expectWorkerCrud(
        $adminBearer->httpCode === 200,
        'Administrator bearer POST must remain outside worker-key registration gates',
        [$adminBearer->httpCode, $adminBearer->data],
    );
    workerFixture(10, 'admin-key-delete', 'offline', '2', '4.0.0', null);
    foreach ([
        'getList' => [],
        'getRecord' => ['id' => 1],
        'update' => ['id' => 1, 'name' => 'Key Admin', 'model' => 'key-model', 'status' => 'online'],
        'patch' => ['id' => 1, 'name' => 'Key Admin Patch'],
        'delete' => ['id' => 10],
    ] as $action => $data) {
        $request = requestForWorkerCrud($action, $data, false, false);
        $request['sessionContext']['auth_type'] = 'bearer_token';
        $request['sessionContext']['token_id'] = 555;
        $result = Processor::callBack($request);
        expectWorkerCrud(
            $result->httpCode >= 200 && $result->httpCode < 300,
            "Explicit full-permission administrator API key must allow {$action} without v2",
            [$result->httpCode, $result->data],
        );
    }

    $jwtRequest = requestForWorkerCrud('getList', [], false, false);
    $jwtRequest['sessionContext'] = [
        'auth_type' => 'bearer_token',
        'token_id' => 0,
        'user_name' => 'admin',
        'role' => 'admins',
        'session_id' => 'admin',
        'remote_addr' => '198.51.100.10',
    ];
    expectWorkerCrud(Processor::callBack($jwtRequest)->httpCode === 200, 'JWT administrator CRUD must not require v2');

    $writeOnlyKey = new ApiKeys();
    $writeOnlyKey->id = 556;
    $writeOnlyKey->allowed_paths = json_encode([
        '/api/v3/module-local-speech-to-text/workers' => 'write',
    ]);
    ApiKeys::$rows[556] = $writeOnlyKey;
    $writeOnlyRequest = requestForWorkerCrud('getList', [], false, false);
    $writeOnlyRequest['sessionContext']['auth_type'] = 'bearer_token';
    $writeOnlyRequest['sessionContext']['token_id'] = 556;
    $beforeLeaseCalls = ModuleLifecycleGate::$acquireCalls;
    expectWorkerCrud(
        Processor::callBack($writeOnlyRequest)->httpCode === 403
            && ModuleLifecycleGate::$acquireCalls === $beforeLeaseCalls,
        'A generic write API key must not be inferred as a worker-resource administrator',
    );

    unset(ApiKeys::$rows[99]);
    $beforeRegistration = LocalSpeechToTextMain::$registrationCalls;
    $cachedAfterCoreDelete = Processor::callBack(requestForWorkerCrud(
        'create',
        ['worker_uid' => 'cached-after-core-delete'],
        true,
        true,
        99,
    ));
    expectWorkerCrud(
        $cachedAfterCoreDelete->httpCode === 403
            && LocalSpeechToTextMain::$registrationCalls === $beforeRegistration,
        'A cached token must fail closed when its live Core API-key record has been deleted',
    );

    STTSetting::$allowedWorkerApiKeyIds = [99];
    foreach (['getList', 'create', 'getRecord', 'update', 'patch', 'delete'] as $action) {
        $data = match ($action) {
            'create' => ['worker_uid' => 'worker-a'],
            'update' => ['id' => 1, 'name' => 'No', 'model' => 'No', 'status' => 'offline'],
            'patch' => ['id' => 1, 'name' => 'No'],
            default => ['id' => 1],
        };
        $beforeLeaseCalls = ModuleLifecycleGate::$acquireCalls;
        $beforeRegistration = LocalSpeechToTextMain::$registrationCalls;
        $revokedQueued = Processor::callBack(requestForWorkerCrud($action, $data, true, true, 77));
        expectWorkerCrud($revokedQueued->httpCode === 403, "Revoked queued worker credential must deny {$action}");
        expectWorkerCrud(
            ModuleLifecycleGate::$acquireCalls === $beforeLeaseCalls
                && LocalSpeechToTextMain::$registrationCalls === $beforeRegistration,
            "Revoked queued worker credential must deny {$action} before mutation",
        );
    }

    $cachedRevoked = Processor::callBack(requestForWorkerCrud('create', ['worker_uid' => 'cached-revoked'], true, true, 123));
    expectWorkerCrud(
        $cachedRevoked->httpCode === 403 && !isset(STTWorker::$rows[STTWorker::$nextId]),
        'Cached bearer for a historical worker key must fail closed after Core key deletion',
    );

    $controller = (string)file_get_contents($root . '/Lib/RestAPI/Workers/Controller.php');
    $processor = (string)file_get_contents($root . '/Lib/RestAPI/Workers/Processor.php');
    $model = (string)file_get_contents($root . '/Models/STTWorker.php');
    $main = (string)file_get_contents($root . '/Lib/LocalSpeechToTextMain.php');
    $resourceService = (string)file_get_contents($serviceFile);
    expectWorkerCrud(str_contains($controller, "idPattern: '[0-9]+'"), 'Worker item routing must be numeric');
    expectWorkerCrud(
        !is_file($root . '/Lib/RestAPI/Workers/Actions/RegisterAction.php')
            && !is_file($root . '/Lib/RestAPI/Workers/Actions/GetProcessingSettingsAction.php'),
        'Legacy action implementations must be deleted',
    );
    expectWorkerCrud(
        !str_contains($resourceService, 'ApiKeys')
            && !str_contains($resourceService, 'WorkerApiKeyService')
            && str_contains($resourceService, 'STTWorker::deleteIfNoActiveLease('),
        'Worker delete must target only the module worker row and leave Core API-key records untouched',
    );
    expectWorkerCrud(
        str_contains($model, '$worker->api_version = $apiVersion;')
            && str_contains($model, '$worker->app_version = $appVersion;')
            && str_contains($model, '$worker->updated_at = (string)time();'),
        'Worker registration model writes must persist API/app versions and updated_at',
    );
    expectWorkerCrud(
        str_contains($main, 'WorkerApiContract::API_VERSION')
            && str_contains($main, "self::stringField(\$data, 'app_version')")
            && str_contains($main, "return JobApiResponseBuilder::success(['id' => (int)\$worker->id]);"),
        'Registration must pass v2/app versions and return the numeric resource id',
    );

    echo "STT worker CRUD v2 behavior contract OK\n";
}
