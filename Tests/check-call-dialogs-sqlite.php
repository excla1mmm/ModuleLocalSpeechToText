<?php

declare(strict_types=1);

/**
 * The call dialog API on the real module schema: lookup by linkedid and by a
 * leg UNIQUEID, the latest revision, turns signed with speaker roles, the call
 * summary read, and call-summary.completed events that stay opt-in.
 */

use Modules\ModuleLocalSpeechToText\Lib\Exceptions\CallTranscriptNotFoundException;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallDialogService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallSummaryEvents;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallSummaryMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptIntegrationService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptSchemaService;

function expectDialog(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for the call dialog test\n");
    exit(1);
}

final class DialogConnection
{
    public function __construct(public readonly PDO $pdo)
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

    public function isUnderTransaction(): bool { return $this->pdo->inTransaction(); }
}

eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTSetting { public const string SOURCE_INSTANCE_ID = "source_instance_id"; public static mixed $connection; public function getReadConnection(): mixed { return self::$connection; } }');
eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscriptEvent { public static mixed $connection; public function getReadConnection(): mixed { return self::$connection; } }');
eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscript { public int $id = 0; public string $call_id = ""; public string $current_revision = "0";
    public static function findFirstById(int $id): ?self { $row = STTCallTranscriptEvent::$connection->fetchOne("SELECT id, call_id, current_revision FROM m_STTCallTranscript WHERE id = :id", \PDO::FETCH_ASSOC, ["id" => $id]); if (!is_array($row)) { return null; } $call = new self(); $call->id = (int)$row["id"]; $call->call_id = (string)$row["call_id"]; $call->current_revision = (string)$row["current_revision"]; return $call; } }');
eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscriptRevision { public string $revision = "0"; public string $content_hash = ""; public string $parts_hash = ""; public string $completeness_status = ""; public string $payload = "{}"; public string $created_at = "0";
    public static function findFirst(array $query): ?self { $row = STTCallTranscriptEvent::$connection->fetchOne("SELECT * FROM m_STTCallTranscriptRevision WHERE call_transcript_id = :a AND revision = :r", \PDO::FETCH_ASSOC, ["a" => $query["bind"]["aggregate_id"], "r" => $query["bind"]["revision"]]); if (!is_array($row)) { return null; } $revision = new self(); foreach (["revision", "content_hash", "parts_hash", "completeness_status", "payload", "created_at"] as $field) { $revision->$field = (string)$row[$field]; } return $revision; } }');
if (!class_exists('Phalcon\\Db\\Enum')) {
    eval('namespace Phalcon\\Db; class Enum { public const int FETCH_ASSOC = 2; }');
}
if (!class_exists('Phalcon\\Di\\Di')) {
    eval('namespace Phalcon\\Di; class Di { public static function getDefault(): ?self { return null; } }');
}

$root = dirname(__DIR__);
require_once $root . '/Lib/Exceptions/CallTranscriptNotFoundException.php';
require_once $root . '/Lib/Services/CallTranscriptSchemaService.php';
require_once $root . '/Lib/Services/CallSummaryMigration.php';
require_once $root . '/Lib/Services/CallDialogService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection = new DialogConnection($pdo);
(new CallTranscriptSchemaService())->ensure($connection);
$pdo->beginTransaction();
(new CallSummaryMigration())->migrate($connection);
(new CallSummaryMigration())->migrateIndexes($connection);
$pdo->commit();
$pdo->exec(
    "CREATE TABLE m_STTSetting (key TEXT PRIMARY KEY, value TEXT NOT NULL);
     INSERT INTO m_STTSetting VALUES ('source_instance_id', '0123456789abcdef0123456789abcdef'),
         ('summarization_enabled', '1'), ('summarization_enabled_at', '1000');
     CREATE TABLE m_STTJob (id INTEGER PRIMARY KEY, call_id TEXT NOT NULL, status TEXT NOT NULL);
     CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, job_id INTEGER NOT NULL, language TEXT, created_at INTEGER NOT NULL DEFAULT 0);"
);
\Modules\ModuleLocalSpeechToText\Models\STTSetting::$connection = $connection;
\Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent::$connection = $connection;

