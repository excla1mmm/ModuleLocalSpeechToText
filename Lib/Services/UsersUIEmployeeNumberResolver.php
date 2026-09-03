<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\ExternalPhones;

final class UsersUIEmployeeNumberResolver
{
    /** @var Closure(array<int, int>): array */
    private readonly Closure $extensions;
    /** @var Closure(array<int, string>): array */
    private readonly Closure $externalPhones;

    public function __construct(?callable $extensions = null, ?callable $externalPhones = null)
    {
        $this->extensions = Closure::fromCallable($extensions ?? self::defaultExtensions(...));
        $this->externalPhones = Closure::fromCallable($externalPhones ?? self::defaultExternalPhones(...));
    }

    /** @param array<int, int> $userIds @return array<int, string> */
    public function resolve(array $userIds): array
    {
        $userIds = self::uniquePositiveInts($userIds);
        if ($userIds === []) {
            return [];
        }

        $extensionNumbers = [];
        foreach (($this->extensions)($userIds) as $extension) {
            $number = is_array($extension) ? trim((string)($extension['number'] ?? '')) : '';
            if ($number !== '') {
                $extensionNumbers[$number] = $number;
            }
        }
        if ($extensionNumbers === []) {
            return [];
        }

        $numbers = array_values($extensionNumbers);
        foreach (($this->externalPhones)(array_values($extensionNumbers)) as $externalPhone) {
            if (is_scalar($externalPhone) && trim((string)$externalPhone) !== '') {
                $numbers[] = trim((string)$externalPhone);
            }
        }

        $resolved = [];
        foreach ($numbers as $number) {
            $resolved[$number] = $number;
            $digits = preg_replace('/\D+/', '', $number) ?? '';
            if ($digits !== '') {
                $resolved[$digits] = $digits;
            }
        }

        return array_values($resolved);
    }

    /** @param array<int, int> $userIds @return array<int, array{userid: int, number: string}> */
    private static function defaultExtensions(array $userIds): array
    {
        [$condition, $bind] = self::inCondition('userid', $userIds, 'users_ui_user');
        $rows = [];
        foreach (Extensions::find([
            'conditions' => $condition . ' AND userid IS NOT NULL AND userid > 0',
            'bind' => $bind,
            'order' => 'is_general_user_number DESC, id ASC',
        ]) as $extension) {
            if ($extension instanceof Extensions) {
                $rows[] = ['userid' => (int)$extension->userid, 'number' => (string)$extension->number];
            }
        }

        return $rows;
    }

    /** @param array<int, string> $extensionNumbers @return array<int, string> */
    private static function defaultExternalPhones(array $extensionNumbers): array
    {
        [$condition, $bind] = self::inCondition('extension', $extensionNumbers, 'users_ui_extension');
        $numbers = [];
        foreach (ExternalPhones::find([
            'conditions' => $condition . ' AND disabled = :disabled:',
            'bind' => $bind + ['disabled' => '0'],
            'order' => 'id ASC',
        ]) as $externalPhone) {
            if ($externalPhone instanceof ExternalPhones && trim((string)$externalPhone->dialstring) !== '') {
                $numbers[] = (string)$externalPhone->dialstring;
            }
        }

        return $numbers;
    }

    /** @param array<int, int|string> $values @return array{0: string, 1: array<string, int|string>} */
    private static function inCondition(string $field, array $values, string $prefix): array
    {
        $placeholders = [];
        $bind = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key . ':';
            $bind[$key] = $value;
        }

        return [$field . ' IN (' . implode(', ', $placeholders) . ')', $bind];
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
