<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTSetting
    {
        public static string $engine = 'parakeet';
        public static int $presetReads = 0;

        public static function selectedLanguageHint(): string
        {
            return 'ru';
        }

        public static function selectedModelPreset(): array
        {
            self::$presetReads++;
            return self::$engine === 'parakeet'
                ? [
                    'model' => 'parakeet-tdt-0.6b-v3',
                    'repo' => 'FluidInference/parakeet-tdt-0.6b-v3-coreml',
                    'engine' => 'parakeet',
                    'artifact_type' => 'parakeet-coreml',
                    'source_url' => 'https://huggingface.co/FluidInference/parakeet-tdt-0.6b-v3-coreml',
                ]
                : [
                    'model' => 'large-v3-v20240930_turbo',
                    'repo' => 'argmaxinc/whisperkit-coreml',
                    'engine' => 'whisperKit',
                    'artifact_type' => 'whisperkit-coreml',
                    'source_url' => 'https://huggingface.co/argmaxinc/whisperkit-coreml/tree/0f63a7800b00dd0226abd051b906c246e1907482/openai_whisper-large-v3-v20240930_turbo',
                    'revision' => '0f63a7800b00dd0226abd051b906c246e1907482',
                    'sha256_manifest' => [
                        ['path' => 'openai_whisper-large-v3-v20240930_turbo/config.json', 'size' => 42, 'sha256' => str_repeat('a', 64)],
                    ],
                ];
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
    STTSetting::$presetReads = 0;
    $parakeet = $snapshotMethod->invoke(null);
    expectParakeetWorkerPayload(1, STTSetting::$presetReads, 'A job snapshot must read one immutable model preset');
    expectParakeetWorkerPayload('parakeet-tdt-0.6b-v3', $parakeet['model_id'] ?? null, 'Parakeet model_id changed');
    expectParakeetWorkerPayload('FluidInference/parakeet-tdt-0.6b-v3-coreml', $parakeet['model_repo'] ?? null, 'Parakeet model_repo changed');
    expectParakeetWorkerPayload('parakeet', $parakeet['model_engine'] ?? null, 'Parakeet model_engine changed');
    expectParakeetWorkerPayload('parakeet-coreml', $parakeet['model_artifact_type'] ?? null, 'Parakeet artifact type changed');
    expectParakeetWorkerPayload(true, array_key_exists('whisperkit_model', $parakeet), 'Parakeet snapshot must retain whisperkit_model');
    expectParakeetWorkerPayload(null, $parakeet['whisperkit_model'], 'Parakeet must expose whisperkit_model as null');
    expectParakeetWorkerPayload(null, $parakeet['model_revision'], 'Parakeet must not expose a Whisper revision');
    expectParakeetWorkerPayload(null, $parakeet['model_sha256_manifest'], 'Parakeet must not expose a Whisper manifest');
    expectParakeetWorkerPayload(['МикоПБХ', 'Иван Петров'], $parakeet['vocabulary_terms'] ?? null, 'Parakeet vocabulary terms must be preserved');

    $payload = $formatMethod->invoke(null, new STTJob(), $parakeet);
    expectParakeetWorkerPayload(
        [
            'id',
            'call_id',
            'file_url',
            'lease_token',
            'lease_expires_at',
            'language_hint',
            'model_id',
            'model_repo',
            'model_engine',
            'model_artifact_type',
            'whisperkit_model',
            'model_display_name',
            'model_revision',
            'model_sha256_manifest',
            'vocabulary_terms',
        ],
        array_keys($payload),
        'Worker v2 job field names or nesting changed'
    );
    expectParakeetWorkerPayload(null, $payload['whisperkit_model'], 'Formatted Parakeet job must preserve nullable whisperkit_model');
    expectParakeetWorkerPayload(['МикоПБХ', 'Иван Петров'], $payload['vocabulary_terms'], 'Formatted Parakeet job lost vocabulary terms');

    STTSetting::$engine = 'whisperKit';
    STTSetting::$presetReads = 0;
    $whisper = $snapshotMethod->invoke(null);
    expectParakeetWorkerPayload(1, STTSetting::$presetReads, 'A WhisperKit snapshot must read one immutable model preset');
    expectParakeetWorkerPayload('large-v3-v20240930_turbo', $whisper['whisperkit_model'] ?? null, 'WhisperKit must retain its runtime model field');
    expectParakeetWorkerPayload('whisperKit', $whisper['model_engine'] ?? null, 'WhisperKit model_engine changed');
    expectParakeetWorkerPayload('whisperkit-coreml', $whisper['model_artifact_type'] ?? null, 'WhisperKit artifact type changed');
    expectParakeetWorkerPayload('0f63a7800b00dd0226abd051b906c246e1907482', $whisper['model_revision'] ?? null, 'WhisperKit commit pin was lost');
    expectParakeetWorkerPayload(1, count($whisper['model_sha256_manifest'] ?? []), 'WhisperKit SHA-256 manifest was lost');

    echo "Parakeet worker payload contract OK\n";
}
