<?php

declare(strict_types=1);

function expectCallIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$required = [
    'Lib/Services/CallTranscriptIntegrationService.php',
    'Lib/Services/CallTranscriptMigration.php',
    'Lib/RestAPI/CallTranscripts/Controller.php',
    'Lib/RestAPI/CallTranscripts/Processor.php',
    'Lib/RestAPI/CallTranscripts/DataStructure.php',
    'Lib/RestAPI/CallTranscriptEvents/Controller.php',
    'Lib/RestAPI/CallTranscriptEvents/Processor.php',
    'Lib/RestAPI/CallTranscriptEvents/DataStructure.php',
];
foreach ($required as $relative) {
    expectCallIntegration(is_file($root . '/' . $relative), "missing {$relative}");
}

$eventsController = file_get_contents($root . '/Lib/RestAPI/CallTranscriptEvents/Controller.php') ?: '';
expectCallIntegration(str_contains($eventsController, "'/call-transcripts/events'"), 'call event route missing');
expectCallIntegration(str_contains($eventsController, 'PBXApiResult'), 'call event endpoint must document PBXApiResult');

$detailController = file_get_contents($root . '/Lib/RestAPI/CallTranscripts/Controller.php') ?: '';
expectCallIntegration(str_contains($detailController, "'/call-transcripts'"), 'call transcript detail route missing');
expectCallIntegration(str_contains($detailController, "idPattern: '[0-9]+'"), 'aggregate id must be numeric');

$integration = file_get_contents($root . '/Lib/Services/CallTranscriptIntegrationService.php') ?: '';
foreach (['callTranscriptEvents', 'callTranscriptDetail', 'content_hash', 'parts_hash', 'completeness_status'] as $needle) {
    expectCallIntegration(str_contains($integration, $needle), "integration service missing {$needle}");
}

$migration = file_get_contents($root . '/Lib/Services/CallTranscriptMigration.php') ?: '';
foreach (['legacy_unresolved', 'recordCompletedPart', 'publishReady', 'm_STTResult'] as $needle) {
    expectCallIntegration(str_contains($migration, $needle), "migration missing {$needle}");
}
expectCallIntegration(!str_contains($migration, 'DELETE FROM'), 'migration must not delete source or historical data');

$legacyEvents = file_get_contents($root . '/Models/STTTranscriptEvent.php') ?: '';
expectCallIntegration(str_contains($legacyEvents, "TYPE_TRANSCRIPT_COMPLETED = 'transcript.completed'"), 'legacy part event must remain');

if (!class_exists('Modules\\ModuleLocalSpeechToText\\Models\\STTSetting')) {
    eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTSetting { public const string SOURCE_INSTANCE_ID = "source_instance_id"; public static mixed $connection; public function getReadConnection(): mixed { return self::$connection; } }');
    eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscriptEvent { public static mixed $connection; public function getReadConnection(): mixed { return self::$connection; } }');
    eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscript { public static ?self $row = null; public int $id = 0; public string $call_id = ""; public string $current_revision = "0"; public static function findFirstById(int $id): ?self { return self::$row; } }');
    eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscriptRevision { public static ?self $row = null; public string $revision = "0"; public string $content_hash = ""; public string $parts_hash = ""; public string $completeness_status = ""; public string $payload = "{}"; public string $created_at = "0"; public static function findFirst(array $query): ?self { return self::$row; } }');
}
if (!class_exists('Phalcon\\Db\\Enum')) {
    eval('namespace Phalcon\\Db; class Enum { public const int FETCH_ASSOC = 2; }');
}
require_once $root . '/Lib/Services/SttSourceInstance.php';
require_once $root . '/Lib/Services/CallTranscriptIntegrationService.php';

final class CallIntegrationConnection
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private readonly array $rows, private readonly string $sourceId)
    {
    }
    public function fetchAll(string $sql, int $mode, array $bind = []): array { return $this->rows; }
    public function fetchOne(string $sql, int $mode, array $bind = []): array { return ['value' => $this->sourceId]; }
}

