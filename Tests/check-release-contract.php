<?php

declare(strict_types=1);

function expectReleaseContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/module.json'), true, flags: JSON_THROW_ON_ERROR);
$releaseSettings = $manifest['release_settings'] ?? [];
foreach (['publish_release', 'changelog_enabled', 'create_github_release'] as $setting) {
    expectReleaseContract(($releaseSettings[$setting] ?? null) === true, "module.json must enable release_settings.{$setting}");
}

$workflowPath = $root . '/.github/workflows/build.yml';
expectReleaseContract(is_file($workflowPath), '.github/workflows/build.yml is required');
$workflow = (string)file_get_contents($workflowPath);
expectReleaseContract(str_contains($workflow, '- master') && str_contains($workflow, '- develop'), 'Release workflow must run for master and develop');
expectReleaseContract(str_contains($workflow, 'workflow_dispatch:'), 'Release workflow must support workflow_dispatch');
expectReleaseContract(str_contains($workflow, 'mikopbx/.github-workflows/.github/workflows/extension-publish.yml@master'), 'Release workflow must use the canonical MikoPBX reusable workflow');
expectReleaseContract(str_contains($workflow, 'initial_version: "1.45"'), 'Release workflow initial_version must match the module baseline');
expectReleaseContract(str_contains($workflow, 'secrets: inherit'), 'Release workflow must inherit publication secrets');

$gitlab = (string)file_get_contents($root . '/.gitlab-ci.yml');
expectReleaseContract(str_contains($gitlab, 'php:8.4-cli-bookworm'), 'GitLab verification must use target PHP 8.4');

echo "Release contract OK\n";
