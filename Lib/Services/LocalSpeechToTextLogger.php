<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use Throwable;

final class LocalSpeechToTextLogger
{
    private const string MODULE_UNIQUE_ID = 'ModuleLocalSpeechToText';

    private static ?self $default = null;
    private static bool $fallbackReported = false;

    /** @var Closure(string, string, int): void|null */
    private readonly ?Closure $syslogMirror;

    public function __construct(
        private readonly LocalSpeechToTextLogWriter $writer,
        ?callable $syslogMirror = null
    ) {
        $this->syslogMirror = $syslogMirror === null ? null : Closure::fromCallable($syslogMirror);
    }

    /** @param array<array-key, mixed> $context */
    public static function event(
        string $component,
        string $event,
        string $message,
        int $level = LOG_INFO,
        array $context = []
    ): void {
        try {
            self::default()->writeEvent($component, $event, $message, $level, $context);
        } catch (Throwable) {
            self::fallbackFailure('structured log initialization failed');
        }
    }

    /** @param array<array-key, mixed> $context */
    public function writeEvent(
        string $component,
        string $event,
        string $message,
        int $level = LOG_INFO,
        array $context = []
    ): void {
        try {
            $this->writeEventInternal($component, $event, $message, $level, $context);
        } catch (Throwable) {
            self::fallbackFailure('structured log write failed');
        }
    }

    /** @param array<array-key, mixed> $context */
    private function writeEventInternal(
        string $component,
        string $event,
        string $message,
        int $level,
        array $context
    ): void {
        $line = LocalSpeechToTextSyslogFormatter::format($component, $event, $message, $level, $context);
        if (!$this->writer->append($line)) {
            self::fallbackFailure('structured log write failed');
        }

        if ($this->syslogMirror === null || !self::mustMirror($level)) {
            return;
        }

        $separator = strpos($line, ']: ');
        $summary = $separator === false ? $line : substr($line, $separator + 3);

        try {
            ($this->syslogMirror)(self::MODULE_UNIQUE_ID, $summary, $level);
        } catch (Throwable) {
            self::fallbackFailure('syslog mirror failed');
        }
    }

    private static function fallbackFailure(string $reason): void
    {
        if (self::$fallbackReported) {
            return;
        }
        self::$fallbackReported = true;
        try {
            error_log(self::MODULE_UNIQUE_ID . ' ' . $reason);
        } catch (Throwable) {
            // Logging is strictly best-effort and must never alter module control flow.
        }
    }

    private static function default(): self
    {
        if (self::$default === null) {
            $directory = rtrim(Directories::getDir(Directories::CORE_LOGS_DIR), '/')
                . '/' . self::MODULE_UNIQUE_ID;
            self::$default = new self(
                new LocalSpeechToTextLogWriter($directory),
                static function (string $ident, string $message, int $level): void {
                    SystemMessages::sysLogMsg($ident, $message, $level);
                }
            );
        }

        return self::$default;
    }

    private static function mustMirror(int $level): bool
    {
        return in_array($level, [LOG_WARNING, LOG_ERR, LOG_CRIT, LOG_ALERT, LOG_EMERG], true);
    }
}
