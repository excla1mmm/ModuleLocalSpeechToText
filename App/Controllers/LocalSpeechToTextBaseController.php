<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Common\Providers\AclProvider;
use MikoPBX\Common\Providers\JwtProvider;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleLocalSpeechToText\App\Forms\ModuleLocalSpeechToTextForm;
use Modules\ModuleLocalSpeechToText\App\Providers\AssetProvider;
use Modules\ModuleLocalSpeechToText\Lib\Services\AdministrativeSettingsWriter;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallDirectionResolver;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallRecordingService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CdrTranscriptService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ConversationSpeakerLabelResolver;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptConversationService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptListService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptUserJsonService;
use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUIAccessService;
use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUICallScope;
use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUIRequestIdentity;
use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUITranscriptAccessService;
use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUIViewCapabilities;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use InvalidArgumentException;
use stdClass;

class LocalSpeechToTextBaseController extends BaseController
{
    protected const string MODULE_UNIQUE_ID = 'ModuleLocalSpeechToText';

    protected string $moduleDir;
    private ?UsersUIAccessService $usersUIAccess = null;
    /** @var array{_role: string, _username: string}|null */
    private ?array $usersUIIdentity = null;

    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir(self::MODULE_UNIQUE_ID);
        (new ModuleDatabasePragmas())->configureRuntime((new STTJob())->getWriteConnection());
        $this->view->logoImagePath = $this->url->get() . 'assets/img/cache/' . self::MODULE_UNIQUE_ID . '/logo.svg';
        $this->view->submitMode = null;
        parent::initialize();
    }

    protected function addModuleAssets(bool $includeJs = true): void
    {
        AssetProvider::addCss($this->assets);
        if ($includeJs) {
            AssetProvider::addJs($this->assets);
        }
    }

    protected function buildSettingsForm(): ModuleLocalSpeechToTextForm
    {
        return new ModuleLocalSpeechToTextForm($this->settingsEntity());
    }

    protected function saveSettingsFromPost(): void
    {
        $processingInput = [
            STTSetting::TEMPERATURE => $this->request->getPost(STTSetting::TEMPERATURE),
            STTSetting::TEMPERATURE_FALLBACK_STEP => $this->request->getPost(STTSetting::TEMPERATURE_FALLBACK_STEP),
            STTSetting::TEMPERATURE_FALLBACK_COUNT => $this->request->getPost(STTSetting::TEMPERATURE_FALLBACK_COUNT),
            STTSetting::TOP_K => $this->request->getPost(STTSetting::TOP_K),
            STTSetting::COMPRESSION_RATIO_THRESHOLD => $this->request->getPost(STTSetting::COMPRESSION_RATIO_THRESHOLD),
            STTSetting::LOG_PROBABILITY_THRESHOLD => $this->request->getPost(STTSetting::LOG_PROBABILITY_THRESHOLD),
            STTSetting::FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD => $this->request->getPost(STTSetting::FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD),
            STTSetting::NO_SPEECH_THRESHOLD => $this->request->getPost(STTSetting::NO_SPEECH_THRESHOLD),
            STTSetting::AUDIO_NORMALIZATION => $this->request->getPost(STTSetting::AUDIO_NORMALIZATION, null, false),
            STTSetting::VOICE_ACTIVITY_DETECTION => $this->request->getPost(STTSetting::VOICE_ACTIVITY_DETECTION, null, false),
            STTSetting::MAX_SEGMENT_DURATION_MS => (int)round(
                (float)$this->request->getPost('max_segment_duration_sec') * 1000
            ),
            STTSetting::SEGMENT_OVERLAP_MS => (int)round(
                (float)$this->request->getPost('segment_overlap_sec') * 1000
            ),
        ];
        $normalizedProcessing = WorkerProcessingSettingsService::normalize($processingInput);
        $previousProcessing = WorkerProcessingSettingsService::current();
        $previousHistoryDays = STTSetting::cdrHistoryDays();
        $previousRetentionDays = STTSetting::transcriptRetentionDays();
        $nextHistoryDays = STTSetting::normalizeCdrHistoryDays(
            (int)$this->request->getPost(STTSetting::CDR_HISTORY_DAYS, 'int', 30)
        );
        $nextRetentionDays = STTSetting::normalizeTranscriptRetentionDays(
            (int)$this->request->getPost(STTSetting::TRANSCRIPT_RETENTION_DAYS, 'int', 365)
        );
        if (!STTSetting::retentionAllowsProcessing($nextHistoryDays, $nextRetentionDays)) {
            throw new InvalidArgumentException(
                $this->translation->_('module_local_speech_to_text_TranscriptRetentionConflict')
            );
        }

        $customTerms = $this->vocabularyTermsTextFromRequest();
        $nextLanguage = STTSetting::normalizeLanguageId(
            $this->postString(STTSetting::DEFAULT_LANGUAGE, STTSetting::AUTO_LANGUAGE, 16)
        );
        $nextModel = $this->request->hasPost(STTSetting::SELECTED_MODEL)
            ? STTSetting::normalizeModelId(
                $this->postString(STTSetting::SELECTED_MODEL, STTSetting::selectedModelId(), 255)
            )
            : STTSetting::selectedModelId();
        $this->assertModelLanguageCompatibility($nextModel, $nextLanguage);

        $nextValues = [
            STTSetting::DEFAULT_LANGUAGE => $nextLanguage,
            STTSetting::CDR_HISTORY_DAYS => (string)$nextHistoryDays,
            STTSetting::TRANSCRIPT_RETENTION_DAYS => (string)$nextRetentionDays,
            STTSetting::SELECTED_MODEL => $nextModel,
            STTSetting::CUSTOM_VOCABULARY_TERMS => function_exists('mb_substr')
                ? mb_substr($customTerms, 0, 8000)
                : substr($customTerms, 0, 8000),
        ];
        $previousValues = [
            STTSetting::DEFAULT_LANGUAGE => STTSetting::selectedLanguageId(),
            STTSetting::CDR_HISTORY_DAYS => (string)$previousHistoryDays,
            STTSetting::TRANSCRIPT_RETENTION_DAYS => (string)$previousRetentionDays,
            STTSetting::SELECTED_MODEL => STTSetting::selectedModelId(),
            STTSetting::CUSTOM_VOCABULARY_TERMS => STTSetting::customVocabularyTermsText(),
        ];

        $settingsToPersist = $nextValues;
        if ($previousHistoryDays !== $nextHistoryDays) {
            $settingsToPersist[STTSetting::CDR_SCAN_CURSOR_ENDTIME] = date('Y-m-d H:i:s');
            $settingsToPersist[STTSetting::CDR_SCAN_CURSOR_ID] = '0';
            $settingsToPersist[STTSetting::CDR_BACKFILL_CURSOR_ENDTIME] = '';
            $settingsToPersist[STTSetting::CDR_BACKFILL_CURSOR_ID] = '0';
        }
        if ($previousRetentionDays !== $nextRetentionDays) {
            $settingsToPersist[STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT] = '0';
        }

        $changedFields = [];
        foreach ($nextValues as $key => $value) {
            if ((string)($previousValues[$key] ?? '') !== (string)$value) {
                $changedFields[] = $key;
            }
        }
        foreach ($normalizedProcessing as $key => $value) {
            if (($previousProcessing[$key] ?? null) !== $value) {
                $changedFields[] = $key;
            }
        }
        $changedFields = array_values(array_unique($changedFields));
        sort($changedFields);
        (new AdministrativeSettingsWriter())->save(
            $settingsToPersist,
            $normalizedProcessing,
            in_array(STTSetting::SELECTED_MODEL, $changedFields, true)
        );
        if ($changedFields !== []) {
            LocalSpeechToTextLogger::event(
                'settings',
                'settings_changed',
                'STT module settings changed',
                LOG_NOTICE,
                ['changed_fields' => implode(',', $changedFields)]
            );
        }
    }

    protected function assertModelLanguageCompatibility(string $modelId, string $languageId): void
    {
        if (STTSetting::modelSupportsLanguage($modelId, $languageId)) {
            return;
        }

        throw new InvalidArgumentException(
            $this->translation->_('module_local_speech_to_text_ModelLanguageIncompatible')
        );
    }

    protected function countJobsByStatus(string $status): int
    {
        return (int)STTJob::count([
            'conditions' => 'status = :status:',
            'bind' => [
                'status' => $status,
            ],
        ]);
    }

    /** @return array{_role: string, _username: string} */
    protected function usersUIIdentity(): array
    {
        if ($this->usersUIIdentity !== null) {
            return $this->usersUIIdentity;
        }

        $jwt = $this->di->getShared(JwtProvider::SERVICE_NAME);
        $resolver = new UsersUIRequestIdentity(
            static function (string $authorizationHeader) use ($jwt): ?array {
                if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                    return null;
                }

                $payload = $jwt->validate(substr($authorizationHeader, 7));

                return is_array($payload) ? $payload : null;
            },
            static fn(string $refreshToken): ?string => $jwt->extractRoleFromRefreshToken($refreshToken),
            static fn(string $refreshToken): ?string => $jwt->extractUserIdFromRefreshToken($refreshToken),
        );

        $refreshToken = '';
        try {
            if ($this->cookies->has('refreshToken')) {
                $refreshToken = (string)$this->cookies->get('refreshToken')->getValue();
            }
        } catch (\Throwable) {
            $refreshToken = '';
        }

        $identity = $resolver->resolve((string)$this->request->getHeader('Authorization'), $refreshToken);
        if ($identity['_role'] === '') {
            $identity['_role'] = trim((string)$this->session->get('role', ''));
        }
        if ($identity['_username'] === '') {
            $identity['_username'] = trim((string)$this->session->get('userName', ''));
        }
        $this->usersUIIdentity = $identity;

        return $identity;
    }

    protected function usersUIViewCapabilities(): UsersUIViewCapabilities
    {
        $identity = $this->usersUIIdentity();
        $access = $this->usersUIAccessService();
        $administrator = $identity['_role'] === AclProvider::ROLE_ADMINS
            || ($identity['_role'] === '' && !$access->isAvailable());

        return new UsersUIViewCapabilities(
            $administrator,
            $access->canViewTranscripts($identity),
            $access->canManageSettings($identity),
            $access->canManageSystem($identity),
        );
    }

    protected function usersUICallScope(): UsersUICallScope
    {
        $identity = $this->usersUIIdentity();
        if ($identity['_role'] === AclProvider::ROLE_ADMINS) {
            return UsersUICallScope::all();
        }

        return $this->usersUIAccessService()->callScopeFor($identity);
    }

    protected function denyUsersUIAccess(): void
    {
        $this->view->disable();
        $this->response->setStatusCode(403, 'Forbidden');
        $this->response->setContentType('text/plain', 'UTF-8');
        $this->response->setContent('Forbidden');
    }

    protected function usersUIAccessService(): UsersUIAccessService
    {
        return $this->usersUIAccess ??= new UsersUIAccessService();
    }

    protected function conversationService(): TranscriptConversationService
    {
        return new TranscriptConversationService($this->translation);
    }

    protected function conversationSpeakerLabelResolver(): ConversationSpeakerLabelResolver
    {
        return new ConversationSpeakerLabelResolver(
            $this->translation,
            new CallDirectionResolver(CallDirectionResolver::loadInternalNumbers()),
        );
    }

    protected function cdrTranscriptService(): CdrTranscriptService
    {
        return new CdrTranscriptService(
            $this->translation,
            $this->usersUITranscriptAccessService(),
            $this->conversationSpeakerLabelResolver(),
            $this->callRecordingService(),
        );
    }

    protected function transcriptUserJsonService(): TranscriptUserJsonService
    {
        return new TranscriptUserJsonService($this->translation);
    }

    protected function callRecordingService(): CallRecordingService
    {
        return new CallRecordingService($this->translation, $this->url, $this->request, $this->response);
    }

    protected function transcriptListService(): TranscriptListService
    {
        return new TranscriptListService(
            $this->translation,
            $this->request,
            $this->callRecordingService(),
            $this->usersUICallScope(),
            $this->usersUITranscriptAccessService(),
        );
    }

    protected function canAccessTranscript(?STTJob $job): bool
    {
        $callId = $job instanceof STTJob ? (string)$job->call_id : '';

        return $this->usersUITranscriptAccessService()->allowsCallId($callId, $this->usersUICallScope());
    }

    protected function usersUITranscriptAccessService(): UsersUITranscriptAccessService
    {
        return new UsersUITranscriptAccessService();
    }

    protected function workerApiKeyService(): WorkerApiKeyService
    {
        return new WorkerApiKeyService();
    }

    private function settingsEntity(): stdClass
    {
        $settings = new stdClass();
        $settings->default_language = STTSetting::selectedLanguageId();
        $settings->cdr_history_days = (string)STTSetting::cdrHistoryDays();
        $settings->transcript_retention_days = (string)STTSetting::transcriptRetentionDays();
        $settings->selected_model = STTSetting::selectedModelId();
        $settings->custom_vocabulary_terms = STTSetting::customVocabularyTermsText();
        $processing = WorkerProcessingSettingsService::current();
        foreach ($processing as $key => $value) {
            $settings->{$key} = $value;
        }
        $settings->max_segment_duration_sec = $processing[STTSetting::MAX_SEGMENT_DURATION_MS] / 1000;
        $settings->segment_overlap_sec = $processing[STTSetting::SEGMENT_OVERLAP_MS] / 1000;

        return $settings;
    }

    private function vocabularyTermsTextFromRequest(): string
    {
        $termsText = trim((string)($this->request->getPost(STTSetting::CUSTOM_VOCABULARY_TERMS) ?? ''));
        $uploadedTermsText = $this->uploadedVocabularyTermsText();
        if ($uploadedTermsText === '') {
            return $termsText;
        }

        $terms = array_merge(
            STTSetting::splitVocabularyTerms($termsText),
            STTSetting::splitVocabularyTerms($uploadedTermsText)
        );

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

        return implode(', ', array_slice($deduped, 0, 100));
    }

    private function uploadedVocabularyTermsText(): string
    {
        if ((int)$this->request->hasFiles() <= 0) {
            return '';
        }

        foreach ($this->request->getUploadedFiles() as $file) {
            if ((int)$file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $fileName = strtolower((string)$file->getName());
            if (!str_ends_with($fileName, '.txt')) {
                continue;
            }

            $filePath = (string)$file->getTempName();
            if ($filePath === '' || !is_readable($filePath)) {
                continue;
            }

            $contents = file_get_contents($filePath, false, null, 0, 8000);
            if ($contents === false) {
                continue;
            }

            return trim(preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents);
        }

        return '';
    }

    private function postString(string $name, string $default, int $maxLength): string
    {
        $value = trim((string)$this->request->getPost($name, 'string', $default));
        if ($value === '') {
            return $default;
        }

        return substr($value, 0, $maxLength);
    }
}
