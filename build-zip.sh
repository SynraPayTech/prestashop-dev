#!/usr/bin/env sh
# Builds dist/synrapay.zip for PrestaShop 8 (Modules → Upload a module).
# Layout inside the zip: synrapay/ at the root, forward-slash entries
# (PowerShell's Compress-Archive writes backslashes, which break the module on Linux hosts).
set -e
cd "$(dirname "$0")"
mkdir -p dist
rm -rf dist/stage dist/synrapay.zip
mkdir -p dist/stage
cp -R synrapay dist/stage/synrapay
cd dist/stage
if command -v zip >/dev/null 2>&1; then
  zip -qr ../synrapay.zip synrapay -x '*.DS_Store'
else
  /c/Windows/System32/tar.exe -a -cf ../synrapay.zip synrapay
fi
cd ..
rm -rf stage
ls -la synrapay.zip
