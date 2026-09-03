<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUIAccessService;

require_once __DIR__ . '/../Lib/Services/UsersUICallScope.php';
require_once __DIR__ . '/../Lib/Services/UsersUIAccessService.php';

function expectUsersUiAccess(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = new UsersUIAccessService(
    moduleAvailable: static fn(): bool => true,
    accessGroupId: static fn(array $request): ?string => ($request['_role'] ?? '') === 'UsersUIRoleID7' ? '7' : null,
    accessGroup: static fn(string $groupId): ?array => $groupId === '7'
        ? ['cdr_filter_mode' => 'selected', 'full_access' => false]
        : null,
    filteredUserIds: static fn(string $groupId): array => $groupId === '7' ? [11, 12] : [],
    employeeNumbers: static fn(array $userIds): array => $userIds === [11, 12]
        ? ['204', '+7 (812) 555-01-02']
        : [],
    groupActions: static fn(string $groupId): array|string|null => $groupId === '7'
        ? ['index', 'system']
        : null,
);

$request = ['_role' => 'UsersUIRoleID7'];
expectUsersUiAccess($service->canViewTranscripts($request), 'index must grant transcript access');
expectUsersUiAccess(!$service->canManageSettings($request), 'missing settings action must deny settings');
expectUsersUiAccess($service->canManageSystem($request), 'system action must grant operational access');

$scope = $service->callScopeFor($request);
expectUsersUiAccess($scope->allowsCall('204', '78125550000'), 'selected extension must be visible');
expectUsersUiAccess($scope->allowsCall('100', '7 812 555-01-02'), 'selected mobile formatting variants must be visible');
expectUsersUiAccess(!$scope->allowsCall('100', '200'), 'unselected call must stay hidden');

$fullAccess = new UsersUIAccessService(
    moduleAvailable: static fn(): bool => true,
    accessGroupId: static fn(array $request): ?string => '9',
    accessGroup: static fn(string $groupId): ?array => ['cdr_filter_mode' => 'selected', 'full_access' => true],
    filteredUserIds: static fn(string $groupId): array => [],
    employeeNumbers: static fn(array $userIds): array => [],
    groupActions: static fn(string $groupId): array|string|null => null,
);
expectUsersUiAccess($fullAccess->canManageSettings([]), 'full access group must receive settings');
expectUsersUiAccess($fullAccess->canManageSystem([]), 'full access group must receive operations');
expectUsersUiAccess($fullAccess->callScopeFor([])->allowsCall('100', '200'), 'full access group must see every call');

$missingModule = new UsersUIAccessService(moduleAvailable: static fn(): bool => false);
expectUsersUiAccess($missingModule->callScopeFor([])->preservesLegacyAuthorization(), 'missing ModuleUsersUI must preserve legacy authorization');
expectUsersUiAccess(!$missingModule->canViewTranscripts([]), 'missing ModuleUsersUI must not grant delegated transcript access');
expectUsersUiAccess(!$missingModule->canManageSettings([]), 'missing ModuleUsersUI must not grant delegated settings access');
expectUsersUiAccess(!$missingModule->canManageSystem([]), 'missing ModuleUsersUI must not grant delegated system access');

$brokenGroup = new UsersUIAccessService(
    moduleAvailable: static fn(): bool => true,
    accessGroupId: static fn(array $request): ?string => null,
);
expectUsersUiAccess(!$brokenGroup->callScopeFor([])->allowsCall('100', '200'), 'unresolved access group must fail closed');

echo "ModuleUsersUI STT access service checks passed\n";
