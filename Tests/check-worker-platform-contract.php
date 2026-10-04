<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
        /** @var array<string, string> */
        public static array $values = [];
        public function setSource(string $source): void {}
        public function initialize(): void {}
        public static function findFirst(array $parameters = []): ?object
        {
            $key = $parameters['bind']['key'] ?? null;
            if (!is_string($key) || !array_key_exists($key, self::$values)) {
                return null;
            }

            $setting = new static();
            $setting->key = $key;
            $setting->value = self::$values[$key];

            return $setting;
        }
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModelMarketplaceService;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Models/STTSetting.php';
    require_once __DIR__ . '/../Lib/Services/ModelMarketplaceService.php';

    function expectWorkerPlatform(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    // Every preset belongs to exactly one known platform.
    foreach (STTSetting::modelPresets() as $modelId => $preset) {
        expectWorkerPlatform(
            array_key_exists((string)($preset['platform'] ?? ''), STTSetting::PLATFORM_PRESETS),
            "{$modelId} must declare a known platform"
        );
    }
    foreach (['large-v3-turbo', 'podlodka', 'large-v3', 'parakeet-tdt-0.6b-v3'] as $modelId) {
        expectWorkerPlatform(STTSetting::modelPlatform($modelId) === 'macos', "{$modelId} must stay a macOS model");
    }
    foreach (['gigaam-v3', 't-one', 'faster-whisper-large-v3-turbo', 'parakeet-tdt-0.6b-v3-linux', 'gigaam-multilingual'] as $modelId) {
        expectWorkerPlatform(STTSetting::modelPlatform($modelId) === 'linux', "{$modelId} must be a Linux model");
    }

    // Core ML artifacts never reach Linux, and Linux artifacts never reach macOS.
    foreach (STTSetting::modelPresets() as $modelId => $preset) {
        $coreMl = str_ends_with((string)$preset['artifact_type'], '-coreml');
        expectWorkerPlatform(
            $coreMl === ($preset['platform'] === 'macos'),
            "{$modelId} artifact type does not match its platform"
        );
    }

    // Each platform default is a model of that platform.
    foreach (array_keys(STTSetting::PLATFORM_PRESETS) as $platform) {
        expectWorkerPlatform(
            STTSetting::modelPlatform(STTSetting::defaultModelForPlatform($platform)) === $platform,
            "Default model for {$platform} must belong to {$platform}"
        );
    }

    // The PBX platform follows the selected model; fresh and legacy installs are macOS.
    ModulesModelsBase::$values = [];
    expectWorkerPlatform(STTSetting::selectedPlatform() === 'macos', 'Fresh settings must select the macOS platform');
    ModulesModelsBase::$values = ['selected_model' => 'large-v3'];
    expectWorkerPlatform(STTSetting::selectedPlatform() === 'macos', 'A legacy WhisperKit selection must stay macOS');
    ModulesModelsBase::$values = ['selected_model' => 'gigaam-v3'];
    expectWorkerPlatform(STTSetting::selectedPlatform() === 'linux', 'A Linux model selection must switch the PBX to Linux');
    ModulesModelsBase::$values = ['selected_model' => 'unknown-model'];
    expectWorkerPlatform(STTSetting::selectedPlatform() === 'macos', 'An unknown stored model falls back to the macOS default');

    // Worker platform declared on a lease request.
    $requestMatrix = [
        [null, 'macos'],
        ['', 'macos'],
        ['  ', 'macos'],
        ['macos', 'macos'],
        ['linux', 'linux'],
        [' Linux ', 'linux'],
        ['windows', null],
        [['linux'], null],
        [1, null],
    ];
    foreach ($requestMatrix as [$value, $expected]) {
        expectWorkerPlatform(
            STTSetting::workerPlatformFromRequest($value) === $expected,
            'Unexpected worker platform for ' . var_export($value, true)
        );
    }

    // Every Linux model ships a pinned revision and a SHA-256 manifest: the worker refuses anything else.
    foreach (STTSetting::modelPresets() as $modelId => $preset) {
        if ($preset['platform'] !== 'linux') {
            continue;
        }
        expectWorkerPlatform(
            preg_match('/^[0-9a-f]{40}$/', (string)($preset['revision'] ?? '')) === 1,
            "{$modelId} must pin a 40-character repository revision"
        );
        $manifest = $preset['sha256_manifest'] ?? [];
        expectWorkerPlatform(is_array($manifest) && $manifest !== [], "{$modelId} must have a SHA-256 manifest");
        foreach ($manifest as $entry) {
            expectWorkerPlatform(
                preg_match('/^[A-Za-z0-9._-]+$/', (string)$entry['path']) === 1
                    && (int)$entry['size'] > 0
                    && preg_match('/^[0-9a-f]{64}$/', (string)$entry['sha256']) === 1,
                "{$modelId} has a malformed manifest entry"
            );
        }
    }
    expectWorkerPlatform(STTSetting::defaultModelForPlatform('linux') === 'gigaam-v3', 'GigaAM v3 is the Linux default');

    // Russian-only engines keep automatic language and reject other explicit languages.
    foreach (['gigaam-v3', 't-one'] as $modelId) {
        expectWorkerPlatform(STTSetting::modelSupportsLanguage($modelId, 'auto'), "{$modelId} must allow Auto");
        expectWorkerPlatform(STTSetting::modelSupportsLanguage($modelId, 'ru'), "{$modelId} must allow Russian");
        expectWorkerPlatform(!STTSetting::modelSupportsLanguage($modelId, 'en'), "{$modelId} must reject English");
    }

    // Marketplace rows carry their platform and the platform switch marks the selected one.
    $translation = new class {
        public function _(string $key): string { return $key; }
    };
    ModulesModelsBase::$values = ['selected_model' => 't-one'];
    $service = new ModelMarketplaceService($translation);
    $rowsById = array_column($service->modelMarketplaceRows(), null, 'id');
    expectWorkerPlatform(($rowsById['t-one']['platform'] ?? '') === 'linux', 'Marketplace row must expose its platform');
    expectWorkerPlatform(($rowsById['parakeet-tdt-0.6b-v3']['platform'] ?? '') === 'macos', 'Marketplace row must expose its platform');
    expectWorkerPlatform(
        ($rowsById['gigaam-v3']['technical'] ?? '') === 'GigaAM · ONNX · Russian · MIT',
        'GigaAM technical label mismatch'
    );
    $platformRows = $service->platformRows();
    expectWorkerPlatform(array_column($platformRows, 'id') === ['macos', 'linux'], 'Platform switch must list macOS then Linux');
    expectWorkerPlatform(
        array_column($platformRows, 'selected', 'id') === ['macos' => false, 'linux' => true],
        'Platform switch must mark the platform of the selected model'
    );
    ModulesModelsBase::$values = [];

    // getNextJob() must decide the platform before it claims anything.
    $main = (string)file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextMain.php');
    $methodStart = strpos($main, 'public static function getNextJob(');
    $methodEnd = strpos($main, 'public static function getWorkerProcessingSettings(');
    $getNext = $methodStart === false || $methodEnd === false ? '' : substr($main, $methodStart, $methodEnd - $methodStart);
    expectWorkerPlatform($getNext !== '', 'Cannot inspect getNextJob()');
    expectWorkerPlatform(
        str_contains($getNext, "STTSetting::workerPlatformFromRequest(\$data['worker_platform'] ?? null)"),
        'getNextJob() must read the worker platform from the lease request'
    );
    expectWorkerPlatform(
        str_contains($getNext, '$job = $platformMismatch ? null : STTJob::claimNextForWorker($worker);'),
        'getNextJob() must not claim a job for a worker of another platform'
    );
    expectWorkerPlatform(
        str_contains($getNext, "\$payload['platform_mismatch'] = true;")
            && str_contains($getNext, "\$payload['required_platform'] = \$requiredPlatform;"),
        'A platform mismatch must be reported to the worker'
    );

    echo "Worker platform contract OK\n";
}
