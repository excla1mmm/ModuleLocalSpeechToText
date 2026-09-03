<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use MikoPBX\Modules\PbxExtensionUtils;
use Throwable;

final class UsersUIAccessService
{
    private const string ROLE_PREFIX = 'UsersUIRoleID';
    private const string CONTROLLER =
        'Modules\\ModuleLocalSpeechToText\\App\\Controllers\\ModuleLocalSpeechToTextController';
    private const string CREDENTIAL_MODEL = 'Modules\\ModuleUsersUI\\Models\\UsersCredentials';
    private const string ACCESS_GROUP_MODEL = 'Modules\\ModuleUsersUI\\Models\\AccessGroups';
    private const string CDR_FILTER_MODEL = 'Modules\\ModuleUsersUI\\Models\\AccessGroupCDRFilter';
    private const string GROUP_RIGHTS_MODEL = 'Modules\\ModuleUsersUI\\Models\\AccessGroupsRights';

    /** @var Closure(): bool */
    private readonly Closure $moduleAvailable;
    /** @var Closure(array<string, mixed>): ?string */
    private readonly Closure $accessGroupId;
    /** @var Closure(string): ?array */
    private readonly Closure $accessGroup;
    /** @var Closure(string): array */
    private readonly Closure $filteredUserIds;
    /** @var Closure(array<int, int>): array */
    private readonly Closure $employeeNumbers;
    /** @var Closure(string): array|string|null */
    private readonly Closure $groupActions;

    public function __construct(
        ?callable $moduleAvailable = null,
        ?callable $accessGroupId = null,
        ?callable $accessGroup = null,
        ?callable $filteredUserIds = null,
        ?callable $employeeNumbers = null,
        ?callable $groupActions = null,
    ) {
        $this->moduleAvailable = Closure::fromCallable($moduleAvailable ?? self::defaultModuleAvailable(...));
        $this->accessGroupId = Closure::fromCallable($accessGroupId ?? self::defaultAccessGroupId(...));
        $this->accessGroup = Closure::fromCallable($accessGroup ?? self::defaultAccessGroup(...));
        $this->filteredUserIds = Closure::fromCallable($filteredUserIds ?? self::defaultFilteredUserIds(...));
        $this->employeeNumbers = Closure::fromCallable($employeeNumbers ?? self::defaultEmployeeNumbers(...));
        $this->groupActions = Closure::fromCallable($groupActions ?? self::defaultGroupActions(...));
    }

    public function isAvailable(): bool
    {
        try {
            return (($this->moduleAvailable)());
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $requestData */
    public function callScopeFor(array $requestData): UsersUICallScope
    {
        if (!$this->isAvailable()) {
            return UsersUICallScope::legacy();
        }

        try {
            $groupId = ($this->accessGroupId)($requestData);
            if (!is_string($groupId) || $groupId === '') {
                return UsersUICallScope::denied();
            }
            $group = ($this->accessGroup)($groupId);
            if (!is_array($group)) {
                return UsersUICallScope::denied();
            }
            if (($group['full_access'] ?? false) === true || (string)($group['full_access'] ?? '') === '1') {
                return UsersUICallScope::all();
            }
            $mode = (string)($group['cdr_filter_mode'] ?? 'all');
            if ($mode === 'all') {
                return UsersUICallScope::all();
            }
            $userIds = ($this->filteredUserIds)($groupId);
            $numbers = ($this->employeeNumbers)(self::uniquePositiveInts($userIds));

            return match ($mode) {
                'selected' => UsersUICallScope::selected($numbers),
                'outgoing-selected' => UsersUICallScope::outgoingSelected($numbers),
                'not-selected' => UsersUICallScope::exceptSelected($numbers),
                default => UsersUICallScope::denied(),
            };
        } catch (Throwable) {
            return UsersUICallScope::denied();
        }
    }

    /** @param array<string, mixed> $requestData */
    public function canViewTranscripts(array $requestData): bool
    {
        return $this->hasAction($requestData, 'index');
    }

    /** @param array<string, mixed> $requestData */
    public function canManageSettings(array $requestData): bool
    {
        return $this->hasAction($requestData, 'settings');
    }

    /** @param array<string, mixed> $requestData */
    public function canManageSystem(array $requestData): bool
    {
        return $this->hasAction($requestData, 'system');
    }

    /** @param array<string, mixed> $requestData */
    private function hasAction(array $requestData, string $action): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $groupId = ($this->accessGroupId)($requestData);
            if (!is_string($groupId) || $groupId === '') {
                return false;
            }
            $group = ($this->accessGroup)($groupId);
            if (!is_array($group)) {
                return false;
            }
            if (($group['full_access'] ?? false) === true || (string)($group['full_access'] ?? '') === '1') {
                return true;
            }
            $actions = ($this->groupActions)($groupId);
            if ($actions === '*') {
                return true;
            }

            return is_array($actions) && in_array($action, $actions, true);
        } catch (Throwable) {
            return false;
        }
    }

