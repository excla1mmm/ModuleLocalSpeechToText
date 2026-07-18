<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models;

use Phalcon\Mvc\Model;
use ReflectionClass;
use RuntimeException;

if (class_exists(ModulesModelsBase::class, false)) {
    throw new RuntimeException('Core ModulesModelsBase was loaded before the isolated rehearsal shim');
}

/**
 * Minimal module-model base used only by the isolated database-copy rehearsal.
 * It deliberately omits Core model events, Redis, and cross-module relations.
 */
if (!class_exists(ModulesModelsBase::class, false)) {
    abstract class ModulesModelsBase extends Model
    {
        public function initialize(): void
        {
            $namespace = (new ReflectionClass(static::class))->getNamespaceName();
            $parts = explode('\\', $namespace);
            if (count($parts) === 3 && $parts[0] === 'Modules') {
                $this->setConnectionService(self::getConnectionServiceName($parts[1]));
            }
        }

        public static function getConnectionServiceName(string $moduleUniqueId): string
        {
            return $moduleUniqueId . '_module_db';
        }
    }
}
