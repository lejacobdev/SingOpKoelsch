#!/usr/bin/env bash
# ci-build.sh — push code to GitHub, build IPA on macOS cloud runner, download here
# Usage: ./ci-build.sh
set -euo pipefail

REPO_NAME="sing-op-koelsch-ios"
IPA_OUT="build/ipa/SingOpKoelsch.ipa"
ARTIFACT_NAME="SingOpKoelsch-ipa"

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; NC='\033[0m'

step() { echo -e "\n${YLW}▶ $*${NC}"; }
ok()   { echo -e "${GRN}✔ $*${NC}"; }
fail() { echo -e "${RED}✖ $*${NC}"; exit 1; }

# ── 1. GitHub auth ────────────────────────────────────────────────────────────
step "Checking GitHub auth..."
if ! gh auth status &>/dev/null; then
  echo "Not logged in. Run:  gh auth login"
  echo "Then re-run this script."
  exit 1
fi
GH_USER=$(gh api user -q .login)
ok "Logged in as: $GH_USER"

# ── 2. Git init ───────────────────────────────────────────────────────────────
step "Initialising git repo..."
if [ ! -d .git ]; then
  git init -b main
  ok "git init done"
else
  ok "git already initialised (branch: $(git branch --show-current 2>/dev/null || echo unknown))"
fi

git config user.name  "$(gh api user -q .name 2>/dev/null || echo "$GH_USER")"
git config user.email "$(gh api user -q .email 2>/dev/null || echo "$GH_USER@users.noreply.github.com")"

# ── 3. Create GitHub repo (idempotent) ────────────────────────────────────────
step "Ensuring GitHub repo exists..."
if gh repo view "$GH_USER/$REPO_NAME" &>/dev/null; then
  ok "Repo already exists: $GH_USER/$REPO_NAME"
else
  gh repo create "$GH_USER/$REPO_NAME" --private --source=. --push \
    --description "Sing op Kölsch iOS app" 2>/dev/null || true
  ok "Repo created: $GH_USER/$REPO_NAME"
fi

# ── 4. Stage and push ─────────────────────────────────────────────────────────
step "Committing and pushing..."
git add -A
if git diff --cached --quiet; then
  ok "Nothing new to commit — pushing current HEAD"
else
  git commit -m "ci: build IPA [$(date '+%Y-%m-%d %H:%M')]"
fi

REMOTE_URL="https://github.com/$GH_USER/$REPO_NAME.git"
if ! git remote get-url origin &>/dev/null; then
  git remote add origin "$REMOTE_URL"
fi
git push -u origin main --force-with-lease 2>/dev/null || git push -u origin main
ok "Pushed to $REMOTE_URL"

# ── 5. Wait for workflow ──────────────────────────────────────────────────────
step "Waiting for GitHub Actions build to start..."
sleep 6  # give GitHub a moment to pick up the push
RUN_ID=""
for i in $(seq 1 20); do
  RUN_ID=$(gh run list \
    --repo "$GH_USER/$REPO_NAME" \
    --workflow build-ipa.yml \
    --limit 1 \
    --json databaseId,status \
    -q '.[0] | select(.status != "completed") | .databaseId' 2>/dev/null || true)
  [ -n "$RUN_ID" ] && break
  sleep 4
done

if [ -z "$RUN_ID" ]; then
  # Maybe it completed very fast, grab latest regardless
  RUN_ID=$(gh run list \
    --repo "$GH_USER/$REPO_NAME" \
    --workflow build-ipa.yml \
    --limit 1 \
    --json databaseId \
    -q '.[0].databaseId' 2>/dev/null || true)
fi

[ -z "$RUN_ID" ] && fail "Could not find a workflow run. Check https://github.com/$GH_USER/$REPO_NAME/actions"
ok "Workflow run ID: $RUN_ID"
echo "Live log: https://github.com/$GH_USER/$REPO_NAME/actions/runs/$RUN_ID"

step "Watching build (this takes ~8-12 minutes on the macOS runner)..."
gh run watch "$RUN_ID" --repo "$GH_USER/$REPO_NAME" --exit-status

# ── 6. Check result ───────────────────────────────────────────────────────────
CONCLUSION=$(gh run view "$RUN_ID" \
  --repo "$GH_USER/$REPO_NAME" \
  --json conclusion -q '.conclusion')

[ "$CONCLUSION" != "success" ] && \
  fail "Build failed (conclusion=$CONCLUSION). Logs: https://github.com/$GH_USER/$REPO_NAME/actions/runs/$RUN_ID"

ok "Build succeeded!"

# ── 7. Download IPA ───────────────────────────────────────────────────────────
step "Downloading IPA to $IPA_OUT ..."
mkdir -p "$(dirname "$IPA_OUT")"
gh run download "$RUN_ID" \
  --repo "$GH_USER/$REPO_NAME" \
  --name "$ARTIFACT_NAME" \
  --dir "$(dirname "$IPA_OUT")"

# Artifact downloads into a subdirectory — flatten if needed
FOUND=$(find "$(dirname "$IPA_OUT")" -name "*.ipa" | head -1)
[ -z "$FOUND" ] && fail "IPA not found after download"
[ "$FOUND" != "$IPA_OUT" ] && mv "$FOUND" "$IPA_OUT"

ok "IPA ready!"
echo ""
echo "╔═══════════════════════════════════════════════════════╗"
echo "║  IPA file: $(pwd)/$IPA_OUT"
echo "║"
echo "║  Install options:"
echo "║   1. AltStore  → drag IPA onto AltServer on your Mac"
echo "║   2. Xcode     → Devices & Simulators → +"
echo "║   3. ideviceinstaller (USB): ideviceinstaller -i $IPA_OUT"
echo "╚═══════════════════════════════════════════════════════╝"
