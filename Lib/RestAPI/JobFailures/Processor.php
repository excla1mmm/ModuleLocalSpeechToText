<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobFailures;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult; use Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobResults\Processor as ResultProcessor; use Phalcon\Di\Injectable;
class Processor extends Injectable { public static function callBack(array $request): PBXApiResult { return ResultProcessor::dispatch($request, true); } }
