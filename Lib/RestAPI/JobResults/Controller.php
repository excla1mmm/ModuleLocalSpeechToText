<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobResults;

use MikoPBX\PBXCoreREST\Attributes\{ApiDataSchema,ApiOperation,ApiParameterRef,ApiResource,ApiResponse,HttpMapping,ResourceSecurity,SecurityType};
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;

#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/job-results', tags: ['Local STT Jobs'], description: 'module_local_speech_to_text_JobResultsApiDescription', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_job_results', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['PUT' => ['update']], resourceLevelMethods: ['update'], collectionLevelMethods: [], idPattern: '[0-9]+')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    public function handleCRUDRequest(?string $id = null): void
    {
        if ($this->processorClass === '') {
            $this->sendErrorResponse('Processor class not configured', 500);
            return;
        }

        $rawData = $this->request->getData();
        $hasBodyId = array_key_exists('id', $rawData);
        $bodyId = $hasBodyId ? $rawData['id'] : null;
        $hasDurationMs = array_key_exists('duration_ms', $rawData);
        $durationMs = $hasDurationMs ? $rawData['duration_ms'] : null;
        $structuredFields = [];
        foreach (['technical_info', 'transcript'] as $field) {
            if (is_array($rawData[$field] ?? null)) {
                $structuredFields[$field] = $rawData[$field];
                unset($rawData[$field]);
            }
        }
        $requestData = self::sanitizeData($rawData, $this->filter);
        foreach ($structuredFields as $field => $value) {
            $requestData[$field] = $this->sanitizeStructuredPayload($value);
        }
        if ($hasDurationMs) {
            $requestData['duration_ms'] = $durationMs;
        }
        if ($hasBodyId) {
            $requestData['_idFromRequestBody'] = $bodyId;
        }
        if ($id !== null && $id !== '') {
            $requestData['id'] = $id;
        }

        $httpMethod = $this->request->getMethod();
        $action = $this->mapHttpMethodToAction($httpMethod, $id !== null);
        if ($action === null) {
            $this->sendErrorResponse("Invalid HTTP method: {$httpMethod}", 405);
            return;
        }

        if (in_array($httpMethod, ['POST', 'PUT', 'PATCH'], true)) {
            $requestData['httpMethod'] = $httpMethod;
        }

        $this->sendRequestToBackendWorker($this->processorClass, $action, $requestData);
    }

    /**
     * Preserve JSON scalar types inside structured worker payloads while
     * applying the standard Core string sanitization recursively.
     *
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private function sanitizeStructuredPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitizeStructuredPayload($value);
                continue;
            }

            if (is_string($value)) {
                $sanitized = self::sanitizeData(['value' => $value], $this->filter);
                $payload[$key] = $sanitized['value'] ?? '';
            }
        }

        return $payload;
    }

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTJobResult')]
    #[ApiOperation(summary: 'module_local_speech_to_text_SubmitResult', description: 'module_local_speech_to_text_SubmitResultDesc', operationId: 'updateLocalSTTJobResult')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseResultAccepted')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    public function update(string $job_id): void
    {
    }
}
