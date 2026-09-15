<?php

declare(strict_types=1);

/**
 * retryErrorsAction/generateWorkerApiKeyAction/deleteWorkerApiKeyAction must
 * not surface $exception->getMessage() directly in a flash message; they
 * must show a translated generic message instead.
 */

function expectNoRawExceptionMessage(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$controllerPath = __DIR__ . '/../App/Controllers/ModuleLocalSpeechToTextController.php';
$controller = (string)file_get_contents($controllerPath);

foreach (['retryErrorsAction', 'generateWorkerApiKeyAction', 'deleteWorkerApiKeyAction'] as $action) {
    $start = strpos($controller, 'public function ' . $action);
    expectNoRawExceptionMessage($start !== false, "Missing {$action}");
    $next = strpos($controller, "\n    public function ", $start + 1);
    $body = substr($controller, $start, $next === false ? null : $next - $start);
    expectNoRawExceptionMessage(
        !str_contains($body, '$exception->getMessage()'),
        "{$action} must not leak \$exception->getMessage() into a flash message"
    );
    expectNoRawExceptionMessage(
        str_contains($body, "translation->_('module_local_speech_to_text_OperationFailedError')"),
        "{$action} must show the translated generic operation-failed message"
    );
}

$key = 'module_local_speech_to_text_OperationFailedError';
$messagesDir = __DIR__ . '/../Messages';
$locales = glob($messagesDir . '/*.php') ?: [];
expectNoRawExceptionMessage(count($locales) >= 29, 'Expected at least 29 locale files');
foreach ($locales as $localeFile) {
    $messages = include $localeFile;
    expectNoRawExceptionMessage(is_array($messages), "{$localeFile} must return an array");
    expectNoRawExceptionMessage(
        isset($messages[$key]) && trim((string)$messages[$key]) !== '',
        "{$localeFile} is missing the {$key} translation"
    );
}

echo "No raw exception message in flash contract OK\n";
