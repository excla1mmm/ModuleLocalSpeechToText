<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib {
    class PBXApiResult
    {
        public int $httpCode = 0;
        public array $data = [];
        public array $responseHeaders = [];
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib {
    class LocalSpeechToTextMain
    {
        public const string MODULE_ID = 'ModuleLocalSpeechToText';
    }
}

namespace {
    /** @param mixed $actual */
    function expectWorkerApiV2Version(bool $condition, string $message, mixed $actual = null): void
    {
        if (!$condition) {
            $suffix = $actual === null ? '' : "\nActual: " . var_export($actual, true);
            throw new RuntimeException($message . $suffix);
        }
    }

    /** @return list<string> */
    function phpFilesWorkerApiV2Version(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    function codeWorkerApiV2Version(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    function functionBodyWorkerApiV2Version(string $source, string $function): string
    {
        $start = strpos($source, "function {$function}(");
        expectWorkerApiV2Version($start !== false, "Processor must declare {$function}()");
        $open = strpos($source, '{', $start);
        expectWorkerApiV2Version($open !== false, "Processor {$function}() has no body");
        $depth = 0;
        for ($offset = $open, $length = strlen($source); $offset < $length; $offset++) {
            if ($source[$offset] === '{') {
                $depth++;
            } elseif ($source[$offset] === '}' && --$depth === 0) {
                return substr($source, $open + 1, $offset - $open - 1);
            }
        }
        throw new RuntimeException("Processor {$function}() body is not balanced");
    }

    function assertWorkerApiV2Gate(string $operation, string $source): void
    {
        $callback = codeWorkerApiV2Version(functionBodyWorkerApiV2Version($source, 'callBack'));
        $gate = strpos($callback, 'WorkerApiContract::requireVersion');
        $dispatch = min(array_filter([
            strpos($callback, 'match('),
            strpos($callback, 'switch('),
        ], static fn(int|false $position): bool => $position !== false) ?: [PHP_INT_MAX]);
        expectWorkerApiV2Version($gate !== false, "{$operation} processor must require worker API v2");
        expectWorkerApiV2Version($gate < $dispatch, "{$operation} must require v2 before its action dispatch");
        expectWorkerApiV2Version(
            preg_match('/WorkerApiContract::requireVersion\([^)]*\).*?if\([^)]*!==null\).*?return/s', $callback) === 1,
            "{$operation} must return the version rejection instead of falling back to v1",
        );
        $beforeGate = substr($callback, 0, $gate);
        expectWorkerApiV2Version(
            !preg_match('/(?:::main\(|LocalSpeechToTextMain::|ModuleLifecycleGate|ModuleDatabasePragmas|newSTT(?:Job|Worker))/', $beforeGate),
            "{$operation} must gate before business or write-lease work",
        );
    }

    /** @return list<string> */
    function fileUrlExpressionsWorkerApiV2Version(string $source): array
    {
        $expressions = [];
        $offset = 0;
        while (preg_match('/[\'\"]file_url[\'\"]\s*=>/A', $source, $match, 0, $offset) === 1
            || preg_match('/[\'\"]file_url[\'\"]\s*=>/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $position = is_array($match[0]) ? $match[0][1] : $offset;
            $start = $position + strlen(is_array($match[0]) ? $match[0][0] : $match[0]);
            $depth = 0;
            $quote = null;
            $escaped = false;
            $end = $start;
            for ($length = strlen($source); $end < $length; $end++) {
                $char = $source[$end];
                if ($quote !== null) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($char === "'" || $char === '"') {
                    $quote = $char;
                } elseif (str_contains('([{', $char)) {
                    $depth++;
                } elseif (str_contains(')]}', $char)) {
                    $depth--;
                } elseif (($char === ',' || $char === ';') && $depth === 0) {
                    break;
                }
            }
            $expressions[] = trim(substr($source, $start, $end - $start));
            $offset = $end + 1;
        }

        return $expressions;
    }

    function assertCredentialFreeWorkerApiV2FileUrl(string $expression): void
    {
        expectWorkerApiV2Version(str_contains($expression, '/job-recordings/'), 'v2 file_url must use /job-recordings/{job_id}', $expression);
        expectWorkerApiV2Version(
            !preg_match('/(?:[?#@]|lease_token|worker_uid|:download|\/jobs\/|https?:\/\/)/i', $expression),
            'v2 file_url must be an exact credential-free recording path without query, fragment, or authority',
            $expression,
        );
    }

    function assertContractConstantsWorkerApiV2Version(array $constants): void
    {
        expectWorkerApiV2Version(($constants['CONTRACT_NAME'] ?? null) === 'MikoPBX-Worker-API', 'WorkerApiContract::CONTRACT_NAME changed');
        expectWorkerApiV2Version(($constants['API_VERSION'] ?? null) === 2, 'WorkerApiContract::API_VERSION must be integer 2');
        expectWorkerApiV2Version(($constants['VERSION_HEADER'] ?? null) === 'x-mikopbx-worker-api-version', 'WorkerApiContract::VERSION_HEADER must be the lower-case filtered header name');
    }

    /** @param list<string> $otherProcessorSources */
    function assertRecordingOwnershipHeaderScope(string $recordingSource, array $otherProcessorSources): void
    {
        $recordingCode = codeWorkerApiV2Version($recordingSource);
        foreach (['X-MikoPBX-Worker-UID', 'X-MikoPBX-Job-Lease-Token'] as $header) {
            expectWorkerApiV2Version(str_contains($recordingCode, $header), "Recording processor must read {$header}");
        }
        expectWorkerApiV2Version(
            preg_match('/unset\(\$data\[[\'\"]worker_uid[\'\"]\](?:,\$data\[[\'\"]lease_token[\'\"]\])?\)/', $recordingCode) === 1
                && preg_match('/unset\(\$data\[[\'\"]lease_token[\'\"]\](?:,\$data\[[\'\"]worker_uid[\'\"]\])?\)/', $recordingCode) === 1,
            'Recording processor must ignore body worker_uid and lease_token before applying ownership headers',
        );
        expectWorkerApiV2Version(
            !preg_match('/\$request\[[\'\"](?:query|queryParams)[\'\"]\].*(?:worker_uid|lease_token)/s', $recordingCode),
            'Recording processor must not consume ownership query parameters',
        );
        foreach ($otherProcessorSources as $source) {
            $code = codeWorkerApiV2Version($source);
            expectWorkerApiV2Version(
                !str_contains($code, 'X-MikoPBX-Worker-UID') && !str_contains($code, 'X-MikoPBX-Job-Lease-Token'),
                'Only GET /job-recordings/{job_id} may consume recording ownership headers',
            );
        }
    }

    if (getenv('WORKER_API_V2_VERSION_MUTATION') === '1') {
        $legacy = "self::API_BASE_PATH . '/jobs/' . \$job->id . ':download?worker_uid=' . \$worker . '&lease_token=' . \$lease";
        try {
            assertCredentialFreeWorkerApiV2FileUrl($legacy);
            throw new RuntimeException('legacy credential-bearing file_url mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'file_url mutation check did not fail for the intended reason');
        }
        try {
            assertWorkerApiV2Gate('mutation', 'function callBack(array $request) { return LocalSpeechToTextMain::getNextJob([]); }');
            throw new RuntimeException('ungated operation mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'version-gate mutation check did not fail for the intended reason');
        }
        try {
            assertContractConstantsWorkerApiV2Version(['CONTRACT_NAME' => 'MikoPBX-Worker-API', 'API_VERSION' => '2', 'VERSION_HEADER' => 'X-MikoPBX-Worker-API-Version']);
            throw new RuntimeException('weak contract constants mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'constants mutation check did not fail for the intended reason');
        }
        try {
            assertRecordingOwnershipHeaderScope(
                "function callBack() { \$headers = ['X-MikoPBX-Worker-UID' => 'wrong']; }",
                ["function callBack() { \$headers = ['X-MikoPBX-Job-Lease-Token' => 'leak']; }"],
            );
            throw new RuntimeException('ownership-header scope mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'ownership-header mutation check did not fail for the intended reason');
        }
        echo "Worker API v2 version mutation checks OK\n";
        exit(0);
    }

    $root = dirname(__DIR__);
    $contractFile = $root . '/Lib/Services/WorkerApiContract.php';
    expectWorkerApiV2Version(is_file($contractFile), 'WorkerApiContract.php must exist before v2 routes can run');
    require_once $contractFile;
    $contractClass = Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract::class;
    expectWorkerApiV2Version(class_exists($contractClass), 'WorkerApiContract class must load from its service file');
    assertContractConstantsWorkerApiV2Version((new ReflectionClass($contractClass))->getConstants());

    $phpFiles = phpFilesWorkerApiV2Version($root . '/Lib');
    $sources = [];
    foreach ($phpFiles as $file) {
        $source = file_get_contents($file);
        expectWorkerApiV2Version($source !== false, "Unable to read {$file}");
        $sources[$file] = $source;
    }

    $required = [
        'WorkerApiContract::API_VERSION',
        'X-MikoPBX-Worker-API-Version',
        'X-MikoPBX-Worker-UID',
        'X-MikoPBX-Job-Lease-Token',
        'worker_upgrade_required',
    ];
    $code = implode("\n", array_map('codeWorkerApiV2Version', $sources));
    foreach ($required as $symbol) {
        expectWorkerApiV2Version(str_contains($code, $symbol), "Required worker API v2 code symbol is missing: {$symbol}");
    }

    $workerOperationsByProcessor = [
        'Workers' => ['PUT /workers/{worker_uid} update'],
        'WorkerProcessingSettings' => ['GET /worker-processing-settings/{worker_uid} getRecord'],
        'JobLeases' => ['POST /job-leases create', 'PATCH /job-leases/{job_id} patch', 'DELETE /job-leases/{job_id} delete'],
        'JobRecordings' => ['GET /job-recordings/{job_id} getRecord'],
        'JobResults' => ['PUT /job-results/{job_id} update'],
        'JobFailures' => ['PUT /job-failures/{job_id} update'],
    ];
    foreach ($workerOperationsByProcessor as $resource => $operations) {
        $file = $root . '/Lib/RestAPI/' . $resource . '/Processor.php';
        expectWorkerApiV2Version(is_file($file), "Worker-only v2 resource {$resource} must have a processor");
        $source = file_get_contents($file);
        expectWorkerApiV2Version($source !== false, "Unable to read {$file}");
        foreach ($operations as $operation) {
            assertWorkerApiV2Gate($operation, $source);
        }
    }

    $jobLeaseDirectory = $root . '/Lib/RestAPI/JobLeases';
    expectWorkerApiV2Version(is_dir($jobLeaseDirectory), 'JobLeases v2 resource directory must exist');
    $v2FileUrlExpressions = [];
    foreach (phpFilesWorkerApiV2Version($jobLeaseDirectory) as $file) {
        foreach (fileUrlExpressionsWorkerApiV2Version((string)file_get_contents($file)) as $expression) {
            $v2FileUrlExpressions[] = $expression;
            assertCredentialFreeWorkerApiV2FileUrl($expression);
        }
    }
    expectWorkerApiV2Version($v2FileUrlExpressions !== [], 'JobLeases must expose at least one v2 file_url expression or OpenAPI example');

    $recordingDirectory = $root . '/Lib/RestAPI/JobRecordings';
    $recordingProcessor = $recordingDirectory . '/Processor.php';
    expectWorkerApiV2Version(is_file($recordingProcessor), 'GET /job-recordings/{job_id} must have a dedicated processor');
    $otherProcessorSources = [];
    foreach ($sources as $file => $source) {
        if (!str_contains($file, '/Lib/RestAPI/') || str_starts_with($file, $recordingDirectory . '/')) {
            continue;
        }
        $otherProcessorSources[] = $source;
    }
    assertRecordingOwnershipHeaderScope((string)file_get_contents($recordingProcessor), $otherProcessorSources);

    echo "Worker API v2 version and sensitive-data contract OK\n";
}
