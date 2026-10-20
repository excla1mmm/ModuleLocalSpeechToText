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
        private readonly ?Closure $requestRedirect = null,
        private readonly ?Closure $requestSize = null
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

        return $this->resolveReleaseDownload(self::WINDOWS_RELEASE_DOWNLOAD_ENDPOINT, 'x86_64', $format);
    }

    /**
     * File name and size of each Windows package for the download menu.
     * A package the release server does not list, or whose size is unknown, has null fields.
     *
     * @return list<array{format: string, filename: ?string, size: ?int}>
     */
    public function describeWindowsPackages(): array
    {
        $packages = [];
        foreach (['msi', 'zip'] as $format) {
            $resolution = $this->resolveWindowsDownload($format);
            $filename = $resolution->availability === WorkerDownloadAvailability::AVAILABLE
                ? self::windowsPackageFilename($resolution->url, $format)
                : null;
            $size = $filename === null ? null : ($this->requestSize !== null
                ? ($this->requestSize)($resolution->url)
                : $this->requestSizeWithCurl($resolution->url));
            $packages[] = [
                'format' => $format,
                'filename' => $filename,
                'size' => is_int($size) && $size > 0 ? $size : null,
            ];
        }

        return $packages;
    }

    private function resolveReleaseDownload(string $baseUrl, string $arch, string $ext): WorkerDownloadResolution
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

    /** Only a release file name of the expected shape is shown to the administrator. */
    private static function windowsPackageFilename(string $url, string $format): ?string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $name = is_string($query['files'] ?? null)
            ? $query['files']
            : rawurldecode(basename((string)parse_url($url, PHP_URL_PATH)));
        $suffix = $format === 'msi' ? 'x64\.msi' : 'win-x64\.zip';

        return preg_match('/^MikoPBX-LocalSTTWorker-\d+\.\d+\.\d+-' . $suffix . '$/D', $name) === 1 ? $name : null;
    }

    private function requestSizeWithCurl(string $url): ?int
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::REQUEST_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_USERAGENT => self::REQUEST_USER_AGENT,
        ]);
        if (curl_exec($handle) === false || (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
            return null;
        }
        $size = (int)curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);

        return $size > 0 ? $size : null;
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
