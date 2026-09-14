<?php

declare(strict_types=1);

use MikoPBX\Core\System\Upgrade\UpdateDatabase;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptPart;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTJobSubmissionReceipt;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Phalcon\Db\Adapter\Pdo\Sqlite;
use Phalcon\Db\Enum;
use Phalcon\Di\Di;

function expectCoreSttSchema(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function coreSttSchemaSnapshot(Sqlite $connection): string
{
    return json_encode(
        $connection->fetchAll(
            "SELECT type, tbl_name, name, sql
               FROM sqlite_master
              WHERE name NOT LIKE 'sqlite_%'
              ORDER BY type, tbl_name, name",
            Enum::FETCH_ASSOC,
        ),
        JSON_THROW_ON_ERROR,
    );
}

if (!extension_loaded('phalcon')) {
    echo "SKIP: STT Core index idempotency requires Phalcon\n";
    exit(0);
}

$coreGlobals = getenv('MIKOPBX_CORE_GLOBALS') ?: '/offload/rootfs/usr/www/src/Core/Config/Globals.php';
$moduleRoot = rtrim((string)(getenv('STT_MODULE_ROOT') ?: dirname(__DIR__, 2)), '/');
$databasePath = (string)getenv('STT_SCHEMA_DATABASE');
if (!is_file($coreGlobals)) {
    echo "SKIP: MikoPBX Core CLI bootstrap is unavailable\n";
    exit(0);
}
expectCoreSttSchema(
    $databasePath !== '' && is_file($databasePath) && str_starts_with(realpath($databasePath) ?: '', '/tmp/'),
    'STT_SCHEMA_DATABASE must point to an isolated database copy under /tmp',
);

spl_autoload_register(
    static function (string $class) use ($moduleRoot): void {
        $prefix = 'Modules\\ModuleLocalSpeechToText\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = $moduleRoot . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    },
    true,
    true,
);

require_once $coreGlobals;

$di = Di::getDefault();
expectCoreSttSchema($di !== null, 'MikoPBX dependency container is unavailable');
$service = ModulesModelsBase::getConnectionServiceName('ModuleLocalSpeechToText');
$di->setShared($service, new Sqlite(['dbname' => $databasePath]));
$connection = $di->getShared($service);
expectCoreSttSchema($connection instanceof Sqlite, 'unable to register isolated STT module database');

$models = [
    STTSetting::class,
    STTWorker::class,
    STTJob::class,
    STTResult::class,
    STTJobSubmissionReceipt::class,
    STTTranscriptEvent::class,
    STTCallTranscript::class,
    STTCallTranscriptPart::class,
    STTCallTranscriptRevision::class,
    STTCallTranscriptEvent::class,
];

$updater = new UpdateDatabase();
foreach ($models as $model) {
    expectCoreSttSchema($updater->createUpdateDbTableByAnnotations($model), "initial Core migration failed for {$model}");
}

foreach ([
    'CREATE UNIQUE INDEX IF NOT EXISTS m_STTWorker_sqlite_autoindex_api_key_id_v2_unique ON m_STTWorker(api_key_id) WHERE api_key_id IS NOT NULL AND api_version = 2',
    'CREATE UNIQUE INDEX IF NOT EXISTS m_STTCallTranscriptPart_sqlite_autoindex_current_result ON m_STTCallTranscriptPart(call_transcript_id, result_id) WHERE is_current = 1',
] as $sql) {
    $connection->execute($sql);
}

foreach ($models as $model) {
    $table = (new $model())->getSource();
    $connection->execute("CREATE TRIGGER IF NOT EXISTS codex_no_rebuild_{$table} AFTER UPDATE ON {$table} BEGIN SELECT 1; END");
}
$before = coreSttSchemaSnapshot($connection);

foreach ($models as $model) {
    expectCoreSttSchema($updater->createUpdateDbTableByAnnotations($model), "repeated Core migration failed for {$model}");
}

expectCoreSttSchema(coreSttSchemaSnapshot($connection) === $before, 'second Core migration changed STT module schema');
echo "STT Core index and schema migration idempotency OK\n";
