<?php

declare(strict_types=1);

/**
 * End-to-end check of call-transcript.deleted tombstones on the real module
 * schema: manual deletion publishes a tombstone, the default event feed stays
 * the completed/updated-only version 1 contract, include_deleted serves the
 * tombstone after every cursor a consumer already holds.
 */

use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptIntegrationService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptSchemaService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleTransaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptDeletionService;

function expectDeletionFeed(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for the deletion feed test\n");
    exit(1);
}

final class DeletionFeedConnection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        return $this->pdo->prepare($sql)->execute($bind);
    }

    public function fetchAll(string $sql, int $fetchMode = PDO::FETCH_ASSOC, array $bind = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);

        return $statement->fetchAll($fetchMode);
    }

    public function fetchOne(string $sql, int $fetchMode = PDO::FETCH_ASSOC, array $bind = []): array|false
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);

        return $statement->fetch($fetchMode);
    }

    public function begin(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollback(): bool { return $this->pdo->rollBack(); }
    public function isUnderTransaction(): bool { return $this->pdo->inTransaction(); }
}

eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTSetting { public const string SOURCE_INSTANCE_ID = "source_instance_id"; public static mixed $connection; public function getReadConnection(): mixed { return self::$connection; } }');
eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscriptEvent { public static mixed $connection; public function getReadConnection(): mixed { return self::$connection; } }');
if (!class_exists('Phalcon\\Db\\Enum')) {
    eval('namespace Phalcon\\Db; class Enum { public const int FETCH_ASSOC = 2; }');
}

$root = dirname(__DIR__);
require_once $root . '/Lib/Services/TransactionRecoveryException.php';
require_once $root . '/Lib/Services/ModuleTransaction.php';
require_once $root . '/Lib/Exceptions/TranscriptDeletionBlockedException.php';
require_once $root . '/Lib/Services/CallTranscriptSchemaService.php';
require_once $root . '/Lib/Services/TranscriptDeletionService.php';
require_once $root . '/Lib/Services/CallTranscriptIntegrationService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection = new DeletionFeedConnection($pdo);
(new CallTranscriptSchemaService())->ensure($connection);
$pdo->exec(
    "CREATE TABLE m_STTSetting (key TEXT PRIMARY KEY, value TEXT NOT NULL);
     INSERT INTO m_STTSetting VALUES ('source_instance_id', '0123456789abcdef0123456789abcdef');
     CREATE TABLE m_STTJob (id INTEGER PRIMARY KEY, call_id TEXT NOT NULL, status TEXT NOT NULL, updated_at INTEGER NOT NULL DEFAULT 0);
     CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, job_id INTEGER NOT NULL);
     CREATE TABLE m_STTTranscriptEvent (id INTEGER PRIMARY KEY, result_id INTEGER NOT NULL); CREATE TABLE m_STTSummary (id INTEGER PRIMARY KEY, result_id INTEGER NOT NULL UNIQUE);"
);
\Modules\ModuleLocalSpeechToText\Models\STTSetting::$connection = $connection;
\Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent::$connection = $connection;

$hashA = str_repeat('a', 64);
$hashB = str_repeat('b', 64);
$seedCall = static function (int $aggregateId, string $callId, int $jobId, int $resultId, int $publishedAt) use ($pdo, $hashA, $hashB): void {
    $pdo->exec("INSERT INTO m_STTJob (id, call_id, status) VALUES ({$jobId}, '{$callId}', 'done')");
    $pdo->exec("INSERT INTO m_STTResult (id, job_id) VALUES ({$resultId}, {$jobId})");
    $pdo->exec(
        "INSERT INTO m_STTCallTranscript (id, call_id, current_revision, completeness_status, created_at, updated_at)
         VALUES ({$aggregateId}, '{$callId}', 1, 'complete', {$publishedAt}, {$publishedAt})"
    );
    $pdo->exec(
        "INSERT INTO m_STTCallTranscriptRevision (call_transcript_id, revision, content_hash, parts_hash, completeness_status, payload, created_at)
         VALUES ({$aggregateId}, 1, '{$hashA}', '{$hashB}', 'complete', '{}', {$publishedAt})"
    );
    $pdo->exec(
        "INSERT INTO m_STTCallTranscriptEvent (event_type, event_key, call_transcript_id, call_id, revision, content_hash, parts_hash, completeness_status, created_at)
         VALUES ('call-transcript.completed', 'call-transcript.completed:{$aggregateId}:1:{$hashA}', {$aggregateId}, '{$callId}', 1, '{$hashA}', '{$hashB}', 'complete', {$publishedAt})"
    );
};
// Future timestamps keep the frontier above time(), as a skewed clock would.
$seedCall(1, 'call-one', 1, 11, 4_000_000_100);
$seedCall(2, 'call-two', 2, 12, 4_000_000_200);

