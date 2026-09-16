<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\CallDetailRecords;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;

final class LogicalCallReviewService
{
    private readonly CallDirectionResolver $directionResolver;
    private readonly TranscriptConversationService $conversations;

    /** @param array<string, bool>|null $internalNumbers */
    public function __construct(
        private readonly mixed $translation,
        private readonly CallRecordingService $recordings,
        private readonly ConversationSpeakerLabelResolver $speakerLabels,
        ?array $internalNumbers = null,
    ) {
        $this->directionResolver = new CallDirectionResolver(
            $internalNumbers ?? CallDirectionResolver::loadInternalNumbers()
        );
        $this->conversations = new TranscriptConversationService($translation);
    }

    /** @return array{stages: array<int, array<string, mixed>>, groups: array<string, array<int, array<string, mixed>>>, employees: array<int, string>, showTabs: bool} */
    public function review(string $callId, STTResult $fallbackResult): array
    {
        $jobs = [];
        if (trim($callId) !== '') {
            foreach (STTJob::find([
                'conditions' => 'call_id = :call_id: AND status = :status:',
                'bind' => ['call_id' => $callId, 'status' => STTJob::STATUS_DONE],
                'order' => 'cdr_start_ms ASC, id ASC',
            ]) as $job) {
                if ($job instanceof STTJob && $job->STTResult instanceof STTResult) {
                    $jobs[] = $job;
                }
            }
        }

        $fallbackJob = $fallbackResult->STTJob ?? null;
        $fallbackJob = $fallbackJob instanceof STTJob ? $fallbackJob : null;
        if ($jobs === [] && $fallbackJob instanceof STTJob) {
            $jobs[] = $fallbackJob;
        }

        usort($jobs, static fn (STTJob $left, STTJob $right): int => [
            (int)$left->cdr_start_ms,
            (int)$left->id,
        ] <=> [
            (int)$right->cdr_start_ms,
            (int)$right->id,
        ]);

        $stages = [];
        $groups = ['customer' => [], 'internal' => []];
        $employees = [];
        foreach ($jobs as $job) {
            $result = $job->STTResult;
            if (!$result instanceof STTResult) {
                continue;
            }
            $cdr = $this->recordings->cdrRecordForJob($job) ?? $this->recordings->cdrRecordForResult($result);
            $direction = $this->directionResolver->resolve($cdr)['direction'];
            $scope = $direction === CallDirectionResolver::DIRECTION_INTERNAL ? 'internal' : 'customer';
            foreach ($this->employeeLabels($cdr, $direction) as $employee) {
                if (!in_array($employee, $employees, true)) {
                    $employees[] = $employee;
                }
            }

            $stage = [
                'result' => $result,
                'job' => $job,
                'recording' => $this->recordings->recordingCard($result, $job, $cdr),
                'conversation' => $this->conversations->conversationFromTranscript(
                    (string)$result->transcript,
                    true,
                    $this->speakerLabels->resolve($cdr)
                ),
                'direction' => $direction,
                'scope' => $scope,
                'title' => $this->routeTitle($cdr),
            ];
            $stages[] = $stage;
            $groups[$scope][] = $stage;
        }

        return [
            'stages' => $stages,
            'groups' => $groups,
            'employees' => $employees,
            'showTabs' => $groups['customer'] !== [] && $groups['internal'] !== [],
        ];
    }

    /** @return array<int, string> */
    private function employeeLabels(?CallDetailRecords $cdr, string $direction): array
    {
        if (!$cdr instanceof CallDetailRecords) {
            return [];
        }
        $source = trim((string)$cdr->src_num);
        $destination = trim((string)$cdr->dst_num);
        $sourceName = (string)($cdr->src_name ?? '');
        $destinationName = (string)($cdr->dst_name ?? '');
        $numbers = match ($direction) {
            CallDirectionResolver::DIRECTION_INCOMING => [$destination => $destinationName],
            CallDirectionResolver::DIRECTION_OUTGOING => [$source => $sourceName],
            CallDirectionResolver::DIRECTION_INTERNAL => [$source => $sourceName, $destination => $destinationName],
            default => [],
        };

        $labels = [];
        foreach ($numbers as $number => $displayName) {
            $label = $this->recordings->numberWithEmployeeName((string)$number, $displayName);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    private function routeTitle(?CallDetailRecords $cdr): string
    {
        if (!$cdr instanceof CallDetailRecords) {
            return '';
        }
        $source = $this->recordings->numberWithEmployeeName((string)$cdr->src_num, (string)($cdr->src_name ?? ''));
        $destination = $this->recordings->numberWithEmployeeName((string)$cdr->dst_num, (string)($cdr->dst_name ?? ''));

        return trim($source . ($source !== '' && $destination !== '' ? ' → ' : '') . $destination);
    }
}
