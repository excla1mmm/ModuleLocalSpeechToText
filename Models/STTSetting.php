<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use RuntimeException;

/**
 * @Indexes(
 *     [name='key', columns=['key'], type='UNIQUE']
 * )
 */
class STTSetting extends ModulesModelsBase
{
    public const string SOURCE_INSTANCE_ID = 'source_instance_id';
    public const string DEFAULT_LANGUAGE = 'default_language';
    public const string CDR_HISTORY_DAYS = 'cdr_history_days';
    public const string TRANSCRIPT_RETENTION_DAYS = 'transcript_retention_days';
    public const string TRANSCRIPT_RETENTION_LAST_CLEANUP_AT = 'transcript_retention_last_cleanup_at';
    public const string CALL_TRANSCRIPT_STABILITY_SEC = 'call_transcript_stability_sec';
    // The model chosen last on any platform. It predates per-platform models:
    // it still names the selected model of its own platform when that
    // platform has no SELECTED_MODEL_PREFIX key yet.
    public const string SELECTED_MODEL = 'selected_model';
    // selected_model_macos, selected_model_linux, selected_model_windows.
    public const string SELECTED_MODEL_PREFIX = 'selected_model_';
    public const string CUSTOM_VOCABULARY_TERMS = 'custom_vocabulary_terms';
    public const string DELAYED_RECORDINGS = 'delayed_recordings';
    public const string SKIPPED_RECORDINGS = 'skipped_recordings';
    public const string CDR_SCAN_CURSOR_ENDTIME = 'cdr_scan_cursor_endtime';
    public const string CDR_SCAN_CURSOR_ID = 'cdr_scan_cursor_id';
    // One-time migration marker for the realtime scan cursor: absent/not 'id'
    // means the stored CDR_SCAN_CURSOR_ID still follows the legacy
    // (endtime, id) ordering and must be re-derived before it can be used as
    // a plain id watermark (see
    // WorkerLocalSpeechToTextJobs::initializeRealtimeScanCursor()). Once set
    // to 'id', the cursor is a strict id watermark forever after.
    public const string CDR_SCAN_CURSOR_MODE = 'cdr_scan_cursor_mode';
    public const string CDR_SCAN_CURSOR_MODE_ID = 'id';
    public const string CDR_BACKFILL_CURSOR_ENDTIME = 'cdr_backfill_cursor_endtime';
    public const string CDR_BACKFILL_CURSOR_ID = 'cdr_backfill_cursor_id';
    public const string CDR_BACKFILL_DONE = 'cdr_backfill_done';
    public const string CDR_BACKFILL_DONE_HISTORY_AT = 'cdr_backfill_done_history_at';
    public const string WORKER_API_KEY_ID = 'worker_api_key_id';
    public const string WORKER_API_KEY_IDS = 'worker_api_key_ids';
    public const string WORKER_API_KEY_HISTORY_IDS = 'worker_api_key_history_ids';
    public const string WORKER_API_CONTRACT_MIGRATION_V2 = 'worker_api_contract_migration_v2';
    public const string TEMPERATURE = 'temperature';
    public const string TEMPERATURE_FALLBACK_STEP = 'temperature_fallback_step';
    public const string TEMPERATURE_FALLBACK_COUNT = 'temperature_fallback_count';
    public const string TOP_K = 'top_k';
    public const string COMPRESSION_RATIO_THRESHOLD = 'compression_ratio_threshold';
    public const string LOG_PROBABILITY_THRESHOLD = 'log_probability_threshold';
    public const string FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD = 'first_token_log_probability_threshold';
    public const string NO_SPEECH_THRESHOLD = 'no_speech_threshold';
    public const string AUDIO_NORMALIZATION = 'audio_normalization';
    public const string VOICE_ACTIVITY_DETECTION = 'voice_activity_detection';
    public const string MAX_SEGMENT_DURATION_MS = 'max_segment_duration_ms';
    public const string SEGMENT_OVERLAP_MS = 'segment_overlap_ms';
    // Call summarization: '1' turns it on. SUMMARIZATION_ENABLED_AT is the unix
    // time of the last off->on switch; only transcripts created since then are
    // summarized.
    public const string SUMMARIZATION_ENABLED = 'summarization_enabled';
    public const string SUMMARIZATION_ENABLED_AT = 'summarization_enabled_at';

    public const string DEFAULT_MODEL = 'parakeet-tdt-0.6b-v3';
    public const string AUTO_LANGUAGE = 'auto';

    // Worker platforms. Every platform has its own selected model, so workers
    // of different platforms run side by side, each with the model of its
    // platform. Workers that predate platform reporting (macOS worker 1.7 and
    // older) send no platform and are treated as macOS.
    public const string PLATFORM_MACOS = 'macos';
    public const string PLATFORM_LINUX = 'linux';
    public const string PLATFORM_WINDOWS = 'windows';
    // Every platform the worker API serves (worker_platform, model_platform,
    // required_platform).
    public const array WORKER_PLATFORMS = [
        self::PLATFORM_MACOS,
        self::PLATFORM_LINUX,
        self::PLATFORM_WINDOWS,
    ];
    // Platforms offered by the model marketplace platform switch.
    public const array PLATFORM_PRESETS = [
        self::PLATFORM_MACOS => 'module_local_speech_to_text_PlatformMacos',
        self::PLATFORM_LINUX => 'module_local_speech_to_text_PlatformLinux',
        self::PLATFORM_WINDOWS => 'module_local_speech_to_text_PlatformWindows',
    ];
    public const array PLATFORM_DEFAULT_MODELS = [
        self::PLATFORM_MACOS => self::DEFAULT_MODEL,
        self::PLATFORM_LINUX => 'gigaam-v3',
        self::PLATFORM_WINDOWS => 'gigaam-v3-windows',
    ];

    // Recording duration hard limit: an internal safety policy, not a user setting.
    // It bounds worker memory usage and prevents a single long recording from
    // monopolizing a worker; see README.ru.md / README.md for the operator-facing note.
    private const int RECORDING_DURATION_HARD_LIMIT_MINUTES = 180;
    private const int RECORDING_DURATION_HARD_LIMIT_SECONDS = 10800;

    private const int FIXED_MAX_FILE_SIZE_BYTES = 500 * 1024 * 1024;
    private const int DEFAULT_CDR_HISTORY_DAYS = 30;
    private const int MAX_CDR_HISTORY_DAYS = 365;
    private const int DEFAULT_TRANSCRIPT_RETENTION_DAYS = 365;

    public const array CDR_HISTORY_PRESETS = [1, 7, 30, 90, 180, 365, 0];
    public const array TRANSCRIPT_RETENTION_PRESETS = [30, 90, 180, 365, 0];

