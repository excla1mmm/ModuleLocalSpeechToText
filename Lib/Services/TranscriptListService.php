<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\CallDetailRecords;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;

class TranscriptListService
{
    private readonly UsersUICallScope $callScope;
    private readonly UsersUITranscriptAccessService $transcriptAccess;

    public function __construct(
        private mixed $translation,
        private mixed $request,
        private CallRecordingService $recordings,
        ?UsersUICallScope $callScope = null,
        ?UsersUITranscriptAccessService $transcriptAccess = null,
    ) {
        $this->callScope = $callScope ?? UsersUICallScope::all();
        $this->transcriptAccess = $transcriptAccess ?? new UsersUITranscriptAccessService();
    }

    public function transcriptRows(int $limit = 100, ?array $dateFilter = null): array
    {
        $rows = [];
        $findParams = [
            'order' => 'created_at DESC, id DESC',
        ];
        $dateFilterActive = (bool)($dateFilter['isActive'] ?? false);
        if (!$dateFilterActive && $this->callScope->allowsEveryCall()) {
            $findParams['limit'] = $limit;
        }

        $results = STTResult::find($findParams);

        foreach ($results as $result) {
            if (!$result instanceof STTResult) {
                continue;
            }

            $job = $result->STTJob;
            $job = $job instanceof STTJob ? $job : null;
            $callId = $job instanceof STTJob ? trim((string)$job->call_id) : '';
            if (!$this->transcriptAccess->allowsCallId($callId, $this->callScope)) {
                continue;
            }
            $cdr = $job instanceof STTJob
                ? ($this->recordings->cdrRecordForJob($job) ?? $this->recordings->cdrRecordForResult($result))
                : $this->recordings->cdrRecordForResult($result);
            $callTimestamp = $this->recordings->callTimestamp($result, $job, $cdr);
            if (!$this->transcriptMatchesDateFilter($callTimestamp, $dateFilter)) {
                continue;
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

            $rows[] = [
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
                    ? date('Y-m-d', $callTimestamp)
                    : $this->translation->_('module_local_speech_to_text_CallDateUnknown'),
                'callTimeLabel' => $hasCallTimestamp ? date('H:i:s', $callTimestamp) : '',
                'createdAtLabel' => $createdAt > 0 ? date('Y-m-d H:i:s', $createdAt) : '',
            ];
        }

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

        if (!$dateFilterActive && count($rows) > $limit) {
            return array_slice($rows, 0, $limit);
        }

        return $rows;
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

}
