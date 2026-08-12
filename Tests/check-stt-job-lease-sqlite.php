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

    final class LeaseSqliteConnection
    {
        public static int $throwAffectedRows = 0;
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
            if (self::$throwAffectedRows > 0) {
                self::$throwAffectedRows--;
                throw new \RuntimeException('injected post-CAS affectedRows failure');
            }
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

        public function getWriteConnection(): LeaseSqliteConnection
        {
            return new LeaseSqliteConnection(self::$testPdo ?? throw new \RuntimeException('Missing test PDO'));
        }

        public static function findFirst(mixed $parameters): ?static
        {
            $pdo = self::$testPdo ?? throw new \RuntimeException('Missing test PDO');
            if (is_int($parameters) || (is_string($parameters) && ctype_digit($parameters))) {
                $statement = $pdo->prepare('SELECT * FROM m_STTJob WHERE id = :id');
                $statement->execute(['id' => (int)$parameters]);
            } else {
                $bind = $parameters['bind'] ?? [];
                $sql = 'SELECT * FROM m_STTJob WHERE status = :status';
                $sql .= ' ORDER BY priority ASC, cdr_endtime DESC, created_at DESC, id DESC LIMIT 1';
                $statement = $pdo->prepare($sql);
                $statement->execute(['status' => $bind['status'] ?? 'pending']);
            }
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? self::hydrate($row) : null;
        }

        public static function findFirstById(mixed $id): ?static
        {
            return static::findFirst((int)$id);
        }

        /** @param array<string, mixed> $row */
        private static function hydrate(array $row): static
        {
            $model = new static();
            foreach ($row as $column => $value) {
                if ($value !== null && !in_array($column, ['id', 'worker_id', 'cdr_id'], true)) {
                    $value = (string)$value;
                }
                $model->{$column} = $value;
            }

            return $model;
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
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTSetting
    {
        public const string PROCESSING_TIMEOUT_SEC = 'processing_timeout_sec';

        public static function getValue(string $key, string $default = ''): string
        {
            return $key === self::PROCESSING_TIMEOUT_SEC ? '60' : $default;
        }
    }

    final class STTWorker
    {
        public int $id;
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use MikoPBX\Modules\Models\LeaseSqliteConnection;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

    require_once __DIR__ . '/../Models/STTJob.php';

    function expectLeaseBehavior(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for lease behavior tests\n");
        exit(1);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE m_STTJob (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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
        )'
    );
    $pdo->exec('CREATE TABLE m_STTWorker (id INTEGER PRIMARY KEY, worker_uid TEXT NOT NULL)');
    $pdo->exec("INSERT INTO m_STTWorker(id, worker_uid) VALUES (1, 'worker-one'), (2, 'worker-two')");
    ModulesModelsBase::useTestPdo($pdo);
    $now = time();
    $statement = $pdo->prepare(
        'INSERT INTO m_STTJob
            (call_id, file_path, status, priority, attempts, created_at, updated_at, cdr_endtime)
         VALUES (:call_id, :file_path, :status, 5, 0, :created_at, :updated_at, :cdr_endtime)'
    );
    $statement->execute([
        'call_id' => 'lease-test',
        'file_path' => '/tmp/lease-test.wav',
        'status' => STTJob::STATUS_PENDING,
        'created_at' => $now,
        'updated_at' => $now,
        'cdr_endtime' => $now,
    ]);

    $workerOne = new STTWorker();
    $workerOne->id = 1;
    $firstClaim = STTJob::claimNextForWorker($workerOne);
    expectLeaseBehavior($firstClaim instanceof STTJob, 'Worker one must claim the pending job');
    $firstLeaseToken = (string)$firstClaim->lease_token;
    $pdo->exec('UPDATE m_STTJob SET lease_expires_at = ' . (time() - 1) . ' WHERE id = 1');

    expectLeaseBehavior(STTJob::requeueStaleProcessing() === 1, 'First expired lease must be requeued');
    $afterFirstTimeout = STTJob::findFirstById(1);
    expectLeaseBehavior((int)$afterFirstTimeout?->attempts === 1, 'First lease timeout must consume one attempt');
    expectLeaseBehavior($afterFirstTimeout?->status === STTJob::STATUS_PENDING, 'First timeout must remain retryable');

    $workerTwo = new STTWorker();
    $workerTwo->id = 2;
    $secondClaim = STTJob::claimNextForWorker($workerTwo);
    expectLeaseBehavior($secondClaim instanceof STTJob, 'Timed-out job must be claimable again');
    expectLeaseBehavior($secondClaim->lease_token !== $firstLeaseToken, 'Re-claim must issue a new lease token');
    expectLeaseBehavior(
        !$firstClaim->markDoneForWorker(1, $firstLeaseToken),
        'The old worker must not complete a job after it is reissued'
    );

    $pdo->exec('UPDATE m_STTJob SET lease_expires_at = ' . time() . ' WHERE id = 1');
    expectLeaseBehavior(
        $secondClaim->refreshProcessingLeaseForWorker(2, (string)$secondClaim->lease_token),
        'Heartbeat at the timeout boundary must refresh the current lease'
    );
    expectLeaseBehavior(STTJob::requeueStaleProcessing() === 0, 'Fresh heartbeat must win over stale requeue');

    $pdo->exec('UPDATE m_STTJob SET lease_expires_at = ' . (time() - 1) . ' WHERE id = 1');
    expectLeaseBehavior(STTJob::requeueStaleProcessing() === 1, 'Second timeout must requeue once');
    $thirdClaim = STTJob::claimNextForWorker($workerTwo);
    expectLeaseBehavior($thirdClaim instanceof STTJob, 'Second timeout must still allow the final claim');
    $pdo->exec('UPDATE m_STTJob SET lease_expires_at = ' . (time() - 1) . ' WHERE id = 1');
    expectLeaseBehavior(STTJob::requeueStaleProcessing() === 1, 'Third timeout must transition once');

    $terminal = STTJob::findFirstById(1);
    expectLeaseBehavior((int)$terminal?->attempts === 3, 'Lease timeouts must stop at the existing three-attempt budget');
    expectLeaseBehavior($terminal?->status === STTJob::STATUS_ERROR, 'Exhausted lease retry budget must be terminal');
    expectLeaseBehavior(
        str_contains((string)$terminal?->error_msg, 'lease timeout')
            && str_contains((string)$terminal?->error_msg, '3'),
        'Terminal timeout must keep a clear reason and attempt count'
    );

    $statement->execute(['call_id' => 'affected-throw', 'file_path' => '/tmp/affected.wav', 'status' => STTJob::STATUS_PENDING, 'created_at' => $now, 'updated_at' => $now, 'cdr_endtime' => $now]);
    LeaseSqliteConnection::$throwAffectedRows = 1;
    try {
        STTJob::claimNextForWorker($workerOne);
        throw new RuntimeException('Injected affectedRows failure was swallowed');
    } catch (RuntimeException $exception) {
        expectLeaseBehavior($exception->getMessage() === 'injected post-CAS affectedRows failure', 'Original affectedRows failure must surface after compensation');
    }
    $compensated = STTJob::findFirstById(2);
    expectLeaseBehavior($compensated?->status === STTJob::STATUS_PENDING && $compensated?->worker_id === null && $compensated?->lease_token === null && (int)$compensated?->attempts === 0, 'Post-CAS affectedRows failure must restore pending ownership without attempts');

    echo "STT job lease SQLite behavior OK\n";
}
