<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use Modules\ModuleLocalSpeechToText\App\Providers\MenuProvider;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Throwable;

class ModuleLocalSpeechToTextController extends LocalSpeechToTextBaseController
{
    public function indexAction(): void
    {
        $this->addModuleAssets();

        $this->view->form = $this->buildSettingsForm();
        $this->view->transcriptsPath = MenuProvider::TRANSCRIPTS_PATH;
        $this->view->pendingCount = $this->countJobsByStatus(STTJob::STATUS_PENDING);
        $this->view->processingCount = $this->countJobsByStatus(STTJob::STATUS_PROCESSING);
        $this->view->doneTodayCount = STTJob::count([
            'conditions' => 'status = :status: AND finished_at >= :since:',
            'bind' => [
                'status' => STTJob::STATUS_DONE,
                'since' => strtotime('today'),
            ],
        ]);
        $this->view->errorCount = $this->countJobsByStatus(STTJob::STATUS_ERROR);
        STTWorker::markStaleOffline();
        $this->view->workers = STTWorker::find([
            'order' => 'last_seen DESC, id DESC',
        ]);

        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/index');
    }

    public function saveAction(): void
    {
        try {
            $this->saveSettingsFromPost();
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
            $this->view->success = true;
            $this->view->reload = MenuProvider::DASHBOARD_PATH;
        } catch (Throwable $exception) {
            $this->flash->error($exception->getMessage());
            $this->view->success = false;
        }

        if ($this->request->isAjax() === false) {
            $this->response->redirect(MenuProvider::DASHBOARD_PATH);
        }
    }

    public function transcriptsAction(): void
    {
        $this->addModuleAssets(false);

        $this->view->dashboardPath = MenuProvider::DASHBOARD_PATH;
        $this->view->results = STTResult::find([
            'order' => 'created_at DESC, id DESC',
            'limit' => 100,
        ]);

        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/transcripts');
    }
}
