<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use InvalidArgumentException;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use PDO;
use RuntimeException;

final class WorkerProcessingSettingsService
{
    public const int SCHEMA_VERSION = 1;

    private const array DEFAULTS = [
        STTSetting::TEMPERATURE => 0.0,
        STTSetting::TEMPERATURE_FALLBACK_STEP => 0.2,
        STTSetting::TEMPERATURE_FALLBACK_COUNT => 5,
        STTSetting::TOP_K => 5,
        STTSetting::COMPRESSION_RATIO_THRESHOLD => 2.4,
        STTSetting::LOG_PROBABILITY_THRESHOLD => -1.0,
        STTSetting::FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD => -1.5,
        STTSetting::NO_SPEECH_THRESHOLD => 0.6,
        STTSetting::AUDIO_NORMALIZATION => true,
        STTSetting::VOICE_ACTIVITY_DETECTION => true,
        STTSetting::MAX_SEGMENT_DURATION_MS => 15000,
        STTSetting::SEGMENT_OVERLAP_MS => 300,
    ];

    private const array FLOAT_RANGES = [
        STTSetting::TEMPERATURE => [0.0, 1.0],
        STTSetting::TEMPERATURE_FALLBACK_STEP => [0.0, 1.0],
        STTSetting::COMPRESSION_RATIO_THRESHOLD => [0.0, 10.0],
        STTSetting::LOG_PROBABILITY_THRESHOLD => [-10.0, 0.0],
        STTSetting::FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD => [-10.0, 0.0],
        STTSetting::NO_SPEECH_THRESHOLD => [0.0, 1.0],
    ];

    private const array INTEGER_RANGES = [
        STTSetting::TEMPERATURE_FALLBACK_COUNT => [0, 10],
        STTSetting::TOP_K => [1, 100],
        STTSetting::MAX_SEGMENT_DURATION_MS => [1000, 120000],
        STTSetting::SEGMENT_OVERLAP_MS => [0, 10000],
    ];

    private const array BOOLEAN_FIELDS = [
        STTSetting::AUDIO_NORMALIZATION,
        STTSetting::VOICE_ACTIVITY_DETECTION,
    ];

    public function __construct(private readonly mixed $connection = null)
    {
    }

    /**
     * @return list<string>
     */
    public static function fieldNames(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * @return array<string, float|int|bool>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, float|int|bool>
     */
    public static function normalize(array $input): array
    {
        $unknown = array_diff(array_keys($input), self::fieldNames());
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown processing setting: ' . (string)reset($unknown));
        }

        $normalized = [];
        foreach (self::DEFAULTS as $field => $_default) {
            if (!array_key_exists($field, $input)) {
                throw new InvalidArgumentException('Missing processing setting: ' . $field);
            }

            $value = $input[$field];
            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $normalized[$field] = self::normalizeBoolean($field, $value);
                continue;
            }

            if (isset(self::INTEGER_RANGES[$field])) {
                $normalized[$field] = self::normalizeInteger($field, $value);
                continue;
            }

            $normalized[$field] = self::normalizeFloat($field, $value);
        }

        if ($normalized[STTSetting::SEGMENT_OVERLAP_MS] >= $normalized[STTSetting::MAX_SEGMENT_DURATION_MS]) {
            throw new InvalidArgumentException('segment_overlap_ms must be less than max_segment_duration_ms');
        }

        return $normalized;
    }

    /**
     * @return array<string, float|int|bool>
     */
    public static function current(): array
    {
        $service = new self();

        return $service->readCurrent($service->database());
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{
     *     schema_version: int,
     *     processing_settings: array<string, float|int|bool>,
     *     selected_model: array<string, string>
     * }
     */
    public static function apiPayload(array $profile): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'processing_settings' => self::normalize($profile),
            'selected_model' => self::selectedModelPayload(),
        ];
    }

    public static function markAllWorkersChanged(mixed $connection = null): void
    {
        $database = $connection ?? (new STTSetting())->getWriteConnection();
        if (!$database->execute('UPDATE m_STTWorker SET has_settings_changed = 1')) {
            throw new RuntimeException('Unable to notify workers about changed settings');
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): bool
    {
        $next = self::normalize($input);

        return $this->withTransaction(function (mixed $database) use ($next): bool {
            $current = $this->readCurrent($database);
            if ($current === $next) {
                return false;
            }

            foreach ($next as $key => $value) {
                $database->execute(
                    'INSERT INTO m_STTSetting (key, value) VALUES (:key, :value)
                     ON CONFLICT(key) DO UPDATE SET value = excluded.value',
                    ['key' => $key, 'value' => self::storageValue($value)]
                );
            }
            $database->execute('UPDATE m_STTWorker SET has_settings_changed = 1');

            return true;
        });
    }

    /**
     * @return array{
     *     schema_version: int,
     *     processing_settings: array<string, float|int|bool>,
     *     selected_model: array<string, string>
     * }
     */
    public function fetchForWorker(STTWorker $worker): array
    {
        return $this->withTransaction(function (mixed $database) use ($worker): array {
            $database->execute(
                'UPDATE m_STTWorker SET has_settings_changed = 0 WHERE id = :id',
                ['id' => (int)$worker->id]
            );

            return self::apiPayload($this->readCurrent($database));
        });
    }

    /**
     * @return array<string, string>
     */
    private static function selectedModelPayload(): array
    {
        $preset = STTSetting::selectedModelPreset();
        $modelId = (string)$preset['model'];
        $repository = (string)$preset['repo'];

        return [
            'model_id' => $modelId,
            'model_repo' => $repository,
            'model_engine' => (string)$preset['engine'],
            'model_artifact_type' => (string)$preset['artifact_type'],
            'model_display_name' => (string)($preset['display_name'] ?? $repository . '/' . $modelId),
        ];
    }

    private static function normalizeBoolean(string $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        throw new InvalidArgumentException($field . ' must be a boolean');
    }

    private static function normalizeInteger(string $field, mixed $value): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $normalized = (int)$value;
        } else {
            throw new InvalidArgumentException($field . ' must be an integer');
        }

        [$minimum, $maximum] = self::INTEGER_RANGES[$field];
        if ($normalized < $minimum || $normalized > $maximum) {
            throw new InvalidArgumentException($field . ' is out of range');
        }

        return $normalized;
    }

    private static function normalizeFloat(string $field, mixed $value): float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be a finite number');
        }

        $normalized = (float)$value;
        if (!is_finite($normalized)) {
            throw new InvalidArgumentException($field . ' must be a finite number');
        }

        [$minimum, $maximum] = self::FLOAT_RANGES[$field];
        if ($normalized < $minimum || $normalized > $maximum) {
            throw new InvalidArgumentException($field . ' is out of range');
        }

        return $normalized;
    }

    private function database(): mixed
    {
        return $this->connection ?? (new STTSetting())->getWriteConnection();
    }

    /**
     * @return array<string, float|int|bool>
     */
    private function readCurrent(mixed $database): array
    {
        $stored = [];
        foreach (self::DEFAULTS as $key => $default) {
            $stored[$key] = self::storageValue($default);
        }

        $rows = $database->fetchAll('SELECT key, value FROM m_STTSetting', PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $key = (string)($row['key'] ?? '');
            if (array_key_exists($key, self::DEFAULTS)) {
                $stored[$key] = (string)($row['value'] ?? '');
            }
        }

        return self::normalize($stored);
    }

    private function withTransaction(callable $operation): mixed
    {
        $database = $this->database();

        return (new ModuleTransaction())->run($database, $operation);
    }

    private static function storageValue(float|int|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION) ?: (string)$value;
    }
}
