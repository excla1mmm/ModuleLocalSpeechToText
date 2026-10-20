<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerDownloadService;
use Throwable;

/** Worker package details for the download menus on the Workers tab. */
final class WorkerPackagesController extends LocalSpeechToTextBaseController
{
    /** File names and sizes for the Windows download menu, loaded when the Windows panel opens. */
    public function windowsAction(): void
    {
        if (!$this->usersUIViewCapabilities()->canManageSystem()) {
            $this->denyUsersUIAccess();
            return;
        }

        try {
            $this->view->packages = (new WorkerDownloadService())->describeWindowsPackages();
            $this->view->success = true;
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::event(
                'settings',
                'operation_failed',
                'STT administration operation failed',
                LOG_WARNING,
                [
                    'operation' => 'windows_worker_packages',
                    'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
                ]
            );
            $this->view->packages = [];
            $this->view->success = false;
        }
    }
}
