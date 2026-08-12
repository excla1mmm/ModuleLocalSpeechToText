<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Exceptions\WorkerApiKeyBindingConflictException;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerRegistrationTransaction;

function expectWorkerRegistrationConcurrency(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class WorkerRegistrationSqliteConnection
{
    private int $affectedRows = 0;

    public function __construct(public readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        $statement = $this->pdo->prepare($sql);
        $result = $statement->execute($bind);
        $this->affectedRows = $statement->rowCount();

        return $result;
    }

    public function fetchAll(string $sql, int $fetchMode, array $bind = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);

        return $statement->fetchAll($fetchMode);
    }

    public function affectedRows(): int { return $this->affectedRows; }
    public function begin(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollback(): bool { return $this->pdo->rollBack(); }
    public function isUnderTransaction(): bool { return $this->pdo->inTransaction(); }
}

$servicePath = __DIR__ . '/../Lib/Services/WorkerRegistrationTransaction.php';
$exceptionPath = __DIR__ . '/../Lib/Exceptions/WorkerApiKeyBindingConflictException.php';
expectWorkerRegistrationConcurrency(is_file($servicePath), 'WorkerRegistrationTransaction implementation is missing');
expectWorkerRegistrationConcurrency(is_file($exceptionPath), 'Worker API-key binding conflict exception is missing');
require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once $exceptionPath;
require_once $servicePath;

if (($argv[1] ?? '') === '--child') {
    [$script, $mode, $databasePath, $workerUid, $readyPath, $goPath, $resultPath] = $argv;
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=100');
    $connection = new WorkerRegistrationSqliteConnection($pdo);

    try {
        (new WorkerRegistrationTransaction())->run(
            $connection,
            91,
            $workerUid,
            static function (mixed $database) use ($workerUid, $readyPath, $goPath): void {
                touch($readyPath);
                $deadline = microtime(true) + 5;
                while (!is_file($goPath) && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                if (!is_file($goPath)) {
                    throw new RuntimeException('concurrency barrier timeout');
                }
                $database->execute(
                    'INSERT INTO m_STTWorker(worker_uid, api_key_id) VALUES (:worker_uid, :api_key_id)',
                    ['worker_uid' => $workerUid, 'api_key_id' => 91]
                );
            }
        );
        file_put_contents($resultPath, 'ok');
    } catch (WorkerApiKeyBindingConflictException) {
        file_put_contents($resultPath, 'conflict');
    } catch (Throwable $exception) {
        file_put_contents($resultPath, 'error:' . $exception->getMessage());
    }
    exit(0);
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for worker registration concurrency tests\n");
    exit(1);
}

$directory = sys_get_temp_dir() . '/stt-worker-binding-' . bin2hex(random_bytes(8));
expectWorkerRegistrationConcurrency(mkdir($directory, 0700), 'Cannot create concurrency test directory');
$databasePath = $directory . '/module.db';
$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('CREATE TABLE m_STTWorker (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    worker_uid TEXT NOT NULL UNIQUE,
    api_key_id INTEGER NULL
)');
$pdo->exec('CREATE UNIQUE INDEX i_m_STTWorker_api_key_id_unique
    ON m_STTWorker(api_key_id) WHERE api_key_id IS NOT NULL');
$pdo = null;

$goPath = $directory . '/go';
$processes = [];
foreach (['race-a', 'race-b'] as $index => $uid) {
    $readyPath = $directory . '/ready-' . $index;
    $resultPath = $directory . '/result-' . $index;
    $command = [PHP_BINARY, __FILE__, '--child', $databasePath, $uid, $readyPath, $goPath, $resultPath];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expectWorkerRegistrationConcurrency(is_resource($process), 'Cannot start registration contender');
    $processes[] = [$process, $pipes, $readyPath, $resultPath];
}

$deadline = microtime(true) + 5;
while ((!is_file($processes[0][2]) || !is_file($processes[1][2])) && microtime(true) < $deadline) {
    usleep(10_000);
}
expectWorkerRegistrationConcurrency(
    is_file($processes[0][2]) && is_file($processes[1][2]),
    'Both contenders did not reach the post-preflight concurrency barrier'
);
touch($goPath);

$results = [];
foreach ($processes as [$process, $pipes, $readyPath, $resultPath]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    expectWorkerRegistrationConcurrency($exitCode === 0, 'Registration contender process failed: ' . $stdout . $stderr);
    $results[] = trim((string)file_get_contents($resultPath));
}
sort($results);
expectWorkerRegistrationConcurrency(
    $results === ['conflict', 'ok'],
    'Concurrent different UIDs must yield exactly one success and one domain conflict: ' . json_encode($results)
);

$verify = new PDO('sqlite:' . $databasePath);
$rows = $verify->query('SELECT worker_uid, api_key_id FROM m_STTWorker ORDER BY worker_uid')->fetchAll(PDO::FETCH_ASSOC);
expectWorkerRegistrationConcurrency(
    count($rows) === 1 && (int)$rows[0]['api_key_id'] === 91,
    'Concurrent loser mutated worker rows or the winner binding was lost'
);

foreach (glob($directory . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($directory);

$main = (string)file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextMain.php');
expectWorkerRegistrationConcurrency(
    str_contains($main, 'new WorkerRegistrationTransaction()')
        && str_contains($main, 'WorkerApiKeyBindingConflictException')
        && str_contains($main, "'worker_api_key_already_bound'")
        && str_contains($main, '409'),
    'Production registration must reserve the binding transactionally and map the loser to HTTP 409'
);

echo "STT worker registration concurrency SQLite behavior OK\n";