$sourceId = '0123456789abcdef0123456789abcdef';
$validEvent = [
    'id' => 4, 'event_type' => 'call-transcript.completed', 'call_transcript_id' => 7,
    'call_id' => '1700000000.1', 'revision' => 2,
    'content_hash' => str_repeat('a', 64), 'parts_hash' => str_repeat('b', 64),
    'completeness_status' => 'complete', 'created_at' => 1700000100,
];
$setConnection = static function (array $rows) use ($sourceId): void {
    $connection = new CallIntegrationConnection($rows, $sourceId);
    \Modules\ModuleLocalSpeechToText\Models\STTSetting::$connection = $connection;
    \Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent::$connection = $connection;
};
$service = new \Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptIntegrationService();
$setConnection([]);
$empty = $service->callTranscriptEvents(['cursor' => '0:0']);
expectCallIntegration(($empty['source_instance_id'] ?? '') === $sourceId, 'empty event page must expose source ID');
expectCallIntegration($empty['events'] === [] && $empty['next_cursor'] === '0:0', 'empty event page changed legacy cursor fields');
$setConnection([$validEvent]);
$page = $service->callTranscriptEvents(['cursor' => '0:0']);
expectCallIntegration(($page['events'][0]['call_id'] ?? '') === $validEvent['call_id'], 'event lacks canonical call ID');
expectCallIntegration(($page['events'][0]['content_hash'] ?? '') === $validEvent['content_hash'], 'event lacks canonical content hash');
$setConnection([$validEvent, array_replace($validEvent, ['id' => 5, 'call_transcript_id' => 0, 'created_at' => 1700000200])]);
$bounded = $service->callTranscriptEvents(['cursor' => '0:0']);
expectCallIntegration(count($bounded['events']) === 1 && $bounded['next_cursor'] === '1700000100:4', 'invalid event must stop publication before cursor advancement');

$call = new \Modules\ModuleLocalSpeechToText\Models\STTCallTranscript();
$call->id = 7; $call->call_id = $validEvent['call_id']; $call->current_revision = '2';
\Modules\ModuleLocalSpeechToText\Models\STTCallTranscript::$row = $call;
$revision = new \Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision();
$revision->revision = '2'; $revision->content_hash = $validEvent['content_hash']; $revision->parts_hash = $validEvent['parts_hash'];
$revision->completeness_status = 'complete'; $revision->created_at = '1700000100'; $revision->payload = '{"part_manifest":[],"transcript":{"segments":[]}}';
\Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision::$row = $revision;
$detail = $service->callTranscriptDetail(7, 2);
foreach (['source_instance_id','call_id','aggregate_id','revision','content_hash','parts_hash','completeness_status'] as $field) {
    $expected = $field === 'source_instance_id' ? $sourceId : ($field === 'aggregate_id' ? 7 : $validEvent[$field]);
    expectCallIntegration(($detail[$field] ?? null) === $expected, "event/detail identity mismatch for {$field}");
}

$reinstallFixtures = dirname($root) . '/ModuleAISupervisor/Tests/fixtures/stt-reinstall';
$fixtureDatabases = [];
foreach (['source-a.sql' => 100, 'source-b.sql' => 75] as $fixture => $expectedCalls) {
    $fixturePath = $reinstallFixtures . '/' . $fixture;
    expectCallIntegration(is_file($fixturePath), "missing shared reinstall fixture {$fixture}");
    $fixtureDatabase = new PDO('sqlite::memory:');
    $fixtureDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fixtureSql = file_get_contents($fixturePath);
    expectCallIntegration(is_string($fixtureSql) && $fixtureDatabase->exec($fixtureSql) !== false, "invalid reinstall fixture {$fixture}");
    expectCallIntegration($fixtureDatabase->query('PRAGMA integrity_check')->fetchColumn() === 'ok', "fixture integrity failed {$fixture}");
    expectCallIntegration(
        (int)$fixtureDatabase->query('SELECT COUNT(*) FROM m_STTCallTranscript')->fetchColumn() === $expectedCalls,
        "fixture call count changed {$fixture}"
    );
    $fixtureDatabases[$fixture] = $fixtureDatabase;
}
$sourceA = $fixtureDatabases['source-a.sql'];
$sourceB = $fixtureDatabases['source-b.sql'];
expectCallIntegration(
    $sourceA->query("SELECT value FROM m_STTSetting WHERE key='source_instance_id'")->fetchColumn()
        !== $sourceB->query("SELECT value FROM m_STTSetting WHERE key='source_instance_id'")->fetchColumn(),
    'clean-reinstall fixtures must represent different source instances'
);
expectCallIntegration(
    $sourceA->query('SELECT call_id FROM m_STTCallTranscript WHERE id=66')->fetchColumn() === 'call-066'
        && $sourceB->query('SELECT call_id FROM m_STTCallTranscript WHERE id=66')->fetchColumn() === 'call-101',
    'source B must deliberately reuse source-local IDs for unrelated calls'
);
expectCallIntegration(
    (int)$sourceB->query("SELECT COUNT(*) FROM m_STTCallTranscript b
        WHERE b.id <= 60 AND b.current_content_hash = printf('%064x', b.id)")->fetchColumn() === 60
        && (int)$sourceB->query("SELECT COUNT(*) FROM m_STTCallTranscript b
            WHERE b.id BETWEEN 61 AND 65 AND b.current_content_hash <> printf('%064x', b.id)")->fetchColumn() === 5,
    'source B unchanged/changed hash distribution is invalid'
);

echo "Call transcript integration contract OK\n";
