<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use Modules\ModuleLocalSpeechToText\App\Providers\MenuProvider;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Throwable;

class ModuleLocalSpeechToTextController extends LocalSpeechToTextBaseController
{
    public function indexAction(): void
    {
        $this->addModuleAssets();

        $this->view->form = $this->buildSettingsForm();
        $this->view->transcriptPath = MenuProvider::TRANSCRIPT_PATH;
        $this->view->modelPresets = STTSetting::MODEL_PRESETS;
        $this->view->selectedModel = STTSetting::selectedModelId();
        $this->view->pendingCount = $this->countJobsByStatus(STTJob::STATUS_PENDING);
        $this->view->processingCount = $this->countJobsByStatus(STTJob::STATUS_PROCESSING);
        $this->view->doneTodayCount = STTJob::count([
            'conditions' => 'status = :status: AND finished_at >= :since:',
            'bind' => [
                'status' => STTJob::STATUS_DONE,
                'since' => strtotime('today'),
            ],
        ]);
        $this->view->errorCount = $this->countJobsByStatus(STTJob::STATUS_ERROR);
        STTWorker::markStaleOffline();
        $this->view->workers = STTWorker::find([
            'order' => 'last_seen DESC, id DESC',
        ]);
        $this->view->results = STTResult::find([
            'order' => 'created_at DESC, id DESC',
            'limit' => 100,
        ]);

        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/index');
    }

    public function saveAction(): void
    {
        try {
            $this->saveSettingsFromPost();
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
            $this->view->success = true;
            $this->view->reload = MenuProvider::DASHBOARD_PATH;
        } catch (Throwable $exception) {
            $this->flash->error($exception->getMessage());
            $this->view->success = false;
        }

        if ($this->request->isAjax() === false) {
            $this->response->redirect(MenuProvider::DASHBOARD_PATH);
        }
    }

    public function retryErrorsAction(): void
    {
        try {
            $count = STTJob::requeueErrors();
            if ($count > 0) {
                $message = sprintf($this->translation->_('module_local_speech_to_text_RetryErrorsSuccess'), $count);
                $this->flash->success($message);
            } else {
                $this->flash->warning($this->translation->_('module_local_speech_to_text_RetryErrorsEmpty'));
            }
        } catch (Throwable $exception) {
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=queue');
    }

    public function transcriptsAction(): void
    {
        $this->addModuleAssets(false);

        $this->view->dashboardPath = MenuProvider::DASHBOARD_PATH;
        $this->view->transcriptPath = MenuProvider::TRANSCRIPT_PATH;
        $this->view->results = STTResult::find([
            'order' => 'created_at DESC, id DESC',
            'limit' => 100,
        ]);

        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/transcripts');
    }

    public function transcriptAction(): void
    {
        $this->addModuleAssets(false);

        $resultId = (int)$this->request->getQuery('id', 'int', 0);
        $result = STTResult::findFirstById($resultId);
        if (!$result instanceof STTResult) {
            $this->flash->error($this->translation->_('module_local_speech_to_text_TranscriptNotFound'));
            $this->response->redirect(MenuProvider::TRANSCRIPTS_PATH);
            return;
        }

        $this->view->transcriptsPath = MenuProvider::DASHBOARD_PATH . '?tab=transcripts';
        $this->view->result = $result;
        $this->view->conversation = $this->conversationFromTranscript((string)$result->transcript);

        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/transcript');
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationFromTranscript(string $transcript): array
    {
        $segments = json_decode($transcript, true);
        if (!is_array($segments)) {
            return $this->emptyConversation();
        }

        $speakers = [
            'left' => $this->speakerColumn('left', $this->translation->_('module_local_speech_to_text_Speaker1')),
            'right' => $this->speakerColumn('right', $this->translation->_('module_local_speech_to_text_Speaker2')),
        ];

        $timeline = [];

        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }

            $text = trim((string)($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $speakerKey = $this->speakerKey($segment);
            if (!isset($speakers[$speakerKey])) {
                $speakers[$speakerKey] = $this->speakerColumn($speakerKey, $this->speakerLabel($speakerKey, count($speakers) + 1));
            }

            $start = $this->floatValue($segment['start'] ?? null);
            $end = $this->floatValue($segment['end'] ?? null);
            $utterance = [
                'text' => $text,
                'speakerKey' => $speakerKey,
                'speakerLabel' => $speakers[$speakerKey]['label'],
                'start' => $start,
                'end' => $end,
                'startLabel' => $this->timeLabel($start),
                'rangeLabel' => $this->rangeLabel($start, $end),
            ];
            $speakers[$speakerKey]['segments'][] = $utterance;
            $timeline[] = $utterance;
        }

        foreach ($speakers as &$speaker) {
            usort(
                $speaker['segments'],
                static fn (array $left, array $right): int => ($left['start'] <=> $right['start'])
            );
        }
        unset($speaker);

        usort(
            $timeline,
            static fn (array $left, array $right): int => [$left['start'], $left['end']] <=> [$right['start'], $right['end']]
        );

        return [
            'speakers' => array_values($speakers),
            'timeline' => $timeline,
            'hasSegments' => count($timeline) > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyConversation(): array
    {
        return [
            'speakers' => [
                $this->speakerColumn('left', $this->translation->_('module_local_speech_to_text_Speaker1')),
                $this->speakerColumn('right', $this->translation->_('module_local_speech_to_text_Speaker2')),
            ],
            'timeline' => [],
            'hasSegments' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function speakerColumn(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'segments' => [],
        ];
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function speakerKey(array $segment): string
    {
        $speaker = strtolower(trim((string)($segment['speaker'] ?? '')));
        $channel = strtolower(trim((string)($segment['channel'] ?? '')));
        $key = $speaker !== '' ? $speaker : $channel;

        if (in_array($key, ['speaker 1', 'speaker_1', 'speaker1', 'left'], true)) {
            return 'left';
        }
        if (in_array($key, ['speaker 2', 'speaker_2', 'speaker2', 'right'], true)) {
            return 'right';
        }

        return $key === '' ? 'left' : (string)preg_replace('/[^a-z0-9_-]+/', '-', $key);
    }

    private function speakerLabel(string $speakerKey, int $number): string
    {
        if ($speakerKey === 'left') {
            return $this->translation->_('module_local_speech_to_text_Speaker1');
        }
        if ($speakerKey === 'right') {
            return $this->translation->_('module_local_speech_to_text_Speaker2');
        }

        return sprintf('%s %d', $this->translation->_('module_local_speech_to_text_Speaker'), $number);
    }

    private function floatValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        return 0.0;
    }

    private function timeLabel(float $seconds): string
    {
        $totalSeconds = (int)floor(max(0.0, $seconds));
        $minutes = intdiv($totalSeconds, 60);
        $rest = $totalSeconds % 60;

        return sprintf('%d:%02d', $minutes, $rest);
    }

    private function rangeLabel(float $start, float $end): string
    {
        if ($end <= $start) {
            return $this->timeLabel($start);
        }

        return sprintf('%s - %s', $this->timeLabel($start), $this->timeLabel($end));
    }
}
