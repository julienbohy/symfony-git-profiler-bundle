#!/usr/bin/env bash
set -euo pipefail

# Cut a release: bump SemVer, promote CHANGELOG [Unreleased] through a
# short-lived squash-merged PR (branch protection on main rejects direct
# pushes), tag the merge commit (no 'v'), and create a GitHub Release.
# Drives git + gh CLI.
#
# Usage: .github/bin/release.sh <patch|minor|major> [--version X.Y.Z] [--dry-run]
# Requires: run on `main`, git + gh (authenticated), clean tree.
# In CI the token needs `contents: write` + `pull-requests: write`, and the
# repository setting "Allow GitHub Actions to create and approve pull
# requests" must be enabled.

REPO="julienbohy/symfony-git-profiler-bundle"
CHANGELOG="CHANGELOG.md"
BASE_URL="https://github.com/${REPO}"

BUMP="${1:?usage: release.sh <patch|minor|major> [--version X.Y.Z] [--dry-run]}"
shift
EXPLICIT="" ; DRY=false
while [ $# -gt 0 ]; do
  case "$1" in
    --version) EXPLICIT="${2:?}"; shift 2 ;;
    --version=*) EXPLICIT="${1#*=}"; shift ;;
    --dry-run) DRY=true; shift ;;
    *) echo "::error::unknown arg: $1" >&2; exit 2 ;;
  esac
done

branch="$(git rev-parse --abbrev-ref HEAD)"
if ! $DRY && [ "$branch" != "main" ]; then
  echo "::error::release must run on main (on: $branch)"; exit 1
fi

git fetch --tags --force --quiet
previous="$(git tag -l --sort=-version:refname | head -n1)"; : "${previous:=0.0.0}"

if [ -n "$EXPLICIT" ]; then
  version="$EXPLICIT"
else
  IFS='.' read -r MA MI PA <<<"$previous"
  case "$BUMP" in
    major) MA=$((MA+1)); MI=0; PA=0 ;;
    minor) MI=$((MI+1)); PA=0 ;;
    patch) PA=$((PA+1)) ;;
    *) echo "::error::unknown bump: $BUMP"; exit 2 ;;
  esac
  version="${MA}.${MI}.${PA}"
fi

[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "::error::invalid version: $version"; exit 2; }
git rev-parse -q --verify "refs/tags/$version" >/dev/null && { echo "::error::tag $version already exists"; exit 1; }

today="$(date +%Y-%m-%d)"
echo "previous=$previous  next=$version  date=$today"

# Body of [Unreleased] (stop at next version header or the links block).
notes="$(awk '
  /^## \[Unreleased\]/ {f=1; next}
  f && (/^## \[/ || /^\[[^]]+\]:[ \t]*http/) {f=0}
  f {print}
' "$CHANGELOG")"
# Trim leading/trailing blank lines (portable across BSD/GNU awk).
notes="$(printf '%s\n' "$notes" | awk '
  { l[NR]=$0 }
  END {
    s=1;  while (s<=NR && l[s] ~ /^[ \t]*$/) s++
    e=NR; while (e>=1  && l[e] ~ /^[ \t]*$/) e--
    for (i=s; i<=e; i++) print l[i]
  }')"

printf '%s' "$notes" | grep -Eq '^[[:space:]]*[-*][[:space:]]+[^[:space:]]' \
  || { echo "::error::[Unreleased] is empty — nothing to release"; exit 3; }

# Promote [Unreleased] -> [version] and regenerate the reference links.
new="$(awk -v ver="$version" -v date="$today" -v base="$BASE_URL" -v prev="$previous" '
  /^## \[Unreleased\]/ && !h { print "## [Unreleased]"; print ""; print "## [" ver "] - " date; h=1; next }
  /^\[Unreleased\]:/ { print "[Unreleased]: " base "/compare/" ver "...HEAD";
                       print "[" ver "]: " base "/compare/" prev "..." ver; next }
  { print }
' "$CHANGELOG")"

if $DRY; then
  echo "----- CHANGELOG diff (dry-run) -----"; diff -u "$CHANGELOG" <(printf '%s\n' "$new") || true
  echo "----- release notes -----"; printf '%s\n' "$notes"
  exit 0
fi

printf '%s\n' "$new" > "$CHANGELOG"

if [ "${CI:-}" = "true" ]; then
  git config user.name  "github-actions[bot]"
  git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
fi

# Branch protection on main (pull request required, admins included, linear
# history) rejects direct pushes, so the promotion lands through a short-lived
# squash-merged PR.
release_branch="release/$version"
git checkout -b "$release_branch"
git add "$CHANGELOG"
git commit -m "chore(release): $version"
git push origin "$release_branch"

pr_url="$(gh pr create --base main --head "$release_branch" \
  --title "chore(release): $version" \
  --body "Promotes CHANGELOG [Unreleased] to [$version]. Opened by the release workflow because branch protection forbids direct pushes to main.")"
gh pr merge "$pr_url" --squash --delete-branch

# The squash rewrites the commit: tag what actually landed on main.
merge_oid=""
for _ in 1 2 3 4 5; do
  merge_oid="$(gh pr view "$pr_url" --json mergeCommit --jq '.mergeCommit.oid // empty')"
  [ -n "$merge_oid" ] && break
  sleep 2
done
[ -n "$merge_oid" ] || { echo "::error::could not resolve the squash merge commit of $pr_url"; exit 1; }

git fetch origin main
git tag -a "$version" -m "Release $version" "$merge_oid"
git push origin "refs/tags/$version"

notes_file="$(mktemp)"; printf '%s\n' "$notes" > "$notes_file"
gh release create "$version" --title "$version" --notes-file "$notes_file" --verify-tag --latest
rm -f "$notes_file"
echo "Released $version"
