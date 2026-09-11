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
            $callPresentation = $this->callPresentation($cdr);

            $rows[] = array_merge([
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
            ], $callPresentation);
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

    /** @return array<string, string> */
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
                $this->routeLabel($source, $destination),
                $duration
            ),
            CallDirectionResolver::DIRECTION_OUTGOING => $this->presentCall('outgoing', $destination, $source, $duration),
            CallDirectionResolver::DIRECTION_INCOMING => $this->presentCall('incoming', $source, $destination, $duration),
            default => $this->presentCall('unknown', '—', $this->routeLabel($source, $destination), $duration),
        };
    }

    /** @return array<string, string> */
    private function presentCall(string $direction, string $client, string $employees, float $duration): array
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
            'callDurationLabel' => $duration > 0 ? $this->durationLabel($duration) : '—',
        ];
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
