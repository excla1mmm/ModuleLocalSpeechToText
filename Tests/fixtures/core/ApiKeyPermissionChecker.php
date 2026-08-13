<?php

declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Services;

use MikoPBX\PBXCoreREST\Attributes\ActionType;

/**
 * Portable permission-checker fixture pinned to the supported Core semantics.
 *
 * It intentionally contains no Core models, DI container, or logging dependencies.
 */
final class ApiKeyPermissionChecker
{
    public function checkPermission(object $apiKey, string $requestPath, string $httpMethod): bool
    {
        if (($apiKey->full_permissions ?? null) === '1') {
            return true;
        }

        $permissions = $this->parseAllowedPaths($apiKey->allowed_paths ?? null);
        if ($permissions === []) {
            return false;
        }

        $basePath = $this->extractBasePath($this->normalizePath($requestPath));
        if (!isset($permissions[$basePath])) {
            return false;
        }

        $grantedAction = ActionType::tryFrom($permissions[$basePath]);
        if ($grantedAction === null) {
            return false;
        }

        return $grantedAction->includes($this->methodToAction($httpMethod));
    }

    /** @return array<string, string> */
    private function parseAllowedPaths(?string $allowedPaths): array
    {
        if ($allowedPaths === null || $allowedPaths === '') {
            return [];
        }

        $decoded = json_decode($allowedPaths, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizePath(string $path): string
    {
        $path = explode('?', $path, 2)[0];
        $path = (string)preg_replace('#^/pbxcore#', '', $path);
        return rtrim($path, '/');
    }

    private function extractBasePath(string $path): string
    {
        $pathWithoutMethod = preg_replace('/:[^\/]+$/', '', $path) ?? $path;
        $segments = array_values(array_filter(explode('/', $pathWithoutMethod), static fn(string $segment): bool => $segment !== ''));
        $lastSegment = end($segments);

        if ($lastSegment === false) {
            return '/';
        }

        if ($this->looksLikeId($lastSegment)) {
            array_pop($segments);
        }

        return '/' . implode('/', $segments);
    }

    private function looksLikeId(string $segment): bool
    {
        return ctype_digit($segment)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $segment) === 1
            || preg_match('/^(?:[A-Z]+-)+[A-F0-9]+$/', $segment) === 1
            || preg_match('/^\d+[a-zA-Z]/', $segment) === 1
            || preg_match('/^[a-z]+-\d+/', $segment) === 1;
    }

    private function methodToAction(string $method): ActionType
    {
        return match (strtoupper($method)) {
            'GET' => ActionType::READ,
            'POST', 'PUT', 'PATCH', 'DELETE' => ActionType::WRITE,
            default => ActionType::READ,
        };
    }
}
