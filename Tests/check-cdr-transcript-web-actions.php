<?php

declare(strict_types=1);

function expectCdrWeb(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$controllerPath = __DIR__ . '/../App/Controllers/CdrTranscriptController.php';
$controller = is_file($controllerPath) ? (file_get_contents($controllerPath) ?: '') : '';
$base = file_get_contents(__DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php') ?: '';

foreach (['lookupAction', 'detailAction'] as $action) {
    $start = strpos($controller, 'public function ' . $action);
    expectCdrWeb($start !== false, "Missing {$action}");
    $next = strpos($controller, "\n    public function ", $start + 1);
    $body = substr($controller, $start, $next === false ? null : $next - $start);
    expectCdrWeb(str_contains($body, 'canViewTranscripts()'), "{$action} must check transcript permission");
    expectCdrWeb(str_contains($body, 'usersUICallScope()'), "{$action} must apply CDR scope");
}

expectCdrWeb(str_contains($controller, '$this->view->transcriptMap'), 'lookup response key missing');
expectCdrWeb(str_contains($controller, '$this->view->transcript'), 'detail response key missing');
expectCdrWeb(str_contains($controller, "setStatusCode(404, 'Not Found')"), 'generic not-found response missing');
expectCdrWeb(str_contains($base, 'CdrTranscriptService'), 'service factory missing');

echo "CDR transcript web action contract OK\n";
