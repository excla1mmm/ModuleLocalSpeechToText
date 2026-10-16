<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/** Last signed Windows release, used until it is listed by the release server. */
final class WindowsWorkerDownload
{
    public const string RELEASE_SHARE_URL = 'https://files.miko.ru/s/jtRNNPcJ8Yz8XYT';
    public const string RELEASE_VERSION = '0.1.17';

    public static function msiUrl(): string
    {
        return self::fileUrl('x64.msi');
    }

    public static function zipUrl(): string
    {
        return self::fileUrl('win-x64.zip');
    }

    private static function fileUrl(string $suffix): string
    {
        $share = rtrim(trim(self::RELEASE_SHARE_URL), '/');
        $version = trim(self::RELEASE_VERSION);
        if (!preg_match('~^https://files\.miko\.ru/s/[A-Za-z0-9]+$~D', $share)
            || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            return '';
        }

        $filename = "MikoPBX-LocalSTTWorker-{$version}-{$suffix}";
        return $share . '/download?path=%2F&files=' . rawurlencode($filename);
    }
}
