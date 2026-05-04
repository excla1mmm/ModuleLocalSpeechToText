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
use Throwable;

class WorkerCreateTranscriptionJobs extends WorkerBase
{
    private const int SCAN_INTERVAL_SECONDS = 15;
    private const int DEFAULT_SCAN_LIMIT = 200;
    private const int LONG_CALL_SECONDS = 1800;

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
                return;
            }

            $recordingPath = $this->recordingPath((string)($record['recordingfile'] ?? ''));
            if ($recordingPath === null || STTJob::existsForFilePath($recordingPath)) {
                continue;
            }

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
