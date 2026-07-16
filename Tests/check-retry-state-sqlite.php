<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\RetryStateStore;

require_once __DIR__ . '/../Lib/Services/RetryStateStore.php';

final class RetryStateSqliteConnection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        $statement = $this->pdo->prepare($sql);

        return $statement->execute($bind);
    }

    public function fetchAll(string $sql, int $fetchMode, array $bind = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);

        return $statement->fetchAll($fetchMode);
    }
}

function expectRetryState(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for retry-state behavior tests\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new RetryStateStore(new RetryStateSqliteConnection($pdo));
$store->ensureSchema();

for ($id = 1; $id <= 501; $id++) {
    $store->remember($id, RetryStateStore::TYPE_DELAYED, [
        'recordingfile' => "delayed-{$id}.wav",
        'first_seen' => 100,
    ], 'recording_missing', 100);
}
for ($id = 502; $id <= 602; $id++) {
    $store->remember($id, RetryStateStore::TYPE_SKIPPED, [
        'recordingfile' => "skipped-{$id}.wav",
        'created_at' => 100,
    ], 'file_too_large', 100);
}

expectRetryState($store->count(RetryStateStore::TYPE_DELAYED) === 501, 'All delayed rows above the former 500 limit must persist');
expectRetryState($store->count(RetryStateStore::TYPE_SKIPPED) === 101, 'All skipped rows above the former 100 limit must persist');

$processed = [];
foreach ([RetryStateStore::TYPE_DELAYED, RetryStateStore::TYPE_SKIPPED] as $type) {
    do {
        $page = $store->due($type, 37, 100);
        expectRetryState(count($page) <= 37, 'Retry pages must keep memory bounded');
        foreach ($page as $record) {
            $cdrId = (int)$record['cdr_id'];
            expectRetryState(!isset($processed[$cdrId]), "CDR {$cdrId} must be deduplicated");
            $processed[$cdrId] = true;
            $store->remove($cdrId);
        }
    } while ($page !== []);
}

expectRetryState(count($processed) === 602, 'Every durable delayed/skipped CDR must be returned for subsequent processing');
expectRetryState($store->count() === 0, 'Processed retry rows must be removable');

$store->remember(700, RetryStateStore::TYPE_DELAYED, ['recordingfile' => 'old.wav'], 'recording_missing', 100);
$store->remember(700, RetryStateStore::TYPE_SKIPPED, ['recordingfile' => 'new.wav'], 'file_too_large', 200);
$deduplicated = $store->due(RetryStateStore::TYPE_SKIPPED, 10, 200);
expectRetryState(count($deduplicated) === 1, 'One CDR must occupy one durable retry row');
expectRetryState(
    ($deduplicated[0]['payload']['recordingfile'] ?? null) === 'new.wav',
    'Retry-state upsert must retain the latest CDR payload'
);
$store->remove(700);

$store->remember(750, RetryStateStore::TYPE_SKIPPED, ['recordingfile' => 'backoff.wav'], 'file_too_large', 100);
$store->deferWithBackoff(750, RetryStateStore::TYPE_SKIPPED, 15, 3600, 100);
expectRetryState($store->due(RetryStateStore::TYPE_SKIPPED, 10, 114) === [], 'First skipped retry must be delayed');
expectRetryState(count($store->due(RetryStateStore::TYPE_SKIPPED, 10, 115)) === 1, 'First skipped retry must become due after 15 seconds');
$store->deferWithBackoff(750, RetryStateStore::TYPE_SKIPPED, 15, 3600, 115);
expectRetryState($store->due(RetryStateStore::TYPE_SKIPPED, 10, 144) === [], 'Skipped retry delay must back off');
expectRetryState(count($store->due(RetryStateStore::TYPE_SKIPPED, 10, 145)) === 1, 'Skipped retry backoff must remain bounded and due');
$store->remove(750);

$store->importLegacy([
    800 => ['recordingfile' => 'legacy-delayed.wav', 'first_seen' => 50],
], RetryStateStore::TYPE_DELAYED, 'recording_missing', 300);
expectRetryState(
    $store->count(RetryStateStore::TYPE_DELAYED) === 1,
    'Upgrade migration must import legacy JSON retry state idempotently'
);
$store->importLegacy([
    800 => ['recordingfile' => 'legacy-delayed.wav', 'first_seen' => 50],
], RetryStateStore::TYPE_DELAYED, 'recording_missing', 300);
expectRetryState(
    $store->count(RetryStateStore::TYPE_DELAYED) === 1,
    'Repeated upgrade migration must not duplicate a legacy CDR'
);
$store->defer(800, RetryStateStore::TYPE_DELAYED, 600, 300);
$store->importLegacy([
    800 => ['recordingfile' => 'stale-legacy.wav', 'first_seen' => 1],
], RetryStateStore::TYPE_SKIPPED, 'stale_import', 400);
expectRetryState(
    $store->due(RetryStateStore::TYPE_DELAYED, 10, 899) === [],
    'Repeated migration must preserve a newer deferred schedule'
);
$preservedLegacy = $store->due(RetryStateStore::TYPE_DELAYED, 10, 900);
expectRetryState(
    count($preservedLegacy) === 1
        && ($preservedLegacy[0]['payload']['recordingfile'] ?? null) === 'legacy-delayed.wav'
        && $preservedLegacy[0]['reason'] === 'recording_missing',
    'Repeated migration must not overwrite newer durable payload, type, or reason'
);

$worker = file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php');
$setup = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
expectRetryState($worker !== false && $setup !== false, 'Cannot inspect retry-state integration');
expectRetryState(
    str_contains($worker, 'RetryStateStore::TYPE_DELAYED')
        && str_contains($worker, 'RetryStateStore::TYPE_SKIPPED')
        && str_contains($worker, '->due(')
        && str_contains($worker, 'durable retry state'),
    'Worker overflow and retry paths must use observable durable state'
);
expectRetryState(
    str_contains($setup, 'ensureRetryStateSchema')
        && str_contains($setup, 'importLegacyRetryState'),
    'Module upgrade must create and idempotently populate durable retry state'
);

echo "Retry state SQLite behavior OK\n";
