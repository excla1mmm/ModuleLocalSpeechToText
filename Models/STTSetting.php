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
    public const string PROCESSING_TIMEOUT_SEC = 'processing_timeout_sec';
    public const string SELECTED_MODEL = 'selected_model';
    public const string SELECTED_VOCABULARY_DICTIONARIES = 'selected_vocabulary_dictionaries';
    public const string CUSTOM_VOCABULARY_TERMS = 'custom_vocabulary_terms';
    public const string DELAYED_RECORDINGS = 'delayed_recordings';
    public const string SKIPPED_RECORDINGS = 'skipped_recordings';
    public const string CDR_SCAN_CURSOR_ENDTIME = 'cdr_scan_cursor_endtime';
    public const string CDR_SCAN_CURSOR_ID = 'cdr_scan_cursor_id';
    public const string WORKER_API_KEY_ID = 'worker_api_key_id';
    public const string WORKER_API_KEY_IDS = 'worker_api_key_ids';

    public const string DEFAULT_MODEL = 'large-v3-turbo';
    public const string AUTO_LANGUAGE = 'auto';
    private const int DEFAULT_MAX_FILE_SIZE_MB = 500;

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
        'tiny' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelTiny',
            'description' => 'module_local_speech_to_text_ModelTinyDesc',
        ],
        'base' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelBase',
            'description' => 'module_local_speech_to_text_ModelBaseDesc',
        ],
        'small' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelSmall',
            'description' => 'module_local_speech_to_text_ModelSmallDesc',
        ],
        'medium' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelMedium',
            'description' => 'module_local_speech_to_text_ModelMediumDesc',
        ],
        'large-v3-turbo' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelLargeV3Turbo',
            'description' => 'module_local_speech_to_text_ModelLargeV3TurboDesc',
        ],
        'large-v3' => [
            'repo' => 'argmaxinc/whisperkit-coreml',
            'label' => 'module_local_speech_to_text_ModelLargeV3',
            'description' => 'module_local_speech_to_text_ModelLargeV3Desc',
        ],
    ];

    public const array VOCABULARY_DICTIONARIES = [
        'ip-telephony' => [
            'label' => 'module_local_speech_to_text_VocabularyIpTelephony',
            'description' => 'module_local_speech_to_text_VocabularyIpTelephonyDesc',
            'category' => 'module_local_speech_to_text_VocabularyCategoryTelephony',
            'terms' => [
                'SIP', 'RTP', 'RTCP', 'SDP', 'VoIP', 'Asterisk', 'FreePBX', 'MikoPBX',
                'extension', 'trunk', 'route', 'dial plan', 'dialplan', 'codec', 'G.711',
                'G.729', 'Opus', 'GSM', 'DTMF', 'IVR', 'queue', 'ring group',
                'call forward', 'call transfer', 'call park', 'voicemail', 'conference',
                'music on hold', 'caller ID', 'DID', 'DDI', 'DOD', 'NAT', 'STUN',
                'TURN', 'ICE', 'jitter', 'latency', 'packet loss', 'softphone',
                'IP phone', 'provider', 'gateway', 'PBX', 'PABX', 'CDR',
                'call detail record', 'callback', 'WebRTC', 'PJSIP', 'chan_sip',
                'AMI', 'AGI', 'ARI', 'outbound route', 'inbound route',
            ],
        ],
        'php-development' => [
            'label' => 'module_local_speech_to_text_VocabularyPhpDevelopment',
            'description' => 'module_local_speech_to_text_VocabularyPhpDevelopmentDesc',
            'category' => 'module_local_speech_to_text_VocabularyCategoryProgramming',
            'terms' => [
                'PHP', 'namespace', 'class', 'interface', 'trait', 'function', 'method',
                'property', 'array', 'string', 'integer', 'boolean', 'null', 'composer',
                'autoload', 'PHPStan', 'PHPUnit', 'PSR', 'Symfony', 'Laravel',
                'Doctrine', 'PDO', 'JSON', 'XML', 'API', 'REST', 'controller',
                'model', 'view', 'router', 'middleware', 'validation', 'authentication',
                'authorization', 'database', 'query', 'migration', 'cache', 'Redis',
            ],
        ],
        'cloud-devops' => [
            'label' => 'module_local_speech_to_text_VocabularyCloudDevops',
            'description' => 'module_local_speech_to_text_VocabularyCloudDevopsDesc',
            'category' => 'module_local_speech_to_text_VocabularyCategoryInfrastructure',
            'terms' => [
                'Docker', 'container', 'image', 'Dockerfile', 'docker-compose',
                'Kubernetes', 'K8s', 'pod', 'deployment', 'service', 'ingress',
                'kubectl', 'helm', 'AWS', 'EC2', 'S3', 'Azure', 'GCP', 'CI/CD',
                'GitHub Actions', 'GitLab CI', 'pipeline', 'build', 'deploy',
                'release', 'Git', 'repository', 'commit', 'branch', 'pull request',
                'nginx', 'Apache', 'HAProxy', 'SSL', 'TLS', 'certificate', 'DNS',
                'firewall', 'VPN', 'Prometheus', 'Grafana', 'backup', 'restore',
                'Terraform', 'Ansible', 'systemd',
            ],
        ],
        'database-sql' => [
            'label' => 'module_local_speech_to_text_VocabularyDatabaseSql',
            'description' => 'module_local_speech_to_text_VocabularyDatabaseSqlDesc',
            'category' => 'module_local_speech_to_text_VocabularyCategoryDatabase',
            'terms' => [
                'SQL', 'database', 'table', 'column', 'row', 'record', 'SELECT',
                'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'WHERE',
                'JOIN', 'GROUP BY', 'ORDER BY', 'primary key', 'foreign key',
                'index', 'transaction', 'commit', 'rollback', 'MySQL', 'PostgreSQL',
                'SQLite', 'MariaDB', 'MongoDB', 'NoSQL', 'schema', 'migration',
                'dump', 'restore', 'query plan', 'replication', 'stored procedure',
            ],
        ],
        'web-development' => [
            'label' => 'module_local_speech_to_text_VocabularyWebDevelopment',
            'description' => 'module_local_speech_to_text_VocabularyWebDevelopmentDesc',
            'category' => 'module_local_speech_to_text_VocabularyCategoryWeb',
            'terms' => [
                'HTML', 'CSS', 'JavaScript', 'TypeScript', 'React', 'Vue', 'Angular',
                'component', 'props', 'state', 'hook', 'DOM', 'async', 'await',
                'promise', 'fetch', 'webpack', 'Vite', 'Babel', 'ESLint', 'Prettier',
                'npm', 'yarn', 'pnpm', 'package.json', 'module', 'import', 'export',
                'responsive', 'Flexbox', 'CSS Grid', 'SASS', 'Tailwind', 'SEO',
                'ARIA', 'Progressive Web App', 'PWA', 'GraphQL', 'WebSocket',
            ],
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
     * @return array<string, string>
     */
    public static function selectedModelPreset(): array
    {
        return self::MODEL_PRESETS[self::selectedModelId()];
    }

    public static function selectedModelRepo(): string
    {
        return self::selectedModelPreset()['repo'];
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

    /**
     * @return array<int, string>
     */
    public static function selectedVocabularyDictionaryIds(): array
    {
        $decoded = json_decode(self::getValue(self::SELECTED_VOCABULARY_DICTIONARIES, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $selected = [];
        foreach ($decoded as $dictionaryId) {
            $dictionaryId = trim((string)$dictionaryId);
            if (array_key_exists($dictionaryId, self::VOCABULARY_DICTIONARIES)) {
                $selected[] = $dictionaryId;
            }
        }

        return array_values(array_unique($selected));
    }

    /**
     * @param array<int, string> $dictionaryIds
     */
    public static function setSelectedVocabularyDictionaryIds(array $dictionaryIds): void
    {
        $selected = [];
        foreach ($dictionaryIds as $dictionaryId) {
            $dictionaryId = trim((string)$dictionaryId);
            if (array_key_exists($dictionaryId, self::VOCABULARY_DICTIONARIES)) {
                $selected[] = $dictionaryId;
            }
        }

        self::setValue(
            self::SELECTED_VOCABULARY_DICTIONARIES,
            json_encode(array_values(array_unique($selected))) ?: '[]'
        );
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
        $terms = [];
        foreach (self::selectedVocabularyDictionaryIds() as $dictionaryId) {
            $dictionary = self::VOCABULARY_DICTIONARIES[$dictionaryId] ?? null;
            if (is_array($dictionary) && is_array($dictionary['terms'] ?? null)) {
                foreach ($dictionary['terms'] as $term) {
                    $terms[] = (string)$term;
                }
            }
        }

        $terms = array_merge($terms, self::splitVocabularyTerms(self::customVocabularyTermsText()));

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

        return array_key_exists($modelId, self::MODEL_PRESETS) ? $modelId : self::DEFAULT_MODEL;
    }

    public static function normalizeLanguageId(string $languageId): string
    {
        $languageId = strtolower(trim($languageId));

        return array_key_exists($languageId, self::LANGUAGE_PRESETS) ? $languageId : self::AUTO_LANGUAGE;
    }
}
