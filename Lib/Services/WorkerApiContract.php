<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use RuntimeException;

final class WorkerApiContract
{
    public const string CONTRACT = 'MikoPBX-Worker-API';
    public const int API_VERSION = 2;
    public const string MODULE_ID = 'ModuleLocalSpeechToText';
    public const string VERSION_HEADER = 'x-mikopbx-worker-api-version';
    public const array CAPABILITIES = [
        'worker-crud',
        'worker-processing-settings',
        'worker-update-license',
        'job-leases',
        'job-recordings',
        'job-results',
        'job-failures',
        'worker-platforms',
        'worker-platform-windows',
        'summary-leases',
        'summary-results',
        'summary-failures',
    ];

    public static function requireVersion(array $request): ?PBXApiResult
    {
        $headers = is_array($request['httpHeaders'] ?? null) ? $request['httpHeaders'] : [];
        if (($headers[self::VERSION_HEADER] ?? null) === (string)self::API_VERSION) {
            return null;
        }

        return self::upgradeRequired();
    }

    public static function contractResult(): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = true;
        $result->httpCode = 200;
        $result->data = [
            'contract' => self::CONTRACT,
            'api_version' => self::API_VERSION,
            'module' => self::MODULE_ID,
            'module_version' => self::moduleVersion(),
            'capabilities' => self::CAPABILITIES,
        ];

        return $result;
    }

    public static function upgradeRequired(): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->httpCode = 426;
        $result->data = [
            'error_code' => 'worker_upgrade_required',
            'module' => self::MODULE_ID,
            'module_version' => self::moduleVersion(),
            'required_worker_api_version' => self::API_VERSION,
        ];
        $result->messages['error'][] = 'Update the worker application to a release that supports worker API version 2';

        return $result;
    }

    private static function moduleVersion(): string
    {
        $moduleFile = dirname(__DIR__, 2) . '/module.json';
        $contents = file_get_contents($moduleFile);
        if ($contents === false) {
            throw new RuntimeException('Unable to read module metadata');
        }

        try {
            $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid module metadata', previous: $exception);
        }

        $version = is_array($metadata) ? ($metadata['version'] ?? null) : null;
        if (!is_scalar($version)) {
            throw new RuntimeException('Module metadata version must be scalar');
        }

        return (string)$version;
    }
}
