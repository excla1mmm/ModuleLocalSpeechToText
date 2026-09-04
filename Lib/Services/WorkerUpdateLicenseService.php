<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\PbxSettings;

final class WorkerUpdateLicenseService
{
    public function licenseHash(): ?string
    {
        $licenseKey = trim((string)PbxSettings::getValueByKey(PbxSettings::PBX_LICENSE));

        return $licenseKey === '' ? null : hash('sha256', $licenseKey);
    }
}
