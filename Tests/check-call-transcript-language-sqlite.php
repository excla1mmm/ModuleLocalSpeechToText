<?php

declare(strict_types=1);

/**
 * The call transcript detail reports the call language derived from the
 * worker-reported language of every part result, and null when a part has
 * none or the parts disagree. The language is read at request time, so the
 * stored revision payload (and its content_hash) never changes.
 */

use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptIntegrationService;

function expectCallLanguage(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for the call language test\n");
    exit(1);
}

final class CallLanguageConnection
{
    public function __construct(private readonly PDO $pdo)
    {
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
}

eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTSetting { public const string SOURCE_INSTANCE_ID = "source_instance_id"; public static mixed $connection; public function getReadConnection(): mixed { return self::$connection; } }');
eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscriptEvent { public static mixed $connection; public function getReadConnection(): mixed { return self::$connection; } }');
eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscript { public static ?self $row = null; public int $id = 0; public string $call_id = ""; public string $current_revision = "0"; public static function findFirstById(int $id): ?self { return self::$row; } }');
eval('namespace Modules\\ModuleLocalSpeechToText\\Models; class STTCallTranscriptRevision { public static ?self $row = null; public string $revision = "0"; public string $content_hash = ""; public string $parts_hash = ""; public string $completeness_status = ""; public string $payload = "{}"; public string $created_at = "0"; public static function findFirst(array $query): ?self { return self::$row; } }');
if (!class_exists('Phalcon\\Db\\Enum')) {
    eval('namespace Phalcon\\Db; class Enum { public const int FETCH_ASSOC = 2; }');
}

$root = dirname(__DIR__);
require_once $root . '/Lib/Services/CallTranscriptIntegrationService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    "CREATE TABLE m_STTSetting (key TEXT PRIMARY KEY, value TEXT NOT NULL);
     INSERT INTO m_STTSetting VALUES ('source_instance_id', '0123456789abcdef0123456789abcdef');
     CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, job_id INTEGER NOT NULL, language TEXT);"
);
$connection = new CallLanguageConnection($pdo);
\Modules\ModuleLocalSpeechToText\Models\STTSetting::$connection = $connection;
\Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent::$connection = $connection;

$call = new \Modules\ModuleLocalSpeechToText\Models\STTCallTranscript();
$call->id = 7;
$call->call_id = '1700000000.1';
$call->current_revision = '1';
\Modules\ModuleLocalSpeechToText\Models\STTCallTranscript::$row = $call;

$revision = new \Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision();
$revision->revision = '1';
$revision->content_hash = str_repeat('a', 64);
$revision->parts_hash = str_repeat('b', 64);
$revision->completeness_status = 'complete';
$revision->created_at = '1700000100';
\Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision::$row = $revision;

$service = new CallTranscriptIntegrationService();
$detailLanguage = static function (array $resultLanguages, ?array $manifestIds = null) use ($pdo, $revision, $service): mixed {
    $pdo->exec('DELETE FROM m_STTResult');
    $insert = $pdo->prepare('INSERT INTO m_STTResult (id, job_id, language) VALUES (?, ?, ?)');
    foreach ($resultLanguages as $resultId => $language) {
        $insert->execute([$resultId, $resultId, $language]);
    }
    $manifest = array_map(
        static fn (int $resultId): array => ['result_id' => $resultId, 'cdr_id' => 500 + $resultId],
        $manifestIds ?? array_keys($resultLanguages)
    );
    $revision->payload = json_encode(['part_manifest' => $manifest, 'transcript' => ['segments' => [], 'turns' => []]], JSON_THROW_ON_ERROR);
    $detail = $service->callTranscriptDetail(7, 1);
    expectCallLanguage(array_key_exists('language', $detail), 'call transcript detail must always carry the language field');
    expectCallLanguage(
        ($detail['content_hash'] ?? null) === str_repeat('a', 64),
        'the language must not touch the stored revision identity'
    );

    return $detail['language'];
};

expectCallLanguage($detailLanguage([10 => 'ru']) === 'ru', 'single-part call must report its language');
expectCallLanguage($detailLanguage([10 => 'kk', 11 => 'kk-KZ']) === 'kk', 'parts in one language must agree after tag normalization');
expectCallLanguage($detailLanguage([10 => 'EN_us']) === 'en', 'language tags must be normalized to the lowercase primary subtag');
expectCallLanguage($detailLanguage([10 => 'ru', 11 => 'kk']) === null, 'parts in different languages must report no language');
expectCallLanguage($detailLanguage([10 => 'ru', 11 => '']) === null, 'a part without a language must make the call language unknown');
expectCallLanguage($detailLanguage([10 => 'ru', 11 => null]) === null, 'a part with a NULL language must make the call language unknown');
expectCallLanguage($detailLanguage([10 => 'auto']) === null, 'auto is not a language');
expectCallLanguage($detailLanguage([10 => 'russian language']) === null, 'free text is not a language tag');
expectCallLanguage($detailLanguage([10 => 'ru'], [10, 12]) === null, 'a manifest part without a stored result must make the call language unknown');
expectCallLanguage($detailLanguage([], []) === null, 'a call without parts has no language');

echo "Call transcript language SQLite behavior OK\n";
