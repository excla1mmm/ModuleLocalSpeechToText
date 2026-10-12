<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/** Download link of the native Windows worker shown on the Workers tab. */
final class WindowsWorkerDownload
{
    // The Windows worker has no published release yet. While the URL is empty the
    // Workers tab shows a disabled "coming soon" button; filling it enables the button.
    public const string URL = '';

    public static function url(): string
    {
        return trim(self::URL);
    }
}
