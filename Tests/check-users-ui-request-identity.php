<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Lib/Services/UsersUIRequestIdentity.php';

use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUIRequestIdentity;

function expectUsersUIIdentity(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$resolver = new UsersUIRequestIdentity(
    static fn(string $header): ?array => $header === 'Bearer valid'
        ? ['role' => 'UsersUIRoleID7', 'userId' => 'operator']
        : null,
    static fn(string $refresh): ?string => $refresh === 'refresh' ? 'UsersUIRoleID8' : null,
    static fn(string $refresh): ?string => $refresh === 'refresh' ? 'browser-user' : null,
);

expectUsersUIIdentity(
    $resolver->resolve('Bearer valid', 'refresh') === [
        '_role' => 'UsersUIRoleID7',
        '_username' => 'operator',
    ],
    'Bearer identity must take precedence over the refresh-token identity'
);
expectUsersUIIdentity(
    $resolver->resolve('', 'refresh') === [
        '_role' => 'UsersUIRoleID8',
        '_username' => 'browser-user',
    ],
    'Browser page loads must resolve ModuleUsersUI identity from the refresh token'
);
expectUsersUIIdentity(
    $resolver->resolve('Bearer invalid', '') === ['_role' => '', '_username' => ''],
    'Invalid credentials must produce an empty, fail-closed identity'
);

echo "ModuleUsersUI STT request identity checks passed\n";
