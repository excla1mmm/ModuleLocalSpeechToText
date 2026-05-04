<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

require_once 'Globals.php';

use MikoPBX\Common\Models\CallDetailRecords;
use MikoPBX\Common\Providers\DatabaseProviderBase;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Throwable;

class WorkerCreateTranscriptionJobs extends WorkerBase
{
    private const int SCAN_INTERVAL_SECONDS = 15;
    private const int DEFAULT_SCAN_LIMIT = 200;
    private const int LONG_CALL_SECONDS = 1800;
    private const int DELAYED_RECORDING_TTL_SECONDS = 86400;
    private const int MAX_DELAYED_RECORDINGS = 500;

    public static function getCheckInterval(): int
    {
        return self::SCAN_INTERVAL_SECONDS;
    }

    public function start(array $argv): void
    {
        DatabaseProviderBase::ensureCdrTables();

        SystemMessages::sysLogMsg(
            __CLASS__,
            sprintf('Worker started (PID:%d)', getmypid()),
            LOG_NOTICE
        );

        while ($this->needRestart === false) {
            pcntl_signal_dispatch();

            try {
                STTWorker::markStaleOffline();
                $this->retryDelayedRecordings();
                $this->createJobsFromCompletedCdr();
            } catch (Throwable $exception) {
                SystemMessages::sysLogMsg(
                    __CLASS__,
                    'Error while scanning CDR recordings: ' . $exception->getMessage(),
                    LOG_ERR
                );
            }

            sleep(self::SCAN_INTERVAL_SECONDS);
        }

        SystemMessages::sysLogMsg(
            __CLASS__,
            sprintf('Worker stopped (PID:%d)', getmypid()),
            LOG_NOTICE
        );
    }

    private function createJobsFromCompletedCdr(): void
    {
        $delayedRecordings = $this->delayedRecordings();

        $records = CallDetailRecords::find([
            'conditions' => 'recordingfile <> :empty: AND work_completed = :completed:',
            'bind' => [
                'empty' => '',
                'completed' => '1',
            ],
            'order' => 'id DESC',
            'limit' => $this->scanLimit(),
        ]);

        $records = array_reverse($records->toArray());
        foreach ($records as $record) {
            if ($this->needRestart) {
                $this->saveDelayedRecordings($delayedRecordings);
                return;
            }

            $recordingPath = $this->recordingPath((string)($record['recordingfile'] ?? ''));
            if ($recordingPath === null) {
                $this->rememberDelayedRecording($delayedRecordings, $record);
                continue;
            }

            $this->forgetDelayedRecording($delayedRecordings, $this->recordId($record));
            if (STTJob::existsForFilePath($recordingPath)) {
                continue;
            }

            $this->createJob($record, $recordingPath);
        }

        $this->saveDelayedRecordings($delayedRecordings);
    }

    private function createJob(array $record, string $recordingPath): void
    {
        STTJob::createPending(
            $this->callId($record),
            $recordingPath,
            $this->priority($record)
        );

        SystemMessages::sysLogMsg(
            __CLASS__,
            sprintf('Created transcription job for recording %s', $recordingPath),
            LOG_INFO
        );
    }

