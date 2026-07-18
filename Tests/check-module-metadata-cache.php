<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleMetadataCache;

require_once __DIR__ . '/../Lib/Services/ModuleMetadataCache.php';

function expectModuleMetadataCache(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ModuleMetadataRedisProbe
{
    /** @var array<int, array<string, string>> */
    public array $databases;
    /** @var array<int, int> */
    public array $selections = [];

    public function __construct(private int $database)
    {
        $this->databases = [
            1 => ['worker-state' => 'preserve'],
            2 => [
                '_PH_METADATA:meta-Modules\\ModuleLocalSpeechToText\\Models\\STTJob' => 'delete',
                '_PH_METADATA:map-Modules\\ModuleLocalSpeechToText\\Models\\STTTranscriptEvent' => 'delete',
                '_PH_METADATA:meta-Modules\\ModuleAISupervisor\\Models\\AISupervisorJob' => 'preserve',
                'unrelated:ModuleLocalSpeechToText' => 'preserve',
            ],
            3 => ['other-db' => 'preserve'],
        ];
    }

    public function getDBNum(): int
    {
        return $this->database;
    }

    public function select(int $database): bool
    {
        $this->database = $database;
        $this->selections[] = $database;

        return true;
    }

    public function scan(mixed &$iterator, string $pattern, int $count): array|false
    {
        $keys = array_values(array_filter(
            array_keys($this->databases[$this->database] ?? []),
            static fn (string $key): bool => fnmatch($pattern, $key, FNM_NOESCAPE)
        ));
        $offset = $iterator === null ? 0 : (int)$iterator;
        if ($offset >= count($keys)) {
            $iterator = 0;
            return false;
        }

        $batch = array_slice($keys, $offset, 1);
        $iterator = $offset + count($batch);
        if ($iterator >= count($keys)) {
            $iterator = 0;
        }

        return $batch;
    }

    public function del(string $key): int
    {
        if (!array_key_exists($key, $this->databases[$this->database] ?? [])) {
            return 0;
        }
        unset($this->databases[$this->database][$key]);

        return 1;
    }
}

$redis = new ModuleMetadataRedisProbe(3);
$runtimeResets = 0;
$warnings = [];
$cache = new ModuleMetadataCache(
    $redis,
    static function () use (&$runtimeResets): void {
        $runtimeResets++;
    },
    static function (string $message) use (&$warnings): void {
        $warnings[] = $message;
    }
);

expectModuleMetadataCache($cache->invalidate() === 2, 'Only STT model metadata keys must be deleted');
expectModuleMetadataCache($redis->getDBNum() === 3, 'Original Redis database selection must be restored');
expectModuleMetadataCache($runtimeResets === 1, 'In-process model metadata must be reset');
expectModuleMetadataCache($warnings === [], 'Healthy Redis invalidation must not log warnings');
expectModuleMetadataCache(
    isset($redis->databases[2]['_PH_METADATA:meta-Modules\\ModuleAISupervisor\\Models\\AISupervisorJob'])
        && isset($redis->databases[2]['unrelated:ModuleLocalSpeechToText'])
        && isset($redis->databases[1]['worker-state'])
        && isset($redis->databases[3]['other-db']),
    'Metadata invalidation must preserve other modules, prefixes, and Redis databases'
);

$failedResets = 0;
$failureWarnings = [];
$failingRedis = new class {
    public function getDBNum(): int
    {
        return 1;
    }

    public function select(int $database): bool
    {
        throw new RuntimeException('redis unavailable');
    }
};
$failingCache = new ModuleMetadataCache(
    $failingRedis,
    static function () use (&$failedResets): void {
        $failedResets++;
    },
    static function (string $message) use (&$failureWarnings): void {
        $failureWarnings[] = $message;
    }
);
expectModuleMetadataCache($failingCache->invalidate() === 0, 'Redis failure must degrade to zero remote deletions');
expectModuleMetadataCache($failedResets === 1, 'Redis failure must not skip in-process reset');
expectModuleMetadataCache(count($failureWarnings) >= 1, 'Redis failure must be observable');

$metadataSource = file_get_contents(__DIR__ . '/../Lib/Services/ModuleMetadataCache.php');
$setupSource = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
expectModuleMetadataCache($metadataSource !== false && $setupSource !== false, 'Cannot inspect metadata integration');
expectModuleMetadataCache(
    !str_contains($metadataSource, 'flushDB') && !str_contains($metadataSource, 'flushAll'),
    'STT metadata invalidation must never flush a complete Redis database'
);
expectModuleMetadataCache(
    substr_count($setupSource, '(new ModuleMetadataCache())->invalidate();') === 2
        && strpos($setupSource, '(new ModuleMetadataCache())->invalidate();')
            < strpos($setupSource, 'isFreshModuleDatabase('),
    'Setup must invalidate STT metadata before inspection and again after migration'
);

echo "Module metadata cache behavior OK\n";
