<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerDownloadAvailability;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerDownloadService;

require_once __DIR__ . '/../Lib/Services/WorkerDownloadService.php';

function expectWorkerDownloadService(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<int, array<string, mixed>> $files
 */
function workerDownloadSuccessPayload(array $files): string
{
    return (string)json_encode([
        'modules' => [
            'release_id' => 2203,
            'uniqid' => 'LocalSTTWorkerMac',
            'version' => '1.83',
            'files' => $files,
        ],
        'result' => 'SUCCESS',
    ]);
}

/**
 * @param array<int, string> $requests Captured [endpoint => json body] pairs
 */
function workerDownloadServiceUnderTest(string $licenseKey, string $response, array &$requests): WorkerDownloadService
{
    return new WorkerDownloadService(
        static fn (): string => $licenseKey,
        static function (string $endpoint, array $body) use (&$requests, $response): string {
            $requests[$endpoint] = (string)json_encode($body);

            return $response;
        }
    );
}

$validKey = 'MIKO-5YSFR-ENOU5-Q29SY-Q3HSM';
$endpoint = 'https://lic.miko.ru/releases/v1/other/getLatestRelease';
$universal = [
    'name' => 'LocalSTTWorkerMac.1.83.dmg',
    'arch' => '',
    'size' => 45882491,
    'hash' => '59b691d4274584d625c2731a33f83a0b',
    'download_link' => 'https://filescdn.miko.ru/s/TrWGzmzPAa4BNjG/download',
];

expectWorkerDownloadService(WorkerDownloadService::LICENSE_PARAM === 'KEY', 'License key body field must be KEY');
expectWorkerDownloadService(
    WorkerDownloadService::PRODUCT_TYPE === 'LocalSTTWorkerMac',
    'Product type must select the macOS worker release'
);
expectWorkerDownloadService(
    WorkerDownloadService::isLicenseKeyShape($validKey),
    'A 28-char MIKO- key must pass the shape check'
);
foreach (
    [
        'MIKO-5YSFR-ENOU5-Q29SY-Q3HS' => 'short',
        'MIKO-5YSFR-ENOU5-Q29SY-Q3HSMX' => 'long',
        'XXXX-5YSFR-ENOU5-Q29SY-Q3HSM' => 'wrong prefix',
        '' => 'empty',
    ] as $key => $shape
) {
    expectWorkerDownloadService(
        !WorkerDownloadService::isLicenseKeyShape($key),
        "A {$shape} key must fail the shape check"
    );
}

// A valid key must be attached to the licensing request.
$requests = [];
$service = workerDownloadServiceUnderTest($validKey, workerDownloadSuccessPayload([$universal]), $requests);
$resolution = $service->resolveDownload();
expectWorkerDownloadService(
    $resolution->availability === WorkerDownloadAvailability::AVAILABLE,
    'A published release with a link must resolve as available'
);
expectWorkerDownloadService(
    $resolution->url === $universal['download_link'],
    'The resolved url must be the file download_link'
);
expectWorkerDownloadService(
    ($requests[$endpoint] ?? '') === (string)json_encode(['TYPE' => 'LocalSTTWorkerMac', 'KEY' => $validKey]),
    'A valid license key must be attached to the request body'
);

// Without a key the request still carries the product selector only.
$requests = [];
$service = workerDownloadServiceUnderTest('not-a-license-key', workerDownloadSuccessPayload([$universal]), $requests);
$resolution = $service->resolveDownload();
expectWorkerDownloadService(
    $resolution->availability === WorkerDownloadAvailability::AVAILABLE,
    'An unprotected release must resolve without a license key'
);
expectWorkerDownloadService(
    ($requests[$endpoint] ?? '') === (string)json_encode(['TYPE' => 'LocalSTTWorkerMac']),
    'A malformed key must not be attached to the request body'
);

// File selection: universal and exact arch match, other arches are skipped.
$requests = [];
$armFile = array_merge($universal, ['arch' => 'arm64', 'download_link' => 'https://filescdn.miko.ru/arm/download']);
$x86File = array_merge($universal, ['arch' => 'x86_64', 'download_link' => 'https://filescdn.miko.ru/x86/download']);
$service = workerDownloadServiceUnderTest(
    $validKey,
    workerDownloadSuccessPayload([$x86File, $armFile]),
    $requests
);
expectWorkerDownloadService(
    $service->resolveDownload()->url === $armFile['download_link'],
    'The arm64 file must win over x86_64 when both are registered'
);
$service = workerDownloadServiceUnderTest(
    $validKey,
    workerDownloadSuccessPayload([$x86File, $universal]),
    $requests
);
expectWorkerDownloadService(
    $service->resolveDownload()->url === $universal['download_link'],
    'A universal file must be selectable among foreign arch tokens'
);

// Responses without a usable link mean the license has no entitlement.
$notEntitledResponses = [
    'result FAILURE' => '{"modules":[],"result":"FAILURE"}',
    'history without files' => '{"modules":{"version":"1.83","changelog":"..."},"result":"SUCCESS"}',
    'empty files list' => workerDownloadSuccessPayload([]),
    'foreign arch only' => workerDownloadSuccessPayload([$x86File]),
    'plain http link' => workerDownloadSuccessPayload([array_merge($universal, ['download_link' => 'http://filescdn.miko.ru/s/x/download'])]),
    'link missing' => workerDownloadSuccessPayload([['name' => 'LocalSTTWorkerMac.1.83.dmg', 'arch' => '', 'size' => 1, 'hash' => 'x']]),
];
foreach ($notEntitledResponses as $case => $response) {
    $requests = [];
    $service = workerDownloadServiceUnderTest($validKey, $response, $requests);
    $resolution = $service->resolveDownload();
    expectWorkerDownloadService(
        $resolution->availability === WorkerDownloadAvailability::NOT_ENTITLED,
        "{$case}: expected NOT_ENTITLED, got {$resolution->availability->value}"
    );
    expectWorkerDownloadService($resolution->url === '', "{$case}: no url may be resolved");
}

// Transport and payload failures are server errors, not entitlement answers.
foreach (['empty body' => '', 'garbage json' => 'not json at all'] as $case => $response) {
    $requests = [];
    $service = workerDownloadServiceUnderTest($validKey, $response, $requests);
    $resolution = $service->resolveDownload();
    expectWorkerDownloadService(
        $resolution->availability === WorkerDownloadAvailability::SERVER_ERROR,
        "{$case}: expected SERVER_ERROR, got {$resolution->availability->value}"
    );
}

$expectedFailureKeys = [
    WorkerDownloadAvailability::AVAILABLE->value => '',
    WorkerDownloadAvailability::NOT_ENTITLED->value => 'module_local_speech_to_text_WorkerDownloadUnavailable',
    WorkerDownloadAvailability::SERVER_ERROR->value => 'module_local_speech_to_text_WorkerDownloadServerError',
];
foreach (WorkerDownloadAvailability::cases() as $availability) {
    expectWorkerDownloadService(
        $service->failureTranslationKey($availability) === $expectedFailureKeys[$availability->value],
        "Availability {$availability->value} must map to its flash translation key"
    );
}

echo "Worker download service OK\n";
