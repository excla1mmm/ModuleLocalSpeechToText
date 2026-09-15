<?php

declare(strict_types=1);

/**
 * CallTranscripts/Transcripts/CallTranscriptEvents Processor read APIs must
 * map ONLY their dedicated not-found exception to 404 with a fixed message,
 * and must never leak $exception->getMessage() from an arbitrary Throwable
 * into the response. Unknown-action branches must set httpCode 400.
 */

namespace {
    function expectReadApi404(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    $root = __DIR__ . '/..';

    $callTranscripts = (string)file_get_contents($root . '/Lib/RestAPI/CallTranscripts/Processor.php');
    $transcripts = (string)file_get_contents($root . '/Lib/RestAPI/Transcripts/Processor.php');
    $callTranscriptEvents = (string)file_get_contents($root . '/Lib/RestAPI/CallTranscriptEvents/Processor.php');

    expectReadApi404(
        str_contains($callTranscripts, 'catch (CallTranscriptNotFoundException)'),
        'CallTranscripts Processor must catch the dedicated not-found exception only'
    );
    expectReadApi404(
        !str_contains($callTranscripts, '$exception->getMessage()'),
        'CallTranscripts Processor must not leak exception messages to the client'
    );
    expectReadApi404(
        str_contains($callTranscripts, "\$result->httpCode = 400;\n            \$result->messages['error'][] = \"Unknown action"),
        'CallTranscripts Processor unknown action must set httpCode 400'
    );

    expectReadApi404(
        str_contains($transcripts, 'catch (TranscriptNotFoundException)'),
        'Transcripts Processor must catch the dedicated not-found exception only'
    );
    expectReadApi404(
        !str_contains($transcripts, '$exception->getMessage()'),
        'Transcripts Processor must not leak exception messages to the client'
    );
    expectReadApi404(
        str_contains($transcripts, "\$result->httpCode = 400;\n            \$result->messages['error'][] = \"Unknown action"),
        'Transcripts Processor unknown action must set httpCode 400'
    );

    expectReadApi404(
        !str_contains($callTranscriptEvents, '$exception->getMessage()'),
        'CallTranscriptEvents Processor must not leak exception messages to the client'
    );
    expectReadApi404(
        str_contains($callTranscriptEvents, "\$result->httpCode = 400;\n            \$result->messages['error'][] = \"Unknown action"),
        'CallTranscriptEvents Processor unknown action must set httpCode 400'
    );

    require_once $root . '/Lib/Exceptions/CallTranscriptNotFoundException.php';
    require_once $root . '/Lib/Exceptions/TranscriptNotFoundException.php';
}

namespace MikoPBX\PBXCoreREST\Lib {
    class PBXApiResult
    {
        public bool $success = false;
        public array $data = [];
        public array $messages = [];
        public int $httpCode = 0;
        public string $processor = '';
        public string $function = '';
    }
}

namespace Phalcon\Di {
    class Injectable
    {
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    class LocalSpeechToTextLogger
    {
        /** @var list<array{level:int, code:string}> */
        public static array $events = [];

        public static function event(string $category, string $code, string $message, int $level, array $context = []): void
        {
            self::$events[] = ['level' => $level, 'code' => $code];
        }
    }

    class LocalSpeechToTextLogSanitizer
    {
        public static function errorCode(\Throwable $exception): string
        {
            return get_class($exception);
        }
    }

    class CallTranscriptIntegrationService
    {
        public static ?\Throwable $throwOnDetail = null;

        public function callTranscriptDetail(int $id, ?int $revision = null): array
        {
            if (self::$throwOnDetail !== null) {
                throw self::$throwOnDetail;
            }

            return ['aggregate_id' => $id];
        }
    }

    class TranscriptIntegrationService
    {
        public static ?\Throwable $throwOnDetail = null;

        public function transcriptDetail(int $id): array
        {
            if (self::$throwOnDetail !== null) {
                throw self::$throwOnDetail;
            }

            return ['id' => $id];
        }

        public function listCompletedTranscripts(array $data): array
        {
            return ['items' => []];
        }
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\Exceptions\CallTranscriptNotFoundException;
    use Modules\ModuleLocalSpeechToText\Lib\Exceptions\TranscriptNotFoundException;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptIntegrationService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptIntegrationService;

    require_once $root . '/Lib/RestAPI/CallTranscripts/Processor.php';
    require_once $root . '/Lib/RestAPI/Transcripts/Processor.php';

    // CallTranscripts: dedicated exception -> 404, fixed message.
    CallTranscriptIntegrationService::$throwOnDetail = new CallTranscriptNotFoundException('super secret internal detail');
    $result = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts\Processor::callBack([
        'action' => 'getRecord',
        'data' => ['id' => 1],
    ]);
    expectReadApi404($result->httpCode === 404, 'CallTranscripts not-found must map to 404');
    expectReadApi404(
        !str_contains(implode(' ', $result->messages['error'] ?? []), 'super secret internal detail'),
        'CallTranscripts 404 must not leak the exception message'
    );

    // Any other exception -> 500 generic, no leak.
    CallTranscriptIntegrationService::$throwOnDetail = new \RuntimeException('db password is hunter2');
    $result = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts\Processor::callBack([
        'action' => 'getRecord',
        'data' => ['id' => 1],
    ]);
    expectReadApi404($result->httpCode === 500, 'CallTranscripts generic RuntimeException must map to 500, not 404');
    expectReadApi404(
        !str_contains(implode(' ', $result->messages['error'] ?? []), 'hunter2'),
        'CallTranscripts 500 must not leak the exception message'
    );
    CallTranscriptIntegrationService::$throwOnDetail = null;

    // Transcripts: same contract.
    TranscriptIntegrationService::$throwOnDetail = new TranscriptNotFoundException('internal id 42 missing');
    $result = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\Processor::callBack([
        'action' => 'getRecord',
        'data' => ['id' => 1],
    ]);
    expectReadApi404($result->httpCode === 404, 'Transcripts not-found must map to 404');
    expectReadApi404(
        !str_contains(implode(' ', $result->messages['error'] ?? []), 'internal id 42 missing'),
        'Transcripts 404 must not leak the exception message'
    );

    TranscriptIntegrationService::$throwOnDetail = new \RuntimeException('leaky detail');
    $result = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\Processor::callBack([
        'action' => 'getRecord',
        'data' => ['id' => 1],
    ]);
    expectReadApi404($result->httpCode === 500, 'Transcripts generic RuntimeException must map to 500, not 404');
    expectReadApi404(
        !str_contains(implode(' ', $result->messages['error'] ?? []), 'leaky detail'),
        'Transcripts 500 must not leak the exception message'
    );
    TranscriptIntegrationService::$throwOnDetail = null;

    $unknown = \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts\Processor::callBack(['action' => 'bogus']);
    expectReadApi404($unknown->httpCode === 400, 'Transcripts unknown action must be httpCode 400');

    echo "Transcript read API 404-only / no-leak contract OK\n";
}
