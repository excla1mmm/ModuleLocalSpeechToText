<?php

declare(strict_types=1);

function expectVocabularyUi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$view = file_get_contents(__DIR__ . '/../App/Views/ModuleLocalSpeechToText/index.volt');
expectVocabularyUi($view !== false, 'Cannot read settings view');
expectVocabularyUi(
    !str_contains($view, "<label>{{ t._('module_local_speech_to_text_CustomVocabularyTerms') }}</label>"),
    'Redundant custom terms label must not be rendered'
);
expectVocabularyUi(str_contains($view, "form.render('custom_vocabulary_terms')"), 'Vocabulary field must remain available');
expectVocabularyUi(
    str_contains($view, "module_local_speech_to_text_CustomVocabularyTermsHint"),
    'Vocabulary guidance must remain available'
);
expectVocabularyUi(
    str_contains($view, 'stt-vocabulary-terms-file') && str_contains($view, 'stt-vocabulary-template-download'),
    'Vocabulary upload and template controls must remain available'
);

$document = new DOMDocument();
$previousLibxmlState = libxml_use_internal_errors(true);
$document->loadHTML($view, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
libxml_use_internal_errors($previousLibxmlState);

$xpath = new DOMXPath($document);
$fileInput = $xpath->query('//*[@id="stt-vocabulary-terms-file"]')->item(0);
$uploadLabel = $xpath->query('//label[@for="stt-vocabulary-terms-file"]')->item(0);

expectVocabularyUi($fileInput instanceof DOMElement, 'Vocabulary file input must be rendered');
expectVocabularyUi(
    $fileInput->hasAttribute('hidden'),
    'Vocabulary file input must stay out of layout and hit testing'
);
expectVocabularyUi($uploadLabel instanceof DOMElement, 'Vocabulary upload label must activate the hidden file input');

echo "Vocabulary UI contract OK\n";
