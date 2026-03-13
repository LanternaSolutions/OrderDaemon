# Order Daemon — Development & Release Workflow

## Repository Overview

This is the **free core plugin** repository. The pro version lives separately at `~/lab/order-daemon-pro`.

The repository is private by default. A filtered copy is mirrored to two public/backup destinations automatically on release.

---

## Git Remotes

| Remote | URL | Purpose |
|---|---|---|
| `origin` | `github.com/YakirLantern/OrderDaemon-private` | **Source of truth** — primary private repo, CI runs here |
| `github-public` | `github.com/LanternaSolutions/OrderDaemon` | Public mirror — filtered, no dev artifacts |
| `gitlab` | `gitlab.com/YakirLanterna/order-daemon` | Cloud backup mirror — full private copy |
| `gitea` | `localhost:2222/yakir-gitea/order-daemon-core` | Local backup mirror |
| `gitlab-private` | `git.boundless.zone/...` | Legacy — old private GitLab instance |

**Normal day-to-day push:**
```bash
git push                  # pushes to origin (GitHub private)
```

The `github-public` and `gitlab` mirrors are updated automatically by CI on release. The `gitea` local mirror should be pushed to manually or via a git hook.

---

## What Gets Filtered from Public Releases

The following are present in the private repo but **never** appear in the public GitHub mirror, the zip artifact, or WordPress.org SVN:

| Path / Pattern | Reason |
|---|---|
| `notes/` | Internal dev notes, planning docs, WP.org correspondence |
| `*.code-workspace` | VSCode workspace files |
| `*.bak` | Editor backup files |
| `vendor/` (dev deps) | Only production composer deps are bundled |

---

## Directory Structure

```
order-daemon/
├── assets/
│   ├── css/
│   ├── js/
│   ├── banner-772x250.png        # WP.org directory page assets
│   ├── banner-1544x500.png
│   ├── icon-128x128.png
│   ├── icon-256x256.png
│   ├── icon.svg
│   └── screenshot-*.png
├── languages/                    # .po / .mo / .php translation files
├── notes/                        # Private dev notes (filtered from all public releases)
├── vendor/                       # Composer dependencies
├── *.php                         # Plugin source files
├── README.txt                    # WordPress.org readme (plain text format)
├── composer.json
├── .gitlab-ci.yml                # CI pipeline (to be migrated to GitHub Actions)
└── DEVELOPMENT.md                # This file
```

---

## Release Pipeline

Releases are triggered by pushing a version tag (`v1.2.3`). The CI pipeline runs three jobs:

### 1. `package_artifacts`
- Runs `composer install --no-dev` to get production-only dependencies
- Uses `rsync` with a strict whitelist to copy only plugin files into a staging dir
- Zips the staging dir into `order-daemon-v1.2.3.zip`
- Attaches the zip as a CI artifact and creates a GitLab/GitHub release

### 2. `deploy_svn`
- Does a sparse SVN checkout of `plugins.svn.wordpress.org/order-daemon` (only `trunk/` and `assets/`)
- Syncs plugin files into `trunk/` using the same rsync whitelist as the zip
- Syncs WP.org directory page assets (banners, icons, screenshots) into `svn/assets/`
- Stages new/deleted files with `svn add` / `svn delete`
- Copies `trunk/` to `tags/1.2.3/`
- Commits everything in one transaction

### 3. `push_github` (on merge to `main`)
- Clones the repo locally
- Uses `git filter-repo` to strip `notes/`, `*.bak`, `*.code-workspace` from history
- Force-pushes filtered history to `github.com/LanternaSolutions/OrderDaemon`

> **Note:** The public GitHub repo has divergent SHA history from the private repo because `filter-repo` rewrites commits. This is expected and intentional.

---

## WordPress.org SVN Layout

```
plugins.svn.wordpress.org/order-daemon/
├── trunk/        ← live plugin files (mirrors the release zip contents)
├── tags/
│   ├── 1.2.3/   ← snapshot copy of trunk at release time
│   └── ...
└── assets/       ← WP.org directory page images only (NOT in the plugin zip)
                     banner-772x250.png, banner-1544x500.png
                     icon-128x128.png, icon-256x256.png, icon.svg
                     screenshot-1.png, screenshot-2.png, ...
```

---

## Required CI Secrets

Set these in GitHub → repo Settings → Secrets and variables → Actions (mark as secrets):

| Secret | Description |
|---|---|
| `WP_SVN_USERNAME` | wordpress.org username |
| `WP_SVN_PASSWORD` | wordpress.org password |
| `PUBLIC_DEPLOY_KEY` | SSH private key with write access to `LanternaSolutions/OrderDaemon` |
| `GITLAB_DEPLOY_KEY` | SSH private key with write access to `gitlab.com/YakirLanterna/order-daemon` |

For the deploy keys: generate a dedicated keypair per target repo, add the **private** key as a CI secret, and add the **public** key as a Deploy Key (with write access) in each target repo's settings.

---

## How to Cut a Release

1. Finish work on a feature branch, open a PR, merge to `main`
2. From `main`, run the version bump script:
   ```bash
   ./update-version.sh patch   # or minor / major
   ```
3. Push the commit and the new tag:
   ```bash
   git push && git push --tags
   ```
4. CI picks up the tag, runs `package_artifacts` then `deploy_svn` automatically
5. Verify on wordpress.org/plugins/order-daemon that the new version appeared

---

## SSH Key Setup (one-time, per machine)

```bash
# Test connections
ssh -T git@github.com
ssh -T git@gitlab.com

# If prompted for passphrase every time, add to agent:
ssh-add ~/.ssh/id_ed25519
```

KDE's `ksshaskpass` handles the agent automatically on login if configured.
