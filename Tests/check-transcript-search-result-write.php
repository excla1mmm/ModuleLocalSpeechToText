<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
        public function setSource(string $source): void {}
        public function initialize(): void {}
        public function belongsTo(...$arguments): void {}
        public static function findFirst(array $parameters = []): ?object { return null; }
        public function save(): bool { return true; }
        public function getMessages(): array { return []; }
    }
}

namespace Phalcon\Mvc\Model {
    final class Relation
    {
        public const int NO_ACTION = 0;
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    class STTJob
    {
        public int $id = 7;
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTResult;

    require_once __DIR__ . '/../Lib/Services/TranscriptSearchText.php';
    require_once __DIR__ . '/../Models/STTResult.php';

    $transcript = json_encode([['text' => 'Ёлка готова']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $result = STTResult::saveForJob(new STTJob(), $transcript, 'ru', 1.0, 'test');

    if (($result->search_text ?? null) !== 'елка готова') {
        throw new RuntimeException('Saving a transcript must atomically populate normalized FTS text');
    }

    echo "Transcript search result write checks passed\n";
}
