<?php

declare(strict_types=1);

use MikoPBX\Core\System\Upgrade\UpdateDatabase;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptSchemaService;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptPart;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision;
use Phalcon\Annotations\Adapter\Memory as AnnotationMemory;
use Phalcon\Config\Config;
use Phalcon\Db\Adapter\Pdo\Sqlite;
use Phalcon\Db\Enum;
use Phalcon\Di\Di;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Model\MetaData\Memory as MetadataMemory;
use Phalcon\Mvc\Model\MetaData\Strategy\Annotations;

const CALL_TRANSCRIPT_TABLES = [
    'm_STTCallTranscript',
    'm_STTCallTranscriptPart',
    'm_STTCallTranscriptRevision',
    'm_STTCallTranscriptEvent',
];

function failOrmIntegration(string $message): never
{
    fwrite(STDERR, "Assertion failed: {$message}\n");
    exit(1);
}

function expectOrmIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        failOrmIntegration($message);
    }
}

/** @return array<string, array<string, mixed>> */
function tableInfo(Sqlite $db, string $table): array
{
    $rows = $db->fetchAll("PRAGMA table_info({$table})", Enum::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $result[(string)$row['name']] = $row;
    }

    return $result;
}

function schemaSnapshot(Sqlite $db): string
{
    $tableList = "'" . implode("','", CALL_TRANSCRIPT_TABLES) . "'";
    $schema = $db->fetchAll(
        "SELECT type, tbl_name, name, sql
           FROM sqlite_master
          WHERE tbl_name IN ({$tableList})
          ORDER BY type, tbl_name, name",
        Enum::FETCH_ASSOC
    );
    $rows = [];
    foreach (CALL_TRANSCRIPT_TABLES as $table) {
        $rows[$table] = $db->fetchAll("SELECT * FROM {$table} ORDER BY id", Enum::FETCH_ASSOC);
    }

    return json_encode([$schema, $rows], JSON_THROW_ON_ERROR);
}

function normalizedSqliteDefault(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = (string)$value;
    if (strlen($value) >= 2) {
        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
            return substr($value, 1, -1);
        }
    }

    return $value;
}

/** @param list<class-string> $modelClasses */
function migrateCallTranscriptSchema(Sqlite $db, MetadataMemory $metadata, array $modelClasses): void
{
    foreach ($modelClasses as $modelClass) {
        expectOrmIntegration(
            (new UpdateDatabase())->createUpdateDbTableByAnnotations($modelClass),
            "Core annotation migration failed for {$modelClass}"
        );
    }
    (new CallTranscriptSchemaService())->ensure($db);
    $metadata->reset();
}

$coreRoot = rtrim((string)(getenv('MIKOPBX_CORE_ROOT') ?: '/usr/www'), '/');
$moduleRoot = rtrim((string)(getenv('MIKOPBX_MODULE_ROOT') ?: dirname(__DIR__, 2)), '/');
$autoload = $coreRoot . '/vendor/autoload.php';
$productionFiles = [
    'Models/STTCallTranscript.php',
    'Models/STTCallTranscriptPart.php',
    'Models/STTCallTranscriptRevision.php',
    'Models/STTCallTranscriptEvent.php',
    'Lib/Services/CallTranscriptSchemaService.php',
];

if (!extension_loaded('phalcon')) {
    failOrmIntegration('ext-phalcon is required');
}
if (!is_file($autoload)) {
    failOrmIntegration("MikoPBX autoload not found at {$autoload}");
}
foreach ($productionFiles as $relativePath) {
    if (!is_file($moduleRoot . '/' . $relativePath)) {
        failOrmIntegration("production file not found at {$moduleRoot}/{$relativePath}");
    }
}

require $autoload;
foreach ($productionFiles as $relativePath) {
    require $moduleRoot . '/' . $relativePath;
}

final class IntegrationSTTCallTranscript extends STTCallTranscript
{
    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscript');
        $this->setConnectionService('db');
    }
}

