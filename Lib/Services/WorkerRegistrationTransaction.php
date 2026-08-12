<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Lib\Exceptions\WorkerApiKeyBindingConflictException;
use PDO;
use Throwable;

final class WorkerRegistrationTransaction
{
    public function run(
        mixed $connection,
        ?int $apiKeyId,
        string $workerUid,
        callable $registration
    ): mixed {
        if ($apiKeyId === null || $apiKeyId <= 0) {
            return (new ModuleTransaction())->run(
                $connection,
                static fn (mixed $database): mixed => $registration($database)
            );
        }

        try {
            return (new ModuleTransaction())->run(
                $connection,
                function (mixed $database) use ($apiKeyId, $workerUid, $registration): mixed {
                    $this->assertBindingAvailable($database, $apiKeyId, $workerUid);

                    return $registration($database);
                },
                8
            );
        } catch (WorkerApiKeyBindingConflictException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            try {
                $this->assertBindingAvailable($connection, $apiKeyId, $workerUid);
            } catch (WorkerApiKeyBindingConflictException $conflict) {
                throw new WorkerApiKeyBindingConflictException(
                    $conflict->errorCode,
                    $conflict->getMessage(),
                    $exception
                );
            }

            throw $exception;
        }
    }

    private function assertBindingAvailable(mixed $connection, int $apiKeyId, string $workerUid): void
    {
        $uidRows = $connection->fetchAll(
            'SELECT api_key_id FROM m_STTWorker WHERE worker_uid = :worker_uid LIMIT 1',
            PDO::FETCH_ASSOC,
            ['worker_uid' => $workerUid]
        );
        $boundKeyId = isset($uidRows[0]['api_key_id']) ? (int)$uidRows[0]['api_key_id'] : 0;
        if ($boundKeyId > 0 && $boundKeyId !== $apiKeyId) {
            throw new WorkerApiKeyBindingConflictException(
                'worker_api_key_mismatch',
                'Worker is bound to another API key'
            );
        }

        $keyRows = $connection->fetchAll(
            'SELECT worker_uid FROM m_STTWorker
              WHERE api_key_id = :api_key_id AND worker_uid <> :worker_uid
              LIMIT 1',
            PDO::FETCH_ASSOC,
            ['api_key_id' => $apiKeyId, 'worker_uid' => $workerUid]
        );
        if ($keyRows !== []) {
            throw new WorkerApiKeyBindingConflictException();
        }
    }
}
