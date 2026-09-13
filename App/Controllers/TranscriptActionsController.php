<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use Modules\ModuleLocalSpeechToText\App\Providers\MenuProvider;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\TranscriptDeletionBlockedException;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptDeletionService;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Throwable;

final class TranscriptActionsController extends LocalSpeechToTextBaseController
{
    public function exportAction(): void
    {
        $this->view->disable();
        if (!$this->usersUIViewCapabilities()->canViewTranscripts()) {
            $this->sendError(403, 'Forbidden');
            return;
        }

        $result = $this->accessibleResult((int)$this->request->getQuery('id', 'int', 0));
        if (!$result instanceof STTResult) {
            $this->sendError(404, 'Transcript not found');
            return;
        }

        $format = strtolower(trim((string)$this->request->getQuery('format', 'string', '')));
        if (!in_array($format, ['txt', 'json'], true)) {
            $this->sendError(400, 'Unsupported transcript format');
            return;
        }

        $service = $this->transcriptUserJsonService();
        $content = $format === 'json'
            ? $service->transcriptJson($this->callMetadata($result), (string)$result->transcript)
            : $service->transcriptText((string)$result->transcript);
        $this->response->setContentType($format === 'json' ? 'application/json' : 'text/plain', 'UTF-8');
        $this->response->setHeader(
            'Content-Disposition',
            sprintf('attachment; filename="local-stt-transcript-%d.%s"', (int)$result->id, $format)
        );
        $this->response->setContent($content);
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost() || !$this->usersUIViewCapabilities()->canDeleteTranscripts()) {
            $this->denyUsersUIAccess();
            return;
        }

        $resultId = (int)$this->request->getPost('result_id', 'int', 0);
        if (!$this->accessibleResult($resultId) instanceof STTResult) {
            $this->flash->error($this->translation->_('module_local_speech_to_text_TranscriptNotFound'));
            $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=transcripts');
            return;
        }

        try {
            $summary = (new TranscriptDeletionService())->deleteByResultId($resultId);
            $identity = $this->usersUIIdentity();
            LocalSpeechToTextLogger::event(
                'transcript',
                'transcript_deleted',
                'Local transcript deleted by administrator',
                LOG_NOTICE,
                [
                    'call_id' => $summary['call_id'],
                    'result_count' => $summary['results'],
                    'administrator' => $identity['_username'],
                ]
            );
            $this->flash->success($this->translation->_('module_local_speech_to_text_TranscriptDeleted'));
        } catch (TranscriptDeletionBlockedException) {
            $this->flash->error(
                $this->translation->_('module_local_speech_to_text_TranscriptDeleteProcessingActive')
            );
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::event(
                'transcript',
                'operation_failed',
                'STT transcript operation failed',
                LOG_ERR,
                ['operation' => 'delete_transcript', 'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception)]
            );
            $this->flash->error($this->translation->_('module_local_speech_to_text_TranscriptDeleteFailed'));
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=transcripts');
    }

    private function accessibleResult(int $resultId): ?STTResult
    {
        $result = STTResult::findFirstById($resultId);
        if (!$result instanceof STTResult) {
            return null;
        }
        $job = $result->STTJob;

        return $this->canAccessTranscript($job instanceof STTJob ? $job : null) ? $result : null;
    }

    /** @return array<string, mixed> */
    private function callMetadata(STTResult $result): array
    {
        $job = $result->STTJob;
        $job = $job instanceof STTJob ? $job : null;
        $recordings = $this->callRecordingService();
        $cdr = $job instanceof STTJob
            ? ($recordings->cdrRecordForJob($job) ?? $recordings->cdrRecordForResult($result))
            : $recordings->cdrRecordForResult($result);

        return [
            'id' => $job instanceof STTJob ? (string)$job->call_id : null,
            'started_at' => $cdr?->start,
            'ended_at' => $cdr?->endtime,
            'duration_seconds' => $result->duration === null ? null : (float)$result->duration,
            'source_number' => $cdr?->src_num,
            'destination_number' => $cdr?->dst_num,
            'language' => $result->language,
            'model' => $result->model_used,
        ];
    }

    private function sendError(int $statusCode, string $message): void
    {
        $this->response->setStatusCode($statusCode);
        $this->response->setContentType('text/plain', 'UTF-8');
        $this->response->setContent($message);
    }
}