    public const array LANGUAGE_PRESETS = [
        'auto' => 'module_local_speech_to_text_LanguageAuto',
        'ru' => 'module_local_speech_to_text_LanguageRussian',
        'en' => 'module_local_speech_to_text_LanguageEnglish',
        'de' => 'module_local_speech_to_text_LanguageGerman',
        'es' => 'module_local_speech_to_text_LanguageSpanish',
        'fr' => 'module_local_speech_to_text_LanguageFrench',
        'it' => 'module_local_speech_to_text_LanguageItalian',
        'pt' => 'module_local_speech_to_text_LanguagePortuguese',
        'zh' => 'module_local_speech_to_text_LanguageChinese',
        'ja' => 'module_local_speech_to_text_LanguageJapanese',
        'ko' => 'module_local_speech_to_text_LanguageKorean',
        'ar' => 'module_local_speech_to_text_LanguageArabic',
        'tr' => 'module_local_speech_to_text_LanguageTurkish',
        'pl' => 'module_local_speech_to_text_LanguagePolish',
        'uk' => 'module_local_speech_to_text_LanguageUkrainian',
        'nl' => 'module_local_speech_to_text_LanguageDutch',
        'hi' => 'module_local_speech_to_text_LanguageHindi',
        'id' => 'module_local_speech_to_text_LanguageIndonesian',
        'vi' => 'module_local_speech_to_text_LanguageVietnamese',
        'th' => 'module_local_speech_to_text_LanguageThai',
        'he' => 'module_local_speech_to_text_LanguageHebrew',
    ];

    public const array PARAKEET_LANGUAGE_IDS = [
        'bg', 'hr', 'cs', 'da', 'nl', 'en', 'et', 'fi', 'fr', 'de', 'el', 'hu', 'it',
        'lv', 'lt', 'mt', 'pl', 'pt', 'ro', 'sk', 'sl', 'es', 'sv', 'ru', 'uk',
    ];

    public const array RUSSIAN_LANGUAGE_IDS = ['ru'];
    // Kazakh, Kyrgyz and Uzbek are not in the hint list; the model needs no hint for them (Auto).
    public const array GIGAAM_MULTILINGUAL_LANGUAGE_IDS = ['ru', 'en'];

    public const string ARGMAX_WHISPERKIT_REVISION = '0f63a7800b00dd0226abd051b906c246e1907482';
    public const string PODLODKA_WHISPERKIT_REVISION = '33ba8cea6ef3ffc11ff0d9cd0f432f12aad9766c';

