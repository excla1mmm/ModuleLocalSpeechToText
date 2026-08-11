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
        preg_match('/\$(?<versionError>[A-Za-z_][A-Za-z0-9_]*)=WorkerApiContract::requireVersion\([^;]+\);/', $callback, $gateMatch);
        $gate = isset($gateMatch[0]) ? strpos($callback, $gateMatch[0]) : false;
        $dispatch = min(array_filter([
            strpos($callback, 'match('),
            strpos($callback, 'switch('),
        ], static fn(int|false $position): bool => $position !== false) ?: [PHP_INT_MAX]);
        expectWorkerApiV2Version($gate !== false, "{$operation} processor must require worker API v2");
        expectWorkerApiV2Version($gate < $dispatch, "{$operation} must require v2 before its action dispatch");
        expectWorkerApiV2Version(
            isset($gateMatch['versionError'])
                && preg_match('/^if\(\$' . preg_quote($gateMatch['versionError'], '/') . '!==null\)\{?return\$' . preg_quote($gateMatch['versionError'], '/') . ';?\}?/', substr($callback, $gate + strlen($gateMatch[0]))) === 1,
            "{$operation} must return the exact requireVersion() result instead of falling back to v1",
        );
        $beforeGate = substr($callback, 0, $gate);
        expectWorkerApiV2Version(
            !preg_match('/(?:::main\(|LocalSpeechToTextMain::|ModuleLifecycleGate|ModuleDatabasePragmas|newSTT(?:Job|Worker)|STT\w+Service)/', $beforeGate),
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

    function assertRuntimeWorkerApiV2FileUrl(string $source): void
    {
        $expressions = fileUrlExpressionsWorkerApiV2Version(codeWorkerApiV2Version($source));
        expectWorkerApiV2Version(count($expressions) === 1, 'Runtime claim code must expose exactly one file_url builder', $expressions);
        expectWorkerApiV2Version(
            preg_match('/^[\'\"]\/job-recordings\/[\'\"]\.\(int\)\$job->id$/', $expressions[0]) === 1,
            'Runtime file_url must be exactly /job-recordings/{job_id} with no prefix, suffix, query, fragment, or extra concatenation',
            $expressions[0],
        );
    }

    function assertSchemaWorkerApiV2FileUrls(string $source, string $file): void
    {
        $code = codeWorkerApiV2Version($source);
        if (!str_contains($code, 'file_url')) {
            return;
        }
        $fileUrlValues = fileUrlExpressionsWorkerApiV2Version($code);
        expectWorkerApiV2Version($fileUrlValues !== [], "{$file} must expose a structural file_url value");
        foreach ($fileUrlValues as $value) {
            if (preg_match('/^[\'\"](?<url>[^\'\"]+)[\'\"]$/', $value, $scalar) === 1) {
                expectWorkerApiV2Version(preg_match('#^/job-recordings/(?:[0-9]+|\{job_id\})$#', $scalar['url']) === 1, "{$file} scalar file_url must be an exact credential-free recording path", $scalar['url']);
                continue;
            }
            preg_match_all('/[\'\"]example[\'\"]=>[\'\"](?<url>[^\'\"]+)[\'\"]/', $value, $examples);
            expectWorkerApiV2Version(($examples['url'] ?? []) !== [], "{$file} file_url value must carry its own example", $value);
            foreach ($examples['url'] as $url) {
            expectWorkerApiV2Version(
                preg_match('#^/job-recordings/[0-9]+$#', $url) === 1,
                "{$file} file_url example must be an exact credential-free recording path",
                $url,
            );
            }
        }
    }

    function assertSwiftWorkerApiV2FileUrls(string $source, string $file): void
    {
        if (!str_contains($source, 'file_url')) {
            return;
        }
        preg_match_all('/(?:[\'\"]file_url[\'\"]\s*:\s*|file_url\s*(?::\s*[A-Za-z_][A-Za-z0-9_?<>\[\]]*\s*=|=))\s*[\'\"](?<url>[^\'\"]+)[\'\"]/', $source, $matches);
        expectWorkerApiV2Version(($matches['url'] ?? []) !== [], "{$file} must bind every file_url example to a literal path");
        foreach ($matches['url'] as $url) {
            expectWorkerApiV2Version(preg_match('#^/job-recordings/(?:[0-9]+|\{job_id\})$#', $url) === 1, "{$file} has an unsafe file_url value", $url);
        }
    }

    function assertContractConstantsWorkerApiV2Version(array $constants): void
    {
        expectWorkerApiV2Version(($constants['CONTRACT'] ?? null) === 'MikoPBX-Worker-API', 'WorkerApiContract::CONTRACT changed');
        expectWorkerApiV2Version(($constants['API_VERSION'] ?? null) === 2, 'WorkerApiContract::API_VERSION must be integer 2');
        expectWorkerApiV2Version(($constants['VERSION_HEADER'] ?? null) === 'x-mikopbx-worker-api-version', 'WorkerApiContract::VERSION_HEADER must be the lower-case filtered header name');
    }

    /** @param list<string> $otherProcessorSources */
    function assertRecordingOwnershipHeaderScope(string $recordingSource, array $otherProcessorSources): void
    {
        $recordingCode = codeWorkerApiV2Version($recordingSource);
        expectWorkerApiV2Version(
            preg_match('/\$data\[[\'\"]worker_uid[\'\"]\]=\(string\)\(\$data\[[\'\"]_worker_uid_header[\'\"]\]\?\?[\'\"]{2}\);/', $recordingCode) === 1
                && preg_match('/\$data\[[\'\"]lease_token[\'\"]\]=\(string\)\(\$data\[[\'\"]_lease_token_header[\'\"]\]\?\?[\'\"]{2}\);/', $recordingCode) === 1,
            'Recording processor must overwrite ownership only from the canonical internal header values',
        );
        preg_match_all('/unset\((?<members>[^)]*)\)/', $recordingCode, $unsets);
        $clearsBothHeaders = array_reduce($unsets['members'] ?? [], static fn(bool $clears, string $members): bool => $clears
            || (str_contains($members, "\$data['_worker_uid_header']") && str_contains($members, "\$data['_lease_token_header']")), false);
        expectWorkerApiV2Version(
            $clearsBothHeaders,
            'Recording processor must clear internal ownership header values after consuming them',
        );
        expectWorkerApiV2Version(
            !preg_match('/\$request\[[\'\"](?:query|queryParams)[\'\"]\].*(?:worker_uid|lease_token)/s', $recordingCode),
            'Recording processor must not consume ownership query parameters',
        );
        foreach ($otherProcessorSources as $source) {
            $code = codeWorkerApiV2Version($source);
            expectWorkerApiV2Version(
                !str_contains($code, '_worker_uid_header') && !str_contains($code, '_lease_token_header'),
                'Only GET /job-recordings/{job_id} may reference canonical recording ownership headers',
            );
        }
    }

    if (getenv('WORKER_API_V2_VERSION_MUTATION') === '1') {
        $runtimeSource = (string)file_get_contents(dirname(__DIR__) . '/Lib/LocalSpeechToTextMain.php');
        try {
            assertRuntimeWorkerApiV2FileUrl($runtimeSource);
            throw new RuntimeException('legacy runtime file_url discovery was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'runtime file_url mutation check did not fail for the intended reason');
        }
        try {
            assertWorkerApiV2Gate('mutation', 'function callBack(array $request) { return LocalSpeechToTextMain::getNextJob([]); }');
            throw new RuntimeException('ungated operation mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'version-gate mutation check did not fail for the intended reason');
        }
        try {
            assertWorkerApiV2Gate('dispatch-before-guard mutation', 'function callBack(array $request) { $result = match($action) { JobAction::GET_NEXT => GetNextAction::main([]) }; $versionError = WorkerApiContract::requireVersion($request); if ($versionError !== null) { return $versionError; } }');
            throw new RuntimeException('dispatch-before-guard mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'dispatch-order mutation check did not fail for the intended reason');
        }
        try {
            assertContractConstantsWorkerApiV2Version(['CONTRACT' => 'MikoPBX-Worker-API', 'API_VERSION' => '2', 'VERSION_HEADER' => 'X-MikoPBX-Worker-API-Version']);
            throw new RuntimeException('weak contract constants mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'constants mutation check did not fail for the intended reason');
        }
        try {
            assertRecordingOwnershipHeaderScope(
                "function callBack() { \$data['worker_uid'] = (string)(\$data['_worker_uid_header'] ?? ''); \$data['lease_token'] = (string)(\$data['_lease_token_header'] ?? ''); unset(\$data['_worker_uid_header'], \$data['_lease_token_header']); }",
                ["function callBack() { \$owner = \$data['_worker_uid_header']; useOwner(\$owner); }"],
            );
            throw new RuntimeException('ownership-header scope mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'ownership-header mutation check did not fail for the intended reason');
        }
        try {
            assertSchemaWorkerApiV2FileUrls("['file_url' => '/job-recordings/42']", 'scalar-safe.php');
            assertSchemaWorkerApiV2FileUrls("['file_url' => '/jobs/42:download?lease_token=x']", 'scalar-unsafe.php');
            throw new RuntimeException('scalar file_url mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'scalar file_url mutation check did not fail for the intended reason');
        }
        try {
            assertSchemaWorkerApiV2FileUrls("['file_url' => ['example' => '/jobs/42:download?worker_uid=x'], 'example' => '/job-recordings/42']", 'nested-unsafe.php');
            throw new RuntimeException('nested file_url mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'nested file_url mutation check did not fail for the intended reason');
        }
        try {
            assertSwiftWorkerApiV2FileUrls("let good = \"/job-recordings/42\"; let file_url: String = \"/jobs/42:download?lease_token=x\"", 'unsafe.swift');
            throw new RuntimeException('Swift file_url mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'Swift file_url mutation check did not fail for the intended reason');
        }
        assertSwiftWorkerApiV2FileUrls('let file_url: String = "/job-recordings/42"', 'typed-safe.swift');
        echo "Worker API v2 version mutation checks OK\n";
        exit(0);
    }

    $root = dirname(__DIR__);
    $runtimeFileUrlSource = $root . '/Lib/LocalSpeechToTextMain.php';
    expectWorkerApiV2Version(is_file($runtimeFileUrlSource), 'LocalSpeechToTextMain runtime claim builder must exist');
    assertRuntimeWorkerApiV2FileUrl((string)file_get_contents($runtimeFileUrlSource));

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

    foreach ($sources as $file => $source) {
        if (str_contains($file, '/Lib/RestAPI/')) {
            assertSchemaWorkerApiV2FileUrls($source, $file);
        }
    }
    $jobLeaseSchema = $root . '/Lib/RestAPI/JobLeases/DataStructure.php';
    expectWorkerApiV2Version(is_file($jobLeaseSchema), 'JobLeases must expose the v2 file_url OpenAPI schema');
    assertSchemaWorkerApiV2FileUrls((string)file_get_contents($jobLeaseSchema), $jobLeaseSchema);

    $swiftExamples = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'swift') {
            $swiftExamples[] = $file->getPathname();
        }
    }
    foreach ($swiftExamples as $swiftExample) {
        assertSwiftWorkerApiV2FileUrls((string)file_get_contents($swiftExample), $swiftExample);
    }

    $recordingDirectory = $root . '/Lib/RestAPI/JobRecordings';
    $recordingProcessor = $recordingDirectory . '/Processor.php';
    expectWorkerApiV2Version(is_file($recordingProcessor), 'GET /job-recordings/{job_id} must have a dedicated processor');
    $otherProcessorSources = [];
    foreach ($sources as $file => $source) {
        if (!str_contains($file, '/Lib/RestAPI/') || !str_ends_with($file, '/Processor.php') || str_starts_with($file, $recordingDirectory . '/')) {
            continue;
        }
        $otherProcessorSources[] = $source;
    }
    assertRecordingOwnershipHeaderScope((string)file_get_contents($recordingProcessor), $otherProcessorSources);

    echo "Worker API v2 version and sensitive-data contract OK\n";
}
