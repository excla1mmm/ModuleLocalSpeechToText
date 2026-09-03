<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class UsersUIViewCapabilities
{
    private const array ALL_TABS = ['settings', 'models', 'queue', 'workers', 'transcripts', 'logs'];

    public function __construct(
        private readonly bool $administrator,
        private readonly bool $transcripts,
        private readonly bool $settings,
        private readonly bool $system,
    ) {
    }

    public function canViewTranscripts(): bool
    {
        return $this->administrator || $this->transcripts;
    }

    public function canManageSettings(): bool
    {
        return $this->administrator || $this->settings;
    }

    public function canManageSystem(): bool
    {
        return $this->administrator || $this->system;
    }

    /** @return array<int, string> */
    public function visibleTabs(): array
    {
        if ($this->administrator) {
            return self::ALL_TABS;
        }

        $tabs = [];
        if ($this->settings) {
            $tabs = ['settings', 'models'];
        }
        if ($this->system) {
            $tabs = array_merge($tabs, ['queue', 'workers']);
        }
        if ($this->transcripts) {
            $tabs[] = 'transcripts';
        }
        if ($this->system) {
            $tabs[] = 'logs';
        }

        return $tabs;
    }

    public function normalizeTab(string $requested): string
    {
        $tabs = $this->visibleTabs();
        if (in_array($requested, $tabs, true)) {
            return $requested;
        }
        if ($this->administrator) {
            return 'settings';
        }

        return in_array('transcripts', $tabs, true) ? 'transcripts' : ($tabs[0] ?? '');
    }
}
