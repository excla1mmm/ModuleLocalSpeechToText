<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTSetting;

/**
 * The one model that summarizes calls on every worker platform: Gemma 3 4B in
 * GGUF Q4_K_M, run by a pinned llama.cpp llama-server on the worker. The file
 * is pinned by Hugging Face revision, size and SHA-256 like the STT models.
 */
final class SummaryModel
{
    public const string MODEL_ID = 'gemma-3-4b-it-Q4_K_M';
    public const string DISPLAY_NAME = 'Gemma 3 4B';
    public const string REPO = 'ggml-org/gemma-3-4b-it-GGUF';
    public const string REVISION = 'd0976223747697cb51e056d85c532013931fe52e';
    public const string FILE = 'gemma-3-4b-it-Q4_K_M.gguf';
    public const int FILE_SIZE = 2489757856;
    public const string FILE_SHA256 = '882e8d2db44dc554fb0ea5077cb7e4bc49e7342a1f0da57901c0802ea21a0863';
    public const int CONTEXT_TOKENS = 8192;

    // Operator-facing requirements shown in the settings tooltip: about 3 GB
    // resident at CONTEXT_TOKENS (measured) plus headroom, and the model file
    // plus the llama.cpp runtime on disk.
    public const int RAM_GB = 4;
    public const int DISK_GB = 3;

    /**
     * The `summarization` block of the worker processing settings.
     *
     * @return array{enabled: bool, model: array<string, mixed>}
     */
    public static function settingsPayload(): array
    {
        return [
            'enabled' => STTSetting::summarizationEnabled(),
            'model' => self::descriptor(),
        ];
    }

    /** @return array<string, mixed> */
    public static function descriptor(): array
    {
        return [
            'model_id' => self::MODEL_ID,
            'model_display_name' => self::DISPLAY_NAME,
            'model_repo' => self::REPO,
            'model_revision' => self::REVISION,
            'model_file' => self::FILE,
            'model_sha256_manifest' => [
                ['path' => self::FILE, 'size' => self::FILE_SIZE, 'sha256' => self::FILE_SHA256],
            ],
            'context_tokens' => self::CONTEXT_TOKENS,
        ];
    }
}
