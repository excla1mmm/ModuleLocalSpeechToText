<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use Modules\ModuleLocalSpeechToText\Lib\Exceptions\CdrTranscriptNotFoundException;

final class CdrTranscriptController extends LocalSpeechToTextBaseController
{
    public function lookupAction(): void
    {
        if (!$this->usersUIViewCapabilities()->canViewTranscripts()) {
            $this->denyUsersUIAccess();
            return;
        }

        $body = $this->request->getJsonRawBody(true);
        $callIds = is_array($body) && is_array($body['call_ids'] ?? null)
            ? $body['call_ids']
            : [];
        $this->view->transcriptMap = $this->cdrTranscriptService()->lookup(
            $callIds,
            $this->usersUICallScope(),
        );
    }

    public function detailAction(): void
    {
        $capabilities = $this->usersUIViewCapabilities();
        if (!$capabilities->canViewTranscripts()) {
            $this->denyUsersUIAccess();
            return;
        }
        $this->view->canDeleteTranscript = $capabilities->canDeleteTranscripts();

        try {
            $this->view->transcript = $this->cdrTranscriptService()->detail(
                (string)$this->request->getQuery('call_id', 'string', ''),
                $this->usersUICallScope(),
            );
        } catch (CdrTranscriptNotFoundException) {
            $this->response->setStatusCode(404, 'Not Found');
            $this->view->success = false;
            $this->view->error = 'transcript_not_found';
        }
    }
}
