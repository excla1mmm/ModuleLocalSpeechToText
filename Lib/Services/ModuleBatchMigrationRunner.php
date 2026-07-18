<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use RuntimeException;

final class ModuleBatchMigrationRunner
{
    public function run(callable $batch): int
    {
        $total = 0;
        do {
            $processed = $batch();
            if (!is_int($processed) || $processed < 0) {
                throw new RuntimeException('Module batch migration returned an invalid processed-row count');
            }
            $total += $processed;
        } while ($processed > 0);

        return $total;
    }
}
