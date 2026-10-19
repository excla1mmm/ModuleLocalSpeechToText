<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;

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

/** Resolves worker packages through the public release endpoint. */
class WorkerDownloadService
{
    private const string RELEASE_DOWNLOAD_ENDPOINT = 'https://releases.mikopbx.com/releases/v1/appcast/downloadLatest/LocalSTTWorkerMac';
    private const string WINDOWS_RELEASE_DOWNLOAD_ENDPOINT = 'https://releases.mikopbx.com/releases/v1/other/downloadLatest/LocalSTTWorkerWin';
    private const int REQUEST_CONNECT_TIMEOUT = 3;
    private const int REQUEST_TIMEOUT = 10;
    private const string REQUEST_USER_AGENT = 'MikoPBX-ModuleLocalSpeechToText';

    public function __construct(
        private readonly ?Closure $licenseHashProvider = null,
        private readonly ?Closure $requestRedirect = null
    ) {
    }

    public function resolveDownload(): WorkerDownloadResolution
    {
        return $this->resolveReleaseDownload(self::RELEASE_DOWNLOAD_ENDPOINT, 'arm64', 'dmg');
    }

    public function resolveWindowsDownload(string $format): WorkerDownloadResolution
    {
        if (!in_array($format, ['msi', 'zip'], true)) {
            return new WorkerDownloadResolution(WorkerDownloadAvailability::SERVER_ERROR);
        }

        return $this->resolveReleaseDownload(self::WINDOWS_RELEASE_DOWNLOAD_ENDPOINT, 'x86_64', $format,
            $format === 'msi' ? WindowsWorkerDownload::msiUrl() : WindowsWorkerDownload::zipUrl());
    }

    private function resolveReleaseDownload(string $baseUrl, string $arch, string $ext, string $fallback = ''): WorkerDownloadResolution
    {
        $query = [];
        $licenseHash = $this->licenseHash();
        if ($licenseHash !== null) {
            $query['lk'] = $licenseHash;
        }
        $query += ['arch' => $arch, 'ext' => $ext];
        $endpoint = $baseUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $response = $this->requestRedirect !== null
            ? ($this->requestRedirect)($endpoint)
            : $this->requestRedirectWithCurl($endpoint);
        $status = is_array($response) ? (int)($response['status'] ?? 0) : 0;
        if ($status === 403) {
            return new WorkerDownloadResolution(WorkerDownloadAvailability::NOT_ENTITLED);
        }
        if ($status === 404 && $fallback !== '') {
            return new WorkerDownloadResolution(WorkerDownloadAvailability::AVAILABLE, $fallback);
        }
        $location = is_array($response) ? (string)($response['location'] ?? '') : '';
        if ($status !== 302 || !self::isTrustedDownloadLink($location)) {
            return new WorkerDownloadResolution(WorkerDownloadAvailability::SERVER_ERROR);
        }

        return new WorkerDownloadResolution(WorkerDownloadAvailability::AVAILABLE, $location);
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

    private function licenseHash(): ?string
    {
        $hash = $this->licenseHashProvider !== null
            ? trim((string)($this->licenseHashProvider)())
            : (new WorkerUpdateLicenseService())->licenseHash();

        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1 ? $hash : null;
    }

    private static function isTrustedDownloadLink(string $link): bool
    {
        return str_starts_with($link, 'https://')
            && filter_var($link, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @return array{status: int, location: string}
     */
    private function requestRedirectWithCurl(string $url): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'location' => ''];
        }

        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::REQUEST_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_USERAGENT => self::REQUEST_USER_AGENT,
        ]);
        if (curl_exec($handle) === false) {
            return ['status' => 0, 'location' => ''];
        }

        return [
            'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'location' => (string)curl_getinfo($handle, CURLINFO_REDIRECT_URL),
        ];
    }
}
