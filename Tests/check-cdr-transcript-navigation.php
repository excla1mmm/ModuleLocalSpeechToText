<?php

declare(strict_types=1);

$test = __DIR__ . '/cdr-transcript-navigation.test.js';
passthru('node --test ' . escapeshellarg($test), $exitCode);
exit($exitCode);
