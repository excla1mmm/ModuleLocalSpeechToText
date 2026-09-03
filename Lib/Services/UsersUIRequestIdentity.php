<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use Throwable;

/** Resolves the authenticated browser identity without trusting request parameters. */
final class UsersUIRequestIdentity
{
    /** @var Closure(string): ?array */
    private readonly Closure $bearerPayload;
    /** @var Closure(string): ?string */
    private readonly Closure $refreshRole;
    /** @var Closure(string): ?string */
    private readonly Closure $refreshUsername;

    public function __construct(
        callable $bearerPayload,
        callable $refreshRole,
        callable $refreshUsername,
    ) {
        $this->bearerPayload = Closure::fromCallable($bearerPayload);
        $this->refreshRole = Closure::fromCallable($refreshRole);
        $this->refreshUsername = Closure::fromCallable($refreshUsername);
    }

    /** @return array{_role: string, _username: string} */
    public function resolve(string $authorizationHeader, string $refreshToken): array
    {
        try {
            $payload = ($this->bearerPayload)($authorizationHeader);
            if (is_array($payload)) {
                return [
                    '_role' => trim((string)($payload['role'] ?? '')),
                    '_username' => trim((string)($payload['userId'] ?? '')),
                ];
            }

            if ($refreshToken !== '') {
                return [
                    '_role' => trim((string)(($this->refreshRole)($refreshToken) ?? '')),
                    '_username' => trim((string)(($this->refreshUsername)($refreshToken) ?? '')),
                ];
            }
        } catch (Throwable) {
            // Fail closed; the Core security plugin remains the outer boundary.
        }

        return ['_role' => '', '_username' => ''];
    }
}
