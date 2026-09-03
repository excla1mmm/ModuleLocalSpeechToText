<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use MikoPBX\Common\Models\PbxSettings;
use RuntimeException;

enum WorkerDownloadAvailability: string
{
    case AVAILABLE = 'available';
    case NO_LICENSE = 'no_license';
    case NOT_ENTITLED = 'not_entitled';
    case SERVER_ERROR = 'server_error';
}

/**
 * Resolves the licensed download link for the macOS worker application.
 *
 * The license key never reaches the browser through the settings page: the
 * controller builds the URL server-side and hands it out only as a redirect
 * target after the availability probe.
 */
class WorkerDownloadService
{
    /** Query parameter carrying the license key on the licensing server. */
    public const string LICENSE_PARAM = 'KEY';

    private const string DOWNLOAD_ENDPOINT = 'https://lm.miko.ru/releases/v1/other/downloadLatest/LocalSTTWorkerMac';
    private const string DOWNLOAD_ARCH = 'arm64';
    private const string DOWNLOAD_EXT = 'dmg';
    private const int PROBE_CONNECT_TIMEOUT = 3;
    private const int PROBE_TIMEOUT = 5;
    private const string PROBE_USER_AGENT = 'MikoPBX-ModuleLocalSpeechToText';

    public function __construct(
        private readonly ?Closure $licenseKeyProvider = null,
        private readonly ?Closure $headStatusProbe = null
    ) {
    }

    /**
     * MikoPBX license key shape: 28 characters with the MIKO- prefix, the
     * same check the core applies before exposing the key to the admin UI.
     */
    public static function isLicenseKeyShape(string $key): bool
    {
        return strlen($key) === 28 && str_starts_with($key, 'MIKO-');
    }

    /**
     * Validated license key, or an empty string when the PBX has none.
     */
    public function licenseKey(): string
    {
        $key = $this->licenseKeyProvider !== null
            ? (string)($this->licenseKeyProvider)()
            : PbxSettings::getValueByKey(PbxSettings::PBX_LICENSE);

        return self::isLicenseKeyShape($key) ? $key : '';
    }

    public function isConfigured(): bool
    {
        return $this->licenseKey() !== '';
    }

    /**
     * @throws RuntimeException when no valid license key is configured
     */
    public function downloadUrl(): string
    {
        $key = $this->licenseKey();
        if ($key === '') {
            throw new RuntimeException('A valid MikoPBX license key is required for the worker download');
        }

        return self::DOWNLOAD_ENDPOINT . '?' . http_build_query([
            'arch' => self::DOWNLOAD_ARCH,
            'ext' => self::DOWNLOAD_EXT,
            self::LICENSE_PARAM => $key,
        ]);
    }

    /**
     * Availability is classified without following the redirect: the licensing
     * server answers 2xx/3xx for an entitled key and 404/403 otherwise.
     */
    public function probeAvailability(): WorkerDownloadAvailability
    {
        try {
            $url = $this->downloadUrl();
        } catch (RuntimeException) {
            return WorkerDownloadAvailability::NO_LICENSE;
        }

        $status = $this->headStatusProbe !== null
            ? (int)($this->headStatusProbe)($url)
            : $this->probeStatusWithCurl($url);

        return match (true) {
            $status >= 200 && $status < 400 => WorkerDownloadAvailability::AVAILABLE,
            in_array($status, [401, 403, 404, 410], true) => WorkerDownloadAvailability::NOT_ENTITLED,
            default => WorkerDownloadAvailability::SERVER_ERROR,
        };
    }

    /**
     * Translation key for a failed download attempt; empty when available.
     */
    public function failureTranslationKey(WorkerDownloadAvailability $availability): string
    {
        return match ($availability) {
            WorkerDownloadAvailability::AVAILABLE => '',
            WorkerDownloadAvailability::NO_LICENSE => 'module_local_speech_to_text_WorkerDownloadNoLicense',
            WorkerDownloadAvailability::NOT_ENTITLED => 'module_local_speech_to_text_WorkerDownloadUnavailable',
            WorkerDownloadAvailability::SERVER_ERROR => 'module_local_speech_to_text_WorkerDownloadServerError',
        };
    }

    private function probeStatusWithCurl(string $url): int
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return 0;
        }

        curl_setopt_array($handle, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::PROBE_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::PROBE_TIMEOUT,
            CURLOPT_USERAGENT => self::PROBE_USER_AGENT,
        ]);
        curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status;
    }
}
