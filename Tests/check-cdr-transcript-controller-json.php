<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers {
    final class CdrTranscriptRequestStub
    {
        /** @param array<string, mixed> $body */
        public function __construct(private readonly array $body)
        {
        }

        /** @return array<string, mixed> */
        public function getJsonRawBody(bool $associative): array
        {
            return $this->body;
        }

        public function getQuery(string $name, string $filter, string $default): string
        {
            return '[&quot;mikopbx-call-1&quot;]';
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
    }

    class LocalSpeechToTextBaseController
    {
        public object $request;
        public object $view;

        public function __construct(
            CdrTranscriptRequestStub $request,
            private readonly CdrTranscriptLookupStub $lookup,
        ) {
            $this->request = $request;
            $this->view = new \stdClass();
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

    echo "CDR transcript JSON request contract OK\n";
}