$feed = new CallTranscriptIntegrationService();
$before = $feed->callTranscriptEvents(['cursor' => '0:0']);
expectDeletionFeed(($before['contract_version'] ?? null) === 3, 'event feed must advertise contract version 3');
expectDeletionFeed(count($before['events']) === 2, 'both published calls must be visible before deletion');
$consumerCursor = $before['next_cursor'];
expectDeletionFeed($consumerCursor === '4000000200:2', 'unexpected consumer cursor ' . $consumerCursor);

(new TranscriptDeletionService($connection, new ModuleTransaction()))->deleteByResultId(12);

$default = $feed->callTranscriptEvents(['cursor' => '0:0']);
expectDeletionFeed(
    array_column($default['events'], 'event_type') === ['call-transcript.completed']
        && ($default['events'][0]['aggregate_id'] ?? null) === 1,
    'default feed must keep only live completed/updated events: ' . json_encode($default['events'])
);
$defaultAfterCursor = $feed->callTranscriptEvents(['cursor' => $consumerCursor]);
expectDeletionFeed(
    $defaultAfterCursor['events'] === [] && $defaultAfterCursor['next_cursor'] === $consumerCursor,
    'default feed must not serve tombstones nor move the cursor for them'
);
foreach (['0', 'false', ''] as $off) {
    $explicitOff = $feed->callTranscriptEvents(['cursor' => $consumerCursor, 'include_deleted' => $off]);
    expectDeletionFeed($explicitOff['events'] === [], "include_deleted={$off} must keep tombstones hidden");
}

$withDeletions = $feed->callTranscriptEvents(['cursor' => $consumerCursor, 'include_deleted' => 'true']);
expectDeletionFeed(count($withDeletions['events']) === 1, 'opted-in consumer must receive the tombstone after its cursor');
$tombstone = $withDeletions['events'][0];
expectDeletionFeed(
    $tombstone['event_type'] === 'call-transcript.deleted'
        && $tombstone['aggregate_id'] === 2
        && $tombstone['revision'] === 1
        && $tombstone['call_id'] === null
        && $tombstone['content_hash'] === null
        && $tombstone['parts_hash'] === null
        && $tombstone['completeness_status'] === null
        && $tombstone['deletion_reason'] === 'manual'
        && $tombstone['deleted_at'] === 4_000_000_200,
    'tombstone shape mismatch: ' . json_encode($tombstone)
);
expectDeletionFeed($withDeletions['next_cursor'] === $tombstone['cursor'], 'tombstone must advance the opted-in cursor');
$drained = $feed->callTranscriptEvents(['cursor' => $withDeletions['next_cursor'], 'include_deleted' => '1']);
expectDeletionFeed($drained['events'] === [], 'tombstone must be served exactly once per cursor');

$fullReplay = $feed->callTranscriptEvents(['cursor' => '0:0', 'include_deleted' => true]);
expectDeletionFeed(
    array_column($fullReplay['events'], 'event_type') === ['call-transcript.completed', 'call-transcript.deleted'],
    'opted-in replay must list live events then tombstones in cursor order'
);

$seedCall(3, 'call-three', 3, 13, 4_000_000_300);
$next = $feed->callTranscriptEvents(['cursor' => $withDeletions['next_cursor']]);
expectDeletionFeed(
    count($next['events']) === 1 && $next['events'][0]['aggregate_id'] === 3,
    'events published after a deletion must keep flowing to default consumers'
);

$pdo->exec("UPDATE m_STTCallTranscriptEvent SET event_key = 'call-transcript.deleted:2:unknown' WHERE event_type = 'call-transcript.deleted'");
$corrupt = $feed->callTranscriptEvents(['cursor' => $consumerCursor, 'include_deleted' => '1']);
expectDeletionFeed(
    $corrupt['events'] === [] && $corrupt['next_cursor'] === $consumerCursor,
    'a malformed tombstone must stop the page before the cursor advances'
);

echo "Call transcript deletion feed SQLite behavior OK\n";
