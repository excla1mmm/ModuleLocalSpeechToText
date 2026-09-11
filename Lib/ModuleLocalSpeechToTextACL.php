<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use Modules\ModuleUsersUI\Lib\ACL\ACLInterface;

/** ModuleUsersUI permission ownership for the server-rendered STT interface. */
final class ModuleLocalSpeechToTextACL implements ACLInterface
{
    private const string API_BASE = '/pbxcore/api/v3/module-local-speech-to-text';
    private const string LEGACY_API = '/pbxcore/api/modules/module-local-speech-to-text';
    private const string CONTROLLER =
        'Modules\\ModuleLocalSpeechToText\\App\\Controllers\\ModuleLocalSpeechToTextController';
    private const string CDR_CONTROLLER =
        'Modules\\ModuleLocalSpeechToText\\App\\Controllers\\CdrTranscriptController';

    public static function getLinkedControllerActions(): array
    {
        return [
            self::CONTROLLER => [
                'transcripts' => [
                    self::CONTROLLER => ['index', 'transcript', 'recording'],
                    self::CDR_CONTROLLER => ['lookup', 'detail'],
                ],
                'settings' => [
                    self::CONTROLLER => ['index', 'save', 'saveModel'],
                ],
                'system' => [
                    self::CONTROLLER => [
                        'index',
                        'retryErrors',
                        'downloadWorker',
                        'generateWorkerApiKey',
                        'deleteWorkerApiKey',
                        'logs',
                    ],
                ],
            ],
        ];
    }

    public static function getAlwaysAllowed(): array
    {
        return [];
    }

    public static function getAlwaysDenied(): array
    {
        $paths = [
            '/call-transcripts',
            '/call-transcripts/events',
            '/job-failures',
            '/job-leases',
            '/job-recordings',
            '/job-results',
            '/jobs',
            '/transcripts',
            '/worker-api-contract',
            '/worker-processing-settings',
            '/worker-update-license',
            '/workers',
        ];

        return [self::LEGACY_API => '*'] + array_fill_keys(
            array_map(static fn(string $path): string => self::API_BASE . $path, $paths),
            '*'
        );
    }
}
