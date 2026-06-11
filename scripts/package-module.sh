#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUTPUT_DIR="${1:-$(cd "${MODULE_DIR}/.." && pwd)/builds}"
MODULE_JSON="${MODULE_DIR}/module.json"

if [[ ! -f "${MODULE_JSON}" ]]; then
  echo "module.json not found: ${MODULE_JSON}" >&2
  exit 1
fi

MODULE_META="$(
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($data) || empty($data["moduleUniqueID"]) || empty($data["version"])) {
        fwrite(STDERR, "module.json must contain moduleUniqueID and version\n");
        exit(1);
    }
    echo $data["moduleUniqueID"] . "\n" . $data["version"];
  ' "${MODULE_JSON}"
)"
MODULE_ID="$(printf '%s\n' "${MODULE_META}" | sed -n '1p')"
MODULE_VERSION="$(printf '%s\n' "${MODULE_META}" | sed -n '2p')"
PACKAGE_VERSION="${VERSION:-${MODULE_VERSION}}"

TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/${MODULE_ID}.package.XXXXXX")"
TMP_MODULE_DIR="${TMP_ROOT}/module"
trap 'rm -rf "${TMP_ROOT}"' EXIT

mkdir -p "${OUTPUT_DIR}" "${TMP_MODULE_DIR}"

COPY_ITEMS=(
  App
  bin
  db
  Lib
  Messages
  Models
  public
  Setup
  composer.json
  module.json
  vendor
)

for item in "${COPY_ITEMS[@]}"; do
  if [[ -e "${MODULE_DIR}/${item}" ]]; then
    cp -R "${MODULE_DIR}/${item}" "${TMP_MODULE_DIR}/"
  fi
done

php -r '
  $path = $argv[1];
  $version = $argv[2];
  $data = json_decode(file_get_contents($path), true);
  if (!is_array($data)) {
      fwrite(STDERR, "Unable to parse module.json\n");
      exit(1);
  }
  $data["version"] = $version;
  file_put_contents(
      $path,
      json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
  );
' "${TMP_MODULE_DIR}/module.json" "${PACKAGE_VERSION}"

ZIP_NAME="${MODULE_ID}.${PACKAGE_VERSION}.zip"
ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"
TMP_ZIP_PATH="${OUTPUT_DIR}/.${ZIP_NAME}.tmp"

rm -f "${ZIP_PATH}" "${TMP_ZIP_PATH}"

(
  cd "${TMP_MODULE_DIR}"
  zip -qr "${TMP_ZIP_PATH}" .
)

php -r '
  $zipPath = $argv[1];
  $expectedModuleId = $argv[2];
  $expectedVersion = $argv[3];
  $zip = new ZipArchive();
  $openResult = $zip->open($zipPath);
  if ($openResult !== true) {
      fwrite(STDERR, "Unable to open zip: {$zipPath}, code {$openResult}\n");
      exit(1);
  }

  $moduleJson = $zip->getFromName("module.json");
  if ($moduleJson === false) {
      $zip->close();
      fwrite(STDERR, "Invalid module package: module.json must be at zip root\n");
      exit(1);
  }

  $data = json_decode($moduleJson, true);
  if (!is_array($data) || ($data["moduleUniqueID"] ?? "") !== $expectedModuleId) {
      $zip->close();
      fwrite(STDERR, "Invalid module package: moduleUniqueID mismatch\n");
      exit(1);
  }
  if (($data["version"] ?? "") !== $expectedVersion) {
      $zip->close();
      fwrite(STDERR, "Invalid module package: version mismatch\n");
      exit(1);
  }

  $forbiddenPrefixes = [
      ".git/",
      ".github/",
      ".gitlab/",
      "Tests/",
      "docs/",
      "react-app/",
      "scripts/",
  ];
  $forbiddenNames = [
      ".git",
      ".github",
      ".gitignore",
      ".gitlab",
      ".gitlab-ci.yml",
      "Tests",
      "docs",
      "react-app",
      "scripts",
  ];
  for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = $zip->getNameIndex($i);
      if (!is_string($name)) {
          continue;
      }
      $normalized = preg_replace("#^\\./#", "", $name) ?? $name;
      $normalized = rtrim($normalized, "/");
      if (in_array($normalized, $forbiddenNames, true)) {
          $zip->close();
          fwrite(STDERR, "Invalid module package: dev artifact included: {$name}\n");
          exit(1);
      }
      foreach ($forbiddenPrefixes as $prefix) {
          if (str_starts_with($normalized . "/", $prefix)) {
              $zip->close();
              fwrite(STDERR, "Invalid module package: dev artifact included: {$name}\n");
              exit(1);
          }
      }
  }

  $zip->close();
' "${TMP_ZIP_PATH}" "${MODULE_ID}" "${PACKAGE_VERSION}"

mv "${TMP_ZIP_PATH}" "${ZIP_PATH}"
shasum -a 256 "${ZIP_PATH}"