final class IntegrationSTTCallTranscriptPart extends STTCallTranscriptPart
{
    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscriptPart');
        $this->setConnectionService('db');
    }
}

final class IntegrationSTTCallTranscriptRevision extends STTCallTranscriptRevision
{
    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscriptRevision');
        $this->setConnectionService('db');
    }
}

final class IntegrationSTTCallTranscriptEvent extends STTCallTranscriptEvent
{
    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscriptEvent');
        $this->setConnectionService('db');
    }
}

Di::reset();
$di = new FactoryDefault();
Di::setDefault($di);

$db = new Sqlite(['dbname' => ':memory:']);
$metadata = new MetadataMemory();
$metadata->setStrategy(new Annotations());
$di->setShared('db', $db);
$di->setShared('modelsMetadata', $metadata);
$di->setShared('annotations', new AnnotationMemory());
$di->setShared('config', new Config(['core' => ['tempDir' => '/tmp']]));
$di->setShared('logger', new class {
    public function log(int $level, string $message): void
    {
    }
});

$db->execute(
    'CREATE TABLE m_STTCallTranscript (
        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        call_id VARCHAR(0) NOT NULL,
        current_revision INTEGER NOT NULL,
        current_content_hash VARCHAR(0) NOT NULL,
        current_parts_hash VARCHAR(0) NOT NULL,
        pending_content_hash VARCHAR(0) NOT NULL,
        pending_parts_hash VARCHAR(0) NOT NULL,
        pending_payload VARCHAR(0) NOT NULL,
        completeness_status VARCHAR(0) NOT NULL,
        stable_after INTEGER NOT NULL,
        published_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )'
);
$db->execute(
    'CREATE TABLE m_STTCallTranscriptPart (
        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        call_transcript_id INTEGER NOT NULL,
        result_id INTEGER NOT NULL,
        job_id INTEGER,
        cdr_id INTEGER,
        cdr_uniqueid VARCHAR(0),
        cdr_start_ms INTEGER,
        cdr_end_ms INTEGER,
        recording_fingerprint VARCHAR(0) NOT NULL,
        content_hash VARCHAR(0) NOT NULL,
        source_label VARCHAR(0) NOT NULL,
        resolution_status VARCHAR(0) NOT NULL,
        segments_json VARCHAR(0) NOT NULL,
        is_current INTEGER NOT NULL,
        superseded_by_part_id INTEGER,
        created_at INTEGER NOT NULL
    )'
);
$db->execute(
    'CREATE TABLE m_STTCallTranscriptRevision (
        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        call_transcript_id INTEGER NOT NULL,
        revision INTEGER NOT NULL,
        content_hash VARCHAR(0) NOT NULL,
        parts_hash VARCHAR(0) NOT NULL,
        completeness_status VARCHAR(0) NOT NULL,
        payload VARCHAR(0) NOT NULL,
        created_at INTEGER NOT NULL
    )'
);
$db->execute(
    'CREATE TABLE m_STTCallTranscriptEvent (
        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        event_type VARCHAR(0) NOT NULL,
        event_key VARCHAR(0) NOT NULL,
        call_transcript_id INTEGER NOT NULL,
        call_id VARCHAR(0) NOT NULL,
        revision INTEGER NOT NULL,
        content_hash VARCHAR(0) NOT NULL,
        parts_hash VARCHAR(0) NOT NULL,
        completeness_status VARCHAR(0) NOT NULL,
        created_at INTEGER NOT NULL
    )'
);

