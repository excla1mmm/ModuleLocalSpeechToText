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
    public const string SELECTED_MODEL = 'selected_model';
    public const string CUSTOM_VOCABULARY_TERMS = 'custom_vocabulary_terms';
    public const string DELAYED_RECORDINGS = 'delayed_recordings';
    public const string SKIPPED_RECORDINGS = 'skipped_recordings';
    public const string CDR_SCAN_CURSOR_ENDTIME = 'cdr_scan_cursor_endtime';
    public const string CDR_SCAN_CURSOR_ID = 'cdr_scan_cursor_id';
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

    public const string DEFAULT_MODEL = 'parakeet-tdt-0.6b-v3';
    public const string AUTO_LANGUAGE = 'auto';

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

    public const array MODEL_PRESETS = [
        'large-v3-turbo' => [
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
        return array_filter(
            self::LANGUAGE_PRESETS,
            static fn (string $label, string $languageId): bool => self::modelSupportsLanguage(
                $modelId,
                $languageId
            ),
            ARRAY_FILTER_USE_BOTH
        );
    }

}
