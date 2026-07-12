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
    public const string MAX_FILE_SIZE_MB = 'max_file_size_mb';
    public const string CDR_SCAN_LIMIT = 'cdr_scan_limit';
    public const string CDR_HISTORY_DAYS = 'cdr_history_days';
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
    public const string CUSTOM_MODELS = 'custom_models';

    public const string DEFAULT_MODEL = 'large-v3-turbo';
    public const string AUTO_LANGUAGE = 'auto';
    private const int DEFAULT_MAX_FILE_SIZE_MB = 500;
    private const int DEFAULT_CDR_HISTORY_DAYS = 30;
    private const int MAX_CDR_HISTORY_DAYS = 730;

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
        'base' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'model' => 'base',
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelBase',
            'description' => 'module_local_speech_to_text_ModelBaseDesc',
            'source_url' => 'https://huggingface.co/argmaxinc/whisperkit-coreml/tree/main/openai_whisper-base',
            'profile' => 'module_local_speech_to_text_ModelProfileBase',
            'best_for' => 'module_local_speech_to_text_ModelBaseBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelBaseTradeoff',
        ],
        'small' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'model' => 'small',
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelSmall',
            'description' => 'module_local_speech_to_text_ModelSmallDesc',
            'source_url' => 'https://huggingface.co/argmaxinc/whisperkit-coreml/tree/main/openai_whisper-small',
            'profile' => 'module_local_speech_to_text_ModelProfileSmall',
            'best_for' => 'module_local_speech_to_text_ModelSmallBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelSmallTradeoff',
        ],
        'medium' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'model' => 'medium',
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelMedium',
            'description' => 'module_local_speech_to_text_ModelMediumDesc',
            'source_url' => 'https://huggingface.co/argmaxinc/whisperkit-coreml/tree/main/openai_whisper-medium',
            'profile' => 'module_local_speech_to_text_ModelProfileMedium',
            'best_for' => 'module_local_speech_to_text_ModelMediumBestFor',
            'tradeoff' => 'module_local_speech_to_text_ModelMediumTradeoff',
        ],
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
        return self::MODEL_PRESETS + self::customModelPresets();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function customModelPresets(): array
    {
        $decoded = json_decode(self::getValue(self::CUSTOM_MODELS, '{}'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $models = [];
        foreach ($decoded as $modelId => $model) {
            if (!is_array($model)) {
                continue;
            }

            $repo = self::sanitizeModelRepo((string)($model['repo'] ?? ''));
            $runtimeModel = self::sanitizeRuntimeModel((string)($model['model'] ?? ''));
            if ($repo === '' || $runtimeModel === '') {
                continue;
            }

            $modelId = self::customModelId($repo, $runtimeModel);
            $models[$modelId] = [
                'repo' => $repo,
                'model' => $runtimeModel,
                'engine' => 'whisperKit',
                'artifact_type' => 'whisperkit-coreml',
                'label' => self::truncateText((string)($model['label'] ?? $repo . '/' . $runtimeModel), 160),
                'description' => self::truncateText((string)($model['description'] ?? ''), 240),
                'license' => self::truncateText((string)($model['license'] ?? ''), 80),
                'source_url' => self::sanitizeSourceUrl((string)($model['source_url'] ?? '')),
                'language' => self::truncateText((string)($model['language'] ?? ''), 24),
                'tags' => self::sanitizeModelTags($model['tags'] ?? []),
                'custom' => true,
            ];
        }

        return $models;
    }

    /**
     * @param array<string, mixed> $model
     */
    public static function addCustomModel(array $model): string
    {
        $repo = self::sanitizeModelRepo((string)($model['repo'] ?? ''));
        $runtimeModel = self::sanitizeRuntimeModel((string)($model['model'] ?? ''));
        if ($repo === '' || $runtimeModel === '') {
            throw new RuntimeException('Invalid Hugging Face model repository or model folder.');
        }

        $modelId = self::customModelId($repo, $runtimeModel);
        $models = self::customModelPresets();
        $models[$modelId] = [
            'repo' => $repo,
            'model' => $runtimeModel,
            'engine' => 'whisperKit',
            'artifact_type' => 'whisperkit-coreml',
            'label' => self::truncateText((string)($model['label'] ?? $repo . '/' . $runtimeModel), 160),
            'description' => self::truncateText((string)($model['description'] ?? ''), 240),
            'license' => self::truncateText((string)($model['license'] ?? ''), 80),
            'source_url' => self::sanitizeSourceUrl((string)($model['source_url'] ?? '')),
            'language' => self::truncateText((string)($model['language'] ?? ''), 24),
            'tags' => self::sanitizeModelTags($model['tags'] ?? []),
            'custom' => true,
        ];

        self::setValue(self::CUSTOM_MODELS, json_encode($models, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return $modelId;
    }

    public static function removeCustomModel(string $modelId): void
    {
        $selectedModel = trim(self::getValue(self::SELECTED_MODEL, self::DEFAULT_MODEL));
        $models = self::customModelPresets();
        unset($models[$modelId]);
        self::setValue(self::CUSTOM_MODELS, json_encode($models, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        if ($selectedModel === $modelId) {
            self::setValue(self::SELECTED_MODEL, self::DEFAULT_MODEL);
        }
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

    public static function maxFileSizeBytes(): int
    {
        $megabytes = (int)self::getValue(self::MAX_FILE_SIZE_MB, (string)self::DEFAULT_MAX_FILE_SIZE_MB);
        $megabytes = max(1, min(10240, $megabytes));

        return $megabytes * 1024 * 1024;
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

    public static function normalizeModelId(string $modelId): string
    {
        $modelId = trim($modelId);
        $legacyAliases = [
            'tiny' => 'base',
            'argmaxinc/whisperkit-coreml:openai_whisper-small_216MB' => 'small',
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

    private static function customModelId(string $repo, string $model): string
    {
        return 'custom:' . sha1($repo . ':' . $model);
    }

    private static function sanitizeModelRepo(string $repo): string
    {
        $repo = trim($repo);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,95}\/[A-Za-z0-9][A-Za-z0-9_.-]{0,95}$/', $repo)) {
            return '';
        }

        return $repo;
    }

    private static function sanitizeRuntimeModel(string $model): string
    {
        $model = trim($model);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,160}$/', $model)) {
            return '';
        }

        return $model;
    }

    private static function sanitizeSourceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('/^https:\/\/huggingface\.co\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+(?:\/.*)?$/', $url)) {
            return '';
        }

        return self::truncateText($url, 300);
    }

    /**
     * @param mixed $tags
     * @return array<int, string>
     */
    private static function sanitizeModelTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = preg_split('/[\r\n,;]+/', $tags) ?: [];
        }
        if (!is_array($tags)) {
            return [];
        }

        $sanitized = [];
        foreach ($tags as $tag) {
            $tag = trim((string)$tag);
            if ($tag === '') {
                continue;
            }
            $sanitized[] = self::truncateText($tag, 32);
        }

        return array_slice(array_values(array_unique($sanitized)), 0, 8);
    }

    private static function truncateText(string $text, int $length): string
    {
        $text = trim($text);

        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }
}
