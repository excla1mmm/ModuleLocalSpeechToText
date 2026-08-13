<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Exceptions;

use RuntimeException;
use Throwable;

final class WorkerApiKeyBindingConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode = 'worker_api_key_already_bound',
        string $message = 'This API key is already bound to another worker',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
