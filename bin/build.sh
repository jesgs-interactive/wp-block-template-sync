#!/usr/bin/env sh
# Extract version from tag (remove "v" prefix if present)
TAG_NAME="$(git describe --tags --abbrev=0>/dev/null || echo '1.0.0')" # get most recent tag
COMMIT_HASH="$(git rev-parse --short HEAD)" # get short commit hash

VERSION="${TAG_NAME}-${COMMIT_HASH}"

echo "Version: ${VERSION}"

# Use '|' as sed delimiter so slashes in VERSION won't break the substitution
sed -i "s|{{VERSION}}|${VERSION}|g" wp-block-template-sync.php

# Show the top of the plugin file for debugging
echo "--- plugin file head ---"
sed -n '1,40p' wp-block-template-sync.php
echo "------------------------"

# Verify both the plugin header and the constant were updated (fixed-string match)
if ! grep -Fq "Version: ${VERSION}" wp-block-template-sync.php; then
  echo "ERROR: plugin header Version was not updated to ${VERSION}"
  exit 1
fi

if ! grep -Fq "define( 'WP_BLOCK_TEMPLATE_SYNC_VERSION', '${VERSION}' );" wp-block-template-sync.php; then
  echo "ERROR: WP_BLOCK_TEMPLATE_SYNC_VERSION constant was not updated to ${VERSION}"
  exit 1
fi

# Update POT template placeholder (lowercase) with the same VERSION
sed -i "s|{{VERSION}}|${VERSION}|g" languages/wp-block-template-sync.pot

# Show the top of the POT file for debugging
echo "--- POT file head ---"
sed -n '1,40p' languages/wp-block-template-sync.pot
echo "---------------------"
