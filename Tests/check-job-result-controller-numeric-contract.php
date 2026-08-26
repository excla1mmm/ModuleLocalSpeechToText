<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Controllers {
    abstract class BaseRestController
    {
        protected string $processorClass = '';
        protected mixed $request;
        protected mixed $filter = null;

        /** @var array<int, array{action: string, data: array<string, mixed>}> */
        public array $dispatches = [];

        public static function sanitizeData(array $data, mixed $filter): array
        {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = self::sanitizeData($value, $filter);
                } elseif (is_string($value)) {
                    $data[$key] = trim($value);
                } elseif (is_numeric($value)) {
                    $data[$key] = (int)str_replace('.', '', (string)$value);
                }
            }

            return $data;
        }

        protected function mapHttpMethodToAction(string $method, bool $hasId): ?string
        {
            return $method === 'PUT' && $hasId ? 'update' : null;
        }

        protected function sendErrorResponse(string $message, int $status = 400): void
        {
            throw new \RuntimeException("Unexpected controller error {$status}: {$message}");
        }

        protected function sendRequestToBackendWorker(string $processor, string $action, array $data): void
        {
            $this->dispatches[] = ['action' => $action, 'data' => $data];
        }

        public function setFakeRequest(object $request): void
        {
            $this->request = $request;
        }

        public function handleCRUDRequest(?string $id = null): void
        {
            $requestData = self::sanitizeData($this->request->getData(), $this->filter);
            if (isset($requestData['id'])) {
                $requestData['_idFromRequestBody'] = $requestData['id'];
            }
            if ($id !== null && $id !== '') {
                $requestData['id'] = $id;
            }
            $requestData['httpMethod'] = $this->request->getMethod();
            $this->sendRequestToBackendWorker($this->processorClass, 'update', $requestData);
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib {
    final class LocalSpeechToTextMain
    {
        public const API_BASE_PATH = '/pbxcore/api/v3/module-local-speech-to-text';
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobResults\Controller;

    require_once __DIR__ . '/../Lib/RestAPI/JobResults/Controller.php';

    function assertJobResultNumericContract(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $request = new class {
        /** @var array<string, mixed> */
        public array $data = [];

        public function getMethod(): string
        {
            return 'PUT';
        }

        /** @return array<string, mixed> */
        public function getData(): array
        {
            return $this->data;
        }
    };

    $payloads = [
        'Parakeet' => [
            'engine' => '  parakeet  ',
            'real_time_factor' => 0.022,
            'alternate_real_time_factor' => 0.29,
            'interruptions_per_minute' => 7.32,
            'words_per_minute' => 79.249,
        ],
        'WhisperKit' => [
            'engine' => '  whisperkit  ',
            'real_time_factor' => 0.433,
            'alternate_real_time_factor' => 0.29,
            'interruptions_per_minute' => 8.69,
            'words_per_minute' => 146.433,
        ],
    ];

    foreach ($payloads as $engineName => $metrics) {
        $request->data = [
            'id' => '042',
            'worker_uid' => '  worker-one  ',
            'lease_token' => '  lease-one  ',
            'duration_ms' => 65_570,
            'technical_info' => [
                'engine' => $metrics['engine'],
                'timing' => [
                    'real_time_factor' => $metrics['real_time_factor'],
                    'alternate_real_time_factor' => $metrics['alternate_real_time_factor'],
                ],
                'voice_analytics' => [
                    'call_level' => [
                        'interruptions_per_minute' => $metrics['interruptions_per_minute'],
                        'words_per_minute' => $metrics['words_per_minute'],
                        'words_count' => 87,
                        'enabled' => true,
                        'optional' => null,
                    ],
                ],
            ],
        ];

        $controller = new Controller();
        $controller->setFakeRequest($request);
        $controller->handleCRUDRequest('42');

        assertJobResultNumericContract(count($controller->dispatches) === 1, "{$engineName}: job result was not dispatched exactly once");
        $dispatch = $controller->dispatches[0];
        assertJobResultNumericContract($dispatch['action'] === 'update', "{$engineName}: job result used the wrong backend action");
        $data = $dispatch['data'];
        $technicalInfo = $data['technical_info'] ?? null;
        assertJobResultNumericContract(is_array($technicalInfo), "{$engineName}: technical_info was not preserved as an object");
        assertJobResultNumericContract(($technicalInfo['timing']['real_time_factor'] ?? null) === $metrics['real_time_factor'], "{$engineName}: three-decimal RTF was coerced to an integer");
        assertJobResultNumericContract(($technicalInfo['timing']['alternate_real_time_factor'] ?? null) === $metrics['alternate_real_time_factor'], "{$engineName}: two-decimal RTF was coerced to an integer");
        assertJobResultNumericContract(($technicalInfo['voice_analytics']['call_level']['interruptions_per_minute'] ?? null) === $metrics['interruptions_per_minute'], "{$engineName}: rate was coerced to an integer");
        assertJobResultNumericContract(($technicalInfo['voice_analytics']['call_level']['words_per_minute'] ?? null) === $metrics['words_per_minute'], "{$engineName}: WPM was coerced to an integer");
        assertJobResultNumericContract(($technicalInfo['voice_analytics']['call_level']['words_count'] ?? null) === 87, "{$engineName}: integer metrics changed type or value");
        assertJobResultNumericContract(($technicalInfo['voice_analytics']['call_level']['enabled'] ?? null) === true, "{$engineName}: boolean metrics changed type or value");
        assertJobResultNumericContract(array_key_exists('optional', $technicalInfo['voice_analytics']['call_level']) && $technicalInfo['voice_analytics']['call_level']['optional'] === null, "{$engineName}: null metrics were removed");
        assertJobResultNumericContract(($technicalInfo['engine'] ?? null) === strtolower($engineName), "{$engineName}: technical_info strings bypassed standard sanitization");
        assertJobResultNumericContract(($data['worker_uid'] ?? null) === 'worker-one', "{$engineName}: worker identity bypassed standard sanitization");
        assertJobResultNumericContract(($data['lease_token'] ?? null) === 'lease-one', "{$engineName}: lease token bypassed standard sanitization");
        assertJobResultNumericContract(($data['_idFromRequestBody'] ?? null) === '042', "{$engineName}: body job ID was not preserved for mismatch validation");
        assertJobResultNumericContract(($data['duration_ms'] ?? null) === 65_570, "{$engineName}: integer millisecond contract changed");
        assertJobResultNumericContract(($data['id'] ?? null) === '42', "{$engineName}: route job ID was not preserved");
        assertJobResultNumericContract(($data['httpMethod'] ?? null) === 'PUT', "{$engineName}: HTTP method metadata was not forwarded");
    }

    echo "Job result controller numeric contract OK\n";
}
