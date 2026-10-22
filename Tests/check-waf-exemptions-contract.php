<?php

declare(strict_types=1);

// The worker result endpoints must be exempt from the MikoPBX WAF body scan:
// transcripts of real calls can look like SQL to it, and a rejected result
// leaves the call without a transcript.

$source = file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextConf.php');
if ($source === false) {
    fwrite(STDERR, "Cannot read LocalSpeechToTextConf.php\n");
    exit(1);
}

$required = [
    "public function getWafExemptions(): array",
    "'/pbxcore/api/v3/module-local-speech-to-text/job-results' => ['scopes' => ['body-scan'], 'prefix' => true]",
    "'/pbxcore/api/v3/module-local-speech-to-text/job-failures' => ['scopes' => ['body-scan'], 'prefix' => true]",
    "'/pbxcore/api/v3/module-local-speech-to-text/summary-results' => ['scopes' => ['body-scan'], 'prefix' => true]",
    "'/pbxcore/api/v3/module-local-speech-to-text/summary-failures' => ['scopes' => ['body-scan'], 'prefix' => true]",
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "WAF exemption contract broken: missing {$needle}\n");
        exit(1);
    }
}

if (preg_match("~'/pbxcore/api/v3/module-local-speech-to-text/(?!job-results'|job-failures'|summary-results'|summary-failures')[^']*' => \\['scopes'~", $source)) {
    fwrite(STDERR, "Only the worker result endpoints may be exempt from the WAF\n");
    exit(1);
}

echo "WAF exemptions contract OK\n";
