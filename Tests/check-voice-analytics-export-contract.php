<?php

declare(strict_types=1);

function expectTrueVoiceAnalyticsExport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = file_get_contents(__DIR__ . '/../Lib/Services/TranscriptIntegrationService.php');
$schema = file_get_contents(__DIR__ . '/../Lib/RestAPI/Transcripts/DataStructure.php');
$legacyKey = 'voice_analytics' . '_v1';

expectTrueVoiceAnalyticsExport($service !== false, 'Cannot read TranscriptIntegrationService.php');
expectTrueVoiceAnalyticsExport($schema !== false, 'Cannot read Transcripts/DataStructure.php');

expectTrueVoiceAnalyticsExport(
    str_contains($service, "'technical_info' => \$this->technicalInfo(\$result)"),
    'Transcript detail must expose decoded technical_info'
);
expectTrueVoiceAnalyticsExport(
    str_contains($service, 'json_decode((string)$result->technical_info, true)')
        && str_contains($service, 'return new \stdClass();'),
    'Malformed technical_info must resolve to an empty object payload'
);
expectTrueVoiceAnalyticsExport(
    str_contains($schema, "'voice_analytics'")
        && !str_contains($schema, $legacyKey),
    'Transcript OpenAPI schema must document technical_info.voice_analytics without v1 suffix'
);

echo "Voice analytics export contract OK\n";
