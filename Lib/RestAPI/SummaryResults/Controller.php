<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryResults;

use MikoPBX\PBXCoreREST\Attributes\ApiDataSchema;
use MikoPBX\PBXCoreREST\Attributes\ApiOperation;
use MikoPBX\PBXCoreREST\Attributes\ApiParameterRef;
use MikoPBX\PBXCoreREST\Attributes\ApiResource;
use MikoPBX\PBXCoreREST\Attributes\ApiResponse;
use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
use MikoPBX\PBXCoreREST\Attributes\SecurityType;
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;

#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/summary-results', tags: ['Local STT Summaries'], description: 'module_local_speech_to_text_SummaryResultsApiDescription', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_summary_results', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['PUT' => ['update']], resourceLevelMethods: ['update'], collectionLevelMethods: [], idPattern: '[0-9]+')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    /**
     * The model answer in `content` is JSON; the standard string sanitizer
     * would HTML-encode its quotes. It is passed through unchanged and the
     * module parses, strips and bounds it (SummaryContentParser).
     */
    public function handleCRUDRequest(?string $id = null): void
    {
        $rawData = $this->request->getData();
        $content = is_string($rawData['content'] ?? null) ? $rawData['content'] : null;
        unset($rawData['content']);
        $hasBodyId = array_key_exists('id', $rawData);
        $bodyId = $hasBodyId ? $rawData['id'] : null;

        $requestData = self::sanitizeData($rawData, $this->filter);
        if ($content !== null) {
            $requestData['content'] = $content;
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
        $requestData['httpMethod'] = $httpMethod;

        $this->sendRequestToBackendWorker($this->processorClass, $action, $requestData);
    }

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTSummaryResult')]
    #[ApiOperation(summary: 'module_local_speech_to_text_SubmitSummary', description: 'module_local_speech_to_text_SubmitSummaryDesc', operationId: 'updateLocalSTTSummaryResult')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseSummaryAccepted')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    public function update(string $summary_id): void {}
}
