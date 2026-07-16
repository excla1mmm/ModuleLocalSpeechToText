<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use MikoPBX\Common\Providers\PBXConfModulesProvider;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGuard;
use RuntimeException;
use Throwable;

class LocalSpeechToTextConf extends ConfigClass
{
    private const int LIFECYCLE_DRAIN_TIMEOUT_MS = 20_000;

    private ?ModuleLifecycleGuard $lifecycleGuard = null;

    public function __destruct()
    {
        try {
            // Core persists its main module flag between the before/after
            // hooks. If execution stops in that window, reconcile instead of
            // blindly restoring the pre-transition state.
            $this->lifecycleGuard?->reconcileOwnedTransition();
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::syslog(
                __CLASS__,
                'Unable to clean up an unfinished module lifecycle transition: ' . $exception->getMessage(),
                LOG_ERR
            );
        }
    }

    public function getModuleWorkers(): array
    {
        return [
            [
                'type' => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => WorkerLocalSpeechToTextJobs::class,
            ],
        ];
    }

    public function onAfterModuleEnable(): void
    {
        $this->guard()->completeEnable();
        $this->refreshCoreRuntime('enable');
    }

    public function onBeforeModuleEnable(): bool
    {
        return $this->prepareLifecycleTransition(true);
    }

    public function onBeforeModuleDisable(): bool
    {
        return $this->prepareLifecycleTransition(false);
    }

    public function onAfterModuleDisable(): void
    {
        // Keep the persistent marker in place before old API workers restart.
        // This prevents a process with cached routes from writing after disable.
        $this->guard()->completeDisable();
        $this->refreshCoreRuntime('disable');
    }

    private function prepareLifecycleTransition(bool $enabling): bool
    {
        $guard = null;
        $operation = $enabling ? 'enable' : 'disable';

        try {
            $guard = $this->guard();
            $requested = $enabling ? $guard->requestEnable() : $guard->requestDisable();
            if (!$requested) {
                throw new RuntimeException('Another module lifecycle transition is already active');
            }

            // Signal the scanner immediately after publishing the marker. The
            // exclusive lock below remains the correctness boundary: killByName
            // is asynchronous and may legitimately report that no process exists.
            try {
                Processes::killByName(WorkerLocalSpeechToTextJobs::class);
            } catch (Throwable $exception) {
                LocalSpeechToTextLogger::syslog(
                    __CLASS__,
                    'Unable to signal the local STT scanner during lifecycle transition: ' . $exception->getMessage(),
                    LOG_WARNING
                );
            }

            if (!$guard->awaitQuiescence(self::LIFECYCLE_DRAIN_TIMEOUT_MS)) {
                throw new RuntimeException(sprintf(
                    'Timed out after %d seconds waiting for active transcription database writes to finish',
                    intdiv(self::LIFECYCLE_DRAIN_TIMEOUT_MS, 1000)
                ));
            }

            return true;
        } catch (Throwable $exception) {
            try {
                $guard?->abortOwnedTransition();
            } catch (Throwable $cleanupException) {
                LocalSpeechToTextLogger::syslog(
                    __CLASS__,
                    'Unable to roll back module lifecycle transition: ' . $cleanupException->getMessage(),
                    LOG_ERR
                );
            }
            $message = sprintf('Cannot %s local speech-to-text module: %s', $operation, $exception->getMessage());
            $this->messages['error'][] = $message;
            LocalSpeechToTextLogger::syslog(__CLASS__, $message, LOG_ERR);

            return false;
        }
    }

    private function guard(): ModuleLifecycleGuard
    {
        return $this->lifecycleGuard ??= new ModuleLifecycleGuard($this->moduleDir . '/db', bootstrap: true);
    }

    private function refreshCoreRuntime(string $operation): void
    {
        foreach ([
            'refresh module provider' => static fn () => PBXConfModulesProvider::recreateModulesProvider(),
            'restart Core workers' => static fn () => Processes::restartAllWorkers(true),
        ] as $action => $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                $message = sprintf(
                    'Module %s committed, but failed to %s: %s',
                    $operation,
                    $action,
                    $exception->getMessage()
                );
                $this->messages['error'][] = $message;
                LocalSpeechToTextLogger::syslog(__CLASS__, $message, LOG_ERR);
            }
        }
    }
}
