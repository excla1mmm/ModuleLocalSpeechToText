<?php

declare(strict_types=1);

// Workers declare optional features (`capabilities` on POST /workers,
// `worker_capabilities` on POST /job-leases). Older workers declare nothing,
// and nothing a worker sends there may break registration or the lease.

use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerCapabilities;

function expectCapabilities(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
require_once $root . '/Lib/Services/WorkerCapabilities.php';

expectCapabilities(WorkerCapabilities::fromRequest(null) === [], 'an older worker declares nothing');
expectCapabilities(WorkerCapabilities::fromRequest('call-summaries') === [], 'a string is not a list');
expectCapabilities(WorkerCapabilities::fromRequest(['a' => 'call-summaries']) === [], 'an object is not a list');
expectCapabilities(
    WorkerCapabilities::fromRequest(['speech_to_text', 'call-summaries', 'call-summaries', 'Bad Name', 7, ['x'], str_repeat('a', 65)])
        === ['call-summaries', 'speech_to_text'],
    'only valid names are kept, unique and sorted'
);
expectCapabilities(count(WorkerCapabilities::fromRequest(array_map(static fn (int $i): string => "f{$i}", range(1, 50)))) === 32, 'the list is bounded');
expectCapabilities(WorkerCapabilities::encode([]) === '' && WorkerCapabilities::decode('') === [], 'nothing is stored as empty');
expectCapabilities(
    WorkerCapabilities::decode(WorkerCapabilities::encode(['call-summaries', 'speech_to_text'])) === ['call-summaries', 'speech_to_text'],
    'stored capabilities round-trip'
);
expectCapabilities(WorkerCapabilities::decode('call-summaries,<script>') === ['call-summaries'], 'a tampered stored value is cleaned');

$main = (string)file_get_contents($root . '/Lib/LocalSpeechToTextMain.php');
expectCapabilities(
    str_contains($main, "WorkerCapabilities::fromRequest(\$data['capabilities'] ?? null)"),
    'registration must replace the declared capabilities'
);
expectCapabilities(
    str_contains($main, "array_key_exists('worker_capabilities', \$data)")
        && str_contains($main, '...WorkerCapabilities::decode($worker->capabilities)'),
    'a lease must only add to the declared capabilities, and only when it sends them'
);

$view = (string)file_get_contents($root . '/Lib/Services/IndexViewService.php');
expectCapabilities(
    str_contains($view, 'STTSetting::summarizationEnabled()')
        && str_contains($view, 'STTWorker::anyOnlineWithCapability(WorkerCapabilities::CALL_SUMMARIES)'),
    'the settings must warn when summarization is on and no online worker supports it'
);

echo "Worker capabilities OK\n";
