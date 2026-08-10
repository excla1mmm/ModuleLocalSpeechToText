<?php

declare(strict_types=1);

namespace MikoPBX\Core\System {
    final class Processes
    {
        // Realistic old-Core compatibility stub: getLockFileDir() does not exist.
    }

    final class Directories
    {
        public const string CORE_TEMP_DIR = 'core_temp';
        public static int $getDirCalls = 0;

        public static function getDir(string $directory): string
        {
            self::$getDirCalls++;
            throw new \RuntimeException('Core temp must not be used for scanner compatibility');
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    final class ScannerGuardCompatibilityProbe
    {
        public const string FALLBACK_ROOT = '/var/run/php-workers';
        public static string $sandboxRoot = '';
        /** @var array<int, string> */
        public static array $requestedPaths = [];

        public static function map(string $path): string
        {
            self::$requestedPaths[] = $path;
            if ($path === self::FALLBACK_ROOT || str_starts_with($path, self::FALLBACK_ROOT . '/')) {
                return self::$sandboxRoot . substr($path, strlen(self::FALLBACK_ROOT));
            }

            return $path;
        }
    }

    function file_exists(string $filename): bool
    {
        return \file_exists(ScannerGuardCompatibilityProbe::map($filename));
    }

    function is_link(string $filename): bool
    {
        return \is_link(ScannerGuardCompatibilityProbe::map($filename));
    }

    function mkdir(string $directory, int $permissions = 0777, bool $recursive = false, mixed $context = null): bool
    {
        $mapped = ScannerGuardCompatibilityProbe::map($directory);
        return $context === null
            ? \mkdir($mapped, $permissions, $recursive)
            : \mkdir($mapped, $permissions, $recursive, $context);
    }

    function lstat(string $filename): array|false
    {
        return \lstat(ScannerGuardCompatibilityProbe::map($filename));
    }

    function realpath(string $path): string|false
    {
        return \realpath(ScannerGuardCompatibilityProbe::map($path));
    }

    function fopen(
        string $filename,
        string $mode,
        bool $useIncludePath = false,
        mixed $context = null
    ): mixed {
        $mapped = ScannerGuardCompatibilityProbe::map($filename);
        return $context === null
            ? \fopen($mapped, $mode, $useIncludePath)
            : \fopen($mapped, $mode, $useIncludePath, $context);
    }

    function chmod(string $filename, int $permissions): bool
    {
        return \chmod(ScannerGuardCompatibilityProbe::map($filename), $permissions);
    }
}

namespace {
    use MikoPBX\Core\System\Directories;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerGuardCompatibilityProbe;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerProcessGuard;

    function removeScannerCompatibilityDirectory(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            unlink($directory);
            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeScannerCompatibilityDirectory($directory . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($directory);
    }

    $temporaryRoot = sys_get_temp_dir() . '/local-stt-scanner-compat-' . bin2hex(random_bytes(8));
    register_shutdown_function(
        static function () use ($temporaryRoot): void {
            removeScannerCompatibilityDirectory($temporaryRoot);
        }
    );
    mkdir($temporaryRoot, 0700, true);
    ScannerGuardCompatibilityProbe::$sandboxRoot = $temporaryRoot . '/php-workers';

    require_once __DIR__ . '/../Lib/Services/ScannerProcessGuard.php';

    $failure = null;
    try {
        $guard = new ScannerProcessGuard();
        if (!$guard->acquire()) {
            throw new RuntimeException('old-Core fallback acquisition reported duplicate contention');
        }
        $guard->assertOwnership();
        $guard->release();
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    if ($failure !== null) {
        throw new RuntimeException(
            'old-Core fallback default construction/acquisition failed: ' . get_class($failure),
            0,
            $failure
        );
    }

    $expectedRuntime = ScannerGuardCompatibilityProbe::$sandboxRoot . '/ModuleLocalSpeechToText/scanner';
    if (!is_file($expectedRuntime . '/.scanner-process.lock')) {
        throw new RuntimeException('old-Core fallback did not acquire under the stable worker runtime');
    }
    if (Directories::$getDirCalls !== 0) {
        throw new RuntimeException('old-Core fallback consulted the clearable Core temp directory');
    }
    if (!in_array('/var/run/php-workers/ModuleLocalSpeechToText/scanner', ScannerGuardCompatibilityProbe::$requestedPaths, true)) {
        throw new RuntimeException('old-Core fallback did not resolve the literal stable runtime pathname');
    }

    echo "Scanner process guard old-Core compatibility OK\n";
}
