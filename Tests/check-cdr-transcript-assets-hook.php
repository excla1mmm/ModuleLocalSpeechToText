<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Config {
    abstract class ConfigClass
    {
    }
}

namespace Phalcon\Assets {
    final class Manager
    {
    }
}

namespace Phalcon\Mvc {
    final class Dispatcher
    {
        public function __construct(
            private readonly string $controller,
            private readonly string $action,
        ) {
        }

        public function getControllerName(): string
        {
            return $this->controller;
        }

        public function getActionName(): string
        {
            return $this->action;
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\App\Providers {
    use Phalcon\Assets\Manager;

    final class AssetProvider
    {
        public static int $cdrCalls = 0;

        public static function addCdrAssets(Manager $assets): void
        {
            self::$cdrCalls++;
        }
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\App\Providers\AssetProvider;
    use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextConf;
    use Phalcon\Assets\Manager;
    use Phalcon\Mvc\Dispatcher;

    require_once __DIR__ . '/../Lib/LocalSpeechToTextConf.php';

    $config = new LocalSpeechToTextConf();
    foreach (['CallDetailRecords', 'call-detail-records', 'call_detail_records'] as $controller) {
        AssetProvider::$cdrCalls = 0;
        $config->onAfterAssetsPrepared(new Manager(), new Dispatcher($controller, 'index'));
        if (AssetProvider::$cdrCalls !== 1) {
            fwrite(STDERR, "Assertion failed: CDR assets missing for {$controller}\n");
            exit(1);
        }
    }

    foreach ([['module-local-speech-to-text', 'index'], ['call-detail-records', 'view']] as $route) {
        AssetProvider::$cdrCalls = 0;
        $config->onAfterAssetsPrepared(new Manager(), new Dispatcher($route[0], $route[1]));
        if (AssetProvider::$cdrCalls !== 0) {
            fwrite(STDERR, "Assertion failed: CDR assets injected into an unrelated route\n");
            exit(1);
        }
    }

    echo "CDR transcript asset hook contract OK\n";
}
