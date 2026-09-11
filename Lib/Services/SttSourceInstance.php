<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use PDO;
use RuntimeException;

final class SttSourceInstance
{
    public const string LEGACY_SENTINEL = 'legacy';

    public function ensure(mixed $connection): string
    {
        $current = $this->read($connection);
        if (self::isValid($current)) {
            return $current;
        }

        $candidate = bin2hex(random_bytes(16));
        if ($connection->execute(
            'INSERT INTO m_STTSetting(key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            ['key' => STTSetting::SOURCE_INSTANCE_ID, 'value' => $candidate]
        ) !== true) {
            throw new RuntimeException('Unable to persist STT source instance identity');
        }

        $stored = $this->read($connection);
        if (!self::isValid($stored)) {
            throw new RuntimeException('Persisted STT source instance identity is invalid');
        }

        return $stored;
    }

    public function current(): string
    {
        $connection = (new STTSetting())->getReadConnection();
        $current = $this->read($connection);
        if (!self::isValid($current)) {
            throw new RuntimeException('STT source instance identity is unavailable');
        }

        return $current;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/', $value) === 1;
    }

    private function read(mixed $connection): string
    {
        $row = $connection->fetchOne(
            'SELECT value FROM m_STTSetting WHERE key = :key LIMIT 1',
            PDO::FETCH_ASSOC,
            ['key' => STTSetting::SOURCE_INSTANCE_ID]
        );

        return is_array($row) && is_scalar($row['value'] ?? null)
            ? trim((string)$row['value'])
            : '';
    }
}
