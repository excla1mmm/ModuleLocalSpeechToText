<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\CallDetailRecords;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Throwable;

class TranscriptListService
{
    // Both the plain listing and the full-text search read candidate ids in
    // pages of this size, so neither ever loads the whole m_STTResult table.
    private const int CANDIDATE_BATCH_SIZE = 100;
    // Hard cap on how many candidate rows a single transcriptRows() call may
    // scan across all batches. A heavily-restricted UsersUI call scope can
    // reject most candidates, so batches keep being pulled until $limit
    // visible rows are found - this bounds that worst case instead of
    // letting it degrade into an unbounded table scan.
    private const int CANDIDATE_MAX_SCAN_ROWS = 2000;

    // callTimestamp()'s exact priority prefers the call's *start* (live CDR
    // start, or cdr_start_ms); EFFECTIVE_TIME_SQL below can only fall back to
    // cdr_endtime (call *end*) or r.created_at (transcription completion
    // time) for a job with no cdr_start_ms, both of which are >= the true
    // call start. That makes the SQL pre-filter's lower bound always safe
    // (a larger approximate value can only pass a "start >= window start"
    // check more easily than the true value would), but its upper bound can
    // wrongly exclude a call that started inside the window and ran long, or
    // whose transcription was queued/backlogged well after the call ended.
    // The margin below only widens :date_bound_end - two things bound how
    // large the true gap can be: a recording's hard duration cap
    // (STTSetting::maxRecordingDurationSeconds(), 180 minutes - jobs longer
    // than that are never created) covers the cdr_endtime fallback, and a
    // flat one-day allowance covers the r.created_at fallback (an orphaned
    // STTResult whose STTJob was deleted, or a legacy row predating
    // cdr_start_ms, transcribed well after a queue backlog). One day is a
    // deliberately generous, simple constant rather than a tight bound - it
    // only widens the *candidate* page (a pre-filter), never the exact
    // transcriptMatchesDateFilter() check applied per row afterward, so
    // erring wide here costs a few extra candidate rows, not correctness.
    private const int DATE_BOUND_END_SAFETY_MARGIN_SECONDS = 86400;

    // SQLite CASE expression approximating CallRecordingService::callTimestamp()'s
    // priority (cdr_start_ms -> cdr_endtime -> result creation time) cheaply,
    // without per-row CDR lookups. It is only a pre-filter/pre-sort: the exact
    // value from callTimestamp() is still computed and checked per row below,
    // so this expression only needs to be a close enough approximation to
    // avoid excluding rows a precise check would keep.
    //
    // The outer CAST is load-bearing, not cosmetic. A bare CASE expression has
    // no SQLite type affinity, while PDO (and Phalcon on top of it) binds
    // integer parameters as TEXT unless a type map is passed. Comparing an
    // affinity-less INTEGER expression against a TEXT parameter compares
    // storage classes instead of numbers - every integer sorts before every
    // string - so `CASE ... >= :date_bound_start` was false for every row and
    // the transcripts list came back empty whenever the date filter was
    // active (which is always: transcriptDateFilterFromRequest() defaults to
    // the last seven days). CAST(... AS INTEGER) gives the expression INTEGER
    // affinity, which makes SQLite coerce the bound text operand to a number
    // before comparing. Keep the wrapper here rather than at the comparison
    // sites so every current and future use of this constant is safe.
    private const string EFFECTIVE_TIME_SQL = <<<'SQL'
        CAST(CASE
            WHEN j.cdr_start_ms IS NOT NULL AND CAST(j.cdr_start_ms AS INTEGER) > 0
                THEN CAST(j.cdr_start_ms AS INTEGER) / 1000
            WHEN j.cdr_endtime IS NOT NULL AND CAST(j.cdr_endtime AS INTEGER) > 0
                THEN CAST(j.cdr_endtime AS INTEGER)
            ELSE CAST(r.created_at AS INTEGER)
        END AS INTEGER)
        SQL;

    private readonly UsersUICallScope $callScope;
    private readonly UsersUITranscriptAccessService $transcriptAccess;
    private readonly CallDirectionResolver $directionResolver;

