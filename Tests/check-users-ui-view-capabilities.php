<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUIViewCapabilities;

require_once __DIR__ . '/../Lib/Services/UsersUIViewCapabilities.php';

function expectViewCapabilities(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$transcriptsOnly = new UsersUIViewCapabilities(false, true, false, false);
expectViewCapabilities($transcriptsOnly->normalizeTab('settings') === 'transcripts', 'Transcript-only users must not enter settings');
expectViewCapabilities($transcriptsOnly->visibleTabs() === ['transcripts'], 'Transcript-only users must see one tab');

$systemOnly = new UsersUIViewCapabilities(false, true, false, true);
expectViewCapabilities($systemOnly->normalizeTab('workers') === 'workers', 'System managers must enter the worker tab');
expectViewCapabilities($systemOnly->normalizeTab('models') === 'transcripts', 'System managers must not enter model settings');
expectViewCapabilities(
    $systemOnly->visibleTabs() === ['queue', 'workers', 'transcripts', 'logs'],
    'System managers must receive operational tabs plus their transcript landing tab'
);

$settingsOnly = new UsersUIViewCapabilities(false, true, true, false);
expectViewCapabilities($settingsOnly->normalizeTab('queue') === 'transcripts', 'Settings managers must not enter queue operations');
expectViewCapabilities(
    $settingsOnly->visibleTabs() === ['settings', 'models', 'transcripts'],
    'Settings managers must receive recognition settings without operations'
);

$administrator = new UsersUIViewCapabilities(true, false, false, false);
expectViewCapabilities(
    $administrator->visibleTabs() === ['settings', 'models', 'queue', 'workers', 'transcripts', 'logs'],
    'Administrators must retain the complete legacy module interface'
);
expectViewCapabilities($administrator->normalizeTab('settings') === 'settings', 'Administrator default tab must remain settings');

echo "ModuleUsersUI STT view capability checks passed\n";
