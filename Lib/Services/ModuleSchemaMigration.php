<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use RuntimeException;

final class ModuleSchemaMigration
{
    private readonly Closure $operation;
    private readonly string $checksum;

    public function __construct(
        public readonly int $version,
        public readonly string $name,
        callable $operation,
        ?string $checksumSeed = null
    ) {
        if ($this->version < 1) {
            throw new RuntimeException('Schema migration version must be positive');
        }
        if (trim($this->name) === '') {
            throw new RuntimeException('Schema migration name must not be empty');
        }

        $this->operation = Closure::fromCallable($operation);
        $this->checksum = hash(
            'sha256',
            $checksumSeed ?? ($this->version . "\0" . $this->name)
        );
    }

    public function apply(mixed $connection): mixed
    {
        return ($this->operation)($connection);
    }

    public function checksum(): string
    {
        return $this->checksum;
    }
}
