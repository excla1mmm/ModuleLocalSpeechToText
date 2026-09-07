<?php

declare(strict_types=1);

namespace Modules\ModuleUsersUI\Lib\ACL {
    interface ACLInterface
    {
        public static function getLinkedControllerActions(): array;
        public static function getAlwaysAllowed(): array;
        public static function getAlwaysDenied(): array;
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\ModuleLocalSpeechToTextACL;

    require_once __DIR__ . '/../Lib/ModuleLocalSpeechToTextACL.php';

    function expectUsersUiAcl(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $controller = 'Modules\\ModuleLocalSpeechToText\\App\\Controllers\\ModuleLocalSpeechToTextController';
    $rules = ModuleLocalSpeechToTextACL::getLinkedControllerActions();
    $actions = $rules[$controller] ?? [];

    expectUsersUiAcl(array_keys($actions) === ['transcripts', 'settings', 'system'], 'STT must expose three cohesive UI permissions');
    expectUsersUiAcl(
        ($actions['transcripts'][$controller] ?? []) === ['index', 'transcript', 'recording'],
        'Transcript access must open the shared page and own detail and recording MVC actions'
    );
    expectUsersUiAcl(
        ($actions['settings'][$controller] ?? []) === ['index', 'save', 'saveModel'],
        'Settings access must open the shared page and own both settings write actions'
    );
    expectUsersUiAcl(
        ($actions['system'][$controller] ?? []) === ['index', 'retryErrors', 'downloadWorker', 'generateWorkerApiKey', 'deleteWorkerApiKey', 'logs'],
        'System access must open the shared page and own queue, worker download, worker-key, and log actions'
    );

    foreach (glob(__DIR__ . '/../Messages/*.php') ?: [] as $messageFile) {
        $messages = require $messageFile;
        expectUsersUiAcl(
            isset($messages['module_usersui_CheckBox_LocalSpeechToText_ModuleLocalSpeechToText_transcripts'])
                && trim((string)$messages['module_usersui_CheckBox_LocalSpeechToText_ModuleLocalSpeechToText_transcripts']) !== '',
            basename($messageFile) . ' must label the visible transcripts permission'
        );
        expectUsersUiAcl(
            !isset($messages['module_usersui_CheckBox_LocalSpeechToText_ModuleLocalSpeechToText_index']),
            basename($messageFile) . ' must not retain the hidden index permission label'
        );
    }

    $denied = ModuleLocalSpeechToTextACL::getAlwaysDenied();
    foreach ([
        '/pbxcore/api/modules/module-local-speech-to-text',
        '/pbxcore/api/v3/module-local-speech-to-text/call-transcripts',
        '/pbxcore/api/v3/module-local-speech-to-text/call-transcripts/events',
        '/pbxcore/api/v3/module-local-speech-to-text/job-failures',
        '/pbxcore/api/v3/module-local-speech-to-text/job-leases',
        '/pbxcore/api/v3/module-local-speech-to-text/job-recordings',
        '/pbxcore/api/v3/module-local-speech-to-text/job-results',
        '/pbxcore/api/v3/module-local-speech-to-text/jobs',
        '/pbxcore/api/v3/module-local-speech-to-text/transcripts',
        '/pbxcore/api/v3/module-local-speech-to-text/worker-api-contract',
        '/pbxcore/api/v3/module-local-speech-to-text/worker-processing-settings',
        '/pbxcore/api/v3/module-local-speech-to-text/worker-update-license',
        '/pbxcore/api/v3/module-local-speech-to-text/workers',
    ] as $path) {
        expectUsersUiAcl(($denied[$path] ?? null) === '*', "Human access groups must not receive transport API {$path}");
    }

    expectUsersUiAcl(ModuleLocalSpeechToTextACL::getAlwaysAllowed() === [], 'STT must not add unconditional access');

    echo "ModuleUsersUI STT module ACL checks passed\n";
}
