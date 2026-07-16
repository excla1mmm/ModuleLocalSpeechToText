<?php

declare(strict_types=1);

namespace MikoPBX\Common\Providers {
    final class PBXConfModulesProvider
    {
        public static int $recreateCalls = 0;
        public static bool $throwOnRecreate = false;

        public static function recreateModulesProvider(): void
        {
            self::$recreateCalls++;
            if (self::$throwOnRecreate) {
                throw new \RuntimeException('provider refresh fixture failure');
            }
        }
    }
}

namespace MikoPBX\Common\Models {
    final class PbxExtensionModules
    {
        public static string $disabled = '0';

        public static function findFirstByUniqid(string $moduleUniqueId): object
        {
            return (object)['disabled' => self::$disabled];
        }
    }
}

namespace MikoPBX\Core\System {
    final class Processes
    {
        public static int $killCalls = 0;
        public static int $restartCalls = 0;
        public static bool $throwOnRestart = false;

        public static function killByName(string $process): int
        {
            self::$killCalls++;
            return 0;
        }

        public static function restartAllWorkers(bool $softRestart = false): void
        {
            self::$restartCalls++;
            if (self::$throwOnRestart) {
                throw new \RuntimeException('worker restart fixture failure');
            }
        }
    }
}

namespace MikoPBX\Core\Workers\Cron {
    final class WorkerSafeScriptsCore
    {
        public const string CHECK_BY_PID_NOT_ALERT = 'pid-not-alert';
    }
}

namespace MikoPBX\Modules\Config {
    abstract class ConfigClass
    {
        protected array $messages = [];
        protected string $moduleDir;

        public function __construct()
        {
            $this->moduleDir = (string)$GLOBALS['moduleLifecycleHookRoot'];
        }

