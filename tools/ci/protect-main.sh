#!/usr/bin/env bash
# Secure the `main` branch: CODEOWNERS (you as sole reviewer) + hardened GitHub
# branch protection. Idempotent — safe to re-run. Requires the `gh` CLI, auth'd
# with a token that has admin rights on the repo.
#
#   ./tools/ci/protect-main.sh
#
set -euo pipefail

REPO="$(gh repo view --json nameWithOwner -q '.nameWithOwner')"
LOGIN="$(gh api user --jq '.login')"
BRANCH="main"

# Optionally add a second collaborator (invitation). Set these env vars:
#   COLLABORATOR=<github-username>  COLLAB_ROLE=<pull|triage|push|maintain|admin>
# e.g.  COLLABORATOR=octocat COLLAB_ROLE=push ./tools/ci/protect-main.sh
COLLABORATOR="${COLLABORATOR:-}"
COLLAB_ROLE="${COLLAB_ROLE:-push}"

if [ -n "$COLLABORATOR" ]; then
  echo "Inviting @$COLLABORATOR as '$COLLAB_ROLE' collaborator..."
  gh api -X PUT "repos/$REPO/collaborators/$COLLABORATOR" \
    -H "Accept: application/vnd.github+json" \
    -f permission="$COLLAB_ROLE" >/dev/null
  echo "Invitation sent (they must accept it)."
  echo
fi

echo "Repo:   $REPO"
echo "Owner:  @$LOGIN  (sole required reviewer)"
echo "Branch: $BRANCH"
echo

# ── 1. CODEOWNERS — @you owns everything, so every PR needs your review ───────
mkdir -p .github
if [ -n "$COLLABORATOR" ]; then
  OWNERS="@$LOGIN @$COLLABORATOR"
else
  OWNERS="@$LOGIN"
fi
printf '# Every change requires review from a repo owner.\n*  %s\n' "$OWNERS" > .github/CODEOWNERS
echo "Wrote .github/CODEOWNERS ($OWNERS)"

# ── 2. Hardened branch protection ─────────────────────────────────────────────
# - 1 approving review, from a CODE OWNER, stale approvals dismissed on new pushes
# - required CI status checks (must pass + be up to date with main)
# - enforced for admins too (strict, no bypass)
# - linear history (no merge commits), conversations resolved before merge
# - force-push + deletion blocked
gh api -X PUT "repos/$REPO/branches/$BRANCH/protection" \
  -H "Accept: application/vnd.github+json" \
  --input - <<'JSON'
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["PHPUnit (PHP 8.4)", "Zig build (all targets)", "PHPStan", "composer audit"]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": {
    "required_approving_review_count": 1,
    "require_code_owner_reviews": true,
    "dismiss_stale_reviews": true
  },
  "restrictions": null,
  "required_linear_history": true,
  "allow_force_pushes": false,
  "allow_deletions": false,
  "required_conversation_resolution": true,
  "block_creations": false,
  "lock_branch": false,
  "allow_fork_syncing": false
}
JSON

echo
echo "Branch protection applied to $BRANCH."
echo "Commit + push .github/CODEOWNERS on master, then PR it into main so it takes effect."
