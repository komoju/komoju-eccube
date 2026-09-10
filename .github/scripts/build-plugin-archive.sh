#!/usr/bin/env bash
set -euo pipefail

STAGING_DIR=${1:?Usage: build-plugin-archive.sh STAGING_DIR ARCHIVE_PATH}
ARCHIVE_PATH=${2:?Usage: build-plugin-archive.sh STAGING_DIR ARCHIVE_PATH}

rm -rf -- "$STAGING_DIR"
mkdir -p -- "$STAGING_DIR"

# vendor must not ship in the plugin archive. EC-CUBE auto-discovers PHP files
# below the plugin directory and otherwise treats vendor/autoload.php as a
# Plugin\Komoju42 service class.
rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='staging' \
  --exclude='vendor' \
  --exclude='tests' \
  --exclude='phpunit.xml' \
  --exclude='phpunit.xml.dist' \
  --exclude='.phpunit.result.cache' \
  --exclude='.gitignore' \
  --exclude='.DS_Store' \
  --exclude='README.md' \
  --exclude='README.txt' \
  --exclude='CHANGELOG.txt' \
  --exclude='TECHNICAL.md' \
  --exclude='LICENSE.txt' \
  --exclude='composer.lock' \
  ./ "$STAGING_DIR/"

# EC-CUBE's PharData extraction requires ustar and rejects a root ./ entry.
(
  cd "$STAGING_DIR"
  tar --format=ustar -czf "$ARCHIVE_PATH" -- *
)
