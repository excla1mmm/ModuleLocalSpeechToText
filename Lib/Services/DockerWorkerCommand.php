<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/** Shell commands that start the Linux Docker worker against this PBX. */
final class DockerWorkerCommand
{
    public const string IMAGE = 'nexus.miko.ru:6789/mikopbx-stt-worker:0.1.13-cpu';

    // The data volume keeps the worker UID the PBX binds the key to, so a re-created container stays the same worker.
    public static function build(string $pbxUrl, string $workerToken): string
    {
        return implode("\n", [
            'docker pull ' . self::IMAGE,
            '',
            'docker run -d --name mikopbx-stt-worker \\',
            '  --restart unless-stopped \\',
            '  -e PBX_URL=' . $pbxUrl . ' \\',
            '  -e WORKER_TOKEN=' . $workerToken . ' \\',
            '  -v mikopbx-stt-models:/models \\',
            '  -v mikopbx-stt-data:/data \\',
            '  ' . self::IMAGE,
        ]);
    }
}