    private function retryDelayedRecordings(): void
    {
        $delayedRecordings = $this->delayedRecordings();
        if ($delayedRecordings === []) {
            return;
        }

        $changed = false;
        $now = time();

        foreach ($delayedRecordings as $recordId => $record) {
            if ($this->needRestart) {
                if ($changed) {
                    $this->saveDelayedRecordings($delayedRecordings);
                }
                return;
            }

            if ($now - (int)($record['first_seen'] ?? $now) > self::DELAYED_RECORDING_TTL_SECONDS) {
                unset($delayedRecordings[$recordId]);
                $changed = true;
                continue;
            }

            $recordingPath = $this->recordingPath((string)($record['recordingfile'] ?? ''));
            if ($recordingPath === null) {
                continue;
            }

            if (!STTJob::existsForFilePath($recordingPath)) {
                STTJob::createPending(
                    (string)($record['call_id'] ?? ''),
                    $recordingPath,
                    (int)($record['priority'] ?? 5)
                );
                SystemMessages::sysLogMsg(
                    __CLASS__,
                    sprintf('Created delayed transcription job for recording %s', $recordingPath),
                    LOG_INFO
                );
            }

            unset($delayedRecordings[$recordId]);
            $changed = true;
        }

        if ($changed) {
            $this->saveDelayedRecordings($delayedRecordings);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function delayedRecordings(): array
    {
        $json = STTSetting::getValue(STTSetting::DELAYED_RECORDINGS, '[]');
        $records = json_decode($json, true);
        if (!is_array($records)) {
            return [];
        }

        $delayedRecordings = [];
        foreach ($records as $recordId => $record) {
            if (!is_array($record)) {
                continue;
            }

            $recordId = (int)$recordId;
            if ($recordId <= 0 || trim((string)($record['recordingfile'] ?? '')) === '') {
                continue;
            }

            $delayedRecordings[$recordId] = $record;
        }

        return $delayedRecordings;
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @param array<string, mixed> $record
     */
    private function rememberDelayedRecording(array &$delayedRecordings, array $record): void
    {
        $recordId = $this->recordId($record);
        if ($recordId <= 0) {
            return;
        }

        $now = time();
        $delayedRecordings[$recordId] = [
            'recordingfile' => (string)($record['recordingfile'] ?? ''),
            'call_id' => $this->callId($record),
            'priority' => $this->priority($record),
            'first_seen' => (int)($delayedRecordings[$recordId]['first_seen'] ?? $now),
            'last_seen' => $now,
        ];

        if (count($delayedRecordings) > self::MAX_DELAYED_RECORDINGS) {
            uasort(
                $delayedRecordings,
                static fn (array $left, array $right): int => ((int)($left['first_seen'] ?? 0)) <=> ((int)($right['first_seen'] ?? 0))
            );
            $delayedRecordings = array_slice($delayedRecordings, -self::MAX_DELAYED_RECORDINGS, null, true);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     */
    private function forgetDelayedRecording(array &$delayedRecordings, int $recordId): void
    {
        unset($delayedRecordings[$recordId]);
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     */
    private function saveDelayedRecordings(array $delayedRecordings): void
    {
        STTSetting::setValue(
            STTSetting::DELAYED_RECORDINGS,
            json_encode($delayedRecordings, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    private function recordId(array $record): int
    {
        return (int)($record['id'] ?? 0);
    }

    private function scanLimit(): int
    {
        $limit = (int)STTSetting::getValue(STTSetting::CDR_SCAN_LIMIT, (string)self::DEFAULT_SCAN_LIMIT);

        return max(1, min(1000, $limit));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function callId(array $record): string
    {
        $linkedId = trim((string)($record['linkedid'] ?? ''));
        if ($linkedId !== '') {
            return $linkedId;
        }

        return trim((string)($record['UNIQUEID'] ?? ''));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function priority(array $record): int
    {
        $duration = (int)($record['duration'] ?? 0);
        if ($duration >= self::LONG_CALL_SECONDS) {
            return 8;
        }

        return 5;
    }

    private function recordingPath(string $recordingFile): ?string
    {
        $recordingFile = trim($recordingFile);
        if ($recordingFile === '' || $recordingFile === '-') {
            return null;
        }

        $monitorDir = Directories::getDir(Directories::AST_MONITOR_DIR);
        $candidate = str_starts_with($recordingFile, '/')
            ? $recordingFile
            : $monitorDir . '/' . ltrim($recordingFile, '/');

        $allowedBase = realpath($monitorDir);
        $resolvedPath = realpath($candidate);
        if ($allowedBase === false || $resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            return null;
        }

        $allowedPrefix = rtrim($allowedBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolvedPath !== $allowedBase && strncmp($resolvedPath, $allowedPrefix, strlen($allowedPrefix)) !== 0) {
            return null;
        }

        return $resolvedPath;
    }
}

WorkerCreateTranscriptionJobs::startWorker($argv ?? []);