$legacyIndexes = [
    'CREATE UNIQUE INDEX m_STTCallTranscript_call_id ON m_STTCallTranscript (call_id)',
    'CREATE INDEX m_STTCallTranscript_publish ON m_STTCallTranscript (stable_after, pending_content_hash)',
    'CREATE UNIQUE INDEX m_STTCallTranscriptPart_version ON m_STTCallTranscriptPart (call_transcript_id, result_id, content_hash, recording_fingerprint)',
    'CREATE UNIQUE INDEX m_STTCallTranscriptPart_current_result ON m_STTCallTranscriptPart (call_transcript_id, result_id) WHERE is_current = 1',
    'CREATE UNIQUE INDEX m_STTCallTranscriptRevision_number ON m_STTCallTranscriptRevision (call_transcript_id, revision)',
    'CREATE UNIQUE INDEX m_STTCallTranscriptRevision_hash ON m_STTCallTranscriptRevision (call_transcript_id, content_hash)',
    'CREATE UNIQUE INDEX m_STTCallTranscriptEvent_key ON m_STTCallTranscriptEvent (event_key)',
    'CREATE INDEX m_STTCallTranscriptEvent_cursor ON m_STTCallTranscriptEvent (created_at, id)',
];
foreach ($legacyIndexes as $sql) {
    $db->execute($sql);
}

$db->execute(
    "INSERT INTO m_STTCallTranscript VALUES
        (7, 'legacy-call', 1, 'aggregate-hash', 'aggregate-parts', '', '', '', 'complete', 0, 1700000000, 1699999900, 1700000000)"
);
$db->execute(
    "INSERT INTO m_STTCallTranscriptPart VALUES
        (11, 7, 101, 201, 301, 'legacy-a', 1000, 2000, 'fingerprint-a', 'part-a', 'cdr:301', 'complete', '[]', 0, 12, 1699999910),
        (12, 7, 101, 201, 301, 'legacy-b', 1000, 2000, 'fingerprint-b', 'part-b', 'cdr:301', 'complete', '[]', 1, NULL, 1699999920)"
);
$db->execute(
    "INSERT INTO m_STTCallTranscriptRevision VALUES
        (21, 7, 1, 'aggregate-hash', 'aggregate-parts', 'complete', '{}', 1700000000)"
);
$db->execute(
    "INSERT INTO m_STTCallTranscriptEvent VALUES
        (31, 'call-transcript.completed', 'completed:7:1:aggregate-hash', 7, 'legacy-call', 1, 'aggregate-hash', 'aggregate-parts', 'complete', 1700000001)"
);

$modelClasses = [
    IntegrationSTTCallTranscript::class,
    IntegrationSTTCallTranscriptPart::class,
    IntegrationSTTCallTranscriptRevision::class,
    IntegrationSTTCallTranscriptEvent::class,
];
migrateCallTranscriptSchema($db, $metadata, $modelClasses);

$aggregateColumns = tableInfo($db, 'm_STTCallTranscript');
foreach (['call_id', 'current_content_hash', 'current_parts_hash', 'pending_content_hash', 'pending_parts_hash', 'pending_payload', 'completeness_status'] as $field) {
    expectOrmIntegration(($aggregateColumns[$field]['type'] ?? null) === 'VARCHAR(0)', "aggregate.{$field} must remain Core-compatible VARCHAR");
}
foreach (['current_content_hash', 'current_parts_hash', 'pending_content_hash', 'pending_parts_hash', 'pending_payload'] as $field) {
    expectOrmIntegration(($aggregateColumns[$field]['notnull'] ?? null) === 1, "aggregate.{$field} must remain NOT NULL");
    expectOrmIntegration(normalizedSqliteDefault($aggregateColumns[$field]['dflt_value'] ?? null) === '', "aggregate.{$field} must default to an empty string");
}
foreach (['current_revision', 'stable_after', 'published_at'] as $field) {
    expectOrmIntegration(($aggregateColumns[$field]['type'] ?? null) === 'INTEGER', "aggregate.{$field} must remain INTEGER");
    expectOrmIntegration(normalizedSqliteDefault($aggregateColumns[$field]['dflt_value'] ?? null) === '0', "aggregate.{$field} must default to 0");
}
expectOrmIntegration(
    normalizedSqliteDefault($aggregateColumns['completeness_status']['dflt_value'] ?? null) === 'pending',
    'aggregate.completeness_status must default to pending'
);

