<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\ApiKeys;
use MikoPBX\PBXCoreREST\Services\TokenValidationService;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use RuntimeException;

class WorkerApiKeyService
{
    public function workerApiKeyRows(): array
    {
        $rows = [];
        $existingIds = [];
        $storedIds = STTSetting::workerApiKeyIds();

        foreach ($storedIds as $apiKeyId) {
            $apiKey = ApiKeys::findFirstById($apiKeyId);
            if (!$apiKey instanceof ApiKeys) {
                continue;
            }

            $existingIds[] = (int)$apiKey->id;
            $rows[] = [
                'id' => (int)$apiKey->id,
                'keyDisplay' => (string)$apiKey->key_display,
                'createdAtLabel' => $this->apiKeyDateLabel((string)$apiKey->created_at),
                'lastUsedAtLabel' => $this->apiKeyDateLabel((string)$apiKey->last_used_at),
                'boundWorkers' => (int)STTWorker::count([
                    'conditions' => 'api_key_id = :api_key_id:',
                    'bind' => [
                        'api_key_id' => (int)$apiKey->id,
                    ],
                ]),
            ];
        }

        if ($existingIds !== $storedIds) {
            STTSetting::setWorkerApiKeyIds($existingIds);
        }

        return $rows;
    }

    public function createWorkerApiKey(string $plainApiKey): ApiKeys
    {
        $apiKey = new ApiKeys();
        $apiKey->description = 'Local Speech To Text Worker ' . date('Y-m-d H:i:s');
        $apiKey->key_hash = password_hash($plainApiKey, PASSWORD_BCRYPT);
        $apiKey->key_suffix = substr($plainApiKey, -4);
        $apiKey->key_display = substr($plainApiKey, 0, 5) . '...' . substr($plainApiKey, -5);
        $apiKey->networkfilterid = null;
        $apiKey->full_permissions = '0';
        $apiKey->allowed_paths = json_encode([
            '/api/v3/module-local-speech-to-text/workers' => 'write',
            '/api/v3/module-local-speech-to-text/jobs' => 'write',
            '/api/v3/module-local-speech-to-text/transcripts' => 'read',
            '/api/v3/module-local-speech-to-text/transcripts/events' => 'read',
        ]);
        $apiKey->created_at = date('Y-m-d H:i:s');

        if (!$apiKey->save()) {
            throw new RuntimeException(implode(', ', $apiKey->getMessages()));
        }

        STTSetting::addWorkerApiKeyId((int)$apiKey->id);
        TokenValidationService::clearCache();

        return $apiKey;
    }

    public function unbindWorkersFromApiKey(int $apiKeyId): void
    {
        $workers = STTWorker::find([
            'conditions' => 'api_key_id = :api_key_id:',
            'bind' => [
                'api_key_id' => $apiKeyId,
            ],
        ]);

        foreach ($workers as $worker) {
            if (!$worker instanceof STTWorker) {
                continue;
            }

            $worker->api_key_id = null;
            if (!$worker->save()) {
                throw new RuntimeException(implode(', ', $worker->getMessages()));
            }
        }
    }

    private function apiKeyDateLabel(string $dateTime): string
    {
        $dateTime = trim($dateTime);

        return $dateTime === '' ? '-' : $dateTime;
    }

}
