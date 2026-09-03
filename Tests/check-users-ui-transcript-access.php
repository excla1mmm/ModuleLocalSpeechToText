<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Lib/Services/UsersUICallScope.php';
require_once dirname(__DIR__) . '/Lib/Services/UsersUITranscriptAccessService.php';

use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUICallScope;
use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUITranscriptAccessService;

function expectUsersUITranscriptAccess(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$queries = [];
$service = new UsersUITranscriptAccessService(
    static function (string $callId) use (&$queries): array {
        $queries[] = $callId;

        return match ($callId) {
            'linked-call' => [
                ['source' => '74950000000', 'destination' => '201'],
                ['source' => '201', 'destination' => '+7 (999) 123-45-67'],
            ],
            'unrelated-call' => [
                ['source' => '74950000000', 'destination' => '202'],
            ],
            default => [],
        };
    }
);

expectUsersUITranscriptAccess(
    $service->allowsCallId('linked-call', UsersUICallScope::selected(['201'])),
    'Selected employee must see a logical call when any CDR leg contains their internal number'
);
expectUsersUITranscriptAccess(
    $service->allowsCallId('linked-call', UsersUICallScope::selected(['79991234567'])),
    'Selected employee must see a logical call when any CDR leg contains their active external number'
);
expectUsersUITranscriptAccess(
    !$service->allowsCallId('unrelated-call', UsersUICallScope::selected(['201'])),
    'A call without selected employees must be hidden'
);
expectUsersUITranscriptAccess(
    $service->allowsCallId('linked-call', UsersUICallScope::outgoingSelected(['201'])),
    'Outgoing-selected mode must inspect every source leg of the logical call'
);
expectUsersUITranscriptAccess(
    !$service->allowsCallId('linked-call', UsersUICallScope::exceptSelected(['201'])),
    'Except-selected mode must hide the whole logical call when any leg contains a selected employee'
);
expectUsersUITranscriptAccess(
    !$service->allowsCallId('', UsersUICallScope::selected(['201'])),
    'Restricted access must fail closed when the transcript has no call identifier'
);

$queryCount = count($queries);
expectUsersUITranscriptAccess(
    $service->allowsCallId('', UsersUICallScope::all()),
    'Full CDR access must preserve access even when legacy data has no call identifier'
);
expectUsersUITranscriptAccess(
    count($queries) === $queryCount,
    'Full access must not perform an unnecessary CDR participant query'
);

echo "ModuleUsersUI STT transcript access checks passed\n";
