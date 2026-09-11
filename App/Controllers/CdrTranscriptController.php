<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use RuntimeException;

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
        if (!$this->usersUIViewCapabilities()->canViewTranscripts()) {
            $this->denyUsersUIAccess();
            return;
        }

        try {
            $this->view->transcript = $this->cdrTranscriptService()->detail(
                (string)$this->request->getQuery('call_id', 'string', ''),
                $this->usersUICallScope(),
            );
        } catch (RuntimeException) {
            $this->response->setStatusCode(404, 'Not Found');
            $this->view->success = false;
            $this->view->error = 'transcript_not_found';
        }
    }
}
