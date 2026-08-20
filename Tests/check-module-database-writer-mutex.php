<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseWriterMutex;

function checkWriterMutex(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$exceptionFile = $root . '/Lib/Exceptions/ModuleDatabaseWriterBusyException.php';
$serviceFile = $root . '/Lib/Services/ModuleDatabaseWriterMutex.php';

checkWriterMutex(is_file($exceptionFile), 'STT database writer busy exception is missing');
checkWriterMutex(is_file($serviceFile), 'STT database writer mutex service is missing');

require_once $exceptionFile;
require_once $serviceFile;

$calls = [];
$mutex = new ModuleDatabaseWriterMutex(
    static function (string $name, callable $operation, int $timeout, int $ttl) use (&$calls): mixed {
        $calls[] = compact('name', 'timeout', 'ttl');
        return $operation();
    }
);

$result = $mutex->synchronized(static fn (): string => 'serialized');
checkWriterMutex($result === 'serialized', 'writer mutex must return the operation result');
checkWriterMutex(count($calls) === 1, 'writer mutex must acquire exactly once');
checkWriterMutex(
    $calls[0] === [
        'name' => 'module-local-stt-db-writer',
        'timeout' => 10,
        'ttl' => 60,
    ],
    'writer mutex lock contract changed'
);

$domainFailure = new RuntimeException('operation failed');
$mutex = new ModuleDatabaseWriterMutex(
    static fn (string $name, callable $operation, int $timeout, int $ttl): mixed => $operation()
);
try {
    $mutex->synchronized(static function () use ($domainFailure): never {
        throw $domainFailure;
    });
    throw new RuntimeException('operation failure was swallowed');
} catch (RuntimeException $exception) {
    checkWriterMutex($exception === $domainFailure, 'operation failure must not be translated into lock contention');
}

$acquisitionFailure = new RuntimeException('lock unavailable');
$mutex = new ModuleDatabaseWriterMutex(
    static function () use ($acquisitionFailure): never {
        throw $acquisitionFailure;
    }
);
try {
    $mutex->synchronized(static fn (): null => null);
    throw new RuntimeException('acquisition failure was not reported');
} catch (ModuleDatabaseWriterBusyException $exception) {
    checkWriterMutex($exception->getPrevious() === $acquisitionFailure, 'busy exception must preserve its cause');
}

$serializedWriters = [
    'job leases' => $root . '/Lib/RestAPI/JobLeases/Processor.php',
    'job recordings' => $root . '/Lib/RestAPI/JobRecordings/Processor.php',
    'job results' => $root . '/Lib/RestAPI/JobResults/Processor.php',
    'management jobs' => $root . '/Lib/RestAPI/Jobs/Processor.php',
    'worker settings' => $root . '/Lib/RestAPI/WorkerProcessingSettings/Processor.php',
    'workers' => $root . '/Lib/RestAPI/Workers/Processor.php',
    'scanner' => $root . '/bin/WorkerLocalSpeechToTextJobs.php',
];
foreach ($serializedWriters as $label => $file) {
    $source = file_get_contents($file);
    checkWriterMutex(
        is_string($source) && str_contains($source, 'new ModuleDatabaseWriterMutex()'),
        "$label does not use the shared STT database writer mutex"
    );
}

echo "STT database writer mutex behavior OK\n";
