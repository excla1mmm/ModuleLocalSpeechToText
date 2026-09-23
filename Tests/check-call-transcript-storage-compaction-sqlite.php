<?php

declare(strict_types=1);

/**
 * Schema migration 18 ("compact call transcript storage") on the real call
 * transcript schema: legacy revision / pending payloads are re-encoded without
 * changing hashes or the projected API transcript, redundant part segment
 * copies are released only where the result row holds the same segments, and
 * a second run changes nothing.
 */

use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptAssembler;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptPayloadCodec;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptSchemaService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptStorageCompaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptTimelineProjector;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptTurnProjector;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptPublicProjection;

function expectCompaction(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for the storage compaction test\n");
    exit(1);
}

final class CompactionConnection
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
}

$root = dirname(__DIR__);
require_once $root . '/Lib/Services/CallTranscriptSchemaService.php';
require_once $root . '/Lib/Services/CallTranscriptAssembler.php';
require_once $root . '/Lib/Services/CallTranscriptStorageCompaction.php';
require_once $root . '/Lib/Services/CallTranscriptTimelineProjector.php';
require_once $root . '/Lib/Services/TranscriptPublicProjection.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new CompactionConnection($pdo);
(new CallTranscriptSchemaService())->ensure($db);
$pdo->exec('CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, job_id INTEGER NOT NULL, transcript TEXT NOT NULL)');

