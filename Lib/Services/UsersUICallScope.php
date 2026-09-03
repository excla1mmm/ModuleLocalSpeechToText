<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class UsersUICallScope
{
    private const string MODE_LEGACY = 'legacy';
    private const string MODE_ALL = 'all';
    private const string MODE_SELECTED = 'selected';
    private const string MODE_OUTGOING_SELECTED = 'outgoing-selected';
    private const string MODE_EXCEPT_SELECTED = 'not-selected';
    private const string MODE_DENIED = 'denied';

    /** @param array<int, string> $employeeNumbers */
    private function __construct(
        private readonly string $mode,
        private readonly array $employeeNumbers = [],
    ) {
    }

    public static function legacy(): self
    {
        return new self(self::MODE_LEGACY);
    }

    public static function all(): self
    {
        return new self(self::MODE_ALL);
    }

    /** @param array<int, string> $employeeNumbers */
    public static function selected(array $employeeNumbers): self
    {
        $numbers = self::uniqueNumbers($employeeNumbers);

        return $numbers === [] ? self::denied() : new self(self::MODE_SELECTED, $numbers);
    }

    /** @param array<int, string> $employeeNumbers */
    public static function outgoingSelected(array $employeeNumbers): self
    {
        $numbers = self::uniqueNumbers($employeeNumbers);

        return $numbers === [] ? self::denied() : new self(self::MODE_OUTGOING_SELECTED, $numbers);
    }

    /** @param array<int, string> $employeeNumbers */
    public static function exceptSelected(array $employeeNumbers): self
    {
        $numbers = self::uniqueNumbers($employeeNumbers);

        return $numbers === [] ? self::all() : new self(self::MODE_EXCEPT_SELECTED, $numbers);
    }

    public static function denied(): self
    {
        return new self(self::MODE_DENIED);
    }

    public function preservesLegacyAuthorization(): bool
    {
        return $this->mode === self::MODE_LEGACY;
    }

    public function allowsEveryCall(): bool
    {
        return in_array($this->mode, [self::MODE_LEGACY, self::MODE_ALL], true);
    }

    public function allowsCall(string $source, string $destination): bool
    {
        $sourceMatches = $this->matchesEmployee($source);
        $destinationMatches = $this->matchesEmployee($destination);

        return match ($this->mode) {
            self::MODE_LEGACY, self::MODE_ALL => true,
            self::MODE_SELECTED => $sourceMatches || $destinationMatches,
            self::MODE_OUTGOING_SELECTED => $sourceMatches,
            self::MODE_EXCEPT_SELECTED => !$sourceMatches && !$destinationMatches,
            default => false,
        };
    }

    /** @param array<int, array{source: string, destination: string}> $participants */
    public function allowsParticipants(array $participants): bool
    {
        if ($this->allowsEveryCall()) {
            return true;
        }
        if ($participants === []) {
            return false;
        }
        if ($this->mode === self::MODE_EXCEPT_SELECTED) {
            foreach ($participants as $participant) {
                if (!$this->allowsCall($participant['source'], $participant['destination'])) {
                    return false;
                }
            }

            return true;
        }
        foreach ($participants as $participant) {
            if ($this->allowsCall($participant['source'], $participant['destination'])) {
                return true;
            }
        }

        return false;
    }

    private function matchesEmployee(string $number): bool
    {
        foreach (self::numberCandidates($number) as $candidate) {
            if (in_array($candidate, $this->employeeNumbers, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private static function uniqueNumbers(array $values): array
    {
        $numbers = [];
        foreach ($values as $value) {
            foreach (self::numberCandidates((string)$value) as $candidate) {
                $numbers[$candidate] = $candidate;
            }
        }

        return array_values($numbers);
    }

    /** @return array<int, string> */
    private static function numberCandidates(string $number): array
    {
        $number = trim($number);
        if ($number === '') {
            return [];
        }
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        return array_values(array_unique(array_filter([$number, $digits])));
    }
}
