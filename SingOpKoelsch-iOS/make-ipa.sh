#!/usr/bin/env bash
# make-ipa.sh  –  Build SingOpKoelsch.ipa using xtool + Docker
#
# What you need:
#   1. Docker (already installed on this server ✓)
#   2. Xcode.xip  — download from https://developer.apple.com/download/all/?q=Xcode
#      Log in with your Apple ID in the browser, then download the latest Xcode .xip
#      (~7 GB). Upload it to this server or point the script at it.
#
# Usage:
#   ./make-ipa.sh [/path/to/Xcode.xip]
#
# The SDK is extracted once and cached in ~/.xtool-cache/swiftpm/.
# Subsequent builds skip the extraction and are much faster.
#
# Install the resulting IPA via:
#   • AltStore (free, drag & drop) — https://altstore.io
#   • Xcode: Window → Devices & Simulators → +
#   • USB:   ideviceinstaller -i SingOpKoelsch.ipa
set -euo pipefail

DOCKER_IMAGE="xtool-builder"
CACHE_DIR="$HOME/.xtool-cache"
SDK_MARKER="$CACHE_DIR/.sdk_installed"
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
IPA_OUT="$PROJECT_DIR/build/ipa/SingOpKoelsch.ipa"

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; CYN='\033[0;36m'; NC='\033[0m'

step() { echo -e "\n${YLW}▶  $*${NC}"; }
ok()   { echo -e "${GRN}✔  $*${NC}"; }
info() { echo -e "${CYN}   $*${NC}"; }
fail() { echo -e "${RED}✖  $*${NC}"; exit 1; }

# ── 0. Locate Xcode.xip ───────────────────────────────────────────────────────
XIP_PATH="${1:-}"
if [ -z "$XIP_PATH" ]; then
    for candidate in ~/Downloads/Xcode*.xip /tmp/Xcode*.xip "$PROJECT_DIR/Xcode.xip"; do
        # expand glob safely
        for f in $candidate; do
            [ -f "$f" ] && XIP_PATH="$f" && break 2
        done
    done
fi

if [ -z "$XIP_PATH" ] || [ ! -f "$XIP_PATH" ]; then
    echo ""
    echo -e "${RED}Xcode.xip not found.${NC}"
    echo ""
    echo "  Download Xcode from:"
    echo "  https://developer.apple.com/download/all/?q=Xcode"
    echo ""
    echo "  Log in with your Apple ID, download 'Xcode.xip', then run:"
    echo "    ./make-ipa.sh /path/to/Xcode.xip"
    echo ""
    exit 1
fi
XIP_PATH=$(realpath "$XIP_PATH")
ok "Xcode.xip: $XIP_PATH  ($(du -sh "$XIP_PATH" | cut -f1))"

# ── 1. Build Docker image (cached after first run) ────────────────────────────
step "Preparing Docker build environment..."
docker build -f "$PROJECT_DIR/Dockerfile.xtool" -t "$DOCKER_IMAGE" "$PROJECT_DIR" --quiet
ok "Docker image ready: $DOCKER_IMAGE"

mkdir -p "$CACHE_DIR/swiftpm"

# ── 2. Extract SDKs from Xcode.xip (one-time, ~5 min) ───────────────────────
# Includes iOS + watchOS SDKs (needed for the Apple Watch companion app).
if [ ! -f "$SDK_MARKER" ]; then
    step "Extracting iOS + watchOS SDKs from Xcode.xip (runs once, takes ~5 minutes)..."
    info "This extracts SwiftUI, UIKit, WatchKit etc. from your Xcode download."

    docker run --rm \
        -v "$CACHE_DIR/swiftpm:/root/.swiftpm" \
        -v "$XIP_PATH:/Xcode.xip:ro" \
        "$DOCKER_IMAGE" \
        xtool sdk install /Xcode.xip

    touch "$SDK_MARKER"
    ok "SDKs installed and cached at $CACHE_DIR/swiftpm/"
else
    ok "SDKs already cached — skipping extraction."
    info "If watchOS build fails with 'SDK not found', delete $SDK_MARKER and re-run."
fi

# ── 3. Build the IPA ──────────────────────────────────────────────────────────
step "Building SingOpKoelsch.ipa (first build: ~5 min; subsequent: ~1 min)..."
mkdir -p "$PROJECT_DIR/build/ipa"

docker run --rm \
    -v "$CACHE_DIR/swiftpm:/root/.swiftpm" \
    -v "$PROJECT_DIR:/workspace" \
    "$DOCKER_IMAGE" \
    sh -c "cd /workspace && xtool dev build --ipa -c release"

# ── 4. Locate and move the IPA ────────────────────────────────────────────────
# xtool writes the packaged IPA to $PROJECT_DIR/xtool/ (newer) or $PROJECT_DIR/.build/ (older)
FOUND=$(find "$PROJECT_DIR/xtool" "$PROJECT_DIR/.build" -name "*.ipa" 2>/dev/null | head -1 || true)
[ -z "$FOUND" ] && FOUND=$(find "$PROJECT_DIR/build" -name "*.ipa" 2>/dev/null | grep -v "$IPA_OUT" | head -1 || true)

if [ -z "$FOUND" ]; then
    fail "IPA not found after build — see output above for errors."
fi

[ "$FOUND" != "$IPA_OUT" ] && mv "$FOUND" "$IPA_OUT"

# ── 4b. Inject app icons (xtool skips asset catalog compilation) ──────────────
step "Injecting app icons into IPA..."
python3 "$PROJECT_DIR/inject_icons.py" "$IPA_OUT"
ok "Icons injected"

IPA_SIZE=$(du -sh "$IPA_OUT" | cut -f1)

echo ""
echo -e "${GRN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GRN}║  ✅  IPA ready!  ($IPA_SIZE)${NC}"
echo -e "${GRN}║${NC}"
echo -e "${GRN}║  $IPA_OUT${NC}"
echo -e "${GRN}║${NC}"
echo -e "${GRN}║  Includes:  📱 iPhone/iPad app${NC}"
echo -e "${YLW}║  ⚠️  Watch app requires macOS build — see .github/workflows/build-ipa.yml${NC}"
echo -e "${YLW}║     Push to GitHub → Actions builds IPA with Watch app automatically.${NC}"
echo -e "${GRN}║${NC}"
echo -e "${GRN}║  Install options:${NC}"
echo -e "${GRN}║   • SideStore → add source URL in SideStore, install there${NC}"
echo -e "${GRN}║   • AltStore  → drag IPA onto AltServer on your Mac/PC${NC}"
echo -e "${GRN}║     (free, signs with your Apple ID automatically)${NC}"
echo -e "${GRN}║   • USB       → ideviceinstaller -i SingOpKoelsch.ipa${NC}"
echo -e "${GRN}╚══════════════════════════════════════════════════════════════╝${NC}"
