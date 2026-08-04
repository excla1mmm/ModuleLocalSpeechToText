<?php

declare(strict_types=1);

function expectLifecycle(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function methodBody(string $source, string $method): string
{
    $signature = 'function ' . $method . '(';
    $methodPosition = strpos($source, $signature);
    expectLifecycle($methodPosition !== false, sprintf('Missing lifecycle method %s', $method));

    $bodyStart = strpos($source, '{', $methodPosition);
    expectLifecycle($bodyStart !== false, sprintf('Missing body for lifecycle method %s', $method));

    $depth = 0;
    $length = strlen($source);
    for ($position = $bodyStart; $position < $length; $position++) {
        if ($source[$position] === '{') {
            $depth++;
        } elseif ($source[$position] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $bodyStart + 1, $position - $bodyStart - 1);
            }
        }
    }

    expectLifecycle(false, sprintf('Unclosed body for lifecycle method %s', $method));
    return '';
}

function expectOrder(string $body, array $needles, string $message): void
{
    $previous = -1;
    foreach ($needles as $needle) {
        $position = strpos($body, $needle);
        expectLifecycle($position !== false && $position > $previous, $message);
        $previous = $position;
    }
}

$configPath = dirname(__DIR__) . '/Lib/LocalSpeechToTextConf.php';
$source = file_get_contents($configPath);
expectLifecycle(is_string($source), 'Unable to read LocalSpeechToTextConf.php');

expectLifecycle(
    str_contains($source, 'WorkerModelsEvents::invokeAction(ReloadRestAPIWorkerAction::class)'),
    'STT lifecycle must schedule a targeted REST API worker reload'
);
expectLifecycle(
    substr_count($source, '$this->scheduleRestApiReload();') === 2,
    'STT enable and disable hooks must both schedule REST API reload'
);
expectLifecycle(
    !str_contains($source, 'restartAllWorkers('),
    'STT lifecycle must not restart all workers'
);
expectLifecycle(
    !str_contains($source, 'processPHPWorker('),
    'STT lifecycle must not restart WorkerApiCommands inline'
);
expectLifecycle(
    str_contains($source, "'rest_api_reload_schedule_failed'"),
    'STT lifecycle must log reload scheduling failures'
);
expectLifecycle(
    str_contains($source, 'throw $exception;'),
    'STT lifecycle must propagate reload scheduling failures'
);

expectOrder(
    methodBody($source, 'onAfterModuleEnable'),
    [
        '$this->gate(bootstrap: true)->completeEnable()',
        'PBXConfModulesProvider::recreateModulesProvider()',
        '$this->scheduleRestApiReload()',
    ],
    'STT enable must schedule REST API reload after opening the gate and recreating the module provider'
);
expectOrder(
    methodBody($source, 'onAfterModuleDisable'),
    [
        '$this->gate()->completeDisable()',
        '$this->scheduleRestApiReload()',
    ],
    'STT disable must schedule REST API reload after closing the lifecycle gate'
);

echo "STT REST API reload lifecycle contract OK\n";
