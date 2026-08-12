<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;

final class WorkerViewService
{
    public function prepare(object $view): void
    {
        $workers = STTWorker::find(['order' => 'last_seen DESC, id DESC']);
        $compatibility = [];
        foreach ($workers as $worker) {
            $compatibility[(int)$worker->id] = (int)$worker->api_version === WorkerApiContract::API_VERSION;
        }

        $view->workers = $workers;
        $view->workerCompatibility = $compatibility;
        $view->workerApiVersion = WorkerApiContract::API_VERSION;
        $view->workerRuntimeModel = STTSetting::selectedModelDisplayName();
    }
}
