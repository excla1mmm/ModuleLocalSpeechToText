<?php

declare(strict_types=1);

// .mjs: node treats it as an ES module on every version; a bare .js file with
// `import` needs module-syntax detection, which Debian bookworm's node 18 lacks.
$test = __DIR__ . '/transcript-link.test.mjs';
passthru('node --test ' . escapeshellarg($test), $exitCode);
exit($exitCode);
