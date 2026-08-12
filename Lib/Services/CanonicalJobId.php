<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class CanonicalJobId
{
    public static function matchesRoute(mixed $bodyId, int $routeId): bool
    {
        if ($routeId <= 0 || is_bool($bodyId) || is_float($bodyId)) return false;
        if (is_int($bodyId)) return $bodyId > 0 && $bodyId === $routeId;
        if (!is_string($bodyId) || preg_match('/^[1-9][0-9]*$/D', $bodyId) !== 1) return false;
        $maximum = (string)PHP_INT_MAX;
        if (strlen($bodyId) > strlen($maximum) || (strlen($bodyId) === strlen($maximum) && strcmp($bodyId, $maximum) > 0)) return false;
        return (int)$bodyId === $routeId;
    }
}