        public function getMessages(): array
        {
            return $this->messages;
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\bin {
    final class WorkerLocalSpeechToTextJobs
    {
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    final class LocalSpeechToTextLogger
    {
        public static array $messages = [];

        public static function syslog(string $ident, string $message, int $level = LOG_INFO): void
        {
            self::$messages[] = [$ident, $message, $level];
        }
    }
}

namespace {
    use MikoPBX\Common\Providers\PBXConfModulesProvider;
    use MikoPBX\Common\Models\PbxExtensionModules;
    use MikoPBX\Core\System\Processes;
    use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextConf;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGuard;

    function expectLifecycleHooks(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $testOwnerPid = getmypid();
    $moduleRoot = sys_get_temp_dir() . '/module-local-stt-hooks-' . $testOwnerPid . '-' . bin2hex(random_bytes(4));
    $GLOBALS['moduleLifecycleHookRoot'] = $moduleRoot;
    if (!mkdir($moduleRoot . '/db', 0700, true) && !is_dir($moduleRoot . '/db')) {
        throw new RuntimeException('Cannot create lifecycle hook test directory');
    }

    register_shutdown_function(static function () use ($moduleRoot, $testOwnerPid): void {
        if (getmypid() !== $testOwnerPid || !is_dir($moduleRoot . '/db')) {
            return;
        }
        foreach (new DirectoryIterator($moduleRoot . '/db') as $file) {
            if (!$file->isDot() && $file->isFile()) {
                unlink($file->getPathname());
            }
        }
        rmdir($moduleRoot . '/db');
        rmdir($moduleRoot);
    });

    require_once __DIR__ . '/../Lib/Services/ModuleLifecycleGuard.php';
    require_once __DIR__ . '/../Lib/LocalSpeechToTextConf.php';

    $config = new LocalSpeechToTextConf();
    expectLifecycleHooks($config->onBeforeModuleDisable(), 'First before-disable hook must succeed');
    expectLifecycleHooks($config->onBeforeModuleDisable(), 'Second before-disable hook must be idempotent');
    $config->onAfterModuleDisable();

    $state = new ModuleLifecycleGuard($moduleRoot . '/db');
    expectLifecycleHooks($state->currentState() === ModuleLifecycleGuard::STATE_DISABLED, 'After-disable must persist disabled state');
    expectLifecycleHooks($state->acquireWriteLease() === null, 'Disabled state must reject stale runtime writers');
    expectLifecycleHooks(PBXConfModulesProvider::$recreateCalls === 1, 'After-disable must refresh the module provider');
    expectLifecycleHooks(Processes::$restartCalls === 1, 'After-disable must restart Core workers');

    expectLifecycleHooks($config->onBeforeModuleEnable(), 'First before-enable hook must succeed');
    expectLifecycleHooks($config->onBeforeModuleEnable(), 'Second before-enable hook must be idempotent');
    $config->onAfterModuleEnable();
    expectLifecycleHooks($state->currentState() === ModuleLifecycleGuard::STATE_OPEN, 'After-enable must reopen runtime writes');
    expectLifecycleHooks(PBXConfModulesProvider::$recreateCalls === 2, 'After-enable must refresh the module provider');
    expectLifecycleHooks(Processes::$restartCalls === 2, 'After-enable must restart Core workers');
    expectLifecycleHooks(Processes::$killCalls === 4, 'Each Core before-hook invocation must safely signal the scanner');

    expectLifecycleHooks($config->onBeforeModuleDisable(), 'Second disable cycle must start');
    $config->onAfterModuleDisable();
    $foreignTransition = new ModuleLifecycleGuard($moduleRoot . '/db');
    expectLifecycleHooks($foreignTransition->requestEnable(), 'Foreign enable transition must start');
    expectLifecycleHooks($foreignTransition->awaitQuiescence(500), 'Foreign enable transition must drain');

    $blockedConfig = new LocalSpeechToTextConf();
    expectLifecycleHooks(!$blockedConfig->onBeforeModuleEnable(), 'Concurrent lifecycle request must be rejected');
    expectLifecycleHooks(
        $foreignTransition->currentState() === ModuleLifecycleGuard::STATE_TRANSITION,
        'Rejected config hook must not abort another guard in the same process'
    );
    $foreignTransition->abortOwnedTransition();
    expectLifecycleHooks(
        $state->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
        'Aborting a failed enable must restore persistent disabled state'
    );

    // The same config object survives across Core's before/persist/after
    // sequence.  If the request dies between persist and after, its destructor
    // must reconcile the marker with the committed main-database flag.
    PbxExtensionModules::$disabled = '0';
    $preCommitDisable = new LocalSpeechToTextConf();
    expectLifecycleHooks($preCommitDisable->onBeforeModuleDisable(), 'Pre-commit disable fixture must start');
    unset($preCommitDisable);
    gc_collect_cycles();
    expectLifecycleHooks(
        $state->currentState() === ModuleLifecycleGuard::STATE_OPEN,
        'A disable aborted before the Core commit must restore open state'
    );

    $postCommitDisable = new LocalSpeechToTextConf();
    expectLifecycleHooks($postCommitDisable->onBeforeModuleDisable(), 'Post-commit disable fixture must start');
    PbxExtensionModules::$disabled = '1';
    unset($postCommitDisable);
    gc_collect_cycles();
    expectLifecycleHooks(
        $state->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
        'A disable interrupted after the Core commit must remain disabled'
    );

    $preCommitEnable = new LocalSpeechToTextConf();
    expectLifecycleHooks($preCommitEnable->onBeforeModuleEnable(), 'Pre-commit enable fixture must start');
    unset($preCommitEnable);
    gc_collect_cycles();
    expectLifecycleHooks(
        $state->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
        'An enable aborted before the Core commit must remain disabled'
    );

    $postCommitEnable = new LocalSpeechToTextConf();
    expectLifecycleHooks($postCommitEnable->onBeforeModuleEnable(), 'Post-commit enable fixture must start');
    PbxExtensionModules::$disabled = '0';
    unset($postCommitEnable);
    gc_collect_cycles();
    expectLifecycleHooks(
        $state->currentState() === ModuleLifecycleGuard::STATE_OPEN,
        'An enable interrupted after the Core commit must reopen writes'
    );

    // Core has already committed its module flag before an after-hook runs.
    // Refresh failures are reported and logged, but must not throw a false
    // "enable failed" response or restore a marker opposite to Core state.
    $refreshFailureConfig = new LocalSpeechToTextConf();
    expectLifecycleHooks($refreshFailureConfig->onBeforeModuleDisable(), 'Refresh-failure fixture disable must start');
    PbxExtensionModules::$disabled = '1';
    $refreshFailureConfig->onAfterModuleDisable();
    expectLifecycleHooks($refreshFailureConfig->onBeforeModuleEnable(), 'Refresh-failure fixture enable must start');
    PbxExtensionModules::$disabled = '0';
    PBXConfModulesProvider::$throwOnRecreate = true;
    Processes::$throwOnRestart = true;
    $refreshFailureConfig->onAfterModuleEnable();
    PBXConfModulesProvider::$throwOnRecreate = false;
    Processes::$throwOnRestart = false;
    expectLifecycleHooks(
        $state->currentState() === ModuleLifecycleGuard::STATE_OPEN,
        'Post-enable refresh failures must keep the guard aligned with committed enabled state'
    );
    expectLifecycleHooks(
        count($refreshFailureConfig->getMessages()['error'] ?? []) === 2,
        'Both post-enable refresh failures must be exposed through config messages'
    );

    echo "Module lifecycle hooks OK\n";
}
