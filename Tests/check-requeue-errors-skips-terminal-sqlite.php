<?php

declare(strict_types=1);

/**
 * STTJob::requeueErrors() must bulk-requeue transient error jobs but skip
 * deterministic terminal failures (markTerminalErrorForWorker(), marked
 * with STTJob::TERMINAL_ERROR_PREFIX) - retrying a recording that is
 * missing/too long/too large can never succeed.
 */

namespace MikoPBX\Modules\Models {
    use PDO;

    final class RequeueSqliteConnection
    {
        private int $affectedRows = 0;

        public function __construct(private readonly PDO $pdo)
        {
        }

        public function execute(string $sql, array $bind = []): bool
        {
            $statement = $this->pdo->prepare($sql);
            $result = $statement->execute($bind);
            $this->affectedRows = $statement->rowCount();

            return $result;
        }

        public function affectedRows(): int
        {
            return $this->affectedRows;
        }
    }

    abstract class ModulesModelsBase
    {
        protected static ?PDO $testPdo = null;

        public static function useTestPdo(PDO $pdo): void
        {
            self::$testPdo = $pdo;
        }

        public function getWriteConnection(): RequeueSqliteConnection
        {
            return new RequeueSqliteConnection(self::$testPdo ?? throw new \RuntimeException('Missing PDO'));
        }

        public function setSource(string $source): void
        {
        }

        public function initialize(): void
        {
        }

        public function hasOne(mixed ...$arguments): void
        {
        }

        public function belongsTo(mixed ...$arguments): void
        {
        }
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;

    function expectRequeueSkipsTerminal(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for this test\n");
        exit(1);
    }

    require_once __DIR__ . '/../Models/STTJob.php';

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE m_STTJob (
        id INTEGER PRIMARY KEY,
        status TEXT,
        worker_id INTEGER,
        attempts INTEGER,
        started_at INTEGER,
        updated_at INTEGER,
        lease_token TEXT,
        lease_expires_at INTEGER,
        finished_at INTEGER,
        error_msg TEXT
    )');
    $insert = $pdo->prepare(
        'INSERT INTO m_STTJob(id, status, attempts, error_msg) VALUES (:id, :status, :attempts, :error_msg)'
    );
    // A transient error (no terminal marker) - must be requeued.
    $insert->execute(['id' => 1, 'status' => STTJob::STATUS_ERROR, 'attempts' => 0, 'error_msg' => 'temporary failure']);
    // A deterministic terminal failure - must NOT be requeued.
    $insert->execute([
        'id' => 2,
        'status' => STTJob::STATUS_ERROR,
        'attempts' => 1,
        'error_msg' => STTJob::TERMINAL_ERROR_PREFIX . 'Recording file is not available',
    ]);
    // A transient error with a NULL message - must be requeued.
    $insert->execute(['id' => 3, 'status' => STTJob::STATUS_ERROR, 'attempts' => 0, 'error_msg' => null]);
    // A pending job - untouched either way.
    $insert->execute(['id' => 4, 'status' => STTJob::STATUS_PENDING, 'attempts' => 0, 'error_msg' => null]);

    ModulesModelsBase::useTestPdo($pdo);

    $count = STTJob::requeueErrors();
    expectRequeueSkipsTerminal($count === 2, "Expected 2 jobs requeued, got {$count}");

    $statuses = [];
    foreach ($pdo->query('SELECT id, status, error_msg FROM m_STTJob ORDER BY id') as $row) {
        $statuses[(int)$row['id']] = $row;
    }
    expectRequeueSkipsTerminal($statuses[1]['status'] === STTJob::STATUS_PENDING, 'Transient error job 1 must be requeued to pending');
    expectRequeueSkipsTerminal($statuses[2]['status'] === STTJob::STATUS_ERROR, 'Terminal error job 2 must remain in error status');
    expectRequeueSkipsTerminal(
        str_starts_with((string)$statuses[2]['error_msg'], STTJob::TERMINAL_ERROR_PREFIX),
        'Terminal error job 2 must keep its terminal marker untouched'
    );
    expectRequeueSkipsTerminal($statuses[3]['status'] === STTJob::STATUS_PENDING, 'Transient error job 3 (NULL message) must be requeued to pending');
    expectRequeueSkipsTerminal($statuses[4]['status'] === STTJob::STATUS_PENDING, 'Already-pending job 4 must remain pending');

    echo "requeueErrors skips deterministic terminal jobs SQLite behavior OK\n";
}
