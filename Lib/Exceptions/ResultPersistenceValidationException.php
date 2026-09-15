<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Exceptions;

use RuntimeException;

/**
 * Thrown when a worker-submitted transcription result fails deterministic
 * Phalcon model validation while being persisted (e.g. a NOT NULL column
 * assertion). Retrying the identical payload will fail identically, so
 * callers must treat this as a terminal, non-retryable outcome for the
 * job's current lease rather than a transient error.
 */
final class ResultPersistenceValidationException extends RuntimeException
{
}
