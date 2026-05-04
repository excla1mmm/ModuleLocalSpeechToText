<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use RuntimeException;

/**
 * @Indexes(
 *     [name='key', columns=['key'], type='UNIQUE']
 * )
 */
class STTSetting extends ModulesModelsBase
{
    public const string DEFAULT_LANGUAGE = 'default_language';
    public const string POLL_INTERVAL_SEC = 'poll_interval_sec';
    public const string MAX_FILE_SIZE_MB = 'max_file_size_mb';
    public const string CDR_SCAN_LIMIT = 'cdr_scan_limit';

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $key = '';

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $value = '';

    public function initialize(): void
    {
        $this->setSource('m_STTSetting');
        parent::initialize();
    }

    public static function getValue(string $key, string $default = ''): string
    {
        $setting = self::findFirst([
            'conditions' => 'key = :key:',
            'bind' => [
                'key' => $key,
            ],
        ]);

        return $setting === null ? $default : (string)$setting->value;
    }

    public static function setValue(string $key, string $value): void
    {
        $setting = self::findFirst([
            'conditions' => 'key = :key:',
            'bind' => [
                'key' => $key,
            ],
        ]);
        if ($setting === null) {
            $setting = new self();
            $setting->key = $key;
        }

        $setting->value = $value;

        if (!$setting->save()) {
            throw new RuntimeException(implode(', ', $setting->getMessages()));
        }
    }
}
