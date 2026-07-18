<?php

declare(strict_types=1);

function failModelAnnotations(string $message): never
{
    fwrite(STDERR, "Assertion failed: {$message}\n");
    exit(1);
}

function columnAnnotation(string $source, string $model, string $field): string
{
    $pattern = sprintf(
        '~\/\*\*(?:(?!\*\/).)*@Column\(([^)]*)\)(?:(?!\*\/).)*\*\/\s*public\s+[^;$]+\$%s\b~s',
        preg_quote($field, '~')
    );
    if (preg_match($pattern, $source, $matches) !== 1) {
        failModelAnnotations("{$model}.{$field} must have a @Column annotation");
    }

    return preg_replace('/\s+/', '', $matches[1]) ?? '';
}

function expectAnnotationArgument(string $annotation, string $model, string $field, string $argument): void
{
    if (!str_contains($annotation, $argument)) {
        failModelAnnotations("{$model}.{$field} annotation must contain {$argument}; got {$annotation}");
    }
}

$root = dirname(__DIR__);
$textFields = [
    'STTCallTranscript.php' => [
        'call_id',
        'current_content_hash',
        'current_parts_hash',
        'pending_content_hash',
        'pending_parts_hash',
        'pending_payload',
        'completeness_status',
    ],
    'STTCallTranscriptPart.php' => [
        'cdr_uniqueid',
        'recording_fingerprint',
        'content_hash',
        'source_label',
        'resolution_status',
        'segments_json',
    ],
    'STTCallTranscriptRevision.php' => [
        'content_hash',
        'parts_hash',
        'completeness_status',
        'payload',
    ],
    'STTCallTranscriptEvent.php' => [
        'event_type',
        'event_key',
        'call_id',
        'content_hash',
        'parts_hash',
        'completeness_status',
    ],
];

$sources = [];
foreach (array_keys($textFields) as $model) {
    $source = file_get_contents($root . '/Models/' . $model);
    if ($source === false || !str_contains($source, '@Primary') || !str_contains($source, '@Identity')) {
        failModelAnnotations("missing primary/identity annotations in {$model}");
    }
    $sources[$model] = $source;

    foreach ($textFields[$model] as $field) {
        $annotation = columnAnnotation($source, $model, $field);
        expectAnnotationArgument($annotation, $model, $field, 'type="text"');
    }
}

$aggregate = $sources['STTCallTranscript.php'];
foreach ([
    'current_content_hash',
    'current_parts_hash',
    'pending_content_hash',
    'pending_parts_hash',
    'pending_payload',
] as $field) {
    $annotation = columnAnnotation($aggregate, 'STTCallTranscript.php', $field);
    expectAnnotationArgument($annotation, 'STTCallTranscript.php', $field, 'nullable=false');
    expectAnnotationArgument($annotation, 'STTCallTranscript.php', $field, 'allow_empty_string=true');
    expectAnnotationArgument($annotation, 'STTCallTranscript.php', $field, 'default=""');
}

foreach ([
    'current_revision' => 'default=0',
    'completeness_status' => 'default="pending"',
    'stable_after' => 'default=0',
    'published_at' => 'default=0',
] as $field => $default) {
    expectAnnotationArgument(
        columnAnnotation($aggregate, 'STTCallTranscript.php', $field),
        'STTCallTranscript.php',
        $field,
        $default
    );
}

expectAnnotationArgument(
    columnAnnotation($sources['STTCallTranscriptPart.php'], 'STTCallTranscriptPart.php', 'is_current'),
    'STTCallTranscriptPart.php',
    'is_current',
    'default=1'
);
foreach ([
    'resolution_status' => 'default="complete"',
    'segments_json' => 'default="[]"',
] as $field => $default) {
    expectAnnotationArgument(
        columnAnnotation($sources['STTCallTranscriptPart.php'], 'STTCallTranscriptPart.php', $field),
        'STTCallTranscriptPart.php',
        $field,
        $default
    );
}

$schemaServicePath = $root . '/Lib/Services/CallTranscriptSchemaService.php';
if (!is_file($schemaServicePath)) {
    failModelAnnotations('CallTranscriptSchemaService must own call-transcript DDL and indexes');
}
$schemaService = file_get_contents($schemaServicePath) ?: '';
$setup = file_get_contents($root . '/Setup/PbxExtensionSetup.php') ?: '';
$migrationPosition = strpos($setup, 'new ModuleSchemaMigrator(');
$ensurePosition = strpos($setup, 'CallTranscriptSchemaService())->ensure(');
if ($migrationPosition === false || $ensurePosition === false || $migrationPosition >= $ensurePosition) {
    failModelAnnotations('Versioned module migration must own call-transcript index restoration');
}
if (str_contains($setup, 'parent::installDB()')) {
    failModelAnnotations('Existing databases must not use generic Core annotation migration');
}

foreach ([
    'm_STTCallTranscript_call_id',
    'm_STTCallTranscript_publish',
    'm_STTCallTranscriptPart_version',
    'm_STTCallTranscriptPart_current_result',
    'WHERE is_current = 1',
    'm_STTCallTranscriptRevision_number',
    'm_STTCallTranscriptRevision_hash',
    'm_STTCallTranscriptEvent_key',
    'm_STTCallTranscriptEvent_cursor',
] as $indexSql) {
    if (!str_contains($schemaService, $indexSql)) {
        failModelAnnotations("schema service must restore {$indexSql}");
    }
}

echo "Call transcript model annotations contract OK\n";