$partColumns = tableInfo($db, 'm_STTCallTranscriptPart');
foreach (['cdr_uniqueid', 'recording_fingerprint', 'content_hash', 'source_label', 'resolution_status', 'segments_json'] as $field) {
    expectOrmIntegration(($partColumns[$field]['type'] ?? null) === 'VARCHAR(0)', "part.{$field} must remain Core-compatible VARCHAR");
}
expectOrmIntegration(normalizedSqliteDefault($partColumns['resolution_status']['dflt_value'] ?? null) === 'complete', 'part.resolution_status default mismatch');
expectOrmIntegration(normalizedSqliteDefault($partColumns['segments_json']['dflt_value'] ?? null) === '[]', 'part.segments_json default mismatch');
expectOrmIntegration(normalizedSqliteDefault($partColumns['is_current']['dflt_value'] ?? null) === '1', 'part.is_current default mismatch');

$revisionColumns = tableInfo($db, 'm_STTCallTranscriptRevision');
foreach (['content_hash', 'parts_hash', 'completeness_status', 'payload'] as $field) {
    expectOrmIntegration(($revisionColumns[$field]['type'] ?? null) === 'VARCHAR(0)', "revision.{$field} must remain Core-compatible VARCHAR");
}
$eventColumns = tableInfo($db, 'm_STTCallTranscriptEvent');
foreach (['event_type', 'event_key', 'call_id', 'content_hash', 'parts_hash', 'completeness_status'] as $field) {
    expectOrmIntegration(($eventColumns[$field]['type'] ?? null) === 'VARCHAR(0)', "event.{$field} must remain Core-compatible VARCHAR");
}

$aggregate = $db->fetchOne('SELECT * FROM m_STTCallTranscript WHERE id = 7', Enum::FETCH_ASSOC);
$parts = $db->fetchAll('SELECT * FROM m_STTCallTranscriptPart ORDER BY id', Enum::FETCH_ASSOC);
$revision = $db->fetchOne('SELECT * FROM m_STTCallTranscriptRevision WHERE id = 21', Enum::FETCH_ASSOC);
$event = $db->fetchOne('SELECT * FROM m_STTCallTranscriptEvent WHERE id = 31', Enum::FETCH_ASSOC);
expectOrmIntegration(($aggregate['call_id'] ?? null) === 'legacy-call', 'aggregate sentinel changed');
expectOrmIntegration(count($parts) === 2, 'part history was lost');
expectOrmIntegration(($parts[0]['superseded_by_part_id'] ?? null) === 12, 'part supersession link changed');
expectOrmIntegration(($parts[1]['call_transcript_id'] ?? null) === 7, 'current part aggregate link changed');
expectOrmIntegration(($revision['call_transcript_id'] ?? null) === 7, 'revision aggregate link changed');
expectOrmIntegration(($event['call_transcript_id'] ?? null) === 7, 'event aggregate link changed');
expectOrmIntegration(($event['created_at'] ?? null) === 1700000001, 'event cursor timestamp changed');

