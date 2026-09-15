<?php

declare(strict_types=1);

namespace MikoPBX\Core\System {
    final class Util
    {
        public static string $ffprobePath = '';

        public static function which(string $command): string
        {
            return $command === 'ffprobe' ? self::$ffprobePath : '';
        }
    }
}

namespace {
    use MikoPBX\Core\System\Util;
    use Modules\ModuleLocalSpeechToText\Lib\Services\RecordingDurationProbe;

    require_once __DIR__ . '/../Lib/Services/QuietOperation.php';
    require_once __DIR__ . '/../Lib/Services/RecordingDurationProbe.php';

    function expectDurationProbe(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $temporaryDirectory = sys_get_temp_dir() . '/stt-duration-probe-' . bin2hex(random_bytes(8));
    if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
        throw new RuntimeException('Cannot create duration probe test directory');
    }

    $fakeProbe = $temporaryDirectory . '/fake-ffprobe';
    $originalDirectory = getcwd();

    try {
        file_put_contents($fakeProbe, <<<'PHP'
#!/usr/bin/env php
<?php
$path = (string)($argv[count($argv) - 1] ?? '');
if (str_contains($path, 'slow')) {
    usleep(500_000);
}
if (str_contains($path, 'malformed')) {
    fwrite(STDOUT, "N/A\n");
    exit(0);
}
if (str_contains($path, 'error')) {
    fwrite(STDERR, "probe failed\n");
    exit(3);
}
fwrite(STDOUT, "8.2\n");
PHP
        );
        chmod($fakeProbe, 0700);
        Util::$ffprobePath = $fakeProbe;

        $normalPath = $temporaryDirectory . '/normal.webm';
        file_put_contents($normalPath, 'audio');
        $result = (new RecordingDurationProbe())->probe($normalPath);
        expectDurationProbe(
            $result === ['status' => RecordingDurationProbe::STATUS_OK, 'duration_sec' => 9],
            'A fractional media duration must be rounded up safely'
        );

        $injectionMarker = $temporaryDirectory . '/injected-marker';
        $injectionPath = $temporaryDirectory . '/valid.webm;touch injected-marker';
        file_put_contents($injectionPath, 'audio');
        chdir($temporaryDirectory);
        $result = (new RecordingDurationProbe())->probe($injectionPath);
        chdir($originalDirectory);
        expectDurationProbe(
            $result['status'] === RecordingDurationProbe::STATUS_OK && !file_exists($injectionMarker),
            'A CDR-derived path must be passed as an argv value without shell interpretation'
        );

        $malformedPath = $temporaryDirectory . '/malformed.mp3';
        file_put_contents($malformedPath, 'audio');
        expectDurationProbe(
            (new RecordingDurationProbe())->probe($malformedPath)['status'] === RecordingDurationProbe::STATUS_FAILED,
            'Malformed ffprobe output must fail closed'
        );

        $errorPath = $temporaryDirectory . '/error.wav';
        file_put_contents($errorPath, 'audio');
        expectDurationProbe(
            (new RecordingDurationProbe())->probe($errorPath)['status'] === RecordingDurationProbe::STATUS_FAILED,
            'A non-zero ffprobe exit code must fail closed'
        );

        $slowPath = $temporaryDirectory . '/slow.webm';
        file_put_contents($slowPath, 'audio');
        expectDurationProbe(
            (new RecordingDurationProbe(0.05))->probe($slowPath)['status'] === RecordingDurationProbe::STATUS_TIMEOUT,
            'A stalled ffprobe process must be terminated by the deadline'
        );

        Util::$ffprobePath = '';
        expectDurationProbe(
            (new RecordingDurationProbe())->probe($normalPath)['status'] === RecordingDurationProbe::STATUS_UNAVAILABLE,
            'A missing ffprobe binary must remain an explicit unavailable result'
        );
    } finally {
        if (is_string($originalDirectory) && $originalDirectory !== '') {
            chdir($originalDirectory);
        }
        foreach (scandir($temporaryDirectory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($temporaryDirectory . '/' . $entry);
            }
        }
        rmdir($temporaryDirectory);
    }

    echo "Recording duration probe behavior OK\n";
}