$canonical = static fn (array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$segmentsFor = static fn (string $who): array => [
    ['start_ms' => 0, 'end_ms' => 1200, 'text' => "Алло, это {$who}.", 'channel' => 'left', 'speaker' => 'left'],
    ['start_ms' => 1500, 'end_ms' => 2600, 'text' => 'Да, здравствуйте.', 'channel' => 'right', 'speaker' => 'right'],
];
$legacyPayload = static function (string $callId, int $resultId, array $segments) use ($canonical): array {
    $assembled = (new CallTranscriptAssembler())->assemble($callId, [[
        'result_id' => $resultId,
        'cdr_id' => 100 + $resultId,
        'cdr_start_ms' => 1_700_000_000_000 + $resultId,
        'cdr_end_ms' => 1_700_000_010_000 + $resultId,
        'recording_fingerprint' => str_repeat('a', 64),
        'source_label' => 'cdr:' . (100 + $resultId),
        'resolution_status' => 'complete',
        'segments' => $segments,
    ]]);

    return [$assembled, $canonical($assembled)];
};
$project = static function (array $payload): array {
    $transcript = (new CallTranscriptTurnProjector())->project($payload['transcript']);
    $transcript = (new CallTranscriptTimelineProjector())->project($transcript, $payload['part_manifest']);

    return (new TranscriptPublicProjection())->project($transcript);
};
$insertPart = static function (int $id, int $callId, int $resultId, string $segmentsJson, int $isCurrent) use ($pdo): void {
    $statement = $pdo->prepare(
        'INSERT INTO m_STTCallTranscriptPart (id, call_transcript_id, result_id, recording_fingerprint, content_hash, source_label, segments_json, is_current, created_at)
         VALUES (:id, :call, :result, :fingerprint, :hash, :label, :segments, :current, 1)'
    );
    $statement->execute([
        'id' => $id,
        'call' => $callId,
        'result' => $resultId,
        'fingerprint' => str_repeat('a', 64),
        'hash' => hash('sha256', $segmentsJson . $id),
        'label' => 'cdr:' . $id,
        'segments' => $segmentsJson,
        'current' => $isCurrent,
    ]);
};

// Call 1: published revision, current part identical to its result.
$segmentsA = $segmentsFor('A');
[$assembledA, $payloadA] = $legacyPayload('call-a', 1, $segmentsA);
$pdo->prepare('INSERT INTO m_STTResult (id, job_id, transcript) VALUES (1, 1, :t)')->execute(['t' => $canonical($segmentsA)]);
$pdo->exec("INSERT INTO m_STTCallTranscript (id, call_id, current_revision, current_content_hash, created_at, updated_at) VALUES (1, 'call-a', 1, '{$assembledA['content_hash']}', 1, 1)");
$pdo->prepare("INSERT INTO m_STTCallTranscriptRevision (id, call_transcript_id, revision, content_hash, parts_hash, completeness_status, payload, created_at) VALUES (1, 1, 1, :c, :p, 'complete', :payload, 1)")
    ->execute(['c' => $assembledA['content_hash'], 'p' => $assembledA['parts_hash'], 'payload' => $payloadA]);
$insertPart(1, 1, 1, $canonical($segmentsA), 1);

// Call 2: pending payload, a superseded part and a current part whose copy
// differs from the result row (kept as-is).
$segmentsB = $segmentsFor('B');
[$assembledB, $payloadB] = $legacyPayload('call-b', 2, $segmentsB);
$pdo->prepare('INSERT INTO m_STTResult (id, job_id, transcript) VALUES (2, 2, :t)')->execute(['t' => $canonical($segmentsFor('B-rewritten'))]);
$pdo->prepare("INSERT INTO m_STTCallTranscript (id, call_id, pending_content_hash, pending_payload, created_at, updated_at) VALUES (2, 'call-b', :c, :payload, 1, 1)")
    ->execute(['c' => $assembledB['content_hash'], 'payload' => $payloadB]);
$insertPart(2, 2, 2, $canonical($segmentsFor('B-old')), 0);
$insertPart(3, 2, 2, $canonical($segmentsB), 1);

// Call 3: unreadable legacy data must be left untouched.
$pdo->exec("INSERT INTO m_STTCallTranscript (id, call_id, created_at, updated_at) VALUES (3, 'call-c', 1, 1)");
$pdo->exec("INSERT INTO m_STTCallTranscriptRevision (id, call_transcript_id, revision, content_hash, parts_hash, completeness_status, payload, created_at) VALUES (2, 3, 1, 'h', 'p', 'complete', '{broken-json', 1)");
$insertPart(4, 3, 99, '{broken-json', 1);

$codec = new CallTranscriptPayloadCodec();
$summary = (new CallTranscriptStorageCompaction($codec, 1))->run($db);
expectCompaction($summary === ['revisions' => 1, 'pending' => 1, 'superseded_parts' => 1, 'current_parts' => 1, 'kept_parts' => 2], 'unexpected compaction summary: ' . json_encode($summary));

$revision = $pdo->query('SELECT * FROM m_STTCallTranscriptRevision WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
expectCompaction($codec->isCompact($revision['payload']), 'revision payload must be compact');
expectCompaction($revision['content_hash'] === $assembledA['content_hash'] && $revision['parts_hash'] === $assembledA['parts_hash'] && (int)$revision['revision'] === 1, 'revision identity must not change');
expectCompaction(strlen($revision['payload']) < strlen($payloadA), 'revision payload must shrink');
expectCompaction($project($codec->decode($revision['payload'])) === $project(json_decode($payloadA, true)), 'projected API transcript must not change');

$pending = $pdo->query('SELECT pending_payload, pending_content_hash FROM m_STTCallTranscript WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
expectCompaction($codec->isCompact($pending['pending_payload']) && $pending['pending_content_hash'] === $assembledB['content_hash'], 'pending payload must be compact with unchanged hash');
expectCompaction($project($codec->decode($pending['pending_payload'])) === $project(json_decode($payloadB, true)), 'pending payload projection must not change');

$parts = $pdo->query('SELECT id, segments_json FROM m_STTCallTranscriptPart ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
expectCompaction($parts[1] === '[]', 'current part identical to its result must release its copy');
expectCompaction($parts[2] === '[]', 'superseded part must release its copy');
expectCompaction($parts[3] === $canonical($segmentsB), 'current part that differs from its result must keep its copy');
expectCompaction($parts[4] === '{broken-json', 'unreadable part must be left untouched');
expectCompaction($pdo->query('SELECT payload FROM m_STTCallTranscriptRevision WHERE id = 2')->fetchColumn() === '{broken-json', 'unreadable revision must be left untouched');

$snapshot = static fn (): array => [
    $pdo->query('SELECT id, payload FROM m_STTCallTranscriptRevision ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
    $pdo->query('SELECT id, pending_payload FROM m_STTCallTranscript ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
    $pdo->query('SELECT id, segments_json FROM m_STTCallTranscriptPart ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
];
$before = $snapshot();
$again = (new CallTranscriptStorageCompaction($codec))->run($db);
expectCompaction($again === ['revisions' => 0, 'pending' => 0, 'superseded_parts' => 0, 'current_parts' => 0, 'kept_parts' => 2], 'second run must be a no-op: ' . json_encode($again));
expectCompaction($snapshot() === $before, 'second run must not modify any row');

echo "Call transcript storage compaction migration OK\n";
