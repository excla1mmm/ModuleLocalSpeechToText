<?php

declare(strict_types=1);

namespace Phalcon\Mvc\Model {
    final class Relation
    {
        public const int NO_ACTION = 0;
    }
}

namespace MikoPBX\Modules\Models {
    use PDO;

    final class WorkerRaceSqliteConnection
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

        public function affectedRows(): int { return $this->affectedRows; }
    }

    abstract class ModulesModelsBase
    {
        protected static ?PDO $testPdo = null;

        public static function useTestPdo(PDO $pdo): void { self::$testPdo = $pdo; }
        public function getWriteConnection(): WorkerRaceSqliteConnection
        {
            return new WorkerRaceSqliteConnection(self::$testPdo ?? throw new \RuntimeException('Missing PDO'));
        }

        public static function findFirst(mixed $parameters): ?static
        {
            $pdo = self::$testPdo ?? throw new \RuntimeException('Missing PDO');
            if (is_int($parameters) || (is_string($parameters) && ctype_digit($parameters))) {
                $statement = $pdo->prepare('SELECT * FROM m_STTJob WHERE id = :id');
                $statement->execute(['id' => (int)$parameters]);
            } else {
                $statement = $pdo->prepare(
                    'SELECT * FROM m_STTJob WHERE status = :status
                     ORDER BY priority ASC, cdr_endtime DESC, created_at DESC, id DESC LIMIT 1'
                );
                $statement->execute(['status' => $parameters['bind']['status'] ?? 'pending']);
            }
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            $model = new static();
            foreach ($row as $column => $value) {
                if ($value !== null && !in_array($column, ['id', 'worker_id', 'cdr_id'], true)) {
                    $value = (string)$value;
                }
                $model->{$column} = $value;
            }

            return $model;
        }

        public static function findFirstById(mixed $id): ?static { return static::findFirst((int)$id); }
        public function setSource(string $source): void {}
        public function initialize(): void {}
        public function hasOne(mixed ...$arguments): void {}
        public function hasMany(mixed ...$arguments): void {}
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTSetting
    {
        public const string PROCESSING_TIMEOUT_SEC = 'processing_timeout_sec';
        public const string POLL_INTERVAL_SEC = 'poll_interval_sec';
        public static function getValue(string $key, string $default = ''): string
        {
            return $key === self::PROCESSING_TIMEOUT_SEC ? '60' : $default;
        }
    }

    final class STTResult {}
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

    require_once __DIR__ . '/../Models/STTWorker.php';
    require_once __DIR__ . '/../Models/STTJob.php';

    function expectWorkerDeleteClaimRace(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for worker delete/claim race tests\n");
        exit(1);
    }

    expectWorkerDeleteClaimRace(
        method_exists(STTWorker::class, 'deleteIfNoActiveLease'),
        'STTWorker atomic deleteIfNoActiveLease implementation is missing'
    );

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE m_STTWorker (id INTEGER PRIMARY KEY, worker_uid TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE m_STTJob (
        id INTEGER PRIMARY KEY,
        call_id TEXT NOT NULL,
        file_path TEXT NOT NULL,
        status TEXT,
        priority INTEGER,
        worker_id INTEGER,
        attempts INTEGER,
        created_at INTEGER,
        updated_at INTEGER,
        cdr_endtime INTEGER,
        started_at INTEGER,
        lease_token TEXT,
        lease_expires_at INTEGER,
        finished_at INTEGER,
        error_msg TEXT
    )');
    $pdo->exec("INSERT INTO m_STTWorker(id, worker_uid) VALUES (1, 'claim-wins'), (2, 'delete-wins')");
    $now = time();
    $insert = $pdo->prepare(
        'INSERT INTO m_STTJob(id, call_id, file_path, status, priority, attempts, created_at, updated_at, cdr_endtime)
         VALUES (:id, :call_id, :file_path, :status, 5, 0, :now, :now, :now)'
    );
    foreach ([1 => 'claim-wins', 2 => 'delete-wins'] as $id => $label) {
        $insert->execute([
            'id' => $id,
            'call_id' => $label,
            'file_path' => '/tmp/' . $label . '.wav',
            'status' => STTJob::STATUS_PENDING,
            'now' => $now + (3 - $id),
        ]);
    }
    ModulesModelsBase::useTestPdo($pdo);

    $claimWinner = new STTWorker();
    $claimWinner->id = 1;
    $claimed = STTJob::claimNextForWorker($claimWinner);
    expectWorkerDeleteClaimRace($claimed instanceof STTJob, 'Claim-first ordering did not acquire a job');
    expectWorkerDeleteClaimRace(
        STTWorker::deleteIfNoActiveLease(1) === false,
        'Delete removed a worker after its claim won'
    );
    expectWorkerDeleteClaimRace(
        (int)$pdo->query('SELECT COUNT(*) FROM m_STTWorker WHERE id=1')->fetchColumn() === 1,
        'Claim-first ordering left a processing job without its worker'
    );

    expectWorkerDeleteClaimRace(STTWorker::deleteIfNoActiveLease(2), 'Delete-first ordering did not remove the worker');
    $deleteWinner = new STTWorker();
    $deleteWinner->id = 2;
    expectWorkerDeleteClaimRace(
        STTJob::claimNextForWorker($deleteWinner) === null,
        'A stale worker object claimed a job after delete won'
    );
    $orphanProcessing = (int)$pdo->query(
        "SELECT COUNT(*) FROM m_STTJob j LEFT JOIN m_STTWorker w ON w.id=j.worker_id
          WHERE j.status='processing' AND w.id IS NULL"
    )->fetchColumn();
    expectWorkerDeleteClaimRace($orphanProcessing === 0, 'Queue contains a processing job for a missing worker');
    expectWorkerDeleteClaimRace(
        (string)$pdo->query('SELECT status FROM m_STTJob WHERE id=2')->fetchColumn() === STTJob::STATUS_PENDING,
        'Delete-first loser mutated the pending job'
    );

    echo "STT worker delete/claim race SQLite behavior OK\n";
}
