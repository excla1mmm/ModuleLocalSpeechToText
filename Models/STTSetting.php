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
    public const string DEFAULT_LANGUAGE = 'default_language';
    public const string POLL_INTERVAL_SEC = 'poll_interval_sec';
    public const string MAX_RECORDING_DURATION_MIN = 'max_recording_duration_min';
    public const string CDR_SCAN_LIMIT = 'cdr_scan_limit';
    public const string CDR_HISTORY_DAYS = 'cdr_history_days';
    public const string TRANSCRIPT_RETENTION_DAYS = 'transcript_retention_days';
    public const string TRANSCRIPT_RETENTION_LAST_CLEANUP_AT = 'transcript_retention_last_cleanup_at';
    public const string PROCESSING_TIMEOUT_SEC = 'processing_timeout_sec';
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
    public const int DEFAULT_CDR_SCAN_LIMIT = 50;
    private const int DEFAULT_MAX_RECORDING_DURATION_MIN = 60;
    private const int MIN_CDR_SCAN_LIMIT = 1;
    private const int MAX_CDR_SCAN_LIMIT = 1000;
    private const int MAX_RECORDING_DURATION_MINUTES = 1440;
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

    public const array MODEL_PRESETS = [
        'large-v3-turbo' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'model' => 'large-v3-v20240930_turbo',
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelLargeV3Turbo',
            'description' => 'module_local_speech_to_text_ModelLargeV3TurboDesc',
            'source_url' => 'https://huggingface.co/argmaxinc/whisperkit-coreml/tree/main/openai_whisper-large-v3-v20240930_turbo',
            'profile' => 'module_local_speech_to_text_ModelProfileLargeV3Turbo',
            'best_for' => 'module_local_speech_to_text_ModelLargeV3TurboBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelLargeV3TurboTradeoff',
        ],
        'podlodka' => [
            'repo' => 'smkrv/whisper-podlodka-turbo-coreml',
            'model' => 'bond005_whisper-podlodka-turbo',
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelPodlodka',
            'description' => 'module_local_speech_to_text_ModelPodlodkaDesc',
            'source_url' => 'https://huggingface.co/smkrv/whisper-podlodka-turbo-coreml',
            'profile' => 'module_local_speech_to_text_ModelProfilePodlodka',
            'best_for' => 'module_local_speech_to_text_ModelPodlodkaBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelPodlodkaTradeoff',
        ],
        'large-v3' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'model' => 'large-v3',
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelLargeV3',
            'description' => 'module_local_speech_to_text_ModelLargeV3Desc',
            'source_url' => 'https://huggingface.co/argmaxinc/whisperkit-coreml/tree/main/openai_whisper-large-v3',
            'profile' => 'module_local_speech_to_text_ModelProfileLargeV3',
            'best_for' => 'module_local_speech_to_text_ModelLargeV3BestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelLargeV3Tradeoff',
        ],
        'parakeet-tdt-0.6b-v3' => [
            'repo' => 'FluidInference/parakeet-tdt-0.6b-v3-coreml',
            'model' => 'parakeet-tdt-0.6b-v3',
            'engine' => 'parakeet',
            'artifact_type' => 'parakeet-coreml',
            'label' => 'module_local_speech_to_text_ModelParakeetV3',
            'description' => 'module_local_speech_to_text_ModelParakeetV3Desc',
            'source_url' => 'https://huggingface.co/FluidInference/parakeet-tdt-0.6b-v3-coreml',
            'profile' => 'module_local_speech_to_text_ModelProfileParakeetV3',
            'best_for' => 'module_local_speech_to_text_ModelParakeetV3BestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelParakeetV3Tradeoff',
            'language' => '25 languages',
            'license' => 'CC BY 4.0',
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

    public static function normalizeRecordingDurationMinutes(string|int $value): int
    {
        return max(1, min(self::MAX_RECORDING_DURATION_MINUTES, (int)$value));
    }

    public static function maxRecordingDurationMinutes(): int
    {
        return self::normalizeRecordingDurationMinutes(
            self::getValue(
                self::MAX_RECORDING_DURATION_MIN,
                (string)self::DEFAULT_MAX_RECORDING_DURATION_MIN
            )
        );
    }

    public static function maxRecordingDurationSeconds(): int
    {
        return self::maxRecordingDurationMinutes() * 60;
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

    public static function cdrScanLimit(): int
    {
        $limit = (int)self::getValue(self::CDR_SCAN_LIMIT, (string)self::DEFAULT_CDR_SCAN_LIMIT);

        return max(self::MIN_CDR_SCAN_LIMIT, min(self::MAX_CDR_SCAN_LIMIT, $limit));
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

}
