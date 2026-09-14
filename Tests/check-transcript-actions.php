<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptUserJsonService;

require_once __DIR__ . '/../Lib/Services/TranscriptUserJsonService.php';

final class TranscriptActionsTranslation
{
    public function _(string $key): string
    {
        return match ($key) {
            'module_local_speech_to_text_Speaker1' => 'Participant 1',
            'module_local_speech_to_text_Speaker2' => 'Participant 2',
            'module_local_speech_to_text_Speaker' => 'Participant',
            default => $key,
        };
    }
}

function expectTranscriptAction(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = new TranscriptUserJsonService(new TranscriptActionsTranslation());
$source = json_encode([
    ['speaker' => 'left', 'start_ms' => 1000, 'end_ms' => 2500, 'text' => 'Hello'],
    ['speaker' => 'right', 'start_ms' => 3000, 'end_ms' => 4200, 'text' => 'World'],
], JSON_THROW_ON_ERROR);

expectTranscriptAction(
    $service->transcriptText($source) === "Participant 1: Hello\nParticipant 2: World\n",
    'TXT export must contain one readable speaker line per phrase'
);

$root = dirname(__DIR__);
$template = (string)file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/transcript.volt')
    . (string)file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/_transcript_stage.volt');
$controller = (string)file_get_contents($root . '/App/Controllers/TranscriptActionsController.php');
$moduleController = (string)file_get_contents($root . '/App/Controllers/ModuleLocalSpeechToTextController.php');
$acl = (string)file_get_contents($root . '/Lib/ModuleLocalSpeechToTextACL.php');
$javascript = (string)file_get_contents($root . '/public/assets/js/src/module-local-speech-to-text.js');

expectTranscriptAction(str_contains($template, 'stt-transcript-actions'), 'Transcript page must render the action menu');
expectTranscriptAction(str_contains($template, '<details class="stt-transcript-actions">'), 'Action menu must use native keyboard behavior');
expectTranscriptAction(
    str_contains($template, 'class="item stt-delete-transcript-form"'),
    'Transcript deletion must use a direct-submit form'
);
expectTranscriptAction(!str_contains($template, 'data-confirm='), 'Transcript deletion must not request confirmation');
expectTranscriptAction(!str_contains($template, 'id="stt-confirm-modal"'), 'Transcript page must not render a confirmation modal');
expectTranscriptAction(str_contains($javascript, "$(document).on('click.stt-transcript-actions'"), 'Clicking outside must close the transcript action menu');
expectTranscriptAction(str_contains($javascript, ".removeAttr('open')"), 'Selecting a transcript action must close its menu');
expectTranscriptAction(!str_contains($template, 'stt-transcript-count'), 'Reply counter must be replaced by the action menu');
expectTranscriptAction(str_contains($controller, 'exportAction'), 'Transcript export action is missing');
expectTranscriptAction(str_contains($controller, 'exportFilename('), 'Transcript export must build the download filename from call details');
expectTranscriptAction(
    str_contains($controller, "'LSTT_%s_%s_%s-%s.%s'"),
    'Export filename must follow the LSTT_<date>_<time>_<source>-<destination> scheme'
);
expectTranscriptAction(
    str_contains($controller, "date('Y-m-d'") && str_contains($controller, "date('H-i'"),
    'Export filename date and time must come from the resolved call timestamp'
);
expectTranscriptAction(
    !str_contains($controller, "date('H:i'"),
    'Export filename time must use the dash separator to stay Windows-safe'
);
expectTranscriptAction(
    str_contains($controller, 'local-stt-transcript-%d.%s'),
    'Export must keep the id-based fallback filename for transcripts without call details'
);
expectTranscriptAction(str_contains($controller, 'deleteAction'), 'Transcript deletion action is missing');
expectTranscriptAction(str_contains($controller, 'canDeleteTranscripts()'), 'Transcript deletion must enforce its dedicated permission');
expectTranscriptAction(!str_contains($controller, 'isAdministrator()'), 'Transcript deletion must not remain administrator-only');
expectTranscriptAction(str_contains($moduleController, 'canDeleteTranscripts()'), 'Transcript page must expose deletion according to the dedicated permission');
expectTranscriptAction(
    !str_contains($controller, 'ModuleDatabaseWriterMutex'),
    'AdminCabinet transcript deletion must not depend on the worker-only mutex service'
);
expectTranscriptAction(
    str_contains($acl, "self::TRANSCRIPT_ACTIONS_CONTROLLER => ['export']"),
    'Transcript viewers must be allowed to export visible transcripts'
);
expectTranscriptAction(
    !str_contains($acl, "self::TRANSCRIPT_ACTIONS_CONTROLLER => ['export', 'delete']"),
    'Delegated transcript viewers must not receive deletion access'
);

foreach (glob($root . '/Messages/*.php') ?: [] as $messageFile) {
    $messages = require $messageFile;
    foreach ([
        'TranscriptActions',
        'DownloadTranscriptTxt',
        'DownloadTranscriptJson',
        'DeleteTranscript',
        'DeleteTranscriptConfirm',
        'DeleteTranscriptConsequence',
        'DeleteTranscriptKeepsCdr',
        'DeleteTranscriptBackupWarning',
        'TranscriptDeleted',
        'TranscriptDeleteFailed',
        'TranscriptDeleteProcessingActive',
    ] as $suffix) {
        $key = 'module_local_speech_to_text_' . $suffix;
        expectTranscriptAction(
            trim((string)($messages[$key] ?? '')) !== '',
            basename($messageFile) . " must translate {$key}"
        );
    }
}

echo "Transcript action behavior OK\n";
