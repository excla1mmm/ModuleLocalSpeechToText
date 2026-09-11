<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers {
    use Modules\ModuleLocalSpeechToText\Lib\Exceptions\CdrTranscriptNotFoundException;

    final class CdrTranscriptRequestStub
    {
        /** @param array<string, mixed> $body */
        public function __construct(private readonly array $body, private readonly string $callId = 'mikopbx-call-1')
        {
        }

        /** @return array<string, mixed> */
        public function getJsonRawBody(bool $associative): array
        {
            return $this->body;
        }

        public function getQuery(string $name, string $filter, string $default): string
        {
            return $this->callId;
        }
    }

    final class CdrTranscriptResponseStub
    {
        public ?int $statusCode = null;

        public function setStatusCode(int $code, string $message): void
        {
            $this->statusCode = $code;
        }
    }

    final class CdrTranscriptLookupStub
    {
        /** @var list<string> */
        public array $receivedCallIds = [];

        /** @param list<string> $callIds @return array<string, int> */
        public function lookup(array $callIds, mixed $scope): array
        {
            $this->receivedCallIds = $callIds;

            return $callIds === ['mikopbx-call-1'] ? ['mikopbx-call-1' => 17] : [];
        }

        /** @return array<string, mixed> */
        public function detail(string $callId, mixed $scope): array
        {
            if ($callId === 'call-missing') {
                throw new CdrTranscriptNotFoundException('Transcript not found');
            }
            if ($callId === 'call-internal') {
                throw new \RuntimeException('database unavailable');
            }

            return ['callId' => $callId];
        }
    }

    class LocalSpeechToTextBaseController
    {
        public object $request;
        public object $view;
        public CdrTranscriptResponseStub $response;

        public function __construct(
            CdrTranscriptRequestStub $request,
            private readonly CdrTranscriptLookupStub $lookup,
        ) {
            $this->request = $request;
            $this->view = new \stdClass();
            $this->response = new CdrTranscriptResponseStub();
        }

        protected function usersUIViewCapabilities(): object
        {
            return new class {
                public function canViewTranscripts(): bool
                {
                    return true;
                }
            };
        }

        protected function usersUICallScope(): object
        {
            return new \stdClass();
        }

        protected function cdrTranscriptService(): CdrTranscriptLookupStub
        {
            return $this->lookup;
        }

        protected function denyUsersUIAccess(): void
        {
        }
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\App\Controllers\CdrTranscriptController;
    use Modules\ModuleLocalSpeechToText\App\Controllers\CdrTranscriptLookupStub;
    use Modules\ModuleLocalSpeechToText\App\Controllers\CdrTranscriptRequestStub;

    require_once __DIR__ . '/../Lib/Exceptions/CdrTranscriptNotFoundException.php';
    require_once __DIR__ . '/../App/Controllers/CdrTranscriptController.php';

    $lookup = new CdrTranscriptLookupStub();
    $controller = new CdrTranscriptController(
        new CdrTranscriptRequestStub(['call_ids' => ['mikopbx-call-1']]),
        $lookup,
    );
    $controller->lookupAction();

    if ($lookup->receivedCallIds !== ['mikopbx-call-1']) {
        fwrite(STDERR, "Assertion failed: lookup must read call_ids from the JSON request body\n");
        exit(1);
    }
    if (($controller->view->transcriptMap ?? null) !== ['mikopbx-call-1' => 17]) {
        fwrite(STDERR, "Assertion failed: lookup response must expose the transcript map\n");
        exit(1);
    }

    $missing = new CdrTranscriptController(new CdrTranscriptRequestStub([], 'call-missing'), $lookup);
    $missing->detailAction();
    if ($missing->response->statusCode !== 404 || ($missing->view->error ?? '') !== 'transcript_not_found') {
        fwrite(STDERR, "Assertion failed: missing transcript must return 404\n");
        exit(1);
    }

    $internal = new CdrTranscriptController(new CdrTranscriptRequestStub([], 'call-internal'), $lookup);
    try {
        $internal->detailAction();
        fwrite(STDERR, "Assertion failed: internal dependency failure must escape to 500 middleware\n");
        exit(1);
    } catch (\RuntimeException $exception) {
        if ($exception->getMessage() !== 'database unavailable' || $internal->response->statusCode !== null) {
            fwrite(STDERR, "Assertion failed: internal dependency failure was masked as 404\n");
            exit(1);
        }
    }

    echo "CDR transcript JSON request contract OK\n";
}