    public function __construct(
        private mixed $translation,
        private mixed $request,
        private CallRecordingService $recordings,
        ?UsersUICallScope $callScope = null,
        ?UsersUITranscriptAccessService $transcriptAccess = null,
        ?array $internalNumbers = null,
    ) {
        $this->callScope = $callScope ?? UsersUICallScope::all();
        $this->transcriptAccess = $transcriptAccess ?? new UsersUITranscriptAccessService();
        $this->directionResolver = new CallDirectionResolver(
            $internalNumbers ?? CallDirectionResolver::loadInternalNumbers()
        );
    }

    /**
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}|null $dateFilter
     */
    public function transcriptRows(int $limit = 100, ?array $dateFilter = null, string $search = ''): array
    {
        $searchActive = trim($search) !== '';

        $rows = [];
        $seenGroupKeys = [];
        $seenResultIds = [];
        // Tracks distinct *logical calls* among the accepted rows, using the
        // exact same key rule as groupLogicalCalls() below (callId, falling
        // back to the result id). The page limit is a limit on logical
        // calls, not on raw STTResult parts, so the loop must keep pulling
        // candidate batches until this set reaches $limit - counting
        // count($rows) instead would stop far short of $limit whenever
        // multi-leg calls are involved, since several rows then collapse
        // into a single grouped call.
        $acceptedGroupKeys = [];
        $offset = 0;
        $scanned = 0;
        $candidates = [];

        do {
            $candidates = $searchActive
                ? $this->searchCandidateBatch($search, $dateFilter, $offset, self::CANDIDATE_BATCH_SIZE)
                : $this->boundedCandidateBatch($dateFilter, $offset, self::CANDIDATE_BATCH_SIZE);
            if ($candidates === []) {
                break;
            }
            $offset += count($candidates);
            $scanned += count($candidates);

            // Only the first candidate seen for a given logical call decides
            // whether that whole call gets loaded; later duplicates of the
            // same call_id within (or across) batches are cheap to skip here.
            $newGroups = [];
            foreach ($candidates as $candidate) {
                $key = $candidate['call_id'] !== '' ? 'call:' . $candidate['call_id'] : 'result:' . $candidate['id'];
                if (!isset($seenGroupKeys[$key])) {
                    $seenGroupKeys[$key] = true;
                    $newGroups[$key] = $candidate;
                }
            }

            if ($newGroups !== []) {
                foreach ($this->loadGroupResults($newGroups) as $result) {
                    $resultId = (int)$result->id;
                    if (isset($seenResultIds[$resultId])) {
                        continue;
                    }
                    $seenResultIds[$resultId] = true;
                    $row = $this->buildRow($result, $dateFilter);
                    if ($row !== null) {
                        $rows[] = $row;
                        $acceptedKey = $row['callId'] !== '' ? 'call:' . $row['callId'] : 'result:' . (int)$row['result']->id;
                        $acceptedGroupKeys[$acceptedKey] = true;
                    }
                }
            }
        } while (
            count($acceptedGroupKeys) < $limit
            && count($candidates) === self::CANDIDATE_BATCH_SIZE
            && $scanned < self::CANDIDATE_MAX_SCAN_ROWS
        );

        $rows = $this->groupLogicalCalls($rows);

        usort(
            $rows,
            static fn (array $left, array $right): int => [
                $right['callTimestamp'],
                (int)$right['result']->id,
            ] <=> [
                $left['callTimestamp'],
                (int)$left['result']->id,
            ]
        );

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}|null $dateFilter
     * @return array<string, mixed>|null
     */
    private function buildRow(STTResult $result, ?array $dateFilter): ?array
    {
        $job = $result->STTJob;
        $job = $job instanceof STTJob ? $job : null;
        $callId = $job instanceof STTJob ? trim((string)$job->call_id) : '';
        if (!$this->transcriptAccess->allowsCallId($callId, $this->callScope)) {
            return null;
        }
        $cdr = $job instanceof STTJob
            ? ($this->recordings->cdrRecordForJob($job) ?? $this->recordings->cdrRecordForResult($result))
            : $this->recordings->cdrRecordForResult($result);
        $callTimestamp = $this->recordings->callTimestamp($result, $job, $cdr);
        if (!$this->transcriptMatchesDateFilter($callTimestamp, $dateFilter)) {
            return null;
        }

        $hasCallTimestamp = $callTimestamp > 0;
        $filePath = $job instanceof STTJob ? (string)$job->file_path : '';
        $recordingPath = $this->recordings->recordingPathForPlayback($job, $cdr);
        $recordingName = $recordingPath !== '' ? basename($recordingPath) : basename($filePath);
        if ($recordingName === '' && $cdr instanceof CallDetailRecords) {
            $recordingName = basename((string)$cdr->recordingfile);
        }
        if ($callId === '' && $cdr instanceof CallDetailRecords) {
            $callId = trim((string)$cdr->linkedid) !== '' ? (string)$cdr->linkedid : (string)$cdr->UNIQUEID;
        }
        $createdAt = (int)$result->created_at;
        $callPresentation = $this->callPresentation($cdr);

        return array_merge([
            'result' => $result,
            'job' => $job,
            'callId' => $callId,
            'recordingName' => $recordingName,
            'callTimestamp' => $callTimestamp,
            'dateKey' => $hasCallTimestamp ? date('Y-m-d', $callTimestamp) : 'unknown',
            'dateGroupLabel' => $hasCallTimestamp
                ? date('Y-m-d', $callTimestamp)
                : $this->translation->_('module_local_speech_to_text_CallDateUnknown'),
            'callDateLabel' => $hasCallTimestamp
                ? date('d.m.Y', $callTimestamp)
                : $this->translation->_('module_local_speech_to_text_CallDateUnknown'),
            'callTimeLabel' => $hasCallTimestamp ? date('H:i', $callTimestamp) : '',
            'createdDateLabel' => $createdAt > 0 ? date('d.m.Y', $createdAt) : '',
            'createdTimeLabel' => $createdAt > 0 ? date('H:i', $createdAt) : '',
            'partStartMs' => $job instanceof STTJob ? (int)($job->cdr_start_ms ?? 0) : 0,
            'partEndMs' => $job instanceof STTJob ? (int)($job->cdr_end_ms ?? 0) : 0,
        ], $callPresentation);
    }

