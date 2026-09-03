<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use MikoPBX\Common\Models\PbxSettings;

enum WorkerDownloadAvailability: string
{
    case AVAILABLE = 'available';
    case NOT_ENTITLED = 'not_entitled';
    case SERVER_ERROR = 'server_error';
}

final class WorkerDownloadResolution
{
    public function __construct(
        public readonly WorkerDownloadAvailability $availability,
        public readonly string $url = ''
    ) {
    }
}

/**
 * Resolves the latest macOS worker release through the licensing server.
 *
 * The POST getLatestRelease endpoint is the primary contract: an unprotected
 * release answers with files[].download_link for anyone, an entitled one
 * only for a valid license key. The resolved link never reaches the browser
 * through the settings page — the controller hands it out as a redirect.
 */
class WorkerDownloadService
{
    /** Request/body field carrying the license key on the licensing server. */
    public const string LICENSE_PARAM = 'KEY';
    public const string PRODUCT_TYPE = 'LocalSTTWorkerMac';

    private const string RELEASE_INFO_ENDPOINT = 'https://lic.miko.ru/releases/v1/other/getLatestRelease';
    // The dmg is published as a universal build: an empty arch token in the
    // response matches every requested architecture, an explicit token only
    // its own.
    private const string DOWNLOAD_ARCH = 'arm64';
    private const int REQUEST_CONNECT_TIMEOUT = 3;
    private const int REQUEST_TIMEOUT = 10;
    private const string REQUEST_USER_AGENT = 'MikoPBX-ModuleLocalSpeechToText';

    public function __construct(
        private readonly ?Closure $licenseKeyProvider = null,
        private readonly ?Closure $postJson = null
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

    public function resolveDownload(): WorkerDownloadResolution
    {
        $body = ['TYPE' => self::PRODUCT_TYPE];
        $key = $this->licenseKey();
        if ($key !== '') {
            // The server ignores the key while the release is unprotected and
            // gates the download link on it once entitlement is enabled.
            $body[self::LICENSE_PARAM] = $key;
        }

        $raw = $this->postJson !== null
            ? (string)($this->postJson)(self::RELEASE_INFO_ENDPOINT, $body)
            : $this->postJsonWithCurl(self::RELEASE_INFO_ENDPOINT, $body);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return new WorkerDownloadResolution(WorkerDownloadAvailability::SERVER_ERROR);
        }
        if (($payload['result'] ?? '') !== 'SUCCESS') {
            return new WorkerDownloadResolution(WorkerDownloadAvailability::NOT_ENTITLED);
        }

        $files = $payload['modules']['files'] ?? null;
        if (!is_array($files)) {
            return new WorkerDownloadResolution(WorkerDownloadAvailability::NOT_ENTITLED);
        }

        $link = $this->selectDownloadLink($files);
        if ($link === '') {
            return new WorkerDownloadResolution(WorkerDownloadAvailability::NOT_ENTITLED);
        }

        return new WorkerDownloadResolution(WorkerDownloadAvailability::AVAILABLE, $link);
    }

    /**
     * Translation key for a failed download attempt; empty when available.
     */
    public function failureTranslationKey(WorkerDownloadAvailability $availability): string
    {
        return match ($availability) {
            WorkerDownloadAvailability::AVAILABLE => '',
            WorkerDownloadAvailability::NOT_ENTITLED => 'module_local_speech_to_text_WorkerDownloadUnavailable',
            WorkerDownloadAvailability::SERVER_ERROR => 'module_local_speech_to_text_WorkerDownloadServerError',
        };
    }

    /**
     * @param array<int, mixed> $files
     */
    private function selectDownloadLink(array $files): string
    {
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $arch = (string)($file['arch'] ?? '');
            if ($arch !== '' && $arch !== self::DOWNLOAD_ARCH) {
                continue;
            }
            $link = (string)($file['download_link'] ?? '');
            if (self::isTrustedDownloadLink($link)) {
                return $link;
            }
        }

        return '';
    }

    private static function isTrustedDownloadLink(string $link): bool
    {
        return str_starts_with($link, 'https://')
            && filter_var($link, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param array<string, string> $body
     */
    private function postJsonWithCurl(string $url, array $body): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return '';
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string)(json_encode($body) ?: ''),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::REQUEST_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_USERAGENT => self::REQUEST_USER_AGENT,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        ]);
        $raw = curl_exec($handle);

        return is_string($raw) ? $raw : '';
    }
}
