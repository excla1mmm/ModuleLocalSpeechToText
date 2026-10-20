<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Providers;

class MenuProvider
{
    public const string DASHBOARD_PATH = 'module-local-speech-to-text/module-local-speech-to-text/index';
    public const string TRANSCRIPTS_PATH = 'module-local-speech-to-text/module-local-speech-to-text/transcripts';
    public const string TRANSCRIPT_PATH = 'module-local-speech-to-text/module-local-speech-to-text/transcript';
    public const string TRANSCRIPT_EXPORT_PATH = 'module-local-speech-to-text/transcript-actions/export';
    public const string TRANSCRIPT_DELETE_PATH = 'module-local-speech-to-text/transcript-actions/delete';
    public const string RECORDING_PATH = 'module-local-speech-to-text/module-local-speech-to-text/recording';
    public const string LOGS_PATH = 'module-local-speech-to-text/module-local-speech-to-text/logs';
    public const string WINDOWS_WORKER_PACKAGES_PATH = 'module-local-speech-to-text/worker-packages/windows';
}
