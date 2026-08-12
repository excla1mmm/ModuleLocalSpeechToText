<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib {
    class PBXApiResult
    {
        public bool $success = false;
        public int $httpCode = 0;
        public array $data = [];
        public array $messages = [];
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
        if (!str_contains($source, '<?php')) {
            $source = "<?php\n" . $source;
        }
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
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
        preg_match('/\$(?<versionError>[A-Za-z_][A-Za-z0-9_]*)=WorkerApiContract::requireVersion\(\$request\);/', $callback, $gateMatch);
        $gate = isset($gateMatch[0]) ? strpos($callback, $gateMatch[0]) : false;
        $dispatch = min(array_filter([
            strpos($callback, 'match('),
            strpos($callback, 'switch('),
        ], static fn(int|false $position): bool => $position !== false) ?: [PHP_INT_MAX]);
        expectWorkerApiV2Version($gate !== false, "{$operation} processor must require worker API v2");
        $prelude = substr($callback, 0, $gate);
        expectWorkerApiV2Version(
            preg_match('/^(?:\$actionString=\(string\)\(\$request\[[\'\"]action[\'\"]\]\?\?[\'\"]{2}\);\$action=\w+Action::tryFrom\(\$actionString\);)?$/', $prelude) === 1,
            "{$operation} must reach requireVersion() unconditionally with only harmless action normalization before it",
            $prelude,
        );
        expectWorkerApiV2Version($gate < $dispatch, "{$operation} must require v2 before its action dispatch");
        expectWorkerApiV2Version(
            isset($gateMatch['versionError'])
                && preg_match('/^if\(\$' . preg_quote($gateMatch['versionError'], '/') . '!==null\)\{?return\$' . preg_quote($gateMatch['versionError'], '/') . ';?\}?/', substr($callback, $gate + strlen($gateMatch[0]))) === 1,
            "{$operation} must return the exact requireVersion() result instead of falling back to v1",
        );
    }

    function closingBraceWorkerApiV2Version(string $source, int $open): int
    {
        $depth = 0;
        for ($offset = $open, $length = strlen($source); $offset < $length; $offset++) {
            if ($source[$offset] === '{') {
                $depth++;
            } elseif ($source[$offset] === '}' && --$depth === 0) {
                return $offset;
            }
        }
        throw new RuntimeException('Source guard block is not balanced');
    }

    function assertRestrictedWorkerCreateVersionScope(string $source): void
    {
        $callback = codeWorkerApiV2Version(functionBodyWorkerApiV2Version($source, 'callBack'));
        preg_match_all('/if\((?<condition>[^{}]+)\)\{/', $callback, $conditions, PREG_OFFSET_CAPTURE);
        $scope = null;
        foreach ($conditions['condition'] ?? [] as $index => [$condition, $conditionOffset]) {
            $createOnly = preg_match('/(?:\$action===WorkerAction::CREATE|WorkerAction::CREATE===\$action)/', $condition) === 1;
            $restrictedOnly = str_contains($condition, 'WorkerRequestContext::isRestrictedWorkerKey($data)');
            if (!$createOnly || !$restrictedOnly || !str_contains($condition, '&&')) {
                continue;
            }
            $ifOffset = $conditions[0][$index][1];
            $open = strpos($callback, '{', $ifOffset);
            expectWorkerApiV2Version($open !== false, 'Restricted worker create guard must have a block');
            $close = closingBraceWorkerApiV2Version($callback, $open);
            $scope = ['open' => $open, 'close' => $close, 'body' => substr($callback, $open + 1, $close - $open - 1)];
            break;
        }
        expectWorkerApiV2Version($scope !== null, 'POST /workers must scope its version gate to restricted WorkerAction::CREATE callers');
        expectWorkerApiV2Version(
            substr_count($callback, 'WorkerApiContract::requireVersion($request)') === 1
                && str_contains($scope['body'], 'WorkerApiContract::requireVersion($request)'),
            'Administrator worker CRUD must remain outside the restricted-create version gate',
        );
        assertWorkerApiV2Gate('restricted POST /workers create', 'function callBack(array $request) {' . $scope['body'] . '}');
        $registration = strpos($callback, 'LocalSpeechToTextMain::registerWorker(');
        expectWorkerApiV2Version(
            $registration !== false && $registration > $scope['close'],
            'Restricted POST /workers must require version 2 before registration mutation',
        );
    }

    function assertLegacyWorkerTombstoneContract(MikoPBX\PBXCoreREST\Lib\PBXApiResult $result, string $source): void
    {
        expectWorkerApiV2Version($result->httpCode === 426, 'Legacy worker tombstone must return HTTP 426', $result->httpCode);
        expectWorkerApiV2Version(($result->data['error_code'] ?? null) === 'worker_upgrade_required', 'Legacy worker tombstone error_code changed', $result->data);
        expectWorkerApiV2Version(($result->data['required_worker_api_version'] ?? null) === 2, 'Legacy worker tombstone must require worker API version 2', $result->data);
        $body = codeWorkerApiV2Version(functionBodyWorkerApiV2Version($source, 'worker'));
        expectWorkerApiV2Version(
            $body === 'returnWorkerApiContract::upgradeRequired();',
            'LegacyRouteRejector::worker() must directly return the upgrade result without legacy or business dispatch',
            $body,
        );
    }

    /** @param array<string, string> $sources */
    function assertOwnershipHeaderIsolation(array $sources): void
    {
        foreach ($sources as $file => $source) {
            $code = codeWorkerApiV2Version($source);
            if (!str_contains($code, '_worker_uid_header') && !str_contains($code, '_lease_token_header')) {
                continue;
            }
            $allowed = str_ends_with($file, '/Lib/RestAPI/Common/WorkerRequestContext.php')
                || str_ends_with($file, '/Lib/RestAPI/JobRecordings/Processor.php');
            expectWorkerApiV2Version($allowed, "Only WorkerRequestContext and JobRecordings may reference internal ownership headers: {$file}");
        }
    }

    function assertCanonicalWorkerRequestContext(string $source): void
    {
        $code = codeWorkerApiV2Version($source);
        expectWorkerApiV2Version(
            preg_match('/\$data\[[\'\"]_worker_uid_header[\'\"]\]=\(string\)\(\$headers\[[\'\"]x-mikopbx-worker-uid[\'\"]\]\?\?[\'\"]{2}\);/', $code) === 1,
            'WorkerRequestContext must assign _worker_uid_header only from x-mikopbx-worker-uid',
        );
        expectWorkerApiV2Version(
            preg_match('/\$data\[[\'\"]_lease_token_header[\'\"]\]=\(string\)\(\$headers\[[\'\"]x-mikopbx-job-lease-token[\'\"]\]\?\?[\'\"]{2}\);/', $code) === 1,
            'WorkerRequestContext must assign _lease_token_header only from x-mikopbx-job-lease-token',
        );
        expectWorkerApiV2Version(
            substr_count($code, '_worker_uid_header') === 1 && substr_count($code, '_lease_token_header') === 1,
            'WorkerRequestContext must contain only the two canonical internal-header assignments',
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
            assertWorkerApiV2Gate('dead-conditional gate mutation', 'function callBack(array $request) { if (false) { $versionError = WorkerApiContract::requireVersion($request); if ($versionError !== null) { return $versionError; } } return match($action) {}; }');
            throw new RuntimeException('dead conditional gate mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'dead conditional gate mutation did not fail for the intended reason');
        }
        try {
            assertWorkerApiV2Gate('unknown-prelude gate mutation', 'function callBack(array $request) { claimJob($request); $versionError = WorkerApiContract::requireVersion($request); if ($versionError !== null) { return $versionError; } return match($action) {}; }');
            throw new RuntimeException('unknown prelude gate mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'unknown prelude gate mutation did not fail for the intended reason');
        }
        $scopedCreate = 'function callBack(array $request) { $data = WorkerRequestContext::data($request); if ($action === WorkerAction::CREATE && WorkerRequestContext::isRestrictedWorkerKey($data)) { $versionError = WorkerApiContract::requireVersion($request); if ($versionError !== null) { return $versionError; } } return match ($action) { WorkerAction::CREATE => LocalSpeechToTextMain::registerWorker($data), WorkerAction::GET_LIST => $service->getList($data) }; }';
        assertRestrictedWorkerCreateVersionScope($scopedCreate);
        foreach ([
            'function callBack(array $request) { $data = WorkerRequestContext::data($request); return match ($action) { WorkerAction::CREATE => LocalSpeechToTextMain::registerWorker($data) }; }',
            'function callBack(array $request) { $versionError = WorkerApiContract::requireVersion($request); if ($versionError !== null) { return $versionError; } return match ($action) { WorkerAction::CREATE => LocalSpeechToTextMain::registerWorker($data), WorkerAction::GET_LIST => $service->getList($data) }; }',
        ] as $unsafeCreate) {
            try {
                assertRestrictedWorkerCreateVersionScope($unsafeCreate);
                throw new RuntimeException('unsafe restricted worker create mutation was accepted');
            } catch (RuntimeException $error) {
                expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'restricted create gate mutation did not fail for the intended reason');
            }
        }
        $validTombstone = new MikoPBX\PBXCoreREST\Lib\PBXApiResult();
        $validTombstone->httpCode = 426;
        $validTombstone->data = ['error_code' => 'worker_upgrade_required', 'required_worker_api_version' => 2];
        assertLegacyWorkerTombstoneContract($validTombstone, 'function worker() { return WorkerApiContract::upgradeRequired(); }');
        foreach ([
            [200, ['error_code' => 'worker_upgrade_required', 'required_worker_api_version' => 2], 'function worker() { return WorkerApiContract::upgradeRequired(); }'],
            [426, ['error_code' => 'api_route_removed', 'required_worker_api_version' => 1], 'function worker() { return WorkerApiContract::upgradeRequired(); }'],
            [426, ['error_code' => 'worker_upgrade_required', 'required_worker_api_version' => 2], 'function worker() { LocalSpeechToTextMain::getNextJob([]); return WorkerApiContract::upgradeRequired(); }'],
        ] as [$status, $data, $source]) {
            $mutatedTombstone = new MikoPBX\PBXCoreREST\Lib\PBXApiResult();
            $mutatedTombstone->httpCode = $status;
            $mutatedTombstone->data = $data;
            try {
                assertLegacyWorkerTombstoneContract($mutatedTombstone, $source);
                throw new RuntimeException('invalid executable legacy tombstone mutation was accepted');
            } catch (RuntimeException $error) {
                expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'legacy tombstone result mutation did not fail for the intended reason');
            }
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
            assertOwnershipHeaderIsolation(['Lib/RestAPI/JobLeases/Actions/ClaimAction.php' => '<?php $owner = $data[\'_worker_uid_header\']; forward($owner);']);
            throw new RuntimeException('ownership Action forwarding mutation was accepted');
        } catch (RuntimeException $error) {
            expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'ownership Action forwarding mutation did not fail for the intended reason');
        }
        $safeContext = '<?php $data[\'_worker_uid_header\'] = (string)($headers[\'x-mikopbx-worker-uid\'] ?? \'\'); $data[\'_lease_token_header\'] = (string)($headers[\'x-mikopbx-job-lease-token\'] ?? \'\');';
        assertCanonicalWorkerRequestContext($safeContext);
        foreach ([
            '<?php $data[\'_worker_uid_header\'] = (string)($data[\'worker_uid\'] ?? \'\'); $data[\'_lease_token_header\'] = (string)($headers[\'x-mikopbx-job-lease-token\'] ?? \'\');',
            '<?php $data[\'_worker_uid_header\'] = (string)($headers[\'x-mikopbx-job-lease-token\'] ?? \'\'); $data[\'_lease_token_header\'] = (string)($headers[\'x-mikopbx-worker-uid\'] ?? \'\');',
            '<?php $data[\'_worker_uid_header\'] = (string)($headers[\'x-mikopbx-worker-uid\'] ?? \'\'); $data[\'_lease_token_header\'] = (string)($headers[\'x-mikopbx-job-lease-token\'] ?? \'\'); logIt($data[\'_worker_uid_header\']);',
        ] as $unsafeContext) {
            try {
                assertCanonicalWorkerRequestContext($unsafeContext);
                throw new RuntimeException('unsafe WorkerRequestContext producer mutation was accepted');
            } catch (RuntimeException $error) {
                expectWorkerApiV2Version(!str_contains($error->getMessage(), 'accepted'), 'WorkerRequestContext producer mutation did not fail for the intended reason');
            }
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
    $contractFile = $root . '/Lib/Services/WorkerApiContract.php';
    expectWorkerApiV2Version(is_file($contractFile), 'WorkerApiContract.php must exist before v2 routes can run');
    require_once $contractFile;
    $contractClass = Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract::class;
    expectWorkerApiV2Version(class_exists($contractClass), 'WorkerApiContract class must load from its service file');
    assertContractConstantsWorkerApiV2Version((new ReflectionClass($contractClass))->getConstants());

    $legacyRejectorFile = $root . '/Lib/RestAPI/Common/LegacyRouteRejector.php';
    expectWorkerApiV2Version(is_file($legacyRejectorFile), 'LegacyRouteRejector.php must exist before legacy v1 routes can run');
    require_once $legacyRejectorFile;
    $legacyRejectorClass = Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\LegacyRouteRejector::class;
    expectWorkerApiV2Version(class_exists($legacyRejectorClass), 'LegacyRouteRejector class must load from its common helper file');
    $legacyResult = $legacyRejectorClass::worker();
    expectWorkerApiV2Version($legacyResult instanceof MikoPBX\PBXCoreREST\Lib\PBXApiResult, 'LegacyRouteRejector::worker() must return PBXApiResult');
    assertLegacyWorkerTombstoneContract($legacyResult, (string)file_get_contents($legacyRejectorFile));

    $runtimeFileUrlSource = $root . '/Lib/LocalSpeechToTextMain.php';
    expectWorkerApiV2Version(is_file($runtimeFileUrlSource), 'LocalSpeechToTextMain runtime claim builder must exist');
    assertRuntimeWorkerApiV2FileUrl((string)file_get_contents($runtimeFileUrlSource));

    $phpFiles = phpFilesWorkerApiV2Version($root . '/Lib');
    foreach (['/Setup', '/App'] as $productionDirectory) {
        $directory = $root . $productionDirectory;
        if (is_dir($directory)) {
            $phpFiles = array_merge($phpFiles, phpFilesWorkerApiV2Version($directory));
        }
    }
    $phpFiles = array_values(array_unique($phpFiles));
    sort($phpFiles);
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
        'WorkerProcessingSettings' => ['GET /worker-processing-settings getList'],
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

    $workersProcessor = $root . '/Lib/RestAPI/Workers/Processor.php';
    expectWorkerApiV2Version(is_file($workersProcessor), 'Workers resource must have a processor for restricted self-registration');
    assertRestrictedWorkerCreateVersionScope((string)file_get_contents($workersProcessor));

    foreach ($sources as $file => $source) {
        if (str_contains($file, '/Lib/RestAPI/')) {
            assertSchemaWorkerApiV2FileUrls($source, $file);
        }
    }
    assertOwnershipHeaderIsolation($sources);
    $workerRequestContext = $root . '/Lib/RestAPI/Common/WorkerRequestContext.php';
    expectWorkerApiV2Version(is_file($workerRequestContext), 'Canonical WorkerRequestContext producer must exist');
    assertCanonicalWorkerRequestContext((string)file_get_contents($workerRequestContext));
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
