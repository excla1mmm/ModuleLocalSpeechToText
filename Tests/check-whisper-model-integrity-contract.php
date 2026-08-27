<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
        public function setSource(string $source): void
        {
        }

        public function initialize(): void
        {
        }

        public static function findFirst(array $parameters = []): ?object
        {
            return null;
        }
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Models/STTSetting.php';

    function expectWhisperIntegrity(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $expectedRevisions = [
        'large-v3-turbo' => '0f63a7800b00dd0226abd051b906c246e1907482',
        'podlodka' => '33ba8cea6ef3ffc11ff0d9cd0f432f12aad9766c',
        'large-v3' => '0f63a7800b00dd0226abd051b906c246e1907482',
    ];

    foreach ($expectedRevisions as $presetId => $expectedRevision) {
        $preset = STTSetting::modelPresets()[$presetId] ?? null;
        expectWhisperIntegrity(is_array($preset), "Missing Whisper preset {$presetId}");
        expectWhisperIntegrity(
            ($preset['revision'] ?? null) === $expectedRevision,
            "Whisper preset {$presetId} must pin the reviewed repository commit"
        );

        $manifest = $preset['sha256_manifest'] ?? null;
        expectWhisperIntegrity(
            is_array($manifest) && $manifest !== [],
            "Whisper preset {$presetId} must provide a non-empty SHA-256 manifest"
        );

        $seenPaths = [];
        foreach ($manifest as $entry) {
            expectWhisperIntegrity(is_array($entry), "Manifest entry for {$presetId} must be an object");
            $path = $entry['path'] ?? null;
            $size = $entry['size'] ?? null;
            $sha256 = $entry['sha256'] ?? null;
            expectWhisperIntegrity(
                is_string($path)
                    && $path !== ''
                    && !str_starts_with($path, '/')
                    && !str_contains($path, '\\')
                    && !in_array('..', explode('/', $path), true),
                "Manifest path for {$presetId} is unsafe"
            );
            expectWhisperIntegrity(
                is_int($size) && $size > 0,
                "Manifest size for {$presetId}/{$path} must be a positive integer"
            );
            expectWhisperIntegrity(
                is_string($sha256) && preg_match('/^[a-f0-9]{64}$/D', $sha256) === 1,
                "Manifest digest for {$presetId}/{$path} must be lowercase SHA-256"
            );
            expectWhisperIntegrity(
                !isset($seenPaths[$path]),
                "Manifest path {$path} is duplicated for {$presetId}"
            );
            $seenPaths[$path] = true;
        }

        expectWhisperIntegrity(
            str_contains((string)($preset['source_url'] ?? ''), $expectedRevision),
            "Whisper preset {$presetId} source URL must point at the pinned commit"
        );
    }

    echo "Whisper model integrity contract OK\n";
}
