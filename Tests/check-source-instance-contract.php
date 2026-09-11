<?php

declare(strict_types=1);

function expectSourceIdentity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = file_get_contents(__DIR__ . '/fixtures/schema/stt-1.45.sql');
expectSourceIdentity(is_string($fixture) && $fixture !== '', 'STT 1.45 fixture is missing');
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec($fixture);
expectSourceIdentity((string)$db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'STT 1.45 fixture is invalid');
expectSourceIdentity((int)$db->query('SELECT COUNT(*) FROM m_STTSchemaMigration')->fetchColumn() === 14, 'STT 1.45 migration ledger is incomplete');
foreach (['m_STTJob','m_STTResult','m_STTCallTranscript','m_STTCallTranscriptRevision','m_STTCallTranscriptPart','m_STTCallTranscriptEvent','m_STTWorker','m_STTSetting','m_STTJobSubmissionReceipt','m_STTRetryState'] as $table) {
    expectSourceIdentity((int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0, "STT 1.45 fixture lacks {$table} data");
}

$root = dirname(__DIR__);
$setting = file_get_contents($root . '/Models/STTSetting.php') ?: '';
$setup = file_get_contents($root . '/Setup/PbxExtensionSetup.php') ?: '';
$integration = file_get_contents($root . '/Lib/Services/CallTranscriptIntegrationService.php') ?: '';
$eventsSchema = file_get_contents($root . '/Lib/RestAPI/CallTranscriptEvents/DataStructure.php') ?: '';
$detailSchema = file_get_contents($root . '/Lib/RestAPI/CallTranscripts/DataStructure.php') ?: '';

$validSourceId = '/\A[a-f0-9]{32}\z/';
$validHash = '/\A[a-f0-9]{64}\z/';
$canonicalIdentity = ['call_id', 'content_hash'];
$sourceLocator = ['source_instance_id', 'aggregate_id', 'source_revision'];
expectSourceIdentity(preg_match($validSourceId, str_repeat('a', 32)) === 1, 'source ID contract is invalid');
expectSourceIdentity(preg_match($validHash, str_repeat('b', 64)) === 1, 'hash contract is invalid');
expectSourceIdentity($canonicalIdentity === ['call_id', 'content_hash'], 'canonical identity drifted');
expectSourceIdentity($sourceLocator === ['source_instance_id', 'aggregate_id', 'source_revision'], 'source locator drifted');
expectSourceIdentity(is_file($root . '/Lib/Services/SttSourceInstance.php'), 'SttSourceInstance service is missing');
if (!class_exists('Modules\\ModuleLocalSpeechToText\\Models\\STTSetting')) {
    eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTSetting { public const string SOURCE_INSTANCE_ID = "source_instance_id"; }');
}
require_once $root . '/Lib/Services/SttSourceInstance.php';

final class SourceIdentityConnection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        $statement = $this->pdo->prepare($sql);
        return $statement->execute($bind);
    }

    public function fetchOne(string $sql, int $mode, array $bind = []): array|false
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);
        return $statement->fetch($mode);
    }
}

$newConnection = static function (?string $value = null): SourceIdentityConnection {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE m_STTSetting(id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL UNIQUE, value TEXT)');
    if ($value !== null) {
        $statement = $pdo->prepare('INSERT INTO m_STTSetting(key,value) VALUES(:key,:value)');
        $statement->execute(['key' => 'source_instance_id', 'value' => $value]);
    }
    return new SourceIdentityConnection($pdo);
};

$service = new \Modules\ModuleLocalSpeechToText\Lib\Services\SttSourceInstance();
$freshA = $newConnection();
$freshB = $newConnection();
$idA = $service->ensure($freshA);
expectSourceIdentity(preg_match($validSourceId, $idA) === 1, 'fresh source ID is invalid');
expectSourceIdentity($service->ensure($freshA) === $idA, 'repeated setup rotated a valid source ID');
$idB = $service->ensure($freshB);
expectSourceIdentity($idB !== $idA, 'independent databases reused one source ID');
$malformed = $newConnection('INVALID');
$repaired = $service->ensure($malformed);
expectSourceIdentity(preg_match($validSourceId, $repaired) === 1 && $repaired !== 'INVALID', 'malformed source ID was not repaired');
$rollbackPdo = new PDO('sqlite::memory:');
$rollbackPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rollbackPdo->exec('CREATE TABLE m_STTSetting(id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL UNIQUE, value TEXT)');
$rollbackPdo->beginTransaction();
$service->ensure(new SourceIdentityConnection($rollbackPdo));
$rollbackPdo->rollBack();
expectSourceIdentity((int)$rollbackPdo->query("SELECT COUNT(*) FROM m_STTSetting WHERE key='source_instance_id'")->fetchColumn() === 0, 'failed migration exposed a rolled-back source ID');
expectSourceIdentity(str_contains($setting, 'SOURCE_INSTANCE_ID'), 'STTSetting must expose SOURCE_INSTANCE_ID');
expectSourceIdentity(str_contains($setup, "new ModuleSchemaMigration(\n                15,"), 'STT schema migration 15 is missing');
expectSourceIdentity(str_contains($setup, 'SttSourceInstance'), 'setup must enforce stable source identity');
expectSourceIdentity(str_contains($integration, "'source_instance_id'"), 'event/detail service must publish source_instance_id');
expectSourceIdentity(str_contains($eventsSchema, "'source_instance_id'"), 'event OpenAPI schema lacks source_instance_id');
expectSourceIdentity(str_contains($detailSchema, "'source_instance_id'"), 'detail OpenAPI schema lacks source_instance_id');

echo "STT source instance contract OK\n";
