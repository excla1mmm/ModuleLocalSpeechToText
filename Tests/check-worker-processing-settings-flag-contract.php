<?php

declare(strict_types=1);

function expectWorkerSettingsFlag(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$workerModel = (string)file_get_contents(__DIR__ . '/../Models/STTWorker.php');
$setup = (string)file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');

expectWorkerSettingsFlag(
    str_contains($workerModel, 'public ?int $has_settings_changed = 1;'),
    'Workers must persist the processing-settings dirty flag'
);
expectWorkerSettingsFlag(
    str_contains($workerModel, 'public function hasProcessingSettingsChanged(): bool'),
    'Workers must expose a boolean dirty-state helper'
);
expectWorkerSettingsFlag(
    str_contains($setup, 'ensureWorkerProcessingSettingsFlag($connection);')
        && str_contains($setup, 'ALTER TABLE m_STTWorker ADD COLUMN has_settings_changed INTEGER NOT NULL DEFAULT 1'),
    'Setup must add the dirty flag for existing installations'
);
expectWorkerSettingsFlag(
    str_contains($setup, 'UPDATE m_STTWorker SET has_settings_changed = 1'),
    'Existing workers must fetch the new profile after upgrade'
);

$newWorkerBranchStart = strpos($workerModel, 'if ($worker === null) {');
$newWorkerBranchEnd = $newWorkerBranchStart === false ? false : strpos($workerModel, "\n        }", $newWorkerBranchStart);
$dirtyAssignment = strpos($workerModel, '$worker->has_settings_changed = 1;');
expectWorkerSettingsFlag(
    $newWorkerBranchStart !== false
        && $newWorkerBranchEnd !== false
        && $dirtyAssignment !== false
        && $dirtyAssignment > $newWorkerBranchStart
        && $dirtyAssignment < $newWorkerBranchEnd
        && substr_count($workerModel, '$worker->has_settings_changed = 1;') === 1,
    'Only newly registered workers may have their dirty flag initialized explicitly'
);

foreach (['registerWorker', 'touchOnline', 'updatePlatformModel', 'markStaleOffline'] as $method) {
    $methodStart = strpos($workerModel, 'function ' . $method . '(');
    expectWorkerSettingsFlag($methodStart !== false, "Cannot inspect {$method}");
    $nextPublicMethod = strpos($workerModel, "\n    public ", $methodStart + 1);
    $nextPrivateMethod = strpos($workerModel, "\n    private ", $methodStart + 1);
    $nextCandidates = array_filter(
        [$nextPublicMethod, $nextPrivateMethod],
        static fn (int|false $offset): bool => $offset !== false
    );
    $nextMethod = $nextCandidates === [] ? false : min($nextCandidates);
    $methodBody = substr(
        $workerModel,
        $methodStart,
        ($nextMethod === false ? strlen($workerModel) : $nextMethod) - $methodStart
    );
    expectWorkerSettingsFlag(
        !str_contains($methodBody, '->save()'),
        "{$method} must update only its own columns and preserve has_settings_changed"
    );
}

expectWorkerSettingsFlag(
    substr_count($workerModel, 'UPDATE m_STTWorker') >= 4,
    'Existing registration, heartbeat, model sync and offline updates must use partial SQL updates'
);

echo "Worker processing settings flag contract OK\n";
