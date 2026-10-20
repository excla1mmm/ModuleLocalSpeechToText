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
 * @param array<int, string> $requests
 */
function workerDownloadServiceUnderTest(
    ?string $licenseHash,
    int $status,
    string $location,
    array &$requests
): WorkerDownloadService {
    return new WorkerDownloadService(
        static fn (): ?string => $licenseHash,
        static function (string $url) use (&$requests, $status, $location): array {
            $requests[] = $url;

            return ['status' => $status, 'location' => $location];
        }
    );
}

$licenseHash = str_repeat('a', 64);
$downloadLocation = 'https://filescdn.miko.ru/s/arm64-worker/download';
$licensedEndpoint = 'https://releases.mikopbx.com/releases/v1/appcast/downloadLatest/LocalSTTWorkerMac'
    . '?lk=' . $licenseHash . '&arch=arm64&ext=dmg';
$publicEndpoint = 'https://releases.mikopbx.com/releases/v1/appcast/downloadLatest/LocalSTTWorkerMac'
    . '?arch=arm64&ext=dmg';

// A valid hash must authorize the arm64 DMG request without exposing the raw PBX key.
$requests = [];
$service = workerDownloadServiceUnderTest($licenseHash, 302, $downloadLocation, $requests);
$resolution = $service->resolveDownload();
expectWorkerDownloadService(
    $resolution->availability === WorkerDownloadAvailability::AVAILABLE,
    'A 302 release redirect must resolve as available'
);
expectWorkerDownloadService($resolution->url === $downloadLocation, 'The trusted redirect target must be returned');
expectWorkerDownloadService($requests === [$licensedEndpoint], 'The request must contain lk, arm64, and dmg parameters');

// Public releases remain downloadable when the PBX has no license.
$requests = [];
$service = workerDownloadServiceUnderTest(null, 302, $downloadLocation, $requests);
expectWorkerDownloadService(
    $service->resolveDownload()->availability === WorkerDownloadAvailability::AVAILABLE,
    'A public release must resolve without a license hash'
);
expectWorkerDownloadService($requests === [$publicEndpoint], 'An absent license hash must be omitted from the URL');

foreach (['msi', 'zip'] as $format) {
    $requests = [];
    $service = workerDownloadServiceUnderTest($licenseHash, 302, $downloadLocation, $requests);
    $resolution = $service->resolveWindowsDownload($format);
    expectWorkerDownloadService(
        $requests === ['https://releases.mikopbx.com/releases/v1/other/downloadLatest/LocalSTTWorkerWin'
            . '?lk=' . $licenseHash . '&arch=x86_64&ext=' . $format],
        "Windows {$format} must use the same licensed release lookup as macOS"
    );
    expectWorkerDownloadService($resolution->url === $downloadLocation, "Windows {$format} must follow the release redirect");

    $requests = [];
    $service = workerDownloadServiceUnderTest($licenseHash, 404, '', $requests);
    expectWorkerDownloadService(
        $service->resolveWindowsDownload($format)->availability === WorkerDownloadAvailability::SERVER_ERROR,
        "Windows {$format} must report a server error when the release server lists no release"
    );
}

$requests = [];
$service = workerDownloadServiceUnderTest($licenseHash, 302, $downloadLocation, $requests);
expectWorkerDownloadService(
    $service->resolveWindowsDownload('exe')->availability === WorkerDownloadAvailability::SERVER_ERROR && $requests === [],
    'Unsupported Windows download formats must not contact the release server'
);

// The Windows menu shows the release file name and size; anything unexpected stays unknown.
$sizeRequests = [];
$service = new WorkerDownloadService(
    static fn (): ?string => $licenseHash,
    static fn (string $url): array => [
        'status' => 302,
        'location' => 'https://filescdn.miko.ru/s/win/download?path=%2F&files=MikoPBX-LocalSTTWorker-0.1.22-'
            . (str_contains($url, 'ext=msi') ? 'x64.msi' : 'win-x64.zip'),
    ],
    static function (string $url) use (&$sizeRequests): int {
        $sizeRequests[] = $url;

        return str_contains($url, '.msi') ? 95674368 : 119376002;
    }
);
expectWorkerDownloadService(
    $service->describeWindowsPackages() === [
        ['format' => 'msi', 'filename' => 'MikoPBX-LocalSTTWorker-0.1.22-x64.msi', 'size' => 95674368],
        ['format' => 'zip', 'filename' => 'MikoPBX-LocalSTTWorker-0.1.22-win-x64.zip', 'size' => 119376002],
    ] && count($sizeRequests) === 2,
    'Windows packages must be described with the release file name and size'
);

$sizeRequests = [];
$service = new WorkerDownloadService(
    static fn (): ?string => $licenseHash,
    static fn (string $url): array => ['status' => 302, 'location' => 'https://filescdn.miko.ru/s/win/download?files=%3Cscript%3E.msi'],
    static function (string $url) use (&$sizeRequests): int {
        $sizeRequests[] = $url;

        return 1;
    }
);
expectWorkerDownloadService(
    $service->describeWindowsPackages() === [
        ['format' => 'msi', 'filename' => null, 'size' => null],
        ['format' => 'zip', 'filename' => null, 'size' => null],
    ] && $sizeRequests === [],
    'An unexpected release file name must not be shown or probed'
);

$service = new WorkerDownloadService(
    static fn (): ?string => $licenseHash,
    static fn (string $url): array => ['status' => 403, 'location' => ''],
    static fn (string $url): int => 1
);
expectWorkerDownloadService(
    array_column($service->describeWindowsPackages(), 'filename') === [null, null],
    'A denied Windows release must leave the package names unknown'
);

// Only a canonical lowercase SHA-256 value may be sent as lk.
foreach ([str_repeat('A', 64), str_repeat('a', 63), 'not-a-hash'] as $invalidHash) {
    $requests = [];
    $service = workerDownloadServiceUnderTest($invalidHash, 302, $downloadLocation, $requests);
    $service->resolveDownload();
    expectWorkerDownloadService($requests === [$publicEndpoint], 'An invalid hash must not be sent to the release server');
}

$requests = [];
$service = workerDownloadServiceUnderTest($licenseHash, 403, '', $requests);
expectWorkerDownloadService(
    $service->resolveDownload()->availability === WorkerDownloadAvailability::NOT_ENTITLED,
    'HTTP 403 must report a missing update entitlement'
);
expectWorkerDownloadService(
    $service->resolveWindowsDownload('msi')->availability === WorkerDownloadAvailability::NOT_ENTITLED,
    'HTTP 403 must report a missing entitlement for the Windows worker too'
);

foreach (
    [
        'unexpected success response' => [200, $downloadLocation],
        'release service error' => [500, ''],
        'missing redirect target' => [302, ''],
        'insecure redirect target' => [302, 'http://filescdn.miko.ru/download'],
    ] as $case => [$status, $location]
) {
    $requests = [];
    $service = workerDownloadServiceUnderTest($licenseHash, $status, $location, $requests);
    $resolution = $service->resolveDownload();
    expectWorkerDownloadService(
        $resolution->availability === WorkerDownloadAvailability::SERVER_ERROR,
        "{$case}: expected SERVER_ERROR, got {$resolution->availability->value}"
    );
    expectWorkerDownloadService($resolution->url === '', "{$case}: no redirect URL may be returned");
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
