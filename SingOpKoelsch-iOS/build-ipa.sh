#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# build-ipa.sh  –  Sing op Kölsch iOS build script
# Run this on a Mac with Xcode 15+ installed.
#
# Usage:
#   chmod +x build-ipa.sh
#   ./build-ipa.sh
#
# The finished IPA will be at: build/ipa/SingOpKoelsch.ipa
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCHEME="SingOpKoelsch"
PROJECT="SingOpKoelsch.xcodeproj"
ARCHIVE="build/SingOpKoelsch.xcarchive"
IPA_DIR="build/ipa"
EXPORT_PLIST="ExportOptions.plist"

# ── 0. Check prerequisites ────────────────────────────────────────────────────
if ! command -v xcodebuild &>/dev/null; then
  echo "❌  xcodebuild not found. Install Xcode from the App Store."
  exit 1
fi
if ! command -v xcodegen &>/dev/null; then
  echo "⚙️   Installing xcodegen via Homebrew..."
  brew install xcodegen
fi

# ── 1. Read Team ID from project.yml if ExportOptions still has placeholder ──
TEAM_ID=$(grep 'DEVELOPMENT_TEAM:' project.yml | awk '{print $2}' | tr -d '"' | head -1)
if [[ -z "$TEAM_ID" || "$TEAM_ID" == '""' ]]; then
  echo "⚠️   DEVELOPMENT_TEAM is not set in project.yml."
  read -rp "Enter your Apple Team ID (10 chars, e.g. ABC1234567): " TEAM_ID
  # Write it into project.yml
  sed -i '' "s/DEVELOPMENT_TEAM: \"\"/DEVELOPMENT_TEAM: \"$TEAM_ID\"/" project.yml
fi
# Patch ExportOptions.plist
sed -i '' "s/REPLACE_TEAM_ID/$TEAM_ID/" "$EXPORT_PLIST"

echo "✅  Team ID: $TEAM_ID"
echo ""

# ── 2. Generate Xcode project ─────────────────────────────────────────────────
echo "⚙️   Generating Xcode project with xcodegen..."
xcodegen generate
echo "✅  Project generated."
echo ""

# ── 3. Clean build folder ────────────────────────────────────────────────────
rm -rf build
mkdir -p "$IPA_DIR"

# ── 4. Archive ────────────────────────────────────────────────────────────────
echo "🔨  Archiving (this will take a few minutes)..."
xcodebuild archive \
  -project "$PROJECT" \
  -scheme  "$SCHEME" \
  -archivePath "$ARCHIVE" \
  -destination "generic/platform=iOS" \
  -allowProvisioningUpdates \
  CODE_SIGN_STYLE=Automatic \
  DEVELOPMENT_TEAM="$TEAM_ID" \
  | xcpretty 2>/dev/null || true

# xcpretty may not be installed — fall back to raw output if it fails
if [ ! -d "$ARCHIVE" ]; then
  echo "⚠️   xcpretty may have hidden errors. Re-running with raw output..."
  xcodebuild archive \
    -project "$PROJECT" \
    -scheme  "$SCHEME" \
    -archivePath "$ARCHIVE" \
    -destination "generic/platform=iOS" \
    -allowProvisioningUpdates \
    CODE_SIGN_STYLE=Automatic \
    DEVELOPMENT_TEAM="$TEAM_ID"
fi

if [ ! -d "$ARCHIVE" ]; then
  echo "❌  Archive failed — see output above."
  exit 1
fi
echo "✅  Archive created at $ARCHIVE"
echo ""

# ── 5. Export IPA ─────────────────────────────────────────────────────────────
echo "📦  Exporting IPA..."
xcodebuild -exportArchive \
  -archivePath "$ARCHIVE" \
  -exportPath  "$IPA_DIR" \
  -exportOptionsPlist "$EXPORT_PLIST" \
  -allowProvisioningUpdates

IPA_PATH=$(find "$IPA_DIR" -name "*.ipa" | head -1)
if [ -z "$IPA_PATH" ]; then
  echo "❌  IPA export failed."
  exit 1
fi

echo ""
echo "╔══════════════════════════════════════════════╗"
echo "║  ✅  IPA ready!                               ║"
echo "║  $IPA_PATH"
echo "╚══════════════════════════════════════════════╝"
echo ""
echo "Install options:"
echo "  • Direct device:  xcrun ios-deploy --bundle \"$IPA_PATH\""
echo "  • TestFlight:     upload via Transporter.app or altool"
echo "  • AltStore:       sideload via AltServer on your Mac"
echo ""

# ── 6. Open in Finder ─────────────────────────────────────────────────────────
open "$IPA_DIR"
