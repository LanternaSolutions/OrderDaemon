---
name: Use update-version.sh for version bumps
description: Always use the update-version.sh script for version bumps — never manually edit version strings
type: feedback
---

Use `./update-version.sh patch|minor|major|<x.y.z>` to bump the version. Never manually edit version strings.

**Why:** The script handles all version locations atomically (plugin header, ODCM_VERSION constant, README.txt Stable tag, @since next placeholders), creates the git commit, and creates the annotated git tag — manual edits miss some of these.

**How to apply:** Before bumping the version, update BOTH `changelog.txt` AND `README.txt` (the `== Changelog ==` and `== Upgrade Notice ==` sections). The CI pipeline gates on `README.txt`, not `changelog.txt`. The script does `git add -A` so both files will be bundled into the version commit.