    private static function defaultModuleAvailable(): bool
    {
        return class_exists(self::CREDENTIAL_MODEL)
            && class_exists(self::ACCESS_GROUP_MODEL)
            && class_exists(self::CDR_FILTER_MODEL)
            && class_exists(self::GROUP_RIGHTS_MODEL)
            && PbxExtensionUtils::isEnabled('ModuleUsersUI');
    }

    /** @param array<string, mixed> $requestData */
    private static function defaultAccessGroupId(array $requestData): ?string
    {
        $role = trim((string)($requestData['_role'] ?? ''));
        if (str_starts_with($role, self::ROLE_PREFIX)) {
            $groupId = substr($role, strlen(self::ROLE_PREFIX));

            return $groupId === '' ? null : $groupId;
        }

        $login = trim((string)($requestData['_username'] ?? ''));
        if ($login === '') {
            return null;
        }
        $model = self::CREDENTIAL_MODEL;
        $credential = $model::findFirst([
            'conditions' => 'user_login = :login:',
            'bind' => ['login' => $login],
        ]);
        $groupId = is_object($credential) ? trim((string)($credential->user_access_group_id ?? '')) : '';

        return $groupId === '' || $groupId === 'No access' ? null : $groupId;
    }

    /** @return array{cdr_filter_mode: string, full_access: bool}|null */
    private static function defaultAccessGroup(string $groupId): ?array
    {
        $model = self::ACCESS_GROUP_MODEL;
        $group = $model::findFirstById($groupId);
        if (!is_object($group)) {
            return null;
        }

        return [
            'cdr_filter_mode' => (string)($group->cdrFilterMode ?? 'all'),
            'full_access' => (string)($group->fullAccess ?? '0') === '1',
        ];
    }

    /** @return array<int, int> */
    private static function defaultFilteredUserIds(string $groupId): array
    {
        $model = self::CDR_FILTER_MODEL;
        $userIds = [];
        foreach ($model::find([
            'conditions' => 'group_id = :group_id:',
            'bind' => ['group_id' => $groupId],
        ]) as $filter) {
            $userId = is_object($filter) ? (int)($filter->user_id ?? 0) : 0;
            if ($userId > 0) {
                $userIds[] = $userId;
            }
        }

        return self::uniquePositiveInts($userIds);
    }

    /** @param array<int, int> $userIds @return array<int, string> */
    private static function defaultEmployeeNumbers(array $userIds): array
    {
        return (new UsersUIEmployeeNumberResolver())->resolve($userIds);
    }

    /** @return array<int, string>|string|null */
    private static function defaultGroupActions(string $groupId): array|string|null
    {
        $model = self::GROUP_RIGHTS_MODEL;
        $right = $model::findFirst([
            'conditions' => 'group_id = :group_id: AND controller = :controller:',
            'bind' => ['group_id' => $groupId, 'controller' => self::CONTROLLER],
        ]);
        if (!is_object($right)) {
            return null;
        }
        $actions = json_decode((string)($right->actions ?? ''), true);

        return $actions === '*' || is_array($actions) ? $actions : null;
    }

    /** @param array<int, mixed> $values @return array<int, int> */
    private static function uniquePositiveInts(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $value = (int)$value;
            if ($value > 0) {
                $unique[$value] = $value;
            }
        }

        return array_values($unique);
    }
}
