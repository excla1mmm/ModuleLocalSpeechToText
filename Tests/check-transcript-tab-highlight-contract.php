<?php

declare(strict_types=1);

/**
 * Static contract test for item 3(D): the Transcripts dashboard nav item has
 * no data-tab (it is a real ?tab=transcripts navigation link, see
 * IndexViewService/index.volt), so Semantic UI's tab module (2.4.1, vendored
 * under Core) never manages it - its activate.navigation() only
 * (de)activates elements within the collection .tab() was initialized on.
 * Without an explicit cleanup hook, landing on ?tab=transcripts (rendered
 * with the menu item already 'active') and then clicking any other,
 * JS-managed tab leaves the Transcripts item highlighted alongside it.
 *
 * This asserts, statically, that both the JS source and its compiled ES5
 * copy:
 *  - scope the tab module to items that actually have a data-tab;
 *  - register an onVisible cleanup that strips 'active' from menu items
 *    without a data-tab whenever a managed tab becomes visible;
 *  - skip the initial `change tab` call for the 'transcripts' request,
 *    since there is no managed item to switch to for it.
 */

function expectTranscriptTabHighlight(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$javascriptFiles = [
    'source' => __DIR__ . '/../public/assets/js/src/module-local-speech-to-text.js',
    'compiled' => __DIR__ . '/../public/assets/js/module-local-speech-to-text.js',
];

foreach ($javascriptFiles as $kind => $path) {
    $js = file_get_contents($path);
    expectTranscriptTabHighlight($js !== false, "unable to read {$kind} JavaScript");

    expectTranscriptTabHighlight(
        str_contains($js, "\$tabMenu.find('.item[data-tab]')"),
        "{$kind} JavaScript must scope the tab module to items with a data-tab, excluding the Transcripts link"
    );
    expectTranscriptTabHighlight(
        (bool)preg_match(
            '/\$tabItems\.tab\(\{\s*onVisible:\s*function\s*\(\)\s*\{[^}]*\$tabMenu\.find\(\'\.item\'\)\.not\(\'\[data-tab\]\'\)\.removeClass\(\'active\'\);/s',
            $js
        ),
        "{$kind} JavaScript must register an onVisible callback on the tab module that deactivates non-data-tab menu items"
    );
    expectTranscriptTabHighlight(
        (bool)preg_match(
            "/if\s*\(requestedTab\(\)\s*!==\s*''\s*&&\s*requestedTab\(\)\s*!==\s*'transcripts'\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*\\\$tabItems\.tab\('change tab', requestedTab\(\)\);/",
            $js
        ),
        "{$kind} JavaScript must skip the initial 'change tab' call for the unmanaged 'transcripts' request"
    );
}

$indexTemplate = (string)file_get_contents(__DIR__ . '/../App/Views/ModuleLocalSpeechToText/index.volt');
expectTranscriptTabHighlight($indexTemplate !== '', 'unable to read index.volt');

$navLinePattern = "/<a class=\"item\{% if activeTab === 'transcripts' %\} active\{% endif %\}\" href=\"\{\{ url\(transcriptsPath\) \}\}\"/";
expectTranscriptTabHighlight(
    (bool)preg_match($navLinePattern, $indexTemplate),
    'the Transcripts nav item must be a real href link with no data-tab attribute'
);
expectTranscriptTabHighlight(
    !(bool)preg_match('/<a class="item\{% if activeTab === \'transcripts\' %\} active\{% endif %\}" data-tab="transcripts"/', $indexTemplate),
    'the Transcripts nav item must not be re-added to the client-side data-tab switch'
);

echo "Transcript tab menu highlight contract OK\n";
