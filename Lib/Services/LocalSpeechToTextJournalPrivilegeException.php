<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use RuntimeException;

/**
 * Raised when the journal owner window cannot return root privileges.
 * Callers must let it escape: continuing with a dropped effective UID/GID
 * would silently change the worker's identity for every later operation.
 */
final class LocalSpeechToTextJournalPrivilegeException extends RuntimeException
{
}
