<?php

declare(strict_types=1);

/**
 * STTJob::claimNextForWorker() claims the lowest priority number first and,
 * within that band, the freshest call first: a live call must overtake a
 * historical backfill, even one enqueued after it. The old backlog is worked
 * off when no fresher call is waiting.
 */

namespace Phalcon\Mvc\Model {
    final class Relation
    {
        public const int NO_ACTION = 0;
    }
}

namespace MikoPBX\Modules\Models {
    use PDO;

    final class FreshFirstSqliteConnection
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

        public function getWriteConnection(): FreshFirstSqliteConnection
        {
            return new FreshFirstSqliteConnection(self::$testPdo ?? throw new \RuntimeException('Missing PDO'));
        }

        public static function findFirst(mixed $parameters): ?static
        {
            $pdo = self::$testPdo ?? throw new \RuntimeException('Missing PDO');
            if (is_int($parameters) || (is_string($parameters) && ctype_digit($parameters))) {
                $statement = $pdo->prepare('SELECT * FROM m_STTJob WHERE id = :id');
                $statement->execute(['id' => (int)$parameters]);
            } else {
                $statement = $pdo->prepare(
                    'SELECT * FROM m_STTJob WHERE ' . preg_replace('/:(\\w+):/', ':$1', $parameters['conditions'])
                    . ' ORDER BY ' . $parameters['order'] . ' LIMIT 1'
                );
                $statement->execute($parameters['bind']);
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

        public static function minimum(array $parameters): mixed
        {
            $pdo = self::$testPdo ?? throw new \RuntimeException('Missing PDO');
            $statement = $pdo->prepare(
                'SELECT MIN(' . $parameters['column'] . ') FROM m_STTJob WHERE '
                . preg_replace('/:(\\w+):/', ':$1', $parameters['conditions'])
            );
            $statement->execute($parameters['bind']);
            $value = $statement->fetchColumn();

            return $value === null || $value === false ? null : (string)$value;
        }

        public static function findFirstById(mixed $id): ?static
        {
            return static::findFirst((int)$id);
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

        public function hasMany(mixed ...$arguments): void
        {
        }
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

    final class STTResult
    {
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

    require_once __DIR__ . '/../Models/STTWorker.php';
    require_once __DIR__ . '/../Models/STTJob.php';

    function expectFreshFirst(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for claim order tests\n");
        exit(1);
    }

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
    $pdo->exec("INSERT INTO m_STTWorker(id, worker_uid) VALUES (1, 'w1')");
    $now = time();

    $insert = $pdo->prepare(
        'INSERT INTO m_STTJob(id, call_id, file_path, status, priority, attempts, created_at, updated_at, cdr_endtime)
         VALUES (:id, :call_id, :file_path, :status, :priority, 0, :now, :now, :cdr_endtime)'
    );
    // id 1-3: calls at the normal priority, oldest to freshest; id 4: a fresh
    // long call (priority 8 runs later); id 5: a backfilled call enqueued last
    // but a day old.
    foreach ([
        1 => [5, $now - 3600],
        2 => [5, $now - 60],
        3 => [5, $now],
        4 => [8, $now],
        5 => [5, $now - 86400],
    ] as $id => [$priority, $cdrEndtime]) {
        $insert->execute([
            'id' => $id,
            'call_id' => 'call-' . $id,
            'file_path' => '/tmp/call-' . $id . '.wav',
            'status' => STTJob::STATUS_PENDING,
            'priority' => $priority,
            'now' => $now,
            'cdr_endtime' => $cdrEndtime,
        ]);
    }
    ModulesModelsBase::useTestPdo($pdo);

    $worker = new STTWorker();
    $worker->id = 1;

    $claimed = [];
    while (($job = STTJob::claimNextForWorker($worker)) instanceof STTJob) {
        $claimed[] = (int)$job->id;
    }
    expectFreshFirst(
        $claimed === [3, 2, 1, 5, 4],
        'Claim order must be freshest call first within the lowest priority number, got ' . implode(',', $claimed)
    );

    echo "Claim fresh-first SQLite behavior OK\n";
}
