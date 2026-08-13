<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$viewsRoot  = $moduleRoot . '/App/Views';

if (!is_dir($viewsRoot)) {
    fwrite(STDERR, "Volt views directory not found: {$viewsRoot}\n");
    exit(1);
}

$templates = [];
$iterator  = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($viewsRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'volt') {
        $templates[] = $fileInfo->getPathname();
    }
}

sort($templates);

if ($templates === []) {
    fwrite(STDERR, "No Volt templates found under {$viewsRoot}\n");
    exit(1);
}

$errors              = [];
$compilerClass       = '\\Phalcon\\Mvc\\View\\Engine\\Volt\\Compiler';
$compilerIsAvailable = class_exists($compilerClass);
$compiler            = $compilerIsAvailable ? new $compilerClass() : null;

foreach ($templates as $template) {
    $source = file_get_contents($template);
    if ($source === false) {
        $errors[] = "Unable to read {$template}";
        continue;
    }

    if (preg_match_all('/\{%.*?%\}/s', $source, $matches, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($matches[0] as [$statement, $offset]) {
            if (strpos($statement, '&&') === false && strpos($statement, '||') === false) {
                continue;
            }

            $line       = substr_count(substr($source, 0, $offset), "\n") + 1;
            $relative   = substr($template, strlen($moduleRoot) + 1);
            $errors[]   = "{$relative}:{$line}: JavaScript logical operator in Volt statement; use 'and'/'or'";
        }
    }

    if ($compiler !== null) {
        try {
            $compiler->compileString($source);
        } catch (Throwable $exception) {
            $relative = substr($template, strlen($moduleRoot) + 1);
            $errors[] = "{$relative}: Volt compiler error: {$exception->getMessage()}";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

$mode = $compilerIsAvailable ? 'static guard + Phalcon compiler' : 'static guard; Phalcon compiler unavailable';
echo "Volt template syntax contract OK ({$mode})\n";
