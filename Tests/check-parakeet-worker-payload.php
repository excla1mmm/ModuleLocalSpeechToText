<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTSetting
    {
        public static string $engine = 'parakeet';

        public static function selectedLanguageHint(): string
        {
            return 'ru';
        }

        public static function selectedModelRuntimeId(): string
        {
            return self::$engine === 'parakeet' ? 'parakeet-tdt-0.6b-v3' : 'large-v3-v20240930_turbo';
        }

        public static function selectedModelRepo(): string
        {
            return self::$engine === 'parakeet'
                ? 'FluidInference/parakeet-tdt-0.6b-v3-coreml'
                : 'argmaxinc/whisperkit-coreml';
        }

        public static function selectedModelEngine(): string
        {
            return self::$engine;
        }

        public static function selectedModelArtifactType(): string
        {
            return self::$engine === 'parakeet' ? 'parakeet-coreml' : 'whisperkit-coreml';
        }

        public static function selectedModelDisplayName(): string
        {
            return self::selectedModelRepo() . '/' . self::selectedModelRuntimeId();
        }

        public static function selectedModelSourceUrl(): string
        {
            return self::$engine === 'parakeet'
                ? 'https://huggingface.co/FluidInference/parakeet-tdt-0.6b-v3-coreml'
                : 'https://huggingface.co/argmaxinc/whisperkit-coreml/tree/main/openai_whisper-large-v3-v20240930_turbo';
        }

        public static function selectedVocabularyTerms(): array
        {
            return ['МикоПБХ', 'Иван Петров'];
        }
    }

    final class STTJob
    {
        public int $id = 42;
        public string $call_id = 'call-42';
        public string $lease_token = 'lease-token';
        public string $lease_expires_at = '1780000000';
        public int $recording_duration_sec = 90;
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Lib/LocalSpeechToTextMain.php';

    function expectParakeetWorkerPayload(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    $snapshotMethod = new ReflectionMethod(LocalSpeechToTextMain::class, 'prepareJobSnapshot');
    $formatMethod = new ReflectionMethod(LocalSpeechToTextMain::class, 'formatJob');

    STTSetting::$engine = 'parakeet';
    $parakeet = $snapshotMethod->invoke(null);
    expectParakeetWorkerPayload('parakeet-tdt-0.6b-v3', $parakeet['model_id'] ?? null, 'Parakeet model_id changed');
    expectParakeetWorkerPayload('FluidInference/parakeet-tdt-0.6b-v3-coreml', $parakeet['model_repo'] ?? null, 'Parakeet model_repo changed');
    expectParakeetWorkerPayload('parakeet', $parakeet['model_engine'] ?? null, 'Parakeet model_engine changed');
    expectParakeetWorkerPayload('parakeet-coreml', $parakeet['model_artifact_type'] ?? null, 'Parakeet artifact type changed');
    expectParakeetWorkerPayload(true, array_key_exists('whisperkit_model', $parakeet), 'Parakeet snapshot must retain whisperkit_model');
    expectParakeetWorkerPayload(null, $parakeet['whisperkit_model'], 'Parakeet must expose whisperkit_model as null');
    expectParakeetWorkerPayload(['МикоПБХ', 'Иван Петров'], $parakeet['vocabulary_terms'] ?? null, 'Parakeet vocabulary terms must be preserved');

    $payload = $formatMethod->invoke(null, new STTJob(), $parakeet);
    expectParakeetWorkerPayload(
        [
            'id',
            'call_id',
            'file_url',
            'lease_token',
            'lease_expires_at',
            'duration_hint',
            'language_hint',
            'model_id',
            'model_repo',
            'model_engine',
            'model_artifact_type',
            'whisperkit_model',
            'model_display_name',
            'model_source_url',
            'vocabulary_terms',
        ],
        array_keys($payload),
        'Worker v2 job field names or nesting changed'
    );
    expectParakeetWorkerPayload(null, $payload['whisperkit_model'], 'Formatted Parakeet job must preserve nullable whisperkit_model');
    expectParakeetWorkerPayload(['МикоПБХ', 'Иван Петров'], $payload['vocabulary_terms'], 'Formatted Parakeet job lost vocabulary terms');

    STTSetting::$engine = 'whisperKit';
    $whisper = $snapshotMethod->invoke(null);
    expectParakeetWorkerPayload('large-v3-v20240930_turbo', $whisper['whisperkit_model'] ?? null, 'WhisperKit must retain its runtime model field');
    expectParakeetWorkerPayload('whisperKit', $whisper['model_engine'] ?? null, 'WhisperKit model_engine changed');
    expectParakeetWorkerPayload('whisperkit-coreml', $whisper['model_artifact_type'] ?? null, 'WhisperKit artifact type changed');

    echo "Parakeet worker payload contract OK\n";
}