    /** @return array{query: string, available: bool} */
    public function transcriptSearchFromRequest(): array
    {
        $query = trim((string)$this->request->getQuery('search', 'string', ''));
        $query = function_exists('mb_substr') ? mb_substr($query, 0, 256) : substr($query, 0, 256);

        return ['query' => $query, 'available' => $this->searchAvailable()];
    }

    /**
     * A bounded, date-pre-filtered page of candidate (id, call_id) pairs for
     * the plain (non-search) listing, newest-call-first. Only a pre-filter:
     * transcriptMatchesDateFilter() still checks the exact callTimestamp()
     * value per row once the candidate's logical call has been loaded.
     *
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}|null $dateFilter
     * @return array<int, array{id: int, call_id: string}>
     */
    private function boundedCandidateBatch(?array $dateFilter, int $offset, int $batchSize): array
    {
        try {
            $dateBound = $this->dateBoundSql($dateFilter);

            $rows = $this->connection()->fetchAll(
                'SELECT r.id AS id, COALESCE(j.call_id, \'\') AS call_id
                   FROM m_STTResult r
                   LEFT JOIN m_STTJob j ON j.id = r.job_id
                  WHERE 1 = 1' . $dateBound['sql'] . '
               ORDER BY (' . self::EFFECTIVE_TIME_SQL . ') DESC, r.id DESC
                  LIMIT ' . max(0, $batchSize) . ' OFFSET ' . max(0, $offset),
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $dateBound['bind']
            );

            return $this->normalizeCandidateRows($rows);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Same shape and date bound as boundedCandidateBatch(), but restricted to
     * full-text matches - so search results are bounded and windowed exactly
     * like the plain listing instead of scanning every match in the index.
     *
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}|null $dateFilter
     * @return array<int, array{id: int, call_id: string}>
     */
    private function searchCandidateBatch(string $search, ?array $dateFilter, int $offset, int $batchSize): array
    {
        try {
            $connection = $this->connection();
            if (!(new TranscriptSearchIndexService())->isReady($connection)) {
                return [];
            }
            $query = TranscriptSearchText::query($search);
            if ($query === '') {
                return [];
            }

            $dateBound = $this->dateBoundSql($dateFilter);
            $bind = array_merge(['search' => $query], $dateBound['bind']);

            $rows = $connection->fetchAll(
                'SELECT r.id AS id, COALESCE(j.call_id, \'\') AS call_id
                   FROM m_STTTranscriptSearch
                   JOIN m_STTResult r ON r.id = m_STTTranscriptSearch.rowid
                   LEFT JOIN m_STTJob j ON j.id = r.job_id
                  WHERE m_STTTranscriptSearch MATCH :search' . $dateBound['sql'] . '
              ORDER BY CAST(r.created_at AS INTEGER) DESC, r.id DESC
                 LIMIT ' . max(0, $batchSize) . ' OFFSET ' . max(0, $offset),
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );

            return $this->normalizeCandidateRows($rows);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{id: int, call_id: string}>
     */
    private function normalizeCandidateRows(array $rows): array
    {
        return array_values(array_filter(array_map(
            static fn (array $row): array => [
                'id' => (int)($row['id'] ?? 0),
                'call_id' => trim((string)($row['call_id'] ?? '')),
            ],
            $rows
        ), static fn (array $row): bool => $row['id'] > 0));
    }

    /**
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}|null $dateFilter
     * @return array{sql: string, bind: array<string, int>}
     */
    private function dateBoundSql(?array $dateFilter): array
    {
        if (!($dateFilter['isActive'] ?? false)) {
            return ['sql' => '', 'bind' => []];
        }

        return [
            'sql' => ' AND (' . self::EFFECTIVE_TIME_SQL . ') BETWEEN :date_bound_start AND :date_bound_end',
            'bind' => [
                'date_bound_start' => (int)$dateFilter['start'],
                'date_bound_end' => (int)$dateFilter['end'] + self::DATE_BOUND_END_SAFETY_MARGIN_SECONDS,
            ],
        ];
    }

    /**
     * Loads every STTResult belonging to the logical calls named by $groups
     * (all parts, not just the ones that landed in the bounded candidate
     * page), so groupLogicalCalls() below always sees complete groups.
     *
     * @param array<string, array{id: int, call_id: string}> $groups
     * @return iterable<STTResult>
     */
    private function loadGroupResults(array $groups): iterable
    {
        $callIds = [];
        $singletonIds = [];
        foreach ($groups as $candidate) {
            if ($candidate['call_id'] !== '') {
                $callIds[$candidate['call_id']] = true;
            } else {
                $singletonIds[] = $candidate['id'];
            }
        }
        $callIds = array_keys($callIds);

        $ids = $singletonIds;
        if ($callIds !== []) {
            try {
                $placeholders = [];
                $bind = [];
                foreach (array_values($callIds) as $index => $callId) {
                    $name = 'call_id_' . $index;
                    $placeholders[] = ':' . $name;
                    $bind[$name] = $callId;
                }
                $rows = $this->connection()->fetchAll(
                    'SELECT r.id AS id
                       FROM m_STTResult r
                       JOIN m_STTJob j ON j.id = r.job_id
                      WHERE j.call_id IN (' . implode(', ', $placeholders) . ')',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    $bind
                );
                foreach ($rows as $row) {
                    $ids[] = (int)($row['id'] ?? 0);
                }
            } catch (Throwable) {
                // Fall back to whatever singleton ids were already gathered;
                // a missing sibling leg here just means a smaller group, not
                // a crash - the exact per-row filters below still apply.
            }
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        return STTResult::find([
            'conditions' => 'id IN ({ids:array})',
            'bind' => ['ids' => $ids],
            'order' => 'created_at DESC, id DESC',
        ]);
    }

    private function connection(): mixed
    {
        return (new STTResult())->getReadConnection();
    }

    private function searchAvailable(): bool
    {
        try {
            return (new TranscriptSearchIndexService())->isReady((new STTResult())->getReadConnection());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}
     */
    public function transcriptDateFilterFromRequest(): array
    {
        $dateFrom = $this->normalizeDateQuery((string)$this->request->getQuery('date_from', 'string', ''));
        $dateTo = $this->normalizeDateQuery((string)$this->request->getQuery('date_to', 'string', ''));

        if ($dateFrom === '' && $dateTo === '') {
            $todayStart = strtotime('today 00:00:00');
            $todayStart = $todayStart === false ? time() : $todayStart;
            $start = strtotime('-6 days', $todayStart);
            $start = $start === false ? $todayStart : $start;
            $end = strtotime('today 23:59:59');
            $end = $end === false ? $todayStart : $end;

            return [
                'dateFrom' => date('Y-m-d', $start),
                'dateTo' => date('Y-m-d', $end),
                'start' => $start,
                'end' => $end,
                'isActive' => true,
            ];
        }

        if ($dateFrom === '') {
            $dateFrom = $dateTo;
        }
        if ($dateTo === '') {
            $dateTo = $dateFrom;
        }

        $start = strtotime($dateFrom . ' 00:00:00');
        $end = strtotime($dateTo . ' 23:59:59');
        if ($start === false || $end === false) {
            return [
                'dateFrom' => '',
                'dateTo' => '',
                'start' => 0,
                'end' => 0,
                'isActive' => false,
            ];
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'start' => $start,
            'end' => $end,
            'isActive' => true,
        ];
    }

    private function normalizeDateQuery(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return '';
        }

        $timestamp = strtotime($date . ' 00:00:00');

        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    /**
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}|null $dateFilter
     */
    private function transcriptMatchesDateFilter(int $callTimestamp, ?array $dateFilter): bool
    {
        if (!($dateFilter['isActive'] ?? false)) {
            return true;
        }

        return $callTimestamp > 0
            && $callTimestamp >= (int)$dateFilter['start']
            && $callTimestamp <= (int)$dateFilter['end'];
    }

    /**
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool} $dateFilter
     * @return array<string, mixed>
     */
    public function transcriptFilterView(array $dateFilter): array
    {
        $dateFrom = $dateFilter['dateFrom'];
        $dateTo = $dateFilter['dateTo'];

        $displayRange = '';
        if ($dateFrom !== '' && $dateTo !== '') {
            $fromTimestamp = strtotime($dateFrom . ' 00:00:00');
            $toTimestamp = strtotime($dateTo . ' 00:00:00');
            if ($fromTimestamp !== false && $toTimestamp !== false) {
                $displayRange = date('d/m/Y', $fromTimestamp) . ' - ' . date('d/m/Y', $toTimestamp);
            }
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'displayRange' => $displayRange,
            'isActive' => $dateFilter['isActive'],
        ];
    }

    /** @return array<string, mixed> */
    private function callPresentation(?CallDetailRecords $cdr): array
    {
        if (!$cdr instanceof CallDetailRecords) {
            return $this->presentCall('unknown', '', '', 0);
        }

        $source = trim((string)($cdr->src_num ?? ''));
        $destination = trim((string)($cdr->dst_num ?? ''));
        $duration = (float)($cdr->duration ?? 0);
        $direction = $this->directionResolver->resolve($cdr)['direction'];

        return match ($direction) {
            CallDirectionResolver::DIRECTION_INTERNAL => $this->presentCall(
                'internal',
                '—',
                $this->routeLabel($this->employeeLabel($source), $this->employeeLabel($destination)),
                $duration,
                [$this->employeeLabel($source), $this->employeeLabel($destination)]
            ),
            CallDirectionResolver::DIRECTION_OUTGOING => $this->presentCall(
                'outgoing',
                $destination,
                $this->employeeLabel($source),
                $duration,
                [$this->employeeLabel($source)]
            ),
            CallDirectionResolver::DIRECTION_INCOMING => $this->presentCall(
                'incoming',
                $source,
                $this->employeeLabel($destination),
                $duration,
                [$this->employeeLabel($destination)]
            ),
            default => $this->presentCall('unknown', '—', $this->routeLabel($source, $destination), $duration),
        };
    }

    /** @param array<int, string> $employeeLabels @return array<string, mixed> */
    private function presentCall(
        string $direction,
        string $client,
        string $employees,
        float $duration,
        array $employeeLabels = []
    ): array
    {
        $directionKeys = [
            'incoming' => 'module_local_speech_to_text_IncomingCall',
            'outgoing' => 'module_local_speech_to_text_OutgoingCall',
            'internal' => 'module_local_speech_to_text_InternalCall',
            'unknown' => 'module_local_speech_to_text_UnknownDirection',
        ];
        $directionIcons = [
            'incoming' => '↙',
            'outgoing' => '↗',
            'internal' => '↔',
            'unknown' => '↔',
        ];

        return [
            'direction' => $direction,
            'directionIcon' => $directionIcons[$direction],
            'directionLabel' => $this->translation->_($directionKeys[$direction]),
            'clientLabel' => $client !== '' ? $client : '—',
            'employeesLabel' => $employees !== '' ? $employees : '—',
            'employeeLabels' => array_values(array_filter(array_unique($employeeLabels))),
            'hasTransfer' => false,
            'transferEmployeeLabels' => [],
            'callDurationLabel' => $duration > 0 ? $this->durationLabel($duration) : '—',
        ];
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function groupLogicalCalls(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $callId = trim((string)($row['callId'] ?? ''));
            $resultId = (int)($row['result']->id ?? 0);
            $key = $callId !== '' ? 'call:' . $callId : 'result:' . $resultId;
            $groups[$key][] = $row;
        }

        $grouped = [];
        foreach ($groups as $parts) {
            usort($parts, static fn (array $left, array $right): int => [
                (int)($left['partStartMs'] ?? 0),
                (int)($left['result']->id ?? 0),
            ] <=> [
                (int)($right['partStartMs'] ?? 0),
                (int)($right['result']->id ?? 0),
            ]);

            $primary = $parts[0];
            foreach ($parts as $part) {
                if (in_array($part['direction'] ?? '', ['incoming', 'outgoing'], true)) {
                    $primary = $part;
                    break;
                }
            }

            $employees = [];
            $customerFacingEmployees = [];
            $hasInternalConsultation = false;
            $startMs = 0;
            $endMs = 0;
            foreach ($parts as $part) {
                foreach (($part['employeeLabels'] ?? []) as $employee) {
                    if ($employee !== '' && !in_array($employee, $employees, true)) {
                        $employees[] = $employee;
                    }
                }
                if (($part['direction'] ?? '') !== 'internal') {
                    foreach (($part['employeeLabels'] ?? []) as $employee) {
                        if ($employee !== '' && end($customerFacingEmployees) !== $employee) {
                            $customerFacingEmployees[] = $employee;
                        }
                    }
                }
                $hasInternalConsultation = $hasInternalConsultation || ($part['direction'] ?? '') === 'internal';
                $partStartMs = (int)($part['partStartMs'] ?? 0);
                $partEndMs = (int)($part['partEndMs'] ?? 0);
                if ($partStartMs > 0 && ($startMs === 0 || $partStartMs < $startMs)) {
                    $startMs = $partStartMs;
                }
                if ($partEndMs > $endMs) {
                    $endMs = $partEndMs;
                }
            }

            $primary['partCount'] = count($parts);
            $primary['hasInternalConsultation'] = $hasInternalConsultation;
            $primary['hasTransfer'] = count($customerFacingEmployees) > 1;
            $primary['transferEmployeeLabels'] = $primary['hasTransfer'] ? $customerFacingEmployees : [];
            if (count($parts) > 1 && $employees !== []) {
                $primary['employeesLabel'] = implode(' · ', $employees);
            }
            if (count($parts) > 1 && $startMs > 0 && $endMs > $startMs) {
                $primary['callDurationLabel'] = $this->durationLabel(($endMs - $startMs) / 1000);
            }
            $grouped[] = $primary;
        }

        return $grouped;
    }

    private function employeeLabel(string $number): string
    {
        return $number === '' ? '' : $this->recordings->numberWithEmployeeName($number);
    }

    private function routeLabel(string $source, string $destination): string
    {
        if ($source === '') {
            return $destination;
        }
        if ($destination === '') {
            return $source;
        }

        return $source . ' → ' . $destination;
    }

    private function durationLabel(float $seconds): string
    {
        $seconds = max(0, (int)round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds)
            : sprintf('%02d:%02d', $minutes, $remainingSeconds);
    }

}
