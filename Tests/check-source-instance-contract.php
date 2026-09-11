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
expectSourceIdentity(str_contains($setting, 'SOURCE_INSTANCE_ID'), 'STTSetting must expose SOURCE_INSTANCE_ID');
expectSourceIdentity(str_contains($setup, "new ModuleSchemaMigration(\n                15,"), 'STT schema migration 15 is missing');
expectSourceIdentity(str_contains($setup, 'SttSourceInstance'), 'setup must enforce stable source identity');
expectSourceIdentity(str_contains($integration, "'source_instance_id'"), 'event/detail service must publish source_instance_id');
expectSourceIdentity(str_contains($eventsSchema, "'source_instance_id'"), 'event OpenAPI schema lacks source_instance_id');
expectSourceIdentity(str_contains($detailSchema, "'source_instance_id'"), 'detail OpenAPI schema lacks source_instance_id');

echo "STT source instance contract OK\n";