$indexes = $db->fetchAll(
    "SELECT name, sql
       FROM sqlite_master
      WHERE type = 'index'
        AND tbl_name IN ('m_STTCallTranscript', 'm_STTCallTranscriptPart', 'm_STTCallTranscriptRevision', 'm_STTCallTranscriptEvent')
        AND sql IS NOT NULL
      ORDER BY name",
    Enum::FETCH_ASSOC
);
$indexSql = [];
foreach ($indexes as $index) {
    $indexSql[(string)$index['name']] = (string)$index['sql'];
}
$expectedIndexes = [
    'i_m_STTCallTranscriptEvent_cursor',
    'i_m_STTCallTranscriptEvent_key',
    'i_m_STTCallTranscriptPart_version',
    'i_m_STTCallTranscriptRevision_hash',
    'i_m_STTCallTranscriptRevision_number',
    'i_m_STTCallTranscript_call_id',
    'i_m_STTCallTranscript_publish',
    'm_STTCallTranscriptPart_sqlite_autoindex_current_result',
];
expectOrmIntegration(array_keys($indexSql) === $expectedIndexes, 'canonical call-transcript indexes were not restored exactly');
$expectedIndexDefinitions = [
    'i_m_STTCallTranscriptEvent_cursor' => ['unique' => false, 'columns' => '(created_at, id)', 'where' => null],
    'i_m_STTCallTranscriptEvent_key' => ['unique' => true, 'columns' => '(event_key)', 'where' => null],
    'i_m_STTCallTranscriptPart_version' => ['unique' => true, 'columns' => '(call_transcript_id, result_id, content_hash, recording_fingerprint)', 'where' => null],
    'i_m_STTCallTranscriptRevision_hash' => ['unique' => true, 'columns' => '(call_transcript_id, content_hash)', 'where' => null],
    'i_m_STTCallTranscriptRevision_number' => ['unique' => true, 'columns' => '(call_transcript_id, revision)', 'where' => null],
    'i_m_STTCallTranscript_call_id' => ['unique' => true, 'columns' => '(call_id)', 'where' => null],
    'i_m_STTCallTranscript_publish' => ['unique' => false, 'columns' => '(stable_after, pending_content_hash)', 'where' => null],
    'm_STTCallTranscriptPart_sqlite_autoindex_current_result' => ['unique' => true, 'columns' => '(call_transcript_id, result_id)', 'where' => 'WHERE is_current = 1'],
];
foreach ($expectedIndexDefinitions as $name => $expected) {
    $sql = preg_replace('/\s+/', ' ', $indexSql[$name] ?? '') ?? '';
    expectOrmIntegration(
        str_contains($sql, $expected['unique'] ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX'),
        "{$name} uniqueness mismatch"
    );
    expectOrmIntegration(str_contains($sql, $expected['columns']), "{$name} columns mismatch");
    if ($expected['where'] !== null) {
        expectOrmIntegration(str_contains($sql, $expected['where']), "{$name} partial predicate mismatch");
    }
}

$firstSnapshot = schemaSnapshot($db);
migrateCallTranscriptSchema($db, $metadata, $modelClasses);
expectOrmIntegration(schemaSnapshot($db) === $firstSnapshot, 'schema migration and index restoration must be idempotent');

$model = new IntegrationSTTCallTranscript();
$model->call_id = 'regression-call';
$model->created_at = '1700000100';
$model->updated_at = '1700000100';
expectOrmIntegration(
    $model->save(),
    'new aggregate save failed: ' . implode('; ', array_map('strval', $model->getMessages()))
);

$model->current_content_hash = 'current';
$model->current_parts_hash = 'current-parts';
$model->pending_content_hash = 'pending';
$model->pending_parts_hash = 'pending-parts';
$model->pending_payload = '{}';
expectOrmIntegration($model->save(), 'aggregate staging update failed');

$model->current_content_hash = '';
$model->current_parts_hash = '';
$model->pending_content_hash = '';
$model->pending_parts_hash = '';
$model->pending_payload = '';
expectOrmIntegration(
    $model->save(),
    'aggregate empty-state update failed: ' . implode('; ', array_map('strval', $model->getMessages()))
);

$stored = $db->fetchOne(
    "SELECT current_content_hash, current_parts_hash, pending_content_hash, pending_parts_hash, pending_payload
       FROM m_STTCallTranscript
      WHERE call_id = 'regression-call'",
    Enum::FETCH_ASSOC
);
foreach (['current_content_hash', 'current_parts_hash', 'pending_content_hash', 'pending_parts_hash', 'pending_payload'] as $field) {
    expectOrmIntegration(($stored[$field] ?? null) === '', "{$field} must persist as an empty string");
}

echo "Call transcript Phalcon ORM and SQLite migration integration OK\n";
