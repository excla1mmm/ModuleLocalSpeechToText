<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib {
    final class PBXApiResult
    {
        public bool $success = false;
        public int $httpCode = 0;
        public array $data = [];
        public array $messages = [];
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTSetting
    {
        public static function workerApiKeyIds(): array { return [91]; }
        public static function isWorkerApiKeyIdAllowed(int $id): bool { return $id === 91; }
    }

    final class STTWorker
    {
        public static function findFirst(array $parameters): ?self { return null; }
        public function getWriteConnection(): object { return (object)[]; }
    }

    final class STTJob {}
    final class STTResult {}
    final class STTTranscriptEvent {}
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    use Modules\ModuleLocalSpeechToText\Lib\Exceptions\WorkerApiKeyBindingConflictException;

    final class WorkerRegistrationTransaction
    {
        public function run(mixed ...$arguments): never
        {
            throw new WorkerApiKeyBindingConflictException();
        }
    }

    final class WorkerApiContract { public const int API_VERSION = 2; }
    final class STTWorkerResourceService {}
    final class LocalSpeechToTextLogger {}
    final class LocalSpeechToTextLogSanitizer {}
    final class ModuleTransaction {}
    final class WorkerProcessingSettingsService {}
    final class CallTranscriptRevisionService {}
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;

    require_once __DIR__ . '/../Lib/Exceptions/WorkerApiKeyBindingConflictException.php';
    require_once __DIR__ . '/../Lib/Services/JobApiResponseBuilder.php';require_once __DIR__ . '/../Lib/Services/WorkerAuthorizationValidator.php';require_once __DIR__ . '/../Lib/Services/JobResultPayloadNormalizer.php';require_once __DIR__ . '/../Lib/LocalSpeechToTextMain.php';

    $result = LocalSpeechToTextMain::registerWorker([
        'worker_uid' => 'race-loser',
        '_auth_type' => 'bearer_token',
        '_api_key_id' => 91,
    ]);
    if ($result->success
        || $result->httpCode !== 409
        || ($result->data['error_code'] ?? null) !== 'worker_api_key_already_bound') {
        throw new RuntimeException(
            'Registration binding loser was not mapped to HTTP 409 without mutation: '
            . json_encode([$result->httpCode, $result->data, $result->messages])
        );
    }

    echo "STT worker registration conflict result OK\n";
}
