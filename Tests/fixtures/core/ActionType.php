<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Attributes;

/**
 * Portable snapshot of the supported Core permission hierarchy.
 */
enum ActionType: string
{
    case READ = 'read';
    case WRITE = 'write';
    case ADMIN = 'admin';
    case SENSITIVE = 'sensitive';

    public function includes(self $other): bool
    {
        return match ($this) {
            self::ADMIN => true,
            self::SENSITIVE => in_array($other, [self::READ, self::SENSITIVE], true),
            self::WRITE => in_array($other, [self::READ, self::WRITE], true),
            self::READ => $other === self::READ,
        };
    }
}
