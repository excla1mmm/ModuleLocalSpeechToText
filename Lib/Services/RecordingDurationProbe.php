<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use InvalidArgumentException;
use MikoPBX\Core\System\Util;
use Modules\ModuleLocalSpeechToText\Lib\Services\QuietOperation;

final class RecordingDurationProbe
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_UNAVAILABLE = 'unavailable';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_TIMEOUT = 'timeout';

    private const float DEFAULT_TIMEOUT_SECONDS = 5.0;
    private const int DEFAULT_MAX_OUTPUT_BYTES = 8192;

    public function __construct(
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES
    ) {
        if ($this->timeoutSeconds <= 0.0 || $this->maxOutputBytes < 64) {
            throw new InvalidArgumentException('Invalid recording duration probe limits');
        }
    }

    /** @return array{status: string, duration_sec: int|null} */
    public function probe(string $recordingPath): array
    {
        if (!is_file($recordingPath) || !is_readable($recordingPath)) {
            return $this->result(self::STATUS_FAILED);
        }
        if (!function_exists('proc_open')) {
            return $this->result(self::STATUS_UNAVAILABLE);
        }

        $ffprobe = trim((string)Util::which('ffprobe'));
        if ($ffprobe === '') {
            return $this->result(self::STATUS_UNAVAILABLE);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        try {
            $process = QuietOperation::run(static function () use ($ffprobe, $recordingPath, $descriptors, &$pipes) {
                return proc_open(
                    [
                        $ffprobe,
                        '-nostdin',
                        '-v',
                        'error',
                        '-show_entries',
                        'format=duration',
                        '-of',
                        'default=noprint_wrappers=1:nokey=1',
                        $recordingPath,
                    ],
                    $descriptors,
                    $pipes,
                    null,
                    null,
                    ['bypass_shell' => true]
                );
            });
        } catch (\Throwable) {
            return $this->result(self::STATUS_FAILED);
        }
        if (!is_resource($process) || !is_array($pipes) || count($pipes) !== 3) {
            return $this->result(self::STATUS_FAILED);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $overflow = false;
        $timedOut = false;
        $exitCode = -1;
        $deadline = microtime(true) + $this->timeoutSeconds;

        do {
            $this->drainPipe($pipes[1], $stdout, $overflow);
            $this->drainPipe($pipes[2], $discarded, $overflow);
            $status = proc_get_status($process);
            if (!is_array($status) || !($status['running'] ?? false)) {
                $exitCode = is_array($status) ? (int)($status['exitcode'] ?? -1) : -1;
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                QuietOperation::run(static fn (): bool => proc_terminate($process, 15));
                usleep(20_000);
                $status = proc_get_status($process);
                if (is_array($status) && ($status['running'] ?? false)) {
                    QuietOperation::run(static fn (): bool => proc_terminate($process, 9));
                }
                break;
            }
            usleep(10_000);
        } while (true);

        $this->drainPipe($pipes[1], $stdout, $overflow);
        $this->drainPipe($pipes[2], $discarded, $overflow);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closeCode;
        }

        if ($timedOut) {
            return $this->result(self::STATUS_TIMEOUT);
        }
        if ($exitCode !== 0 || $overflow) {
            return $this->result(self::STATUS_FAILED);
        }

        $duration = trim($stdout);
        if (!is_numeric($duration)) {
            return $this->result(self::STATUS_FAILED);
        }
        $durationSeconds = (float)$duration;
        if (!is_finite($durationSeconds) || $durationSeconds <= 0.0 || $durationSeconds > PHP_INT_MAX) {
            return $this->result(self::STATUS_FAILED);
        }

        return $this->result(self::STATUS_OK, (int)ceil($durationSeconds));
    }

    /**
     * @param resource $pipe
     */
    private function drainPipe(mixed $pipe, ?string &$buffer, bool &$overflow): void
    {
        while (is_resource($pipe)) {
            $chunk = fread($pipe, 4096);
            if (!is_string($chunk) || $chunk === '') {
                return;
            }
            if ($buffer === null) {
                continue;
            }
            $remaining = $this->maxOutputBytes - strlen($buffer);
            if ($remaining <= 0) {
                $overflow = true;
                continue;
            }
            if (strlen($chunk) > $remaining) {
                $buffer .= substr($chunk, 0, $remaining);
                $overflow = true;
                continue;
            }
            $buffer .= $chunk;
        }
    }

    /** @return array{status: string, duration_sec: int|null} */
    private function result(string $status, ?int $durationSeconds = null): array
    {
        return [
            'status' => $status,
            'duration_sec' => $durationSeconds,
        ];
    }
}
