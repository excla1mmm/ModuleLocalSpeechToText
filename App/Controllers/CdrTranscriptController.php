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

        $raw = (string)$this->request->getQuery('call_ids', 'string', '[]');
        $callIds = json_decode($raw);
        $this->view->transcriptMap = $this->cdrTranscriptService()->lookup(
            is_array($callIds) ? $callIds : [],
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
