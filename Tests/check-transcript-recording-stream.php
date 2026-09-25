<?php

declare(strict_types=1);

// The transcript card streams its recording like the Core CDR playback
// endpoint: chunks pass straight through nginx, and the player's HEAD
// availability check gets headers without the file body.
function expectRecordingStream(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$service = file_get_contents(__DIR__ . '/../Lib/Services/CallRecordingService.php') ?: '';
$start = strpos($service, 'public function streamRecordingFile');
expectRecordingStream($start !== false, 'streamRecordingFile is missing');
$body = substr($service, (int)$start, (int)strpos($service, "\n    }\n", (int)$start) - (int)$start);

expectRecordingStream(str_contains($body, "setHeader('Accept-Ranges', 'bytes')"), 'Range requests must stay supported');
expectRecordingStream(str_contains($body, "setHeader('X-Accel-Buffering', 'no')"), 'nginx must not buffer the whole recording');
$head = strpos($body, '$this->request->isHead()');
expectRecordingStream($head !== false, 'HEAD must return headers only');
expectRecordingStream($head > (int)strpos($body, 'sendHeaders()'), 'HEAD must still send the headers');
expectRecordingStream($head < (int)strpos($body, 'fseek($fileHandle, $start)'), 'HEAD must not stream the file body');

echo "Transcript recording stream contract OK\n";
