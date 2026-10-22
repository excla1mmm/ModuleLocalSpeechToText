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
        $summaries = [];
        foreach ($workers as $worker) {
            $compatibility[(int)$worker->id] = (int)$worker->api_version === WorkerApiContract::API_VERSION;
            $summaries[(int)$worker->id] = in_array(
                WorkerCapabilities::CALL_SUMMARIES,
                WorkerCapabilities::decode($worker->capabilities),
                true
            );
        }

        $view->workers = $workers;
        $view->workerCompatibility = $compatibility;
        $view->workerSupportsSummaries = $summaries;
        $view->workerApiVersion = WorkerApiContract::API_VERSION;
        $view->workerPlatformLabels = STTSetting::PLATFORM_PRESETS;
    }
}
