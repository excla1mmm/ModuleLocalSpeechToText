<?php

declare(strict_types=1);

function expectCallIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$required = [
    'Lib/Services/CallTranscriptIntegrationService.php',
    'Lib/Services/CallTranscriptMigration.php',
    'Lib/RestAPI/CallTranscripts/Controller.php',
    'Lib/RestAPI/CallTranscripts/Processor.php',
    'Lib/RestAPI/CallTranscripts/DataStructure.php',
    'Lib/RestAPI/CallTranscriptEvents/Controller.php',
    'Lib/RestAPI/CallTranscriptEvents/Processor.php',
    'Lib/RestAPI/CallTranscriptEvents/DataStructure.php',
];
foreach ($required as $relative) {
    expectCallIntegration(is_file($root . '/' . $relative), "missing {$relative}");
}

$eventsController = file_get_contents($root . '/Lib/RestAPI/CallTranscriptEvents/Controller.php') ?: '';
expectCallIntegration(str_contains($eventsController, "'/call-transcripts/events'"), 'call event route missing');
expectCallIntegration(str_contains($eventsController, 'PBXApiResult'), 'call event endpoint must document PBXApiResult');

$detailController = file_get_contents($root . '/Lib/RestAPI/CallTranscripts/Controller.php') ?: '';
expectCallIntegration(str_contains($detailController, "'/call-transcripts'"), 'call transcript detail route missing');
expectCallIntegration(str_contains($detailController, "idPattern: '[0-9]+'"), 'aggregate id must be numeric');

$integration = file_get_contents($root . '/Lib/Services/CallTranscriptIntegrationService.php') ?: '';
foreach (['callTranscriptEvents', 'callTranscriptDetail', 'content_hash', 'parts_hash', 'completeness_status'] as $needle) {
    expectCallIntegration(str_contains($integration, $needle), "integration service missing {$needle}");
}

$migration = file_get_contents($root . '/Lib/Services/CallTranscriptMigration.php') ?: '';
foreach (['legacy_unresolved', 'recordCompletedPart', 'publishReady', 'm_STTResult'] as $needle) {
    expectCallIntegration(str_contains($migration, $needle), "migration missing {$needle}");
}
expectCallIntegration(!str_contains($migration, 'DELETE FROM'), 'migration must not delete source or historical data');

$legacyEvents = file_get_contents($root . '/Models/STTTranscriptEvent.php') ?: '';
expectCallIntegration(str_contains($legacyEvents, "TYPE_TRANSCRIPT_COMPLETED = 'transcript.completed'"), 'legacy part event must remain');

echo "Call transcript integration contract OK\n";
