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

        /** @var array<string, int> call id => first-leg result id */
        public static array $results = [];

        /** @param array<string, string> $bind @return array{id: int}|false */
        public function fetchOne(string $sql, int $fetchMode, array $bind): array|false
        {
            $resultId = self::$results[$bind['call_id'] ?? ''] ?? null;

            return $resultId === null ? false : ['id' => $resultId];
        }

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
        /** @var array<string, array<string, mixed>> */
        public static array $rows = [];

        public int $revision;
        public string $content_hash;
        public string $parts_hash;
        public string $completeness_status;
        public int $created_at;
        public string $payload;

        /** @param array<string, mixed> $row */
        public function __construct(array $row)
        {
            $this->revision = (int)($row['revision'] ?? 0);
            $this->content_hash = (string)($row['content_hash'] ?? 'content-hash');
            $this->parts_hash = (string)($row['parts_hash'] ?? 'parts-hash');
            $this->completeness_status = (string)($row['completeness_status'] ?? 'complete');
            $this->created_at = (int)($row['created_at'] ?? 1_788_800_000);
            $this->payload = (string)($row['payload'] ?? '');
        }

        /** @param array<string, mixed> $query */
        public static function findFirst(array $query): ?self
        {
            $aggregateId = (int)($query['bind']['aggregate_id'] ?? 0);
            $revision = (int)($query['bind']['revision'] ?? 0);

            $row = self::$rows["{$aggregateId}:{$revision}"] ?? null;

            return is_array($row) ? new self($row) : null;
        }
    }
}

namespace {
    require_once __DIR__ . '/../Lib/TranscriptTimeNormalizer.php';
    require_once __DIR__ . '/../Lib/Services/UsersUICallScope.php';
    require_once __DIR__ . '/../Lib/Services/UsersUITranscriptAccessService.php';
    require_once __DIR__ . '/../Lib/Services/TranscriptConversationService.php';
    require_once __DIR__ . '/../Lib/Services/CallTranscriptTimelineProjector.php';
    require_once __DIR__ . '/../Lib/Exceptions/CdrTranscriptNotFoundException.php';
    require_once __DIR__ . '/../Lib/Services/CdrTranscriptService.php';

    use Modules\ModuleLocalSpeechToText\Lib\Exceptions\CdrTranscriptNotFoundException;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CdrTranscriptService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUICallScope;
    use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUITranscriptAccessService;
    use Modules\ModuleLocalSpeechToText\Models\FakeCdrTranscriptDb;
    use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision;

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
        'call-multi' => ['id' => 14, 'current_revision' => 2],
        'call-legacy' => ['id' => 15, 'current_revision' => 1],
        'call-corrupt' => ['id' => 16, 'current_revision' => 1],
    ];
    FakeCdrTranscriptDb::$results = ['call-1' => 77];
    $payload = static fn (array $manifest, array $segments): string => json_encode([
        'part_manifest' => $manifest,
        'transcript' => ['segments' => $segments],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    STTCallTranscriptRevision::$rows = [
        '11:3' => [
            'revision' => 3,
            'payload' => $payload(
                [['result_id' => 101, 'cdr_id' => 501, 'cdr_start_ms' => 100_000]],
                [[
                    'segment_id' => 'segment-1', 'result_id' => 101, 'index' => 0,
                    'start_ms' => 5_000, 'end_ms' => 6_000,
                    'absolute_start_ms' => 105_000, 'absolute_end_ms' => 106_000,
                    'text' => 'Здравствуйте', 'channel' => 'left',
                ]]
            ),
        ],
        '14:2' => [
            'revision' => 2,
            'payload' => $payload(
                [
                    ['result_id' => 101, 'cdr_id' => 501, 'cdr_start_ms' => 100_000],
                    ['result_id' => 102, 'cdr_id' => 502, 'cdr_start_ms' => 130_000],
                ],
                [
                    [
                        'segment_id' => 'segment-a', 'result_id' => 101, 'index' => 0,
                        'start_ms' => 5_000, 'end_ms' => 6_000,
                        'absolute_start_ms' => 105_000, 'absolute_end_ms' => 106_000,
                        'text' => 'A', 'channel' => 'left',
                    ],
                    [
                        'segment_id' => 'segment-b', 'result_id' => 102, 'index' => 1,
                        'start_ms' => 1_000, 'end_ms' => 2_000,
                        'absolute_start_ms' => 131_000, 'absolute_end_ms' => 132_000,
                        'text' => 'B', 'channel' => 'right',
                    ],
                ]
            ),
        ],
        '15:1' => [
            'revision' => 1,
            'completeness_status' => 'legacy_unresolved',
            'payload' => $payload(
                [['result_id' => 201, 'cdr_id' => 601, 'cdr_start_ms' => null]],
                [
                    ['result_id' => 201, 'start_ms' => 5_000, 'end_ms' => 6_000, 'text' => 'A', 'channel' => 'left'],
                    ['result_id' => 202, 'start_ms' => 1_000, 'end_ms' => 2_000, 'text' => 'B', 'channel' => 'right'],
                ]
            ),
        ],
        '16:1' => ['revision' => 1, 'payload' => '{broken-json'],
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
    $service = new CdrTranscriptService($translation, $access);
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
    expectCdrTranscript($detail['resultId'] === 77, 'first-leg result id mismatch');
    expectCdrTranscript($detail['playbackCdrId'] === 501, 'single-leg playback CDR mismatch');
    expectCdrTranscript($detail['conversation']['timelineReliable'] === true, 'single-leg timeline must be reliable');
    expectCdrTranscript($detail['conversation']['hasSegments'] === true, 'conversation projection missing');
    expectCdrTranscript(
        $detail['conversation']['timeline'][0]['text'] === 'Здравствуйте',
        'turn text mismatch'
    );

    $multi = $service->detail('call-multi', $admin);
    expectCdrTranscript(
        array_column($multi['conversation']['timeline'], 'text') === ['A', 'B'],
        'multi-leg timeline must preserve absolute call order'
    );
    expectCdrTranscript(
        array_column($multi['conversation']['timeline'], 'start') === [5.0, 31.0],
        'multi-leg timeline must use call-relative absolute timestamps'
    );
    expectCdrTranscript($multi['playbackCdrId'] === null, 'multi-leg transcript must not select a single recording');

    $legacy = $service->detail('call-legacy', $admin);
    expectCdrTranscript(
        array_column($legacy['conversation']['timeline'], 'text') === ['A', 'B'],
        'unresolved transcript must preserve assembler order'
    );
    expectCdrTranscript($legacy['conversation']['timelineReliable'] === false, 'unresolved timeline must be marked unreliable');
    expectCdrTranscript($legacy['playbackCdrId'] === null, 'unresolved transcript must disable shared playback');

    foreach (['call-2', 'call-missing', 'call-pending'] as $callId) {
        try {
            $service->detail($callId, $restricted);
            expectCdrTranscript(false, "{$callId} must be hidden as not found");
        } catch (CdrTranscriptNotFoundException $exception) {
            expectCdrTranscript(
                $exception->getMessage() === 'Transcript not found',
                'public failure must be generic'
            );
        }
    }

    try {
        $service->detail('call-corrupt', $admin);
        expectCdrTranscript(false, 'corrupt revision must fail as an internal error');
    } catch (\JsonException) {
        // Expected: this must not be converted into a public 404.
    }

    echo "CDR transcript service contract OK\n";
}
