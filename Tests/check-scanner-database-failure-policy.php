<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerDatabaseFailurePolicy;

require_once __DIR__ . '/../Lib/Services/ScannerDatabaseFailurePolicy.php';

function expectScannerDatabaseFailurePolicy(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scannerPdoException(int|string $code, string $message = 'database failure'): PDOException
{
    $exception = new PDOException($message, is_int($code) ? $code : 0);
    $exception->errorInfo = ['HY000', $code, $message];

    return $exception;
}

$policy = new ScannerDatabaseFailurePolicy();

$expectedKinds = [
    5 => ScannerDatabaseFailurePolicy::KIND_BUSY,
    6 => ScannerDatabaseFailurePolicy::KIND_LOCKED,
    8 => ScannerDatabaseFailurePolicy::KIND_READONLY,
    10 => ScannerDatabaseFailurePolicy::KIND_IO,
    11 => ScannerDatabaseFailurePolicy::KIND_CORRUPT,
    14 => ScannerDatabaseFailurePolicy::KIND_CANTOPEN,
    26 => ScannerDatabaseFailurePolicy::KIND_NOTADB,
];

foreach ($expectedKinds as $code => $expectedKind) {
    expectScannerDatabaseFailurePolicy(
        $policy->classify(scannerPdoException($code)) === $expectedKind,
        "SQLite code {$code} was not classified as {$expectedKind}"
    );
}

$wrapped = new RuntimeException('scanner transaction failed', 0, scannerPdoException(5));
expectScannerDatabaseFailurePolicy(
    $policy->classify($wrapped) === ScannerDatabaseFailurePolicy::KIND_BUSY,
    'PDO exception in the previous chain was not classified'
);

expectScannerDatabaseFailurePolicy(
    $policy->classify(scannerPdoException(261)) === ScannerDatabaseFailurePolicy::KIND_BUSY,
    'Extended SQLite busy code was not normalized'
);
expectScannerDatabaseFailurePolicy(
    $policy->classify(scannerPdoException(262)) === ScannerDatabaseFailurePolicy::KIND_LOCKED,
    'Extended SQLite locked code was not normalized'
);

$fallbacks = [
    'sqlite_busy: database is busy' => ScannerDatabaseFailurePolicy::KIND_BUSY,
    'database table is locked' => ScannerDatabaseFailurePolicy::KIND_LOCKED,
    'attempt to write a readonly database' => ScannerDatabaseFailurePolicy::KIND_READONLY,
    'disk I/O error' => ScannerDatabaseFailurePolicy::KIND_IO,
    'database disk image is malformed' => ScannerDatabaseFailurePolicy::KIND_CORRUPT,
    'unable to open database file' => ScannerDatabaseFailurePolicy::KIND_CANTOPEN,
    'file is not a database' => ScannerDatabaseFailurePolicy::KIND_NOTADB,
];

foreach ($fallbacks as $message => $expectedKind) {
    expectScannerDatabaseFailurePolicy(
        $policy->classify(scannerPdoException('HY000', $message)) === $expectedKind,
        "PDO message {$message} was not classified as {$expectedKind}"
    );
}

expectScannerDatabaseFailurePolicy(
    $policy->classify(new RuntimeException('database is busy')) === ScannerDatabaseFailurePolicy::KIND_NOT_PDO,
    'Non-PDO exception must not use PDO message fallbacks'
);
expectScannerDatabaseFailurePolicy(
    $policy->classify(scannerPdoException(1, 'unrecognized database failure')) === ScannerDatabaseFailurePolicy::KIND_UNKNOWN_PDO,
    'Unmapped PDO exception was not classified as unknown_pdo'
);

foreach ([ScannerDatabaseFailurePolicy::KIND_BUSY, ScannerDatabaseFailurePolicy::KIND_LOCKED] as $kind) {
    foreach ([1, 2] as $failures) {
        expectScannerDatabaseFailurePolicy(
            $policy->recoveryAction($kind, $failures, true) === ScannerDatabaseFailurePolicy::ACTION_CONTINUE,
            "{$kind} must continue at failure count {$failures}"
        );
    }
    expectScannerDatabaseFailurePolicy(
        $policy->recoveryAction($kind, 3, true) === ScannerDatabaseFailurePolicy::ACTION_RECYCLE,
        "{$kind} must recycle at failure count 3"
    );
}

foreach ([
    ScannerDatabaseFailurePolicy::KIND_READONLY,
    ScannerDatabaseFailurePolicy::KIND_IO,
    ScannerDatabaseFailurePolicy::KIND_CORRUPT,
    ScannerDatabaseFailurePolicy::KIND_CANTOPEN,
    ScannerDatabaseFailurePolicy::KIND_NOTADB,
    ScannerDatabaseFailurePolicy::KIND_UNKNOWN_PDO,
] as $kind) {
    expectScannerDatabaseFailurePolicy(
        $policy->recoveryAction($kind, 1, true) === ScannerDatabaseFailurePolicy::ACTION_RECYCLE,
        "{$kind} must recycle immediately"
    );
}

expectScannerDatabaseFailurePolicy(
    $policy->recoveryAction(ScannerDatabaseFailurePolicy::KIND_NOT_PDO, 1, true) === ScannerDatabaseFailurePolicy::ACTION_CONTINUE,
    'not_pdo must continue after a successful recovery'
);
expectScannerDatabaseFailurePolicy(
    $policy->recoveryAction(ScannerDatabaseFailurePolicy::KIND_NOT_PDO, 1, false) === ScannerDatabaseFailurePolicy::ACTION_RECYCLE,
    'failed recovery must recycle even for not_pdo'
);

echo "Scanner database failure policy behavior OK\n";
