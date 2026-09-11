<?php

declare(strict_types=1);

namespace Phalcon\Db {
    if (!class_exists(Enum::class, false)) {
        final class Enum
        {
            public const int FETCH_ASSOC = 2;
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    final class FakeCdrTranscriptDb
    {
        public static int $lastBindCount = 0;

        /** @var array<string, array{id: int, current_revision: int}> */
        public static array $calls = [];

        /** @param array<string, string> $bind @return array<int, array{id: int, call_id: string}> */
        public function fetchAll(string $sql, int $fetchMode, array $bind): array
        {
            self::$lastBindCount = count($bind);
            $rows = [];
            foreach ($bind as $callId) {
                $fixture = self::$calls[$callId] ?? null;
                if ($fixture === null || $fixture['current_revision'] <= 0) {
                    continue;
                }
                $rows[] = ['id' => $fixture['id'], 'call_id' => $callId];
            }

            return $rows;
        }
    }

    final class STTCallTranscript
    {
        public int $id;
        public string $call_id;
        public int $current_revision;

        public function __construct(int $id = 0, string $callId = '', int $currentRevision = 0)
        {
            $this->id = $id;
            $this->call_id = $callId;
            $this->current_revision = $currentRevision;
        }

        public function getReadConnection(): FakeCdrTranscriptDb
        {
            return new FakeCdrTranscriptDb();
        }

        /** @param array<string, mixed> $query */
        public static function findFirst(array $query): ?self
        {
            $callId = (string)($query['bind']['call_id'] ?? '');
            $fixture = FakeCdrTranscriptDb::$calls[$callId] ?? null;
            if ($fixture === null || $fixture['current_revision'] <= 0) {
                return null;
            }

            return new self($fixture['id'], $callId, $fixture['current_revision']);
        }

        public static function findFirstById(int $aggregateId): ?self
        {
            foreach (FakeCdrTranscriptDb::$calls as $callId => $fixture) {
                if ($fixture['id'] === $aggregateId && $fixture['current_revision'] > 0) {
                    return new self($fixture['id'], $callId, $fixture['current_revision']);
                }
            }

            return null;
        }
    }

    final class STTCallTranscriptRevision
    {
        public int $revision = 3;
        public string $content_hash = 'content-hash';
        public string $parts_hash = 'parts-hash';
        public string $completeness_status = 'complete';
        public int $created_at = 1_788_800_000;
        public string $payload;

        public function __construct()
        {
            $this->payload = json_encode([
                'transcript' => [
                    'segments' => [[
                        'segment_id' => 'segment-1',
                        'index' => 0,
                        'start_ms' => 0,
                        'end_ms' => 1_000,
                        'text' => 'Здравствуйте',
                        'channel' => 'left',
                    ]],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        /** @param array<string, mixed> $query */
        public static function findFirst(array $query): ?self
        {
            $aggregateId = (int)($query['bind']['aggregate_id'] ?? 0);
            $revision = (int)($query['bind']['revision'] ?? 0);

            return $aggregateId === 11 && $revision === 3 ? new self() : null;
        }
    }
}

namespace {
    require_once __DIR__ . '/../Lib/TranscriptTimeNormalizer.php';
    require_once __DIR__ . '/../Lib/Services/UsersUICallScope.php';
    require_once __DIR__ . '/../Lib/Services/UsersUITranscriptAccessService.php';
    require_once __DIR__ . '/../Lib/Services/TranscriptConversationService.php';
    require_once __DIR__ . '/../Lib/Services/CallTranscriptIntegrationService.php';
    require_once __DIR__ . '/../Lib/Services/CdrTranscriptService.php';

    use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptIntegrationService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CdrTranscriptService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUICallScope;
    use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUITranscriptAccessService;
    use Modules\ModuleLocalSpeechToText\Models\FakeCdrTranscriptDb;

    function expectCdrTranscript(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    FakeCdrTranscriptDb::$calls = [
        'call-1' => ['id' => 11, 'current_revision' => 3],
        'call-2' => ['id' => 12, 'current_revision' => 1],
        'call-pending' => ['id' => 13, 'current_revision' => 0],
    ];

    $translation = new class {
        public function _(string $key): string
        {
            return match ($key) {
                'module_local_speech_to_text_Speaker1' => 'Speaker 1',
                'module_local_speech_to_text_Speaker2' => 'Speaker 2',
                default => $key,
            };
        }
    };
    $access = new UsersUITranscriptAccessService(
        static fn (string $callId): array => match ($callId) {
            'call-1' => [['source' => '201', 'destination' => '84951234567']],
            'call-2' => [['source' => '202', 'destination' => '84957654321']],
            default => [],
        }
    );
    $service = new CdrTranscriptService($translation, $access, new CallTranscriptIntegrationService());
    $admin = UsersUICallScope::all();
    $restricted = UsersUICallScope::selected(['201']);

    expectCdrTranscript(
        $service->lookup([' call-1 ', 'call-1', '', 'call-2', 'call-pending'], $admin)
            === ['call-1' => 11, 'call-2' => 12],
        'lookup must normalize, deduplicate, and omit unpublished calls'
    );
    expectCdrTranscript(
        $service->lookup(['call-1', 'call-2'], $restricted) === ['call-1' => 11],
        'lookup must apply the logical-call scope'
    );

    $manyCallIds = array_map(static fn (int $number): string => "call-{$number}", range(1, 150));
    $service->lookup($manyCallIds, $admin);
    expectCdrTranscript(FakeCdrTranscriptDb::$lastBindCount === 100, 'lookup query must bind only 100 IDs');

    $detail = $service->detail('call-1', $restricted);
    expectCdrTranscript($detail['aggregateId'] === 11, 'aggregate id mismatch');
    expectCdrTranscript($detail['callId'] === 'call-1', 'call id mismatch');
    expectCdrTranscript($detail['revision'] === 3, 'revision mismatch');
    expectCdrTranscript($detail['conversation']['hasSegments'] === true, 'conversation projection missing');
    expectCdrTranscript(
        $detail['conversation']['timeline'][0]['text'] === 'Здравствуйте',
        'turn text mismatch'
    );

    foreach (['call-2', 'call-missing', 'call-pending'] as $callId) {
        try {
            $service->detail($callId, $restricted);
            expectCdrTranscript(false, "{$callId} must be hidden as not found");
        } catch (\RuntimeException $exception) {
            expectCdrTranscript(
                $exception->getMessage() === 'Transcript not found',
                'public failure must be generic'
            );
        }
    }

    echo "CDR transcript service contract OK\n";
}
