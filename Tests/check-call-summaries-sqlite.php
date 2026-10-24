<?php
declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    use PDO;

    final class SummaryConnection
    {
        private int $affected = 0;

        public function __construct(private PDO $pdo) {}

        public function execute(string $sql, array $bind = []): bool
        {
            $statement = $this->pdo->prepare($sql);
            $ok = $statement->execute($bind);
            $this->affected = $statement->rowCount();

            return $ok;
        }

        public function affectedRows(): int
        {
            return $this->affected;
        }

        public function fetchOne(string $sql, int $mode, array $bind = []): array|false
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bind);

            return $statement->fetch($mode);
        }

        public function fetchAll(string $sql, int $mode, array $bind = []): array
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bind);

            return $statement->fetchAll($mode);
        }

        public function isUnderTransaction(): bool
        {
            return $this->pdo->inTransaction();
        }
    }

    abstract class ModulesModelsBase
    {
        protected static ?PDO $pdo = null;
        private string $source = '';

        public function __construct()
        {
            $this->initialize();
        }

        public static function useTestPdo(PDO $pdo): void
        {
            self::$pdo = $pdo;
        }

        public function setSource(string $source): void
        {
            $this->source = $source;
        }

        public function initialize(): void {}

        public function getWriteConnection(): SummaryConnection
        {
            return new SummaryConnection(self::$pdo);
        }

        public static function findFirstById(mixed $id): ?static
        {
            return static::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => (int)$id]]);
        }

        public static function findFirst(array $parameters): ?static
        {
            return static::find($parameters + ['limit' => 1])[0] ?? null;
        }

        /** @return list<static> */
        public static function find(array $parameters): array
        {
            $bind = $parameters['bind'] ?? [];
            $conditions = preg_replace_callback('/\{(\w+):array\}/', static function (array $match) use (&$bind): string {
                $names = [];
                foreach (array_values($bind[$match[1]]) as $index => $value) {
                    $bind[$match[1] . '_' . $index] = $value;
                    $names[] = ':' . $match[1] . '_' . $index;
                }
                unset($bind[$match[1]]);

                return implode(', ', $names);
            }, $parameters['conditions']);
            $sql = 'SELECT * FROM ' . (new static())->source . ' WHERE ' . preg_replace('/:(\w+):/', ':$1', $conditions)
                . (isset($parameters['order']) ? ' ORDER BY ' . $parameters['order'] : '')
                . (isset($parameters['limit']) ? ' LIMIT ' . (int)$parameters['limit'] : '');
            $statement = self::$pdo->prepare($sql);
            $statement->execute($bind);
            $models = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $model = new static();
                foreach ($row as $column => $value) {
                    $model->{$column} = $column === 'id' || $value === null ? $value : (string)$value;
                }
                $models[] = $model;
            }

            return $models;
        }
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use MikoPBX\Modules\Models\SummaryConnection;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallDirectionResolver;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallSummaryMigration;
    use Modules\ModuleLocalSpeechToText\Lib\Services\SummaryContentParser;
    use Modules\ModuleLocalSpeechToText\Lib\Services\SummaryPromptBuilder;
    use Modules\ModuleLocalSpeechToText\Models\STTSummary;

    require_once __DIR__ . '/../Models/STTSummary.php';
    require_once __DIR__ . '/../Lib/Services/CallDirectionResolver.php';
    require_once __DIR__ . '/../Lib/Services/CallSummaryMigration.php';
    require_once __DIR__ . '/../Lib/Services/SummaryContentParser.php';
    require_once __DIR__ . '/../Lib/Services/SummaryPromptBuilder.php';
    if (!class_exists('Modules\\ModuleLocalSpeechToText\\Lib\\Services\\CallDialogService', false)) {
        eval('namespace Modules\\ModuleLocalSpeechToText\\Lib\\Services; final class CallDialogService { public const string ROLE_CLIENT = "client"; public const string ROLE_EMPLOYEE = "employee"; }');
    }

    function expectSummary(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function summaryRow(PDO $pdo, int $callTranscriptId): array
    {
        $statement = $pdo->prepare('SELECT * FROM m_STTSummary WHERE call_transcript_id = ?');
        $statement->execute([$callTranscriptId]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    function migrate(PDO $pdo, callable $step): void
    {
        $pdo->beginTransaction();
        $step(new SummaryConnection($pdo));
        $pdo->commit();
    }

    function callSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE m_STTJob (id INTEGER PRIMARY KEY, call_id TEXT NOT NULL, status TEXT NOT NULL, cdr_endtime INTEGER)');
        $pdo->exec('CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, job_id INTEGER, search_text TEXT, created_at INTEGER)');
        $pdo->exec("CREATE TABLE m_STTCallTranscript (id INTEGER PRIMARY KEY, call_id TEXT NOT NULL, current_revision INTEGER NOT NULL DEFAULT 0,
            current_content_hash TEXT NOT NULL DEFAULT '', pending_content_hash TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL DEFAULT 0)");
        $pdo->exec('CREATE TABLE m_STTCallTranscriptPart (id INTEGER PRIMARY KEY AUTOINCREMENT, call_transcript_id INTEGER NOT NULL,
            result_id INTEGER NOT NULL, job_id INTEGER, cdr_uniqueid TEXT, is_current INTEGER NOT NULL DEFAULT 1)');
    }

    /** One published call with its legs: [result id, job end time, recognized text, job status]. */
    function seedCall(PDO $pdo, int $id, int $createdAt, array $legs, int $revision = 1, string $pending = ''): void
    {
        $pdo->prepare('INSERT INTO m_STTCallTranscript VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, "call-{$id}", $revision, $revision > 0 ? "hash-{$id}-{$revision}" : '', $pending, $createdAt]);
        foreach ($legs as [$resultId, $endtime, $text, $status]) {
            $pdo->prepare('INSERT INTO m_STTJob VALUES (?, ?, ?, ?)')->execute([$resultId, "call-{$id}", $status, $endtime]);
            $pdo->prepare('INSERT INTO m_STTResult VALUES (?, ?, ?, ?)')->execute([$resultId, $resultId, $text, $createdAt]);
            $pdo->prepare('INSERT INTO m_STTCallTranscriptPart (call_transcript_id, result_id, job_id) VALUES (?, ?, ?)')
                ->execute([$id, $resultId, $resultId]);
        }
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for call summary tests\n");
        exit(1);
    }

    $speech = str_repeat('клиент спрашивает про лицензию ', 3);

    // Migration 23: a single-leg call keeps its finished summary; a
    // multi-leg call and an unfinished summary are dropped and queued again.
    $legacy = new PDO('sqlite::memory:');
    $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    callSchema($legacy);
    $legacy->exec('CREATE TABLE m_STTSummary (id INTEGER PRIMARY KEY AUTOINCREMENT, result_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT \'pending\',
        topic TEXT, summary TEXT, model_used TEXT, worker_id INTEGER, lease_token TEXT, lease_expires_at INTEGER,
        attempts INTEGER NOT NULL DEFAULT 0, error_msg TEXT, created_at INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0, finished_at INTEGER)');
    $legacy->exec('CREATE UNIQUE INDEX i_m_STTSummary_result_id ON m_STTSummary(result_id)');
    seedCall($legacy, 1, 1000, [[11, 500, $speech, 'done']]);
    seedCall($legacy, 2, 1000, [[21, 600, $speech, 'done'], [22, 650, $speech, 'done']]);
    seedCall($legacy, 3, 1000, [[31, 700, $speech, 'done']]);
    $legacy->exec("INSERT INTO m_STTSummary (result_id, status, topic, summary, model_used, finished_at) VALUES
        (11, 'done', 'Лицензия', 'Клиент спросил про лицензию.', 'gemma', 1200),
        (21, 'done', 'Перевод', 'Первый участок.', 'gemma', 1200),
        (22, 'done', 'МДС', 'Второй участок.', 'gemma', 1200),
        (31, 'processing', NULL, NULL, NULL, NULL)");
    $kept = 0;
    migrate($legacy, static function (SummaryConnection $connection) use (&$kept): void {
        $kept = (new CallSummaryMigration())->migrateToCalls($connection);
    });
    expectSummary($kept === 1, 'Only the finished summary of a single-leg call must be kept');
    $moved = summaryRow($legacy, 1);
    expectSummary(
        $moved['status'] === 'done' && $moved['topic'] === 'Лицензия' && $moved['content_hash'] === 'hash-1-1'
            && (int)$moved['revision'] === 1 && (int)$moved['call_endtime'] === 500,
        'A kept summary must be bound to the call revision: ' . json_encode($moved, JSON_UNESCAPED_UNICODE)
    );
    expectSummary(summaryRow($legacy, 2) === [] && summaryRow($legacy, 3) === [], 'Per-leg and unfinished summaries must be dropped');
    $indexes = array_column($legacy->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'm_STTSummary'")->fetchAll(PDO::FETCH_ASSOC), 'name');
    expectSummary(
        in_array('i_m_STTSummary_call_transcript_id', $indexes, true) && in_array('i_m_STTSummary_queue', $indexes, true)
            && in_array('i_m_STTSummary_finished', $indexes, true) && !in_array('i_m_STTSummary_result_id', $indexes, true),
        'The rebuilt table must be indexed by call: ' . implode(', ', $indexes)
    );
    migrate($legacy, static function (SummaryConnection $connection): void {
        expectSummary((new CallSummaryMigration())->migrateToCalls($connection) === 0, 'The migration must be a no-op on the call schema');
    });

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    callSchema($pdo);
    migrate($pdo, static function (SummaryConnection $connection): void {
        (new CallSummaryMigration())->migrate($connection);
        (new CallSummaryMigration())->migrateIndexes($connection);
    });
    seedCall($pdo, 1, 100, [[11, 100, $speech, 'done']]);
    // A transferred call: two legs, one summary. Its latest leg ended last.
    seedCall($pdo, 2, 1000, [[21, 900, $speech, 'done'], [22, 950, $speech, 'done']]);
    seedCall($pdo, 3, 1001, [[31, 800, 'алло', 'done']]);
    seedCall($pdo, 4, 1002, [[41, 500, $speech, 'done']]);
    seedCall($pdo, 5, 1003, [[51, 400, $speech, 'done']], 0);
    seedCall($pdo, 6, 1004, [[61, 300, $speech, 'done'], [62, 310, '', 'processing']]);
    seedCall($pdo, 7, 1005, [[71, 200, $speech, 'done']], 1, 'newer');
    ModulesModelsBase::useTestPdo($pdo);

    // Only published, settled calls created since summarization was turned
    // on are queued, one row per call; one with almost no speech is skipped.
    expectSummary(STTSummary::enqueueMissing(1000) === 3, 'Settled calls since the switch must be queued once each');
    expectSummary(summaryRow($pdo, 1) === [], 'Calls from before the switch must not be summarized');
    expectSummary(summaryRow($pdo, 3)['status'] === STTSummary::STATUS_SKIPPED, 'A call without speech must be skipped');
    expectSummary(summaryRow($pdo, 5) === [] && summaryRow($pdo, 6) === [] && summaryRow($pdo, 7) === [], 'Unpublished, still transcribed or restaged calls must wait');
    expectSummary((int)$pdo->query('SELECT COUNT(*) FROM m_STTSummary WHERE call_transcript_id = 2')->fetchColumn() === 1, 'A multi-leg call must get one summary');
    expectSummary(STTSummary::enqueueMissing(1000) === 0, 'Queueing must be idempotent');
    $plan = implode(' ', array_column($pdo->query("EXPLAIN QUERY PLAN SELECT id FROM m_STTCallTranscript WHERE created_at >= 1000")->fetchAll(PDO::FETCH_ASSOC), 'detail'));
    expectSummary(str_contains($plan, 'i_m_STTCallTranscript_created_at'), 'The queue must range over recent calls: ' . $plan);

    // The freshest call first, by when its last leg ended; the lease pins the revision.
    $first = STTSummary::claimNextForWorker(7);
    expectSummary($first instanceof STTSummary && (int)$first->call_transcript_id === 2, 'The freshest call must be summarized first');
    expectSummary((int)$first->revision === 1 && $first->content_hash === 'hash-2-1', 'The lease must pin the call revision');
    expectSummary($first->isLeasedTo(7, (string)$first->lease_token), 'The claim must lease the summary to the worker');
    expectSummary(!$first->isLeasedTo(8, (string)$first->lease_token), 'Another worker must not own the lease');
    expectSummary(!$first->isLeasedTo(7, 'wrong'), 'A wrong lease token must not own the lease');
    $second = STTSummary::claimNextForWorker(8);
    expectSummary($second instanceof STTSummary && (int)$second->call_transcript_id === 4, 'The next claim must take the next freshest call');
    expectSummary(STTSummary::claimNextForWorker(9) === null, 'Nothing must be left to claim');

    expectSummary($first->refreshLeaseForWorker(7, (string)$first->lease_token), 'The owner must extend its lease');
    expectSummary(!$first->refreshLeaseForWorker(8, (string)$first->lease_token), 'Another worker must not extend the lease');

    // A call that changes while it is summarized is queued again after the
    // summary of the old content is in.
    $pdo->exec("UPDATE m_STTCallTranscript SET current_revision = 2, current_content_hash = 'hash-2-2' WHERE id = 2");
    expectSummary(STTSummary::requeueChangedCalls() === 0, 'A summary being made must be left to finish');
    expectSummary($first->markDoneForWorker(7, (string)$first->lease_token, 'Лицензия', 'Клиент спросил про лицензию.', 'gemma-3-4b-it-Q4_K_M'), 'The owner must complete its summary');
    $done = summaryRow($pdo, 2);
    expectSummary($done['status'] === STTSummary::STATUS_DONE && $done['topic'] === 'Лицензия' && $done['lease_token'] === null, 'A completed summary must keep the text and drop the lease');
    expectSummary(STTSummary::requeueChangedCalls() === 1, 'A summary of earlier content must be queued again');
    $redo = summaryRow($pdo, 2);
    expectSummary(
        $redo['status'] === STTSummary::STATUS_PENDING && $redo['topic'] === null && (int)$redo['revision'] === 2
            && $redo['content_hash'] === 'hash-2-2' && (int)$redo['attempts'] === 0,
        'A requeued summary must target the new revision: ' . json_encode($redo, JSON_UNESCAPED_UNICODE)
    );
    expectSummary(STTSummary::requeueChangedCalls() === 0, 'Requeueing must be idempotent');
    $again = STTSummary::claimNextForWorker(7);
    expectSummary($again instanceof STTSummary && (int)$again->call_transcript_id === 2 && (int)$again->revision === 2, 'The requeued call must be claimable at its new revision');
    expectSummary($again->markDoneForWorker(7, (string)$again->lease_token, 'Перевод', 'Клиента перевели в доставку.', 'gemma'), 'The new summary must complete');

    // A release returns the task without spending an attempt.
    $token = (string)$second->lease_token;
    expectSummary($second->releaseForWorker(8, $token), 'The owner must release its summary');
    expectSummary(summaryRow($pdo, 4)['status'] === STTSummary::STATUS_PENDING && (int)summaryRow($pdo, 4)['attempts'] === 0, 'A release must not spend an attempt');

    // Three failures end the summary in error.
    for ($attempt = 1; $attempt <= STTSummary::MAX_ATTEMPTS; $attempt++) {
        $claim = STTSummary::claimNextForWorker(8);
        expectSummary($claim instanceof STTSummary && (int)$claim->call_transcript_id === 4, 'A failed summary must be retried');
        expectSummary($claim->markFailedForWorker(8, (string)$claim->lease_token, 'llama-server timed out'), 'The owner must report a failure');
    }
    $failed = summaryRow($pdo, 4);
    expectSummary($failed['status'] === STTSummary::STATUS_ERROR && (int)$failed['attempts'] === 3 && $failed['finished_at'] !== null, 'The third failure must end in error');
    expectSummary(STTSummary::claimNextForWorker(8) === null, 'A summary in error must not be retried');

    // An expired lease spends an attempt and goes back to the queue.
    seedCall($pdo, 8, 1006, [[81, 100, $speech, 'done']]);
    STTSummary::enqueueMissing(1000);
    $stale = STTSummary::claimNextForWorker(9);
    $pdo->exec('UPDATE m_STTSummary SET lease_expires_at = 1 WHERE call_transcript_id = 8');
    expectSummary(STTSummary::requeueStaleProcessing() === 1, 'An expired lease must be requeued');
    expectSummary(!$stale->markDoneForWorker(9, (string)$stale->lease_token, 'x', 'y', 'm'), 'A worker must not complete a summary whose lease expired');
    $requeued = summaryRow($pdo, 8);
    expectSummary($requeued['status'] === STTSummary::STATUS_PENDING && (int)$requeued['attempts'] === 1, 'An expired lease must spend one attempt');

    // A request skips the summaries it already tried; a final failure ends
    // the summary at once.
    $claimed = STTSummary::claimNextForWorker(9);
    expectSummary($claimed instanceof STTSummary && (int)$claimed->call_transcript_id === 8, 'The requeued summary must be claimable');
    expectSummary($claimed->releaseForWorker(9, (string)$claimed->lease_token), 'The owner must release it');
    expectSummary(STTSummary::claimNextForWorker(9, [(int)$claimed->id]) === null, 'A summary tried in this request must be skipped');
    $final = STTSummary::claimNextForWorker(9);
    expectSummary($final->markFailedForWorker(9, (string)$final->lease_token, 'Summary prompt could not be built', true), 'The owner must report a final failure');
    $finalRow = summaryRow($pdo, 8);
    expectSummary($finalRow['status'] === STTSummary::STATUS_ERROR && (int)$finalRow['attempts'] === 2, 'A final failure must end in error at once');

    expectSummary((int)STTSummary::forCallTranscriptId(2)?->call_transcript_id === 2 && STTSummary::forCallTranscriptId(99) === null, 'Summaries must be found by call');

    // Model answers.
    expectSummary(
        SummaryContentParser::parse('{"topic": "Проблема с 1С.", "summary": "Клиент сообщил о сбое."}') === ['topic' => 'Проблема с 1С', 'summary' => 'Клиент сообщил о сбое.'],
        'A JSON answer must be parsed and the topic trimmed of its final period'
    );
    expectSummary(
        SummaryContentParser::parse("```json\n{\"topic\":\"**Тема**\",\"summary\":\"<b>Итог</b>  разговора\"}\n```") === ['topic' => 'Тема', 'summary' => 'Итог разговора'],
        'Markdown fences, emphasis and tags must be removed'
    );
    expectSummary(SummaryContentParser::parse('{"topic":"Тема","summary":"  "}') === null, 'An empty summary must be rejected');
    expectSummary(SummaryContentParser::parse('Краткое содержание: всё хорошо') === null, 'Prose without JSON must be rejected');
    $long = SummaryContentParser::parse(json_encode(['topic' => 't', 'summary' => str_repeat('а', 5000)], JSON_UNESCAPED_UNICODE));
    expectSummary(mb_strlen($long['summary'] ?? '') === SummaryContentParser::MAX_SUMMARY_CHARS, 'A summary must be bounded');
    expectSummary(SummaryContentParser::parse('{"topic": "t", "summary": "' . str_repeat('a', 70000) . '"}') === null, 'An oversized answer must not be parsed');

    // Prompt: the whole call, every leg in one dialog.
    $turn = static fn (int $leg, string $text, ?string $channel, ?string $role, ?string $name = null): array =>
        ['result_id' => $leg, 'channel' => $channel, 'speaker' => $role, 'speaker_name' => $name, 'text' => $text];
    $turns = [
        $turn(11, 'Компания Мико, Иван, слушаю. ', 'right', 'employee', 'Иван Петров'),
        $turn(11, 'Добрый день.', 'right', 'employee', 'Иван Петров'),
        $turn(11, 'Мне нужна помощь с лицензией.', 'left', 'client'),
        $turn(11, '  ', 'left', 'client'),
    ];
    $builder = new SummaryPromptBuilder();
    $request = $builder->build($turns, CallDirectionResolver::DIRECTION_INCOMING, 'ru');
    $system = $request['messages'][0]['content'];
    $user = $request['messages'][1]['content'];
    expectSummary($request['messages'][0]['role'] === 'system' && $request['messages'][1]['role'] === 'user', 'The request must hold a system and a user message');
    expectSummary(str_contains($system, 'по данным АТС') && str_contains($system, 'только JSON'), 'Known sides must be declared accurate and JSON required');
    expectSummary(
        str_contains($user, "Иван Петров (сотрудник): Компания Мико, Иван, слушаю. Добрый день.\nКлиент: Мне нужна помощь с лицензией.")
            && !str_contains($user, 'Клиент:  '),
        'Turns must merge one speaker and skip empty text'
    );
    expectSummary(str_contains($user, 'ВХОДЯЩИЙ звонок') && str_contains($user, 'ответил сотрудник Иван Петров'), 'The direction must be repeated in the task');
    expectSummary(!str_contains($user, 'участок') && str_contains($user, '1-2 коротких предложения'), 'A single-leg call must not mention legs');
    expectSummary(str_contains($user, 'на русском языке'), 'The answer language must follow the conversation');
    expectSummary(strrpos($user, 'Задание.') > strrpos($user, 'Клиент:'), 'The task must come after the transcript');
    expectSummary(
        $request['json_schema']['required'] === ['topic', 'summary'] && $request['temperature'] === 0 && $request['max_tokens'] === 300,
        'The request must constrain the answer to the topic/summary schema'
    );

    // The reported case: the caller was transferred from one employee to another.
    $transferred = $builder->build([
        $turn(21, 'Вас беспокоит Андромеда, соедините с техподдержкой.', 'left', 'client'),
        $turn(21, 'Минуточку, сейчас переведу.', 'right', 'employee', 'Портнова Татьяна'),
        $turn(22, 'Техподдержка, Юлия.', 'right', 'employee', 'Юлия Ефимова'),
        $turn(22, 'Не получается заполнить декларацию по МДС.', 'left', 'client'),
    ], CallDirectionResolver::DIRECTION_INCOMING, 'ru')['messages'][1]['content'];
    expectSummary(
        str_contains($transferred, "Портнова Татьяна (сотрудник): Минуточку, сейчас переведу.\n— Следующий участок звонка (перевод или консультация), участвует Юлия Ефимова —\nЮлия Ефимова (сотрудник): Техподдержка, Юлия.\nКлиент: Не получается"),
        'A new leg must be marked where it joins the dialog: ' . $transferred
    );
    expectSummary(
        str_contains($transferred, 'ответил сотрудник Портнова Татьяна.')
            && str_contains($transferred, 'участвовали: Портнова Татьяна, Юлия Ефимова')
            && str_contains($transferred, 'опиши его целиком')
            && str_contains($transferred, '1-3 коротких предложения'),
        'The task must ask for one summary of the whole transferred call'
    );

    // Without CDR sides a leg's channel is all that is known.
    $anonymous = $builder->build([
        $turn(31, 'Компания Мико.', 'right', null),
        $turn(31, 'Мне нужна помощь.', 'left', null),
        $turn(32, 'Отдел доставки.', 'right', null),
    ], '', '');
    $anonymousUser = $anonymous['messages'][1]['content'];
    expectSummary(
        str_contains($anonymousUser, "Собеседник 1: Компания Мико.\nСобеседник 2: Мне нужна помощь.\n— Следующий участок звонка (перевод или консультация) —\nСобеседник 3: Отдел доставки.")
            && str_contains($anonymous['messages'][0]['content'], 'определи по смыслу')
            && str_contains($anonymousUser, 'Направление звонка неизвестно')
            && !str_contains($anonymousUser, 'Используй имена')
            && str_contains($anonymousUser, 'на том языке, на котором идёт разговор'),
        'Unknown sides, direction and language must be stated as unknown: ' . $anonymousUser
    );
    $outgoing = $builder->build([$turn(41, 'Здравствуйте, это Анна.', 'left', 'employee', 'Анна'), $turn(41, 'Слушаю.', 'right', 'client')], CallDirectionResolver::DIRECTION_OUTGOING, 'en');
    expectSummary(
        str_contains($outgoing['messages'][1]['content'], 'ИСХОДЯЩИЙ звонок: сотрудник Анна сам позвонил Клиенту')
            && str_contains($outgoing['messages'][1]['content'], 'на английском языке'),
        'An outgoing call must name the calling employee'
    );

    $longTurns = array_map(
        static fn (int $index): array => $turn(intdiv($index, 100), str_repeat('слово ', 40), $index % 2 === 0 ? 'left' : 'right', $index % 2 === 0 ? 'client' : 'employee'),
        range(0, 199)
    );
    $truncated = $builder->build($longTurns, '', 'ru')['messages'][1]['content'];
    expectSummary(str_contains($truncated, "\n[...]\n") && mb_strlen($truncated) < 19500, 'A long dialog must keep only its head and tail');

    // Transcription always wins over summaries.
    $service = (string)file_get_contents(__DIR__ . '/../Lib/Services/CallSummaryService.php');
    expectSummary(
        str_contains($service, '!STTSetting::summarizationEnabled() || $this->transcriptionPending()'),
        'No summary may be handed out while summarization is off or a transcription job is pending'
    );

    echo "Call summary queue, parser and prompt OK\n";
}
