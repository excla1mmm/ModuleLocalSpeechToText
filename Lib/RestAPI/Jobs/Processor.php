<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\Actions\DownloadAction;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\Actions\GetNextAction;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\Actions\SubmitErrorAction;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\Actions\SubmitResultAction;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Phalcon\Di\Injectable;

enum JobAction: string
{
    case GET_NEXT = 'getNext';
    case DOWNLOAD = 'download';
    case SUBMIT_RESULT = 'submitResult';
    case SUBMIT_ERROR = 'submitError';
    case HEARTBEAT = 'heartbeat';
    case RELEASE = 'release';
}

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = self::class . '::callBack';

        $actionString = (string)($request['action'] ?? '');
        $action = JobAction::tryFrom($actionString);
        if ($action === null) {
            $result->messages['error'][] = "Unknown action - $actionString in " . __CLASS__;
            $result->function = $actionString;
            return $result;
        }

        $gate = new ModuleLifecycleGate();
        $writeLease = $gate->acquireWriteLease();
        if ($writeLease === null) {
            $result->success = false;
            $result->httpCode = 503;
            $result->function = $actionString;
            $result->messages['error'][] = 'ModuleLocalSpeechToText is temporarily unavailable during maintenance';
            $result->data['retry_after'] = 5;
            return $result;
        }

        try {
            (new ModuleDatabasePragmas())->configureRuntime((new STTJob())->getWriteConnection());
            $data = self::requestData($request);
            $result = match ($action) {
                JobAction::GET_NEXT => GetNextAction::main($data),
                JobAction::DOWNLOAD => DownloadAction::main($data),
                JobAction::SUBMIT_RESULT => SubmitResultAction::main($data),
                JobAction::SUBMIT_ERROR => SubmitErrorAction::main($data),
                JobAction::HEARTBEAT => LocalSpeechToTextMain::heartbeatJob($data),
                JobAction::RELEASE => LocalSpeechToTextMain::releaseJob($data),
            };
        } finally {
            $gate->releaseWriteLease($writeLease);
        }

        $result->processor = __METHOD__;
        $result->function = $actionString;

        return $result;
    }

    private static function requestData(array $request): array
    {
        $data = $request['data'] ?? [];
        $data = is_array($data) ? $data : [];
        unset($data['_remote_addr'], $data['_auth_type'], $data['_api_key_id']);

        if (isset($request['sessionContext']['remote_addr']) && is_scalar($request['sessionContext']['remote_addr'])) {
            $data['_remote_addr'] = (string)$request['sessionContext']['remote_addr'];
        }
        if (isset($request['sessionContext']['auth_type']) && is_scalar($request['sessionContext']['auth_type'])) {
            $data['_auth_type'] = (string)$request['sessionContext']['auth_type'];
        }
        if (isset($request['sessionContext']['token_id']) && is_scalar($request['sessionContext']['token_id'])) {
            $data['_api_key_id'] = (int)$request['sessionContext']['token_id'];
        }

        return $data;
    }
}
