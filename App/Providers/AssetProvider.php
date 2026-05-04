<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Providers;

use MikoPBX\AdminCabinet\Providers\AssetProvider as AdminAssetProvider;
use Phalcon\Assets\Manager;

class AssetProvider
{
    private const string MODULE_UNIQUE_ID = 'ModuleLocalSpeechToText';
    private const string CSS_FILE = 'module-local-speech-to-text.css';
    private const string JS_FILE = 'module-local-speech-to-text.js';

    public static function addCss(Manager $assets): void
    {
        $assets
            ->collection(AdminAssetProvider::HEADER_CSS)
            ->addCss('css/cache/' . self::MODULE_UNIQUE_ID . '/' . self::CSS_FILE, true);
    }

    public static function addJs(Manager $assets): void
    {
        $assets
            ->collection(AdminAssetProvider::FOOTER_JS)
            ->addJs('js/cache/' . self::MODULE_UNIQUE_ID . '/' . self::JS_FILE, true);
    }
}
