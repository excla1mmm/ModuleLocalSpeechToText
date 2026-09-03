<?php

declare(strict_types=1);

function expectUsersUIWeb(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$controller = file_get_contents(dirname(__DIR__) . '/App/Controllers/ModuleLocalSpeechToTextController.php');
$base = file_get_contents(dirname(__DIR__) . '/App/Controllers/LocalSpeechToTextBaseController.php');
$indexView = file_get_contents(dirname(__DIR__) . '/Lib/Services/IndexViewService.php');
$template = file_get_contents(dirname(__DIR__) . '/App/Views/ModuleLocalSpeechToText/index.volt');

expectUsersUIWeb(
    is_string($controller) && is_string($base) && is_string($indexView) && is_string($template),
    'Unable to read STT web integration files'
);

foreach ([
    'saveAction' => 'canManageSettings()',
    'saveModelAction' => 'canManageSettings()',
    'retryErrorsAction' => 'canManageSystem()',
    'downloadWorkerAction' => 'canManageSystem()',
    'generateWorkerApiKeyAction' => 'canManageSystem()',
    'deleteWorkerApiKeyAction' => 'canManageSystem()',
    'logsAction' => 'canManageSystem()',
    'transcriptsAction' => 'canViewTranscripts()',
    'transcriptAction' => 'canViewTranscripts()',
    'recordingAction' => 'canViewTranscripts()',
] as $action => $capability) {
    $start = strpos($controller, 'public function ' . $action);
    expectUsersUIWeb($start !== false, "Missing {$action}");
    $next = strpos($controller, "\n    public function ", $start + 1);
    $body = substr($controller, $start, $next === false ? null : $next - $start);
    expectUsersUIWeb(str_contains($body, $capability), "{$action} is not guarded by {$capability}");
}

expectUsersUIWeb(
    substr_count($controller, 'canAccessTranscript($job)') === 2,
    'Transcript details and recording playback must both enforce the logical-call scope'
);
expectUsersUIWeb(
    str_contains($base, '$this->usersUICallScope()')
        && str_contains($base, '$this->usersUITranscriptAccessService()'),
    'Transcript list service must receive both the CDR scope and logical-call resolver'
);
expectUsersUIWeb(
    str_contains($base, 'JwtProvider::SERVICE_NAME')
        && str_contains($base, "get('refreshToken')")
        && str_contains($base, "AclProvider::ROLE_ADMINS"),
    'Web identity must come from Core JWT/refresh-token state and preserve administrator access'
);
foreach (['canManageSettings', 'canManageSystem', 'canViewTranscripts'] as $flag) {
    expectUsersUIWeb(
        str_contains($indexView, '$capabilities->' . $flag . '()'),
        "The dashboard loader does not isolate data controlled by {$flag}"
    );
    expectUsersUIWeb(
        str_contains($template, '{% if ' . $flag . ' %}'),
        "The index template does not hide content controlled by {$flag}"
    );
}

echo "ModuleUsersUI STT web integration contract checks passed\n";
