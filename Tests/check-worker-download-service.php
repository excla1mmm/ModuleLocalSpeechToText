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

function workerDownloadProbeRecordingService(string $licenseKey, int $probeStatus, array &$probeCalls): WorkerDownloadService
{
    return new WorkerDownloadService(
        static fn (): string => $licenseKey,
        static function (string $url) use (&$probeCalls, $probeStatus): int {
            $probeCalls[] = $url;

            return $probeStatus;
        }
    );
}

$validKey = 'MIKO-5YSFR-ENOU5-Q29SY-Q3HSM';
$expectedUrl = 'https://lm.miko.ru/releases/v1/other/downloadLatest/LocalSTTWorkerMac'
    . '?arch=arm64&ext=dmg&KEY=' . $validKey;

expectWorkerDownloadService(WorkerDownloadService::LICENSE_PARAM === 'KEY', 'License key query parameter must be KEY');
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

$licensed = new WorkerDownloadService(static fn (): string => $validKey);
expectWorkerDownloadService($licensed->licenseKey() === $validKey, 'Valid key must pass through the provider');
expectWorkerDownloadService($licensed->isConfigured(), 'A valid key must mark the service configured');
expectWorkerDownloadService($licensed->downloadUrl() === $expectedUrl, 'downloadUrl must build the licensed download URL');

$unlicensed = new WorkerDownloadService(static fn (): string => 'not-a-license-key');
expectWorkerDownloadService($unlicensed->licenseKey() === '', 'A malformed key must be normalized to an empty string');
expectWorkerDownloadService(!$unlicensed->isConfigured(), 'A missing key must leave the service unconfigured');
try {
    $unlicensed->downloadUrl();
    throw new RuntimeException('downloadUrl must refuse to build a URL without a license key');
} catch (RuntimeException) {
    // expected
}

$availabilityByStatus = [
    200 => WorkerDownloadAvailability::AVAILABLE,
    204 => WorkerDownloadAvailability::AVAILABLE,
    302 => WorkerDownloadAvailability::AVAILABLE,
    401 => WorkerDownloadAvailability::NOT_ENTITLED,
    403 => WorkerDownloadAvailability::NOT_ENTITLED,
    404 => WorkerDownloadAvailability::NOT_ENTITLED,
    410 => WorkerDownloadAvailability::NOT_ENTITLED,
    500 => WorkerDownloadAvailability::SERVER_ERROR,
    503 => WorkerDownloadAvailability::SERVER_ERROR,
    0 => WorkerDownloadAvailability::SERVER_ERROR,
];
foreach ($availabilityByStatus as $status => $expected) {
    $probeCalls = [];
    $service = workerDownloadProbeRecordingService($validKey, $status, $probeCalls);
    $availability = $service->probeAvailability();
    expectWorkerDownloadService(
        $availability === $expected,
        "HTTP {$status} must map to {$expected->value}, got {$availability->value}"
    );
    expectWorkerDownloadService(
        $probeCalls === [$expectedUrl],
        "HTTP {$status} probe must receive exactly the licensed download URL"
    );
}

$noLicenseProbeCalls = [];
$noLicense = workerDownloadProbeRecordingService('', 200, $noLicenseProbeCalls);
expectWorkerDownloadService(
    $noLicense->probeAvailability() === WorkerDownloadAvailability::NO_LICENSE,
    'A missing license key must map to NO_LICENSE without a network probe'
);
expectWorkerDownloadService($noLicenseProbeCalls === [], 'NO_LICENSE must not perform the network probe');

$expectedFailureKeys = [
    WorkerDownloadAvailability::AVAILABLE->value => '',
    WorkerDownloadAvailability::NO_LICENSE->value => 'module_local_speech_to_text_WorkerDownloadNoLicense',
    WorkerDownloadAvailability::NOT_ENTITLED->value => 'module_local_speech_to_text_WorkerDownloadUnavailable',
    WorkerDownloadAvailability::SERVER_ERROR->value => 'module_local_speech_to_text_WorkerDownloadServerError',
];
foreach (WorkerDownloadAvailability::cases() as $availability) {
    expectWorkerDownloadService(
        $licensed->failureTranslationKey($availability) === $expectedFailureKeys[$availability->value],
        "Availability {$availability->value} must map to its flash translation key"
    );
}

echo "Worker download service OK\n";
