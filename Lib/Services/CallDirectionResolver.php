<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\CallDetailRecords;
use MikoPBX\Common\Models\Extensions;
use Throwable;

/** Determines call direction (incoming/outgoing/internal) from a CDR row. */
final class CallDirectionResolver
{
    public const string DIRECTION_INCOMING = 'incoming';
    public const string DIRECTION_OUTGOING = 'outgoing';
    public const string DIRECTION_INTERNAL = 'internal';
    public const string DIRECTION_UNKNOWN = 'unknown';

    /** @param array<string, bool> $internalNumbers */
    public function __construct(private readonly array $internalNumbers)
    {
    }

    /** @return array<string, bool> */
    public static function loadInternalNumbers(): array
    {
        $numbers = [];
        try {
            foreach (Extensions::find(['columns' => 'number']) as $extension) {
                $number = trim((string)$extension->number);
                if ($number !== '') {
                    $numbers[$number] = true;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $numbers;
    }

    /** @return array{direction: string, sourceInternal: bool, destinationInternal: bool} */
    public function resolve(?CallDetailRecords $cdr): array
    {
        if (!$cdr instanceof CallDetailRecords) {
            return $this->result(self::DIRECTION_UNKNOWN, false, false);
        }

        $source = trim((string)($cdr->src_num ?? ''));
        $destination = trim((string)($cdr->dst_num ?? ''));
        $sourceInternal = isset($this->internalNumbers[$source]);
        $destinationInternal = isset($this->internalNumbers[$destination]);

        if ($sourceInternal && $destinationInternal) {
            return $this->result(self::DIRECTION_INTERNAL, true, true);
        }
        if ($sourceInternal && !$destinationInternal) {
            return $this->result(self::DIRECTION_OUTGOING, true, false);
        }
        if (!$sourceInternal && $destinationInternal) {
            return $this->result(self::DIRECTION_INCOMING, false, true);
        }
        if (trim((string)($cdr->did ?? '')) !== '' || trim((string)($cdr->from_account ?? '')) !== '') {
            return $this->result(self::DIRECTION_INCOMING, false, false);
        }
        if (trim((string)($cdr->to_account ?? '')) !== '') {
            return $this->result(self::DIRECTION_OUTGOING, false, false);
        }

        return $this->result(self::DIRECTION_UNKNOWN, false, false);
    }

    /** @return array{direction: string, sourceInternal: bool, destinationInternal: bool} */
    private function result(string $direction, bool $sourceInternal, bool $destinationInternal): array
    {
        return [
            'direction' => $direction,
            'sourceInternal' => $sourceInternal,
            'destinationInternal' => $destinationInternal,
        ];
    }
}