$linkedId = 'mikopbx-1791275140.999663_claea922';
$hashA = str_repeat('a', 64);
$hashB = str_repeat('b', 64);
$hashC = str_repeat('c', 64);
$segment = static fn (int $resultId, int $order, int $index, int $startMs, string $channel, string $text): array => [
    'segment_id' => "call:fixed:r{$resultId}:s{$index}", 'result_id' => $resultId, 'source_index' => $index,
    'part_order' => $order, 'start_ms' => $startMs, 'end_ms' => $startMs + 3_000, 'text' => $text,
    'channel' => $channel, 'speaker' => '', 'source_label' => "part:{$resultId}",
];
$payload = static fn (array $resultIds, array $segments): string => json_encode([
    'part_manifest' => array_map(static fn (int $resultId, int $order): array => [
        'result_id' => $resultId, 'job_id' => $resultId, 'cdr_id' => 500 + $resultId,
        'uniqueid' => "leg-{$resultId}.1", 'cdr_start_ms' => 1_000_000 + $order * 30_000,
        'cdr_end_ms' => 1_030_000 + $order * 30_000, 'source_label' => "part:{$resultId}",
    ], $resultIds, array_keys($resultIds)),
    'transcript' => ['segments' => $segments],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$pdo->exec("INSERT INTO m_STTJob VALUES (11, '{$linkedId}', 'done'), (12, '{$linkedId}', 'done')");
$pdo->exec("INSERT INTO m_STTResult VALUES (11, 11, 'ru', 2000), (12, 12, 'ru', 2100)");
$pdo->exec(
    "INSERT INTO m_STTCallTranscript (id, call_id, current_revision, current_content_hash, completeness_status, created_at, updated_at)
     VALUES (7, '{$linkedId}', 2, '{$hashA}', 'complete', 3000, 3000)"
);
foreach ([[11, 'leg-11.1'], [12, 'leg-12.1']] as [$resultId, $uniqueId]) {
    $pdo->exec(
        "INSERT INTO m_STTCallTranscriptPart (call_transcript_id, result_id, job_id, cdr_uniqueid, recording_fingerprint, content_hash, source_label, created_at)
         VALUES (7, {$resultId}, {$resultId}, '{$uniqueId}', 'fp{$resultId}', '{$hashA}', 'part:{$resultId}', 3000)"
    );
}
$insertRevision = $pdo->prepare(
    "INSERT INTO m_STTCallTranscriptRevision (call_transcript_id, revision, content_hash, parts_hash, completeness_status, payload, created_at)
     VALUES (7, :revision, :content_hash, :parts_hash, 'complete', :payload, :created_at)"
);
// Revision 1 had only the first part; revision 2 is the latest.
$insertRevision->execute([
    'revision' => 1, 'content_hash' => $hashC, 'parts_hash' => $hashC, 'created_at' => 2500,
    'payload' => $payload([11], [$segment(11, 0, 0, 1_000, 'left', 'Старая ревизия')]),
]);
$insertRevision->execute([
    'revision' => 2, 'content_hash' => $hashA, 'parts_hash' => $hashB, 'created_at' => 3000,
    'payload' => $payload([11, 12], [
        $segment(11, 0, 0, 1_000, 'left', 'Здравствуйте, компания.'),
        $segment(11, 0, 1, 4_500, 'left', 'Чем могу помочь?'),
        $segment(11, 0, 2, 9_000, 'right', 'Хочу узнать статус заказа.'),
        $segment(12, 1, 0, 1_000, 'left', 'Соединяю с отделом доставки.'),
    ]),
]);
$pdo->exec(
    "INSERT INTO m_STTCallTranscriptEvent (event_type, event_key, call_transcript_id, call_id, revision, content_hash, parts_hash, completeness_status, created_at)
     VALUES ('call-transcript.completed', 'call-transcript.completed:7:2:{$hashA}', 7, '{$linkedId}', 2, '{$hashA}', '{$hashB}', 'complete', 3000)"
);

$contexts = [
    11 => ['direction' => 'incoming', 'speakers' => [
        'left' => ['role' => 'employee', 'name' => 'Иван Петров'],
        'right' => ['role' => 'client', 'name' => null],
    ]],
    12 => ['direction' => 'internal', 'speakers' => null],
];
$labels = ['client' => 'Клиент', 'employee' => 'Сотрудник', 'speaker_1' => 'Собеседник 1', 'speaker_2' => 'Собеседник 2'];
$dialogs = new CallDialogService(
    new CallTranscriptIntegrationService(),
    static fn (int $resultId): array => $contexts[$resultId] ?? ['direction' => null, 'speakers' => null],
    static fn (string $role): string => $labels[$role],
);

$dialog = $dialogs->forCallId($linkedId);
expectDialog($dialog['call_id'] === $linkedId && $dialog['aggregate_id'] === 7, 'dialog must be found by linkedid');
expectDialog($dialog['revision'] === 2 && $dialog['contract_version'] === 3, 'dialog must serve the latest revision');
expectDialog($dialog['direction'] === 'incoming' && $dialog['language'] === 'ru', 'dialog must carry the first part direction and the call language');
expectDialog(count($dialog['turns']) === 3, 'adjacent phrases of one channel must be joined into one turn: ' . json_encode($dialog['turns'], JSON_UNESCAPED_UNICODE));
expectDialog(
    $dialog['turns'][0]['speaker'] === 'employee' && $dialog['turns'][0]['speaker_name'] === 'Иван Петров'
        && str_contains($dialog['turns'][0]['text'], 'Здравствуйте') && str_contains($dialog['turns'][0]['text'], 'Чем могу помочь?'),
    'the employee turn must be joined and signed'
);
expectDialog($dialog['turns'][1]['speaker'] === 'client' && $dialog['turns'][1]['speaker_name'] === null, 'the client turn must be signed');
expectDialog($dialog['turns'][2]['speaker'] === null && $dialog['turns'][2]['start_ms'] === 31_000, 'a part without a channel mapping stays unsigned on the call timeline');
expectDialog(
    $dialog['text'] === "Иван Петров: Здравствуйте, компания. Чем могу помочь?\nКлиент: Хочу узнать статус заказа.\nСобеседник 1: Соединяю с отделом доставки.",
    'dialog text must be signed line by line: ' . $dialog['text']
);
expectDialog(!array_key_exists('summary', $dialog), 'the dialog must not carry the summary');
expectDialog(!array_key_exists('result_id', $dialog['turns'][0]) && !array_key_exists('content_hash', $dialog), 'the public dialog keeps its shape');
$promptDialog = $dialogs->forAggregateRevision(7, 2);
expectDialog(
    array_column($promptDialog['turns'], 'result_id') === [11, 11, 12] && $promptDialog['content_hash'] === $hashA,
    'the summary prompt dialog must name the leg of every turn and the revision content'
);
expectDialog(count($dialogs->forAggregateRevision(7, 1)['turns']) === 1, 'the summary prompt must be built from the leased revision');
$detail = (new CallTranscriptIntegrationService())->callTranscriptDetail(7);
expectDialog(!array_key_exists('summary', $detail) && $detail['revision'] === 2, 'the call transcript detail keeps its earlier shape');
$pending = $dialogs->summaryForCallId($linkedId);
expectDialog($pending['status'] === 'pending' && $pending['summary'] === null, 'a call without a summary row yet is pending');
expectDialog(!array_key_exists('parts', $pending) && array_key_exists('model', $pending) && array_key_exists('finished_at', $pending), 'the summary covers the whole call, not its parts');
expectDialog($pending['call_id'] === $linkedId && $pending['aggregate_id'] === 7 && $pending['revision'] === 2, 'the summary must identify the latest revision');
expectDialog($dialogs->summaryForCallId('leg-11.1')['aggregate_id'] === 7, 'the summary must be found by a leg UNIQUEID');

expectDialog($dialogs->forCallId('leg-12.1')['aggregate_id'] === 7, 'dialog must be found by a leg UNIQUEID');
$plan = implode(' ', array_column($pdo->query("EXPLAIN QUERY PLAN SELECT id FROM m_STTCallTranscriptPart WHERE cdr_uniqueid = 'leg-12.1'")->fetchAll(PDO::FETCH_ASSOC), 'detail'));
expectDialog(str_contains($plan, 'i_m_STTCallTranscriptPart_cdr_uniqueid'), 'the leg UNIQUEID lookup must use its index: ' . $plan);
$plan = implode(' ', array_column($pdo->query("EXPLAIN QUERY PLAN SELECT call_transcript_id FROM m_STTSummary WHERE status IN ('done', 'skipped', 'error') AND finished_at >= '100'")->fetchAll(PDO::FETCH_ASSOC), 'detail'));
expectDialog(str_contains($plan, 'i_m_STTSummary_finished'), 'the summary event scan must range over finished summaries: ' . $plan);
foreach (['unknown-call', '', '../etc/passwd', str_repeat('x', 300)] as $missing) {
    try {
        $dialogs->forCallId($missing);
        expectDialog(false, "unknown call id {$missing} must not be found");
    } catch (CallTranscriptNotFoundException) {
    }
    try {
        $dialogs->summaryForCallId($missing);
        expectDialog(false, "unknown call id {$missing} must not have a summary");
    } catch (CallTranscriptNotFoundException) {
    }
}

// One summary of the whole call; its event waits until it is final.
$feed = new CallTranscriptIntegrationService();
$events = new CallSummaryEvents();
$pdo->exec("INSERT INTO m_STTSummary (call_transcript_id, revision, content_hash, status) VALUES (7, 2, '{$hashA}', 'processing')");
expectDialog($events->publishReady($connection, 3200) === 0, 'no summary event while the call is still summarized');
expectDialog($dialogs->summaryForCallId($linkedId)['status'] === 'pending', 'a call being summarized is pending');

$pdo->exec("UPDATE m_STTSummary SET status = 'done', topic = 'Статус заказа', summary = 'Клиент спросил о заказе, его перевели в доставку.', model_used = 'gemma-3-4b-it-Q4_K_M', finished_at = 3300 WHERE call_transcript_id = 7");
$summary = $dialogs->summaryForCallId($linkedId);
expectDialog($summary['status'] === 'done' && $summary['topic'] === 'Статус заказа', 'the call summary must be served');
expectDialog($summary['summary'] === 'Клиент спросил о заказе, его перевели в доставку.' && $summary['model'] === 'gemma-3-4b-it-Q4_K_M' && $summary['finished_at'] === 3300, 'the call summary must carry its text, model and finish time');

expectDialog($events->publishReady($connection, 3400) === 1, 'one summary event once the call summary is final');
expectDialog($events->publishReady($connection, 3500) === 0, 'summary events are idempotent per revision');

$default = $feed->callTranscriptEvents(['cursor' => '0:0']);
expectDialog(array_column($default['events'], 'event_type') === ['call-transcript.completed'], 'default feed must not serve summary events');
expectDialog($default['next_cursor'] === '3000:1', 'default feed cursor must ignore summary events');
$withSummaries = $feed->callTranscriptEvents(['cursor' => '3000:1', 'include_summaries' => 'true']);
expectDialog(count($withSummaries['events']) === 1, 'opted-in consumer must receive the summary event');
$event = $withSummaries['events'][0];
expectDialog(
    $event['event_type'] === 'call-summary.completed' && $event['call_id'] === $linkedId
        && $event['aggregate_id'] === 7 && $event['revision'] === 2 && $event['content_hash'] === $hashA,
    'summary event must identify the call revision: ' . json_encode($event)
);
expectDialog($event['cursor'] === '3400:2', 'summary event must sort after the transcript event');
$withBoth = $feed->callTranscriptEvents(['cursor' => '0:0', 'include_summaries' => '1', 'include_deleted' => '1']);
expectDialog(array_column($withBoth['events'], 'event_type') === ['call-transcript.completed', 'call-summary.completed'], 'both opt-ins must work together');

// A summary of earlier content is about to be redone and gets no event for
// the new revision until then.
$hashD = str_repeat('d', 64);
$pdo->exec("UPDATE m_STTCallTranscript SET current_revision = 3, current_content_hash = '{$hashD}' WHERE id = 7");
$pdo->exec("INSERT INTO m_STTCallTranscriptRevision (call_transcript_id, revision, content_hash, parts_hash, completeness_status, payload, created_at)
     SELECT 7, 3, '{$hashD}', parts_hash, completeness_status, payload, 3600 FROM m_STTCallTranscriptRevision WHERE call_transcript_id = 7 AND revision = 2");
expectDialog($dialogs->summaryForCallId($linkedId)['status'] === 'pending', 'a summary of earlier content must show as pending');
expectDialog($events->publishReady($connection, 3700) === 0, 'a summary of earlier content must not complete the new revision');

// Turning summarization off keeps the last finished summary and hides calls
// that never got one.
$pdo->exec("UPDATE m_STTSetting SET value = '0' WHERE key = 'summarization_enabled'");
expectDialog($dialogs->summaryForCallId($linkedId)['status'] === 'done', 'a finished summary stays visible while summarization is off');
$pdo->exec("DELETE FROM m_STTSummary WHERE call_transcript_id = 7");
expectDialog($dialogs->summaryForCallId($linkedId)['status'] === 'unavailable', 'a call without a summary is unavailable while summarization is off');

echo "Call dialog SQLite contract OK\n";