    public const array WHISPER_SHA256_MANIFESTS = [
        'large-v3-turbo' => [
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/AudioEncoder.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => 'b58d36a7f4a729570b46b424ed8d847baefa07580e6cb9d47773ae738f8b845a'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/AudioEncoder.mlmodelc/coremldata.bin', 'size' => 348, 'sha256' => 'ffa9eb76e8e9d9be75a4d527e5249e61d67fd43081c5aa110fd24efa6c8c5ea3'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/AudioEncoder.mlmodelc/metadata.json', 'size' => 1824, 'sha256' => '3f8920fecd553c40dfc978e1b2664cefbac80d97937c2c160e079b37cfa95e13'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/AudioEncoder.mlmodelc/model.mil', 'size' => 7175750, 'sha256' => '9aac7799f12bc5fc414cb0bd6b60536d4fb7723d8bb0d879e3c0ceb220b224aa'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/AudioEncoder.mlmodelc/model.mlmodel', 'size' => 441653, 'sha256' => 'c5eb570c19871d7b0c59e0a84718cc9c8cde77a94273886de87e866961262e2c'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/AudioEncoder.mlmodelc/weights/weight.bin', 'size' => 1273974400, 'sha256' => '98daf651a919978e28fe185daf55ce2f70085a8e59fa07fe8a4d08c87d368ae4'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/MelSpectrogram.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => 'c5be419f8622083ac7046306400643539f0e7577c843448c36defc090d41e7ce'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/MelSpectrogram.mlmodelc/coremldata.bin', 'size' => 329, 'sha256' => '98efa1e351b759e078c4044668926d32bee886caf7596ae897e08e21da45565a'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/MelSpectrogram.mlmodelc/metadata.json', 'size' => 1850, 'sha256' => '2bc552e09a6f124d9e6c178dd1a6979e010206acb26308b2224887c9dcbeb35f'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/MelSpectrogram.mlmodelc/model.mil', 'size' => 10143, 'sha256' => 'c270b95b5f81d7f7d0b8a3e8f991d4e5812a37cad29349868a35b91f3a6a4463'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/MelSpectrogram.mlmodelc/weights/weight.bin', 'size' => 373376, 'sha256' => '009d9fb8f6b589accfa08cebf1c712ef07c3405229ce3cfb3a57ee033c9d8a49'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoder.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => '47703aed03fbfa5128e118cfe6519024006f953a53e921d66003f1412c27996c'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoder.mlmodelc/coremldata.bin', 'size' => 633, 'sha256' => '605dad4099a82cf2c7afe93e6d8e322f1c16d4160ab27bd017ec2517b81c1bdd'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoder.mlmodelc/metadata.json', 'size' => 4756, 'sha256' => 'de6afb1e8fa1d01568b8d96283ca17af19f6124f151d0fdbdbd5917edc7b4836'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoder.mlmodelc/model.mil', 'size' => 132680, 'sha256' => '1a1c2ec962fc7c9d2de9e2e4cf2d3827c8671f86a918b5698c00bf62c322ed3f'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoder.mlmodelc/model.mlmodel', 'size' => 113164, 'sha256' => '03b79e4355e814c56239ea809e5d8f6432d70d74256bc9d70c27184fe9fcec48'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoder.mlmodelc/weights/weight.bin', 'size' => 343933748, 'sha256' => '47b2703aa37448e09cf2f06e45984fabd5ded4c34ba3400cec38a5294af39dc1'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoderContextPrefill.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => '97639d36c7b137ea51c3c39b175911788f4d4a601ab03cd67a4b14164c3145e1'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoderContextPrefill.mlmodelc/coremldata.bin', 'size' => 380, 'sha256' => '2c159f5c862ec187092ea58e755d8c0b298952e22f3d75da023d7693c1c7389e'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoderContextPrefill.mlmodelc/metadata.json', 'size' => 2240, 'sha256' => 'eb88dc350fa6748a8bc3fa5fb10958152c138752ebbbac1824d2f99b4c9fc068'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoderContextPrefill.mlmodelc/model.mil', 'size' => 4092, 'sha256' => '990ff5052fd817e28ba7c34d9d06d324c69c7c0630b6eaac9cfdf08329dbcb34'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/TextDecoderContextPrefill.mlmodelc/weights/weight.bin', 'size' => 12288192, 'sha256' => '1310070082639173e9d81508c5f220692d489e85655aa6883cc1c7506da7fcfd'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/config.json', 'size' => 1149, 'sha256' => 'f01d83dd891791d6f12421c05d3ed8ebbe70866f10d6c9a7a7e80b558ce5a0f1'],
            ['path' => 'openai_whisper-large-v3-v20240930_turbo/generation_config.json', 'size' => 2767, 'sha256' => '7fbb053a023be11fbeccd8421811610308143daa93d9617c52aab4a0fa1491c6'],
        ],
        'podlodka' => [
            ['path' => 'AudioEncoder.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => '2cf55d7b02560d2563e6014465169668d7506512c6d3cff841bc2c3732aaa9db'],
            ['path' => 'AudioEncoder.mlmodelc/coremldata.bin', 'size' => 409, 'sha256' => '7565e6f4b2815116238b72cb7e7bb35ba52542971667d70430797ed535e9c678'],
            ['path' => 'AudioEncoder.mlmodelc/metadata.json', 'size' => 1923, 'sha256' => 'f73d1040a3a87cf969855ed49ec7b4121885b260746ebfb94d35d379d71e62d5'],
            ['path' => 'AudioEncoder.mlmodelc/model.mil', 'size' => 9747079, 'sha256' => '2a55370e6827e99ba268cf912b2fd52985014f159a0f6e9a181003b907b4aab7'],
            ['path' => 'AudioEncoder.mlmodelc/weights/weight.bin', 'size' => 1273974400, 'sha256' => '88dc1d9f9f0fc7fe37823517feba8ff9a481d9ed98e66e9d4dd64d493f8c9c97'],
            ['path' => 'MelSpectrogram.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => '44a1d5d866ba3be835608fc39fb4e2a4c8d44b63bcaf3d864b5383a3a7bd5b9f'],
            ['path' => 'MelSpectrogram.mlmodelc/coremldata.bin', 'size' => 390, 'sha256' => '5251ef499d7212b4083acdc2e4cdaaf7ab7e9442c2ccea16023867f6b3b8dc9a'],
            ['path' => 'MelSpectrogram.mlmodelc/metadata.json', 'size' => 1949, 'sha256' => '5ae0d5a81fdd95ac71a76f3987d470792a9f6e876ec99a63307e61ca388b7c11'],
            ['path' => 'MelSpectrogram.mlmodelc/model.mil', 'size' => 10143, 'sha256' => '9b926b119b5a54765a095f42b3f66380543497923483efdaf76502d45e9bd868'],
            ['path' => 'MelSpectrogram.mlmodelc/weights/weight.bin', 'size' => 373376, 'sha256' => '009d9fb8f6b589accfa08cebf1c712ef07c3405229ce3cfb3a57ee033c9d8a49'],
            ['path' => 'TextDecoder.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => '1c83f0fbf8c9d5e4f8a00725af2d93b9a6af34434bf673f85c42dbeef1ce97a6'],
            ['path' => 'TextDecoder.mlmodelc/coremldata.bin', 'size' => 694, 'sha256' => '6798d379b4ec621b53d87019bf95714eda44beeaae069bbb8e57afafea0b3268'],
            ['path' => 'TextDecoder.mlmodelc/metadata.json', 'size' => 4855, 'sha256' => 'be3f17d4e00c48803c1ba1912f3943ad2041f208520575ac1f8a6f464dac6838'],
            ['path' => 'TextDecoder.mlmodelc/model.mil', 'size' => 132737, 'sha256' => '04f294a6c38978067acb63207b35b74951b7ca936b80bfe0d6cbd36c80a1b0da'],
            ['path' => 'TextDecoder.mlmodelc/weights/weight.bin', 'size' => 343933748, 'sha256' => '1044368e7b46f2b1d7e904042a90e95d352dc2849232364cbd6b3c2095226dee'],
            ['path' => 'config.json', 'size' => 1255, 'sha256' => 'c8a115f749498ee22ceb60285aa86f1eb1959f654741641aec275fb10b24e376'],
            ['path' => 'generation_config.json', 'size' => 3771, 'sha256' => '1db97f5ab6a3ade6f82ef9a0de9613a98e2f5f525aaee053bc2f5e71c397323f'],
            ['path' => 'tokenizer.json', 'size' => 2710337, 'sha256' => '297b13372ac43916285644fb9687add3cc62ee2a1adb60da3dc25cc94c1871fd'],
            ['path' => 'tokenizer_config.json', 'size' => 282843, 'sha256' => '844b642c73a91359722f47b35705f7174686df33d252695d8572cf9ac03a6389'],
        ],
        'large-v3' => [
            ['path' => 'openai_whisper-large-v3/AudioEncoder.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => '195e35e9fbaa218cd59eca69988a2165245e5bfccc4a5d9776847e8955d1625a'],
            ['path' => 'openai_whisper-large-v3/AudioEncoder.mlmodelc/coremldata.bin', 'size' => 348, 'sha256' => '273d6cd004f95763e9d03e5d36622f11038819a81b9eafed64b1d95444e04f62'],
            ['path' => 'openai_whisper-large-v3/AudioEncoder.mlmodelc/metadata.json', 'size' => 1826, 'sha256' => 'db0d80a1046e03e211928cd2918d5e5924947f4ecbcee5232b125f0f59757fbc'],
            ['path' => 'openai_whisper-large-v3/AudioEncoder.mlmodelc/model.mil', 'size' => 581035, 'sha256' => '3e7737df445ef8fc13238970e3e518c0c6455bcadb6371df2ede1f22dcb8a8e0'],
            ['path' => 'openai_whisper-large-v3/AudioEncoder.mlmodelc/model.mlmodel', 'size' => 408667, 'sha256' => 'bf0987c0d8c3fe180877b12ba5b4ac1d890e011f2103926e53186089feced575'],
            ['path' => 'openai_whisper-large-v3/AudioEncoder.mlmodelc/weights/weight.bin', 'size' => 1273974400, 'sha256' => 'eb07bab32dcd62ce653b5b288bd6c27bdc5a538be309f242e33ed05e1cb53457'],
            ['path' => 'openai_whisper-large-v3/MelSpectrogram.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => '091a361134891f94e613562771beea0d93a9aefbc6984ba86c60f856e07a508f'],
            ['path' => 'openai_whisper-large-v3/MelSpectrogram.mlmodelc/coremldata.bin', 'size' => 329, 'sha256' => 'f3c5778c86d6fbc6a9817a56dbcac05a946a4d95c77f6db8355572f3be9e9a68'],
            ['path' => 'openai_whisper-large-v3/MelSpectrogram.mlmodelc/metadata.json', 'size' => 1850, 'sha256' => '1a94f4dfaec25549cbb386fe59b68c67a7a5af4a1c672a5998e5a84b9111135b'],
            ['path' => 'openai_whisper-large-v3/MelSpectrogram.mlmodelc/model.mil', 'size' => 10187, 'sha256' => '17977ed2332430c6cc4c1da2516f2cd4deb662d65ae38d72725ab157f32d4949'],
            ['path' => 'openai_whisper-large-v3/MelSpectrogram.mlmodelc/weights/weight.bin', 'size' => 373376, 'sha256' => '97a66b915cd3fc97dcba6806d92381e1a56024b8f68c1a1cd370d4c92505fe87'],
            ['path' => 'openai_whisper-large-v3/TextDecoder.mlmodelc/analytics/coremldata.bin', 'size' => 243, 'sha256' => '7c151c6259c279aa7922d60e82c7851bbad1df10018b1cebc566aa6c2aee5e0f'],
            ['path' => 'openai_whisper-large-v3/TextDecoder.mlmodelc/coremldata.bin', 'size' => 637, 'sha256' => 'f41a7939b47f7cf127aa69dfd8c552a141dc773b936bf8621aeffcd201fb9e30'],
            ['path' => 'openai_whisper-large-v3/TextDecoder.mlmodelc/metadata.json', 'size' => 4770, 'sha256' => 'de900a968f7640e9775c28268e348a0bc6559a4a92defae07d91e7effa838246'],
            ['path' => 'openai_whisper-large-v3/TextDecoder.mlmodelc/model.mil', 'size' => 1010477, 'sha256' => 'f8273cbf61aac4a43118a9090fc4d580678ea04abda473b4cb9dda2647d25c27'],
            ['path' => 'openai_whisper-large-v3/TextDecoder.mlmodelc/model.mlmodel', 'size' => 745579, 'sha256' => '680d8dfb7c7d8f1f852071ac7bdc96e55c696d0b822e70861045efb426b0a27b'],
            ['path' => 'openai_whisper-large-v3/TextDecoder.mlmodelc/weights/weight.bin', 'size' => 1813201716, 'sha256' => '680f398925225a313c62da0221aa0a58c9f1bffac5c36f20c449a70a7c9b1e55'],
            ['path' => 'openai_whisper-large-v3/config.json', 'size' => 1163, 'sha256' => '798b69c08cf93b2b03d94bea6eb3eb25fd4712259712d8a62ed2483fdf818a9e'],
            ['path' => 'openai_whisper-large-v3/generation_config.json', 'size' => 2810, 'sha256' => 'd24f9cca0f448609a71ae044b736023706382f45e9700e0dffb2559d10cf1fea'],
        ],
    ];

    // Linux models: pinned Hugging Face commits and every file the engine loads.
    public const array LINUX_MODEL_REVISIONS = [
        'gigaam-v3' => '322c3b29492673eb7d0b434bfa9dfb8653e34d02',
        't-one' => '106f3b0b32a9e107eb613312e4ebc61ff3d53926',
        'faster-whisper-large-v3-turbo' => '0a363e9161cbc7ed1431c9597a8ceaf0c4f78fcf',
        'parakeet-tdt-0.6b-v3-linux' => '8f23f0c03c8761650bdb5b40aaf3e40d2c15f1ce',
        'gigaam-multilingual' => '458860e1983aef670dd9795fb6af603c82767d5d',
    ];

    // whisper.cpp GGML models (Windows): pinned Hugging Face commit and file.
    public const string WHISPER_CPP_REVISION = '5359861c739e955e79d9a303bcbc70fb988958b1';

    public const array WHISPER_CPP_SHA256_MANIFESTS = [
        'large-v3-turbo' => [
            ['path' => 'ggml-large-v3-turbo.bin', 'size' => 1624555275, 'sha256' => '1fc70f774d38eb169993ac391eea357ef47c88757ef72ee5943879b7e8e2bc69'],
        ],
    ];

    public const array LINUX_SHA256_MANIFESTS = [
        'gigaam-v3' => [
            ['path' => 'config.json', 'size' => 135, 'sha256' => '0641fcf73f4af791c73f05083e38a658ff5dcbee3534a0c61396c10f4b97f0fe'],
            ['path' => 'v3_e2e_rnnt_decoder.onnx', 'size' => 4599910, 'sha256' => '7b0a16d67fd2cb37061decc93c69e364a9ab27afee3c57495d55b1c974cf7231'],
            ['path' => 'v3_e2e_rnnt_encoder.onnx', 'size' => 885084534, 'sha256' => 'cd60b3764a832e8560ae6d3ad0b10adc1a42ffae412b9476f25620aae4f4a508'],
            ['path' => 'v3_e2e_rnnt_joint.onnx', 'size' => 2712896, 'sha256' => '602ff7017a93311aad34df1437c8d7f49911353c13d6eae7a6ee7b041339465c'],
            ['path' => 'v3_e2e_rnnt_vocab.txt', 'size' => 13354, 'sha256' => '39abae20e692998290c574e606f11a9edef2902a1995463fcff63d1490cf22b7'],
        ],
        't-one' => [
            ['path' => 'model.onnx', 'size' => 144193371, 'sha256' => '707e4a282d5036304a0b8603ad83945840c3075f53c49911ef88c19bcc5a7d52'],
            ['path' => 'kenlm.bin', 'size' => 5463477004, 'sha256' => '8c31a489a51a6e9236112dacb6bed12f45e8df734057615fa6bf220a5a769a1d'],
        ],
        'faster-whisper-large-v3-turbo' => [
            ['path' => 'config.json', 'size' => 2263, 'sha256' => 'b0253ea6c0d3bea6b1e19e91a02acfd3b53f4467362efcb5a3e6b16c9b3a9b7e'],
            ['path' => 'model.bin', 'size' => 1617884929, 'sha256' => 'e76620f83d5f5b69efd3d87e3dc180c1bd21df9fbebacfd4335e5e1efcc018da'],
            ['path' => 'preprocessor_config.json', 'size' => 340, 'sha256' => '7ccc62c6f2765af1f3b46c00c9b5894426835a05021c8b9c01eecb6dfb542711'],
            ['path' => 'tokenizer.json', 'size' => 2710337, 'sha256' => '297b13372ac43916285644fb9687add3cc62ee2a1adb60da3dc25cc94c1871fd'],
            ['path' => 'vocabulary.json', 'size' => 1068114, 'sha256' => 'c69260f2ab26d659b7c398f9a2b2b48ed0df16c3b47d7326782fd9cba71690c1'],
        ],
        'parakeet-tdt-0.6b-v3-linux' => [
            ['path' => 'config.json', 'size' => 97, 'sha256' => '666903c76b9798caf2c210afd4f6cd60b08a8dbf9800ec8d7a3bc0d2148ac466'],
            ['path' => 'decoder_joint-model.onnx', 'size' => 72520893, 'sha256' => 'e978ddf6688527182c10fde2eb4b83068421648985ef23f7a86be732be8706c1'],
            ['path' => 'encoder-model.onnx', 'size' => 41770866, 'sha256' => '98a74b21b4cc0017c1e7030319a4a96f4a9506e50f0708f3a516d02a77c96bb1'],
            ['path' => 'encoder-model.onnx.data', 'size' => 2435420160, 'sha256' => '9a22d372c51455c34f13405da2520baefb7125bd16981397561423ed32d24f36'],
            ['path' => 'vocab.txt', 'size' => 93939, 'sha256' => 'd58544679ea4bc6ac563d1f545eb7d474bd6cfa467f0a6e2c1dc1c7d37e3c35d'],
        ],
        'gigaam-multilingual' => [
            ['path' => 'config.json', 'size' => 152, 'sha256' => 'd82b4672db0e04db93620ce41081115439ab315abf4f6dbf389a1aa1f0336779'],
            ['path' => 'multilingual_ctc.onnx', 'size' => 885388622, 'sha256' => '8bc803289f9cb5147ee95451fd9bdba219b1ecf1ddcd59a3651177c103c9eeec'],
            ['path' => 'multilingual_vocab.txt', 'size' => 393, 'sha256' => '4d130287892e1099fedfb3f93c4b4cf8a263151158801680b28977d1be4133f4'],
        ],
    ];

    public const array MODEL_PRESETS = [
        'large-v3-turbo' => [
            'platform' => self::PLATFORM_MACOS,
            'repo' => 'argmaxinc/whisperkit-coreml',
            'model' => 'large-v3-v20240930_turbo',
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'revision' => self::ARGMAX_WHISPERKIT_REVISION,
            'sha256_manifest' => self::WHISPER_SHA256_MANIFESTS['large-v3-turbo'],
            'display_name' => 'Whisper Large V3 Turbo',
            'label' => 'module_local_speech_to_text_ModelLargeV3Turbo',
            'description' => 'module_local_speech_to_text_ModelLargeV3TurboDesc',
            'source_url' => 'https://huggingface.co/argmaxinc/whisperkit-coreml/tree/' . self::ARGMAX_WHISPERKIT_REVISION . '/openai_whisper-large-v3-v20240930_turbo',
            'profile' => 'module_local_speech_to_text_ModelProfileLargeV3Turbo',
            'best_for' => 'module_local_speech_to_text_ModelLargeV3TurboBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelLargeV3TurboTradeoff',
        ],
        'podlodka' => [
            'platform' => self::PLATFORM_MACOS,
            'repo' => 'smkrv/whisper-podlodka-turbo-coreml',
            'model' => 'bond005_whisper-podlodka-turbo',
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'revision' => self::PODLODKA_WHISPERKIT_REVISION,
            'sha256_manifest' => self::WHISPER_SHA256_MANIFESTS['podlodka'],
            'display_name' => 'Whisper Podlodka Turbo',
            'label' => 'module_local_speech_to_text_ModelPodlodka',
            'description' => 'module_local_speech_to_text_ModelPodlodkaDesc',
            'source_url' => 'https://huggingface.co/smkrv/whisper-podlodka-turbo-coreml/tree/' . self::PODLODKA_WHISPERKIT_REVISION,
            'profile' => 'module_local_speech_to_text_ModelProfilePodlodka',
            'best_for' => 'module_local_speech_to_text_ModelPodlodkaBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelPodlodkaTradeoff',
        ],
        'large-v3' => [
            'platform' => self::PLATFORM_MACOS,
            'repo' => 'argmaxinc/whisperkit-coreml',
            'model' => 'large-v3',
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'revision' => self::ARGMAX_WHISPERKIT_REVISION,
            'sha256_manifest' => self::WHISPER_SHA256_MANIFESTS['large-v3'],
            'display_name' => 'Whisper Large V3',
            'label' => 'module_local_speech_to_text_ModelLargeV3',
            'description' => 'module_local_speech_to_text_ModelLargeV3Desc',
            'source_url' => 'https://huggingface.co/argmaxinc/whisperkit-coreml/tree/' . self::ARGMAX_WHISPERKIT_REVISION . '/openai_whisper-large-v3',
            'profile' => 'module_local_speech_to_text_ModelProfileLargeV3',
            'best_for' => 'module_local_speech_to_text_ModelLargeV3BestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelLargeV3Tradeoff',
        ],
        'parakeet-tdt-0.6b-v3' => [
            'platform' => self::PLATFORM_MACOS,
            'repo' => 'FluidInference/parakeet-tdt-0.6b-v3-coreml',
            'model' => 'parakeet-tdt-0.6b-v3',
            'engine' => 'parakeet',
            'artifact_type' => 'parakeet-coreml',
            'display_name' => 'Parakeet TDT 0.6B v3',
            'label' => 'module_local_speech_to_text_ModelParakeetV3',
            'description' => 'module_local_speech_to_text_ModelParakeetV3Desc',
            'source_url' => 'https://huggingface.co/FluidInference/parakeet-tdt-0.6b-v3-coreml',
            'profile' => 'module_local_speech_to_text_ModelProfileParakeetV3',
            'best_for' => 'module_local_speech_to_text_ModelParakeetV3BestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelParakeetV3Tradeoff',
            'language' => '25 languages',
            'license' => 'CC BY 4.0',
            'supported_languages' => self::PARAKEET_LANGUAGE_IDS,
        ],
        'gigaam-v3' => [
            'platform' => self::PLATFORM_LINUX,
            'repo' => 'istupakov/gigaam-v3-onnx',
            'model' => 'v3_e2e_rnnt',
            'engine' => 'gigaam',
            'artifact_type' => 'gigaam-onnx',
            'revision' => self::LINUX_MODEL_REVISIONS['gigaam-v3'],
            'sha256_manifest' => self::LINUX_SHA256_MANIFESTS['gigaam-v3'],
            'display_name' => 'GigaAM v3',
            'label' => 'module_local_speech_to_text_ModelGigaamV3',
            'description' => 'module_local_speech_to_text_ModelGigaamV3Desc',
            'source_url' => 'https://huggingface.co/ai-sage/GigaAM-v3',
            'profile' => 'module_local_speech_to_text_ModelProfileGigaamV3',
            'best_for' => 'module_local_speech_to_text_ModelGigaamV3BestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelGigaamV3Tradeoff',
            'language' => 'Russian',
            'license' => 'MIT',
            'supported_languages' => self::RUSSIAN_LANGUAGE_IDS,
        ],
        't-one' => [
            'platform' => self::PLATFORM_LINUX,
            'repo' => 't-tech/T-one',
            'model' => 't-one',
            'engine' => 'tOne',
            'artifact_type' => 't-one-onnx',
            'revision' => self::LINUX_MODEL_REVISIONS['t-one'],
            'sha256_manifest' => self::LINUX_SHA256_MANIFESTS['t-one'],
            'display_name' => 'T-one',
            'label' => 'module_local_speech_to_text_ModelTOne',
            'description' => 'module_local_speech_to_text_ModelTOneDesc',
            'source_url' => 'https://huggingface.co/t-tech/T-one',
            'profile' => 'module_local_speech_to_text_ModelProfileTOne',
            'best_for' => 'module_local_speech_to_text_ModelTOneBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelTOneTradeoff',
            'language' => 'Russian',
            'license' => 'Apache 2.0',
            'supported_languages' => self::RUSSIAN_LANGUAGE_IDS,
        ],
        'faster-whisper-large-v3-turbo' => [
            'platform' => self::PLATFORM_LINUX,
            'repo' => 'dropbox-dash/faster-whisper-large-v3-turbo',
            'model' => 'large-v3-turbo',
            'engine' => 'fasterWhisper',
            'artifact_type' => 'ctranslate2',
            'revision' => self::LINUX_MODEL_REVISIONS['faster-whisper-large-v3-turbo'],
            'sha256_manifest' => self::LINUX_SHA256_MANIFESTS['faster-whisper-large-v3-turbo'],
            'display_name' => 'Whisper Large V3 Turbo (CTranslate2)',
            'label' => 'module_local_speech_to_text_ModelFasterWhisperLargeV3Turbo',
            'description' => 'module_local_speech_to_text_ModelFasterWhisperLargeV3TurboDesc',
            'source_url' => 'https://huggingface.co/dropbox-dash/faster-whisper-large-v3-turbo',
            'profile' => 'module_local_speech_to_text_ModelProfileFasterWhisperLargeV3Turbo',
            'best_for' => 'module_local_speech_to_text_ModelFasterWhisperLargeV3TurboBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelFasterWhisperLargeV3TurboTradeoff',
            'license' => 'MIT',
        ],
        'parakeet-tdt-0.6b-v3-linux' => [
            'platform' => self::PLATFORM_LINUX,
            'repo' => 'istupakov/parakeet-tdt-0.6b-v3-onnx',
            'model' => 'parakeet-tdt-0.6b-v3',
            'engine' => 'parakeet',
            'artifact_type' => 'parakeet-onnx',
            'revision' => self::LINUX_MODEL_REVISIONS['parakeet-tdt-0.6b-v3-linux'],
            'sha256_manifest' => self::LINUX_SHA256_MANIFESTS['parakeet-tdt-0.6b-v3-linux'],
            'display_name' => 'Parakeet TDT 0.6B v3',
            'label' => 'module_local_speech_to_text_ModelParakeetV3',
            'description' => 'module_local_speech_to_text_ModelParakeetV3LinuxDesc',
            'source_url' => 'https://huggingface.co/nvidia/parakeet-tdt-0.6b-v3',
            'profile' => 'module_local_speech_to_text_ModelProfileParakeetV3Linux',
            'best_for' => 'module_local_speech_to_text_ModelParakeetV3LinuxBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelParakeetV3LinuxTradeoff',
            'language' => '25 languages',
            'license' => 'CC BY 4.0',
            'supported_languages' => self::PARAKEET_LANGUAGE_IDS,
        ],
        'gigaam-multilingual' => [
            'platform' => self::PLATFORM_LINUX,
            'repo' => 'istupakov/gigaam-multilingual-ctc-onnx',
            'model' => 'multilingual_ctc',
            'engine' => 'gigaam',
            'artifact_type' => 'gigaam-onnx',
            'revision' => self::LINUX_MODEL_REVISIONS['gigaam-multilingual'],
            'sha256_manifest' => self::LINUX_SHA256_MANIFESTS['gigaam-multilingual'],
            'display_name' => 'GigaAM Multilingual',
            'label' => 'module_local_speech_to_text_ModelGigaamMultilingual',
            'description' => 'module_local_speech_to_text_ModelGigaamMultilingualDesc',
            'source_url' => 'https://github.com/salute-developers/GigaAM',
            'profile' => 'module_local_speech_to_text_ModelProfileGigaamMultilingual',
            'best_for' => 'module_local_speech_to_text_ModelGigaamMultilingualBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelGigaamMultilingualTradeoff',
            'language' => 'Kazakh, Kyrgyz, Uzbek, Russian, English',
            'license' => 'MIT',
            'supported_languages' => self::GIGAAM_MULTILINGUAL_LANGUAGE_IDS,
        ],
        // Windows models. The ONNX models are the very artifacts of their Linux
        // counterparts (same repository, pinned commit and manifest).
        'gigaam-v3-windows' => [
            'platform' => self::PLATFORM_WINDOWS,
            'repo' => 'istupakov/gigaam-v3-onnx',
            'model' => 'v3_e2e_rnnt',
            'engine' => 'gigaam',
            'artifact_type' => 'gigaam-onnx',
            'revision' => self::LINUX_MODEL_REVISIONS['gigaam-v3'],
            'sha256_manifest' => self::LINUX_SHA256_MANIFESTS['gigaam-v3'],
            'display_name' => 'GigaAM v3',
            'label' => 'module_local_speech_to_text_ModelGigaamV3',
            'description' => 'module_local_speech_to_text_ModelGigaamV3Desc',
            'source_url' => 'https://huggingface.co/ai-sage/GigaAM-v3',
            'profile' => 'module_local_speech_to_text_ModelProfileGigaamV3',
            'best_for' => 'module_local_speech_to_text_ModelGigaamV3BestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelGigaamV3Tradeoff',
            'language' => 'Russian',
            'license' => 'MIT',
            'supported_languages' => self::RUSSIAN_LANGUAGE_IDS,
        ],
        'parakeet-tdt-0.6b-v3-windows' => [
            'platform' => self::PLATFORM_WINDOWS,
            'repo' => 'istupakov/parakeet-tdt-0.6b-v3-onnx',
            'model' => 'parakeet-tdt-0.6b-v3',
            'engine' => 'parakeet',
            'artifact_type' => 'parakeet-onnx',
            'revision' => self::LINUX_MODEL_REVISIONS['parakeet-tdt-0.6b-v3-linux'],
            'sha256_manifest' => self::LINUX_SHA256_MANIFESTS['parakeet-tdt-0.6b-v3-linux'],
            'display_name' => 'Parakeet TDT 0.6B v3',
            'label' => 'module_local_speech_to_text_ModelParakeetV3',
            'description' => 'module_local_speech_to_text_ModelParakeetV3LinuxDesc',
            'source_url' => 'https://huggingface.co/nvidia/parakeet-tdt-0.6b-v3',
            'profile' => 'module_local_speech_to_text_ModelProfileParakeetV3Linux',
            'best_for' => 'module_local_speech_to_text_ModelParakeetV3LinuxBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelParakeetV3LinuxTradeoff',
            'language' => '25 languages',
            'license' => 'CC BY 4.0',
            'supported_languages' => self::PARAKEET_LANGUAGE_IDS,
        ],
        'gigaam-multilingual-windows' => [
            'platform' => self::PLATFORM_WINDOWS,
            'repo' => 'istupakov/gigaam-multilingual-ctc-onnx',
            'model' => 'multilingual_ctc',
            'engine' => 'gigaam',
            'artifact_type' => 'gigaam-onnx',
            'revision' => self::LINUX_MODEL_REVISIONS['gigaam-multilingual'],
            'sha256_manifest' => self::LINUX_SHA256_MANIFESTS['gigaam-multilingual'],
            'display_name' => 'GigaAM Multilingual',
            'label' => 'module_local_speech_to_text_ModelGigaamMultilingual',
            'description' => 'module_local_speech_to_text_ModelGigaamMultilingualDesc',
            'source_url' => 'https://github.com/salute-developers/GigaAM',
            'profile' => 'module_local_speech_to_text_ModelProfileGigaamMultilingual',
            'best_for' => 'module_local_speech_to_text_ModelGigaamMultilingualBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelGigaamMultilingualTradeoff',
            'language' => 'Kazakh, Kyrgyz, Uzbek, Russian, English',
            'license' => 'MIT',
            'supported_languages' => self::GIGAAM_MULTILINGUAL_LANGUAGE_IDS,
        ],
        'whisper-large-v3-turbo-windows' => [
            'platform' => self::PLATFORM_WINDOWS,
            'repo' => 'ggerganov/whisper.cpp',
            'model' => 'large-v3-turbo',
            'engine' => 'whisperCpp',
            'artifact_type' => 'whisper-ggml',
            'revision' => self::WHISPER_CPP_REVISION,
            'sha256_manifest' => self::WHISPER_CPP_SHA256_MANIFESTS['large-v3-turbo'],
            'display_name' => 'Whisper Large V3 Turbo (whisper.cpp)',
            'label' => 'module_local_speech_to_text_ModelLargeV3Turbo',
            'description' => 'module_local_speech_to_text_ModelWhisperCppLargeV3TurboDesc',
            'source_url' => 'https://huggingface.co/ggerganov/whisper.cpp/blob/' . self::WHISPER_CPP_REVISION . '/ggml-large-v3-turbo.bin',
            'profile' => 'module_local_speech_to_text_ModelProfileWhisperCppLargeV3Turbo',
            'best_for' => 'module_local_speech_to_text_ModelWhisperCppLargeV3TurboBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelWhisperCppLargeV3TurboTradeoff',
            'license' => 'MIT',
        ],
    ];

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $key = '';

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $value = '';

    public function initialize(): void
    {
        $this->setSource('m_STTSetting');
        parent::initialize();
    }

    public static function getValue(string $key, string $default = ''): string
    {
        $setting = self::findFirst([
            'conditions' => 'key = :key:',
            'bind' => [
                'key' => $key,
            ],
        ]);

        return $setting === null ? $default : (string)$setting->value;
    }

    public static function setValue(string $key, string $value): void
    {
        $setting = self::findFirst([
            'conditions' => 'key = :key:',
            'bind' => [
                'key' => $key,
            ],
        ]);
        if ($setting === null) {
            $setting = new self();
            $setting->key = $key;
        }

        $setting->value = $value;

        if (!$setting->save()) {
            throw new RuntimeException(implode(', ', $setting->getMessages()));
        }
    }

    public static function selectedModelId(): string
    {
        return self::normalizeModelId(self::getValue(self::SELECTED_MODEL, self::DEFAULT_MODEL));
    }

    /**
     * @return array<string, mixed>
     */
    public static function selectedModelPreset(): array
    {
        return self::modelPresets()[self::selectedModelId()];
    }

    public static function selectedModelRepo(): string
    {
        return (string)self::selectedModelPreset()['repo'];
    }

    public static function selectedModelRuntimeId(): string
    {
        return (string)self::selectedModelPreset()['model'];
    }

    public static function selectedModelEngine(): string
    {
        return (string)self::selectedModelPreset()['engine'];
    }

    public static function selectedModelArtifactType(): string
    {
        return (string)self::selectedModelPreset()['artifact_type'];
    }

    /** Platform of the model chosen last; the marketplace and the Workers tab open on it. */
    public static function selectedPlatform(): string
    {
        return self::modelPlatform(self::selectedModelId());
    }

    public static function modelPlatform(string $modelId): string
    {
        return (string)(self::modelPresets()[self::normalizeModelId($modelId)]['platform'] ?? self::PLATFORM_MACOS);
    }

    public static function selectedModelKey(string $platform): string
    {
        return self::SELECTED_MODEL_PREFIX . self::normalizePlatform($platform);
    }

    /**
     * Model an administrator chose for the platform: its own key, else the
     * pre-per-platform selection when that one belongs to the platform.
     * Null when the platform was never configured.
     */
    public static function configuredModelIdForPlatform(string $platform): ?string
    {
        $platform = self::normalizePlatform($platform);
        $modelId = trim(self::getValue(self::selectedModelKey($platform)));
        if (array_key_exists($modelId, self::modelPresets()) && self::modelPlatform($modelId) === $platform) {
            return $modelId;
        }
        $legacyModelId = trim(self::getValue(self::SELECTED_MODEL));
        if ($legacyModelId === '') {
            return null;
        }
        $legacyModelId = self::normalizeModelId($legacyModelId);

        return self::modelPlatform($legacyModelId) === $platform ? $legacyModelId : null;
    }

    public static function selectedModelIdForPlatform(string $platform): string
    {
        return self::configuredModelIdForPlatform($platform) ?? self::defaultModelForPlatform($platform);
    }

    /**
     * @return array<string, mixed>
     */
    public static function selectedModelPresetForPlatform(string $platform): array
    {
        return self::modelPresets()[self::selectedModelIdForPlatform($platform)];
    }

    public static function selectedModelDisplayNameForPlatform(string $platform): string
    {
        $preset = self::selectedModelPresetForPlatform($platform);

        return (string)$preset['repo'] . '/' . (string)$preset['model'];
    }

    /**
     * Selected model of every platform, keyed by platform.
     *
     * @return array<string, string>
     */
    public static function selectedModelIdsByPlatform(): array
    {
        $models = [];
        foreach (self::WORKER_PLATFORMS as $platform) {
            $models[$platform] = self::selectedModelIdForPlatform($platform);
        }

        return $models;
    }

    /**
     * Models an administrator chose, keyed by platform. A platform that still
     * runs its default model was never chosen and does not restrict the language.
     *
     * @return array<string, string>
     */
    public static function configuredModelIds(): array
    {
        $models = [];
        foreach (self::WORKER_PLATFORMS as $platform) {
            $modelId = self::configuredModelIdForPlatform($platform);
            if ($modelId !== null) {
                $models[$platform] = $modelId;
            }
        }

        return $models;
    }

    public static function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return in_array($platform, self::WORKER_PLATFORMS, true) ? $platform : self::PLATFORM_MACOS;
    }

    /**
     * Platform a worker declares on a lease request. A missing or empty value
     * means a worker that predates platform reporting, which is always macOS.
     * An unknown value returns null so that worker never receives jobs.
     */
    public static function workerPlatformFromRequest(mixed $value): ?string
    {
        if ($value === null) {
            return self::PLATFORM_MACOS;
        }
        if (!is_string($value)) {
            return null;
        }
        $platform = strtolower(trim($value));
        if ($platform === '') {
            return self::PLATFORM_MACOS;
        }

        return in_array($platform, self::WORKER_PLATFORMS, true) ? $platform : null;
    }

    public static function defaultModelForPlatform(string $platform): string
    {
        return self::PLATFORM_DEFAULT_MODELS[self::normalizePlatform($platform)];
    }

    public static function selectedModelDisplayName(): string
    {
        $preset = self::selectedModelPreset();

        return (string)$preset['repo'] . '/' . (string)$preset['model'];
    }

    public static function selectedModelSourceUrl(): string
    {
        return (string)(self::selectedModelPreset()['source_url'] ?? '');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function modelPresets(): array
    {
        return self::MODEL_PRESETS;
    }

    public static function selectedLanguageId(): string
    {
        return self::normalizeLanguageId(self::getValue(self::DEFAULT_LANGUAGE, self::AUTO_LANGUAGE));
    }

    public static function selectedLanguageHint(): string
    {
        $language = self::selectedLanguageId();

        return $language === self::AUTO_LANGUAGE ? '' : $language;
    }

    /** The language hint, or automatic detection when the platform's model does not know the language. */
    public static function languageHintForPlatform(string $platform): string
    {
        $language = self::selectedLanguageHint();

        return self::modelSupportsLanguage(self::selectedModelIdForPlatform($platform), $language) ? $language : '';
    }

    public static function customVocabularyTermsText(): string
    {
        return self::getValue(self::CUSTOM_VOCABULARY_TERMS, '');
    }

    /**
     * @return array<int, string>
     */
    public static function selectedVocabularyTerms(): array
    {
        $terms = self::splitVocabularyTerms(self::customVocabularyTermsText());
        $deduped = [];
        $seen = [];
        foreach ($terms as $term) {
            $term = trim((string)$term);
            if ($term === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $term;
        }

        return array_slice($deduped, 0, 100);
    }

    /**
     * @return array<int, string>
     */
    public static function splitVocabularyTerms(string $termsText): array
    {
        $parts = preg_split('/[\r\n,;]+/', $termsText);
        if (!is_array($parts)) {
            return [];
        }

        $terms = [];
        foreach ($parts as $part) {
            $term = trim($part);
            if ($term !== '') {
                $terms[] = function_exists('mb_substr') ? mb_substr($term, 0, 120) : substr($term, 0, 120);
            }
        }

        return $terms;
    }

    public static function maxRecordingDurationMinutes(): int
    {
        return self::RECORDING_DURATION_HARD_LIMIT_MINUTES;
    }

    public static function maxRecordingDurationSeconds(): int
    {
        return self::RECORDING_DURATION_HARD_LIMIT_SECONDS;
    }

    public static function normalizeRecordingDurationSeconds(mixed $value): ?int
    {
        if (!is_numeric($value) || (float)$value <= 0) {
            return null;
        }

        return (int)ceil((float)$value);
    }

    public static function recordingDurationAllowed(mixed $value): bool
    {
        $seconds = self::normalizeRecordingDurationSeconds($value);

        return $seconds === null || $seconds <= self::maxRecordingDurationSeconds();
    }

    public static function maxFileSizeBytes(): int
    {
        return self::FIXED_MAX_FILE_SIZE_BYTES;
    }

    public static function cdrHistoryDays(): int
    {
        return self::normalizeCdrHistoryDays(self::getValue(self::CDR_HISTORY_DAYS, (string)self::DEFAULT_CDR_HISTORY_DAYS));
    }

    public static function cdrHistoryStartDateTime(): string
    {
        $days = self::cdrHistoryDays();
        if ($days <= 0) {
            return '';
        }

        return date('Y-m-d H:i:s', time() - ($days * 86400));
    }

    public static function normalizeCdrHistoryDays(string|int $value): int
    {
        $days = (int)$value;
        if ($days <= 0) {
            return 0;
        }

        return max(1, min(self::MAX_CDR_HISTORY_DAYS, $days));
    }

    public static function transcriptRetentionDays(): int
    {
        return self::normalizeTranscriptRetentionDays(
            self::getValue(
                self::TRANSCRIPT_RETENTION_DAYS,
                (string)self::DEFAULT_TRANSCRIPT_RETENTION_DAYS
            )
        );
    }

    public static function normalizeTranscriptRetentionDays(string|int $value): int
    {
        $days = (int)$value;

        return in_array($days, self::TRANSCRIPT_RETENTION_PRESETS, true)
            ? $days
            : self::DEFAULT_TRANSCRIPT_RETENTION_DAYS;
    }

    public static function summarizationEnabled(): bool
    {
        return self::getValue(self::SUMMARIZATION_ENABLED, '0') === '1';
    }

    public static function summarizationEnabledAt(): int
    {
        return max(0, (int)self::getValue(self::SUMMARIZATION_ENABLED_AT, '0'));
    }

    public static function retentionAllowsProcessing(int $processingDays, int $retentionDays): bool
    {
        return $retentionDays === 0
            || ($processingDays > 0 && $processingDays <= $retentionDays);
    }

    /**
     * @return array<int, int>
     */
    public static function workerApiKeyIds(): array
    {
        $ids = [];
        $encodedIds = self::getValue(self::WORKER_API_KEY_IDS, '[]');
        $decodedIds = json_decode($encodedIds, true);
        if (is_array($decodedIds)) {
            foreach ($decodedIds as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        $legacyId = (int)self::getValue(self::WORKER_API_KEY_ID, '0');
        if ($legacyId > 0) {
            $ids[] = $legacyId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, int|string> $ids
     */
    public static function setWorkerApiKeyIds(array $ids): void
    {
        $normalizedIds = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $normalizedIds[] = $id;
            }
        }

        $normalizedIds = array_values(array_unique($normalizedIds));
        self::setValue(self::WORKER_API_KEY_IDS, json_encode($normalizedIds) ?: '[]');
        self::setValue(self::WORKER_API_KEY_ID, $normalizedIds === [] ? '' : (string)$normalizedIds[0]);
    }

    public static function addWorkerApiKeyId(int $id): void
    {
        $ids = self::workerApiKeyIds();
        $ids[] = $id;

        self::setWorkerApiKeyIds($ids);
        self::rememberWorkerApiKeyId($id);
    }

    public static function removeWorkerApiKeyId(int $id): void
    {
        $ids = array_values(array_filter(
            self::workerApiKeyIds(),
            static fn (int $storedId): bool => $storedId !== $id
        ));

        self::setWorkerApiKeyIds($ids);
    }

    public static function isWorkerApiKeyIdAllowed(int $id): bool
    {
        return $id > 0 && in_array($id, self::workerApiKeyIds(), true);
    }

    /** @return array<int, int> */
    public static function workerApiKeyHistoryIds(): array
    {
        $decoded = json_decode(self::getValue(self::WORKER_API_KEY_HISTORY_IDS, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function rememberWorkerApiKeyId(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $ids = self::workerApiKeyHistoryIds();
        $ids[] = $id;
        self::setValue(
            self::WORKER_API_KEY_HISTORY_IDS,
            json_encode(array_values(array_unique($ids))) ?: '[]'
        );
    }

    public static function isWorkerApiKeyIdKnown(int $id): bool
    {
        return $id > 0 && in_array(
            $id,
            array_values(array_unique(array_merge(self::workerApiKeyIds(), self::workerApiKeyHistoryIds()))),
            true
        );
    }

    public static function normalizeModelId(string $modelId): string
    {
        $modelId = trim($modelId);
        $legacyAliases = [
            'argmaxinc/whisperkit-coreml:openai_whisper-large-v3-v20240930_turbo_632MB' => 'large-v3-turbo',
            'argmaxinc/whisperkit-coreml:openai_whisper-large-v3_947MB' => 'large-v3',
            'argmaxinc/whisperkit-coreml:distil-whisper_distil-large-v3_turbo_600MB' => 'large-v3-turbo',
            'smkrv/whisper-podlodka-turbo-coreml:bond005_whisper-podlodka-turbo' => 'podlodka',
            'custom:c60f298fe61e0020d1932b4341e62fc30c6a9eac' => 'podlodka',
        ];
        $modelId = $legacyAliases[$modelId] ?? $modelId;

        return array_key_exists($modelId, self::modelPresets()) ? $modelId : self::DEFAULT_MODEL;
    }

    public static function normalizeLanguageId(string $languageId): string
    {
        $languageId = strtolower(trim($languageId));

        return array_key_exists($languageId, self::LANGUAGE_PRESETS) ? $languageId : self::AUTO_LANGUAGE;
    }

    public static function modelSupportsLanguage(string $modelId, string $languageId): bool
    {
        $languageId = strtolower(trim($languageId));
        if ($languageId === '' || $languageId === self::AUTO_LANGUAGE) {
            return true;
        }

        $preset = self::modelPresets()[self::normalizeModelId($modelId)];
        $supportedLanguages = $preset['supported_languages'] ?? null;

        return !is_array($supportedLanguages) || in_array($languageId, $supportedLanguages, true);
    }

    /**
     * @return array<string, string>
     */
    public static function languagePresetsForModel(string $modelId): array
    {
        return self::languagePresetsForModels([$modelId]);
    }

    /**
     * Languages every given model supports.
     *
     * @param array<array-key, string> $modelIds
     * @return array<string, string>
     */
    public static function languagePresetsForModels(array $modelIds): array
    {
        return array_filter(
            self::LANGUAGE_PRESETS,
            static function (string $label, string $languageId) use ($modelIds): bool {
                foreach ($modelIds as $modelId) {
                    if (!self::modelSupportsLanguage($modelId, $languageId)) {
                        return false;
                    }
                }

                return true;
            },
            ARRAY_FILTER_USE_BOTH
        );
    }

}
