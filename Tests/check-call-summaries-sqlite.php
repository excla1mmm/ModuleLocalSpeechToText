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
                    $model->{$column} = $column === 'result_id' || $column === 'id' || $value === null ? $value : (string)$value;
                }
                $models[] = $model;
            }

            return $models;
        }
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallDirectionResolver;
    use Modules\ModuleLocalSpeechToText\Lib\Services\SummaryContentParser;
    use Modules\ModuleLocalSpeechToText\Lib\Services\SummaryPromptBuilder;
    use Modules\ModuleLocalSpeechToText\Models\STTSummary;

    require_once __DIR__ . '/../Models/STTSummary.php';
    require_once __DIR__ . '/../Lib/Services/CallDirectionResolver.php';
    require_once __DIR__ . '/../Lib/Services/SummaryContentParser.php';
    require_once __DIR__ . '/../Lib/Services/SummaryPromptBuilder.php';

    function expectSummary(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function summaryRow(PDO $pdo, int $resultId): array
    {
        $statement = $pdo->prepare('SELECT * FROM m_STTSummary WHERE result_id = ?');
        $statement->execute([$resultId]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for call summary tests\n");
        exit(1);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE m_STTJob (id INTEGER PRIMARY KEY, cdr_endtime INTEGER)');
    $pdo->exec('CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, job_id INTEGER, search_text TEXT, created_at INTEGER)');
    $pdo->exec('CREATE TABLE m_STTSummary (id INTEGER PRIMARY KEY AUTOINCREMENT, result_id INTEGER NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT \'pending\', topic TEXT, summary TEXT, model_used TEXT, worker_id INTEGER,
        lease_token TEXT, lease_expires_at INTEGER, attempts INTEGER NOT NULL DEFAULT 0, error_msg TEXT,
        created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, finished_at INTEGER)');
    $speech = str_repeat('клиент спрашивает про лицензию ', 3);
    // Transcription runs newest call first, so the freshest call (job 2) has
    // an older result than an earlier call (job 4).
    $pdo->exec('INSERT INTO m_STTJob VALUES (1, 100), (2, 900), (3, 800), (4, 500), (5, 300)');
    $pdo->exec("INSERT INTO m_STTResult VALUES
        (1, 1, '{$speech}', 100),
        (2, 2, '{$speech}', 1000),
        (3, 3, 'алло', 1001),
        (4, 4, '{$speech}', 1002)");
    ModulesModelsBase::useTestPdo($pdo);

    // Only transcripts created since summarization was turned on are queued;
    // one with almost no speech is skipped at once.
    expectSummary(STTSummary::enqueueMissing(1000) === 3, 'Transcripts since the switch must be queued');
    expectSummary(summaryRow($pdo, 1) === [], 'Transcripts from before the switch must not be summarized');
    expectSummary(summaryRow($pdo, 3)['status'] === STTSummary::STATUS_SKIPPED, 'A transcript without speech must be skipped');
    expectSummary(STTSummary::enqueueMissing(1000) === 0, 'Queueing must be idempotent');

    // The freshest call first, whatever the transcription order.
    $first = STTSummary::claimNextForWorker(7);
    expectSummary($first instanceof STTSummary && (int)$first->result_id === 2, 'The freshest call must be summarized first');
    expectSummary($first->isLeasedTo(7, (string)$first->lease_token), 'The claim must lease the summary to the worker');
    expectSummary(!$first->isLeasedTo(8, (string)$first->lease_token), 'Another worker must not own the lease');
    expectSummary(!$first->isLeasedTo(7, 'wrong'), 'A wrong lease token must not own the lease');
    $second = STTSummary::claimNextForWorker(8);
    expectSummary($second instanceof STTSummary && (int)$second->result_id === 4, 'The next claim must take the next freshest call');
    expectSummary(STTSummary::claimNextForWorker(9) === null, 'Nothing must be left to claim');

    expectSummary($first->refreshLeaseForWorker(7, (string)$first->lease_token), 'The owner must extend its lease');
    expectSummary(!$first->refreshLeaseForWorker(8, (string)$first->lease_token), 'Another worker must not extend the lease');
    expectSummary($first->markDoneForWorker(7, (string)$first->lease_token, 'Лицензия', 'Клиент спросил про лицензию.', 'gemma-3-4b-it-Q4_K_M'), 'The owner must complete its summary');
    $done = summaryRow($pdo, 2);
    expectSummary($done['status'] === STTSummary::STATUS_DONE && $done['topic'] === 'Лицензия' && $done['lease_token'] === null, 'A completed summary must keep the text and drop the lease');

    // A release returns the task without spending an attempt.
    $token = (string)$second->lease_token;
    expectSummary($second->releaseForWorker(8, $token), 'The owner must release its summary');
    expectSummary(summaryRow($pdo, 4)['status'] === STTSummary::STATUS_PENDING && (int)summaryRow($pdo, 4)['attempts'] === 0, 'A release must not spend an attempt');

    // Three failures end the summary in error.
    for ($attempt = 1; $attempt <= STTSummary::MAX_ATTEMPTS; $attempt++) {
        $claim = STTSummary::claimNextForWorker(8);
        expectSummary($claim instanceof STTSummary && (int)$claim->result_id === 4, 'A failed summary must be retried');
        expectSummary($claim->markFailedForWorker(8, (string)$claim->lease_token, 'llama-server timed out'), 'The owner must report a failure');
    }
    $failed = summaryRow($pdo, 4);
    expectSummary($failed['status'] === STTSummary::STATUS_ERROR && (int)$failed['attempts'] === 3 && $failed['finished_at'] !== null, 'The third failure must end in error');
    expectSummary(STTSummary::claimNextForWorker(8) === null, 'A summary in error must not be retried');

    // An expired lease spends an attempt and goes back to the queue.
    $pdo->exec("INSERT INTO m_STTResult VALUES (5, 5, '{$speech}', 1003)");
    STTSummary::enqueueMissing(1000);
    $stale = STTSummary::claimNextForWorker(9);
    $pdo->exec('UPDATE m_STTSummary SET lease_expires_at = 1 WHERE result_id = 5');
    expectSummary(STTSummary::requeueStaleProcessing() === 1, 'An expired lease must be requeued');
    expectSummary(!$stale->markDoneForWorker(9, (string)$stale->lease_token, 'x', 'y', 'm'), 'A worker must not complete a summary whose lease expired');
    $requeued = summaryRow($pdo, 5);
    expectSummary($requeued['status'] === STTSummary::STATUS_PENDING && (int)$requeued['attempts'] === 1, 'An expired lease must spend one attempt');

    // A request skips the summaries it already tried; a final failure ends
    // the summary at once.
    $claimed = STTSummary::claimNextForWorker(9);
    expectSummary($claimed instanceof STTSummary && (int)$claimed->result_id === 5, 'The requeued summary must be claimable');
    expectSummary($claimed->releaseForWorker(9, (string)$claimed->lease_token), 'The owner must release it');
    expectSummary(STTSummary::claimNextForWorker(9, [(int)$claimed->id]) === null, 'A summary tried in this request must be skipped');
    $final = STTSummary::claimNextForWorker(9);
    expectSummary($final->markFailedForWorker(9, (string)$final->lease_token, 'Summary prompt could not be built', true), 'The owner must report a final failure');
    $finalRow = summaryRow($pdo, 5);
    expectSummary($finalRow['status'] === STTSummary::STATUS_ERROR && (int)$finalRow['attempts'] === 2, 'A final failure must end in error at once');

    $summaries = STTSummary::forResultIds([4, 5, 99]);
    $foundIds = array_keys($summaries);
    sort($foundIds);
    expectSummary($foundIds === [4, 5], 'Summaries must be found by transcript');

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

    // Prompt.
    $transcript = json_encode([
        ['start_ms' => 2000, 'end_ms' => 3000, 'text' => 'Мне нужна помощь с лицензией.', 'channel' => 'left'],
        ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Компания Мико, Иван, слушаю.', 'channel' => 'right'],
        ['start_ms' => 1000, 'end_ms' => 1500, 'text' => 'Добрый день.', 'channel' => 'right'],
        ['start_ms' => 4000, 'end_ms' => 4500, 'text' => '  ', 'channel' => 'left'],
    ], JSON_UNESCAPED_UNICODE);
    $builder = new SummaryPromptBuilder();
    $request = $builder->build($transcript, CallDirectionResolver::DIRECTION_INCOMING, ['left' => 'Клиент', 'right' => 'Иван Петров'], 'ru');
    $system = $request['messages'][0]['content'];
    $user = $request['messages'][1]['content'];
    expectSummary($request['messages'][0]['role'] === 'system' && $request['messages'][1]['role'] === 'user', 'The request must hold a system and a user message');
    expectSummary(str_contains($system, 'по данным АТС') && str_contains($system, 'только JSON'), 'Known sides must be declared accurate and JSON required');
    expectSummary(
        str_contains($user, "Иван Петров (сотрудник): Компания Мико, Иван, слушаю. Добрый день.\nКлиент: Мне нужна помощь с лицензией.")
            && !str_contains($user, 'Клиент:  '),
        'Turns must follow time order, merge one speaker and skip empty text'
    );
    expectSummary(str_contains($user, 'ВХОДЯЩИЙ звонок') && str_contains($user, 'ответил сотрудник Иван Петров'), 'The direction must be repeated in the task');
    expectSummary(str_contains($user, 'на русском языке'), 'The answer language must follow the conversation');
    expectSummary(strrpos($user, 'Задание.') > strrpos($user, 'Клиент:'), 'The task must come after the transcript');
    expectSummary(
        $request['json_schema']['required'] === ['topic', 'summary'] && $request['temperature'] === 0 && $request['max_tokens'] === 300,
        'The request must constrain the answer to the topic/summary schema'
    );

    $anonymous = $builder->build($transcript, '', null, '');
    $anonymousUser = $anonymous['messages'][1]['content'];
    expectSummary(
        str_contains($anonymousUser, 'Собеседник 1: Компания Мико') && str_contains($anonymousUser, 'Собеседник 2: Мне нужна')
            && str_contains($anonymous['messages'][0]['content'], 'определи по смыслу')
            && str_contains($anonymousUser, 'Направление звонка неизвестно')
            && str_contains($anonymousUser, 'на том языке, на котором идёт разговор'),
        'Unknown sides, direction and language must be stated as unknown'
    );
    $outgoing = $builder->build($transcript, CallDirectionResolver::DIRECTION_OUTGOING, ['left' => 'Анна', 'right' => 'Клиент'], 'en');
    expectSummary(
        str_contains($outgoing['messages'][1]['content'], 'ИСХОДЯЩИЙ звонок: сотрудник Анна сам позвонил Клиенту')
            && str_contains($outgoing['messages'][1]['content'], 'на английском языке'),
        'An outgoing call must name the calling employee'
    );

    $longTranscript = json_encode(array_map(
        static fn (int $index): array => ['start_ms' => $index * 1000, 'end_ms' => $index * 1000 + 900, 'text' => str_repeat('слово ', 40), 'channel' => $index % 2 === 0 ? 'left' : 'right'],
        range(0, 199)
    ), JSON_UNESCAPED_UNICODE);
    $truncated = $builder->build($longTranscript, '', null, 'ru')['messages'][1]['content'];
    expectSummary(str_contains($truncated, "\n[...]\n") && mb_strlen($truncated) < 13500, 'A long dialog must keep only its head and tail');

    // Transcription always wins over summaries.
    $service = (string)file_get_contents(__DIR__ . '/../Lib/Services/CallSummaryService.php');
    expectSummary(
        str_contains($service, '!STTSetting::summarizationEnabled() || $this->transcriptionPending()'),
        'No summary may be handed out while summarization is off or a transcription job is pending'
    );

    echo "Call summary queue, parser and prompt OK\n";
}
