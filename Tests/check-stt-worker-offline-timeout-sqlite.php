<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    use PDO;

    final class WorkerOfflineTimeoutConnection
    {
        public function __construct(private readonly PDO $pdo)
        {
        }

        public function execute(string $sql, array $bind = []): bool
        {
            $statement = $this->pdo->prepare($sql);

            return $statement->execute($bind);
        }
    }

    abstract class ModulesModelsBase
    {
        protected static ?PDO $testPdo = null;

        public static function useTestPdo(PDO $pdo): void
        {
            self::$testPdo = $pdo;
        }

        public function getWriteConnection(): WorkerOfflineTimeoutConnection
        {
            return new WorkerOfflineTimeoutConnection(self::$testPdo ?? throw new \RuntimeException('Missing test PDO'));
        }

        public function setSource(string $source): void
        {
        }

        public function initialize(): void
        {
        }

        public function hasMany(mixed ...$arguments): void
        {
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTJob
    {
        public const string STATUS_PROCESSING = 'processing';
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

    require_once __DIR__ . '/../Models/STTWorker.php';

    function expectOfflineTimeout(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for worker offline timeout tests\n");
        exit(1);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE m_STTWorker (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_uid TEXT NOT NULL,
            status TEXT,
            last_seen INTEGER
        )'
    );
    ModulesModelsBase::useTestPdo($pdo);

    $now = time();

    // A worker last seen 899 seconds ago must still be considered online: this must
    // hold no matter how large a (now removed) poll_interval_sec setting used to be,
    // since offline detection is now a fixed internal policy.
    $pdo->exec("INSERT INTO m_STTWorker (worker_uid, status, last_seen) VALUES ('recently-seen', 'online', " . ($now - 899) . ')');
    // A worker last seen 901 seconds ago must be marked offline.
    $pdo->exec("INSERT INTO m_STTWorker (worker_uid, status, last_seen) VALUES ('stale', 'online', " . ($now - 901) . ')');

    STTWorker::markStaleOffline();

    $statuses = $pdo->query('SELECT worker_uid, status FROM m_STTWorker ORDER BY worker_uid')->fetchAll(PDO::FETCH_KEY_PAIR);
    expectOfflineTimeout(
        ($statuses['recently-seen'] ?? null) === 'online',
        'A worker seen within the fixed 900 second window must remain online'
    );
    expectOfflineTimeout(
        ($statuses['stale'] ?? null) === 'offline',
        'A worker unseen for more than the fixed 900 second window must be marked offline'
    );

    echo "STT worker offline timeout SQLite behavior OK\n";
}
