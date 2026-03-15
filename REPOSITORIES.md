# Order Daemon — Repository & Release Guide

This document covers everything you need to know to work with the Order Daemon repositories:
day-to-day development, cutting releases, pushing to WordPress.org, and publishing to the public GitHub.

---

## Overview

Order Daemon has **two codebases**:

| Codebase | Location | Visibility |
|---|---|---|
| Free core plugin | `~/lab/order-daemon` | Private (mirrored publicly on release) |
| Pro plugin | `~/lab/order-daemon-pro` | Private only |

This document covers the free core plugin only.

---

## Git Remotes

The local repository is connected to four remote destinations:

| Remote name | URL | Purpose |
|---|---|---|
| `origin` | `github.com/YakirLantern/OrderDaemon-private` | **Source of truth** — primary private repo, CI runs here |
| `gitlab` | `gitlab.com/YakirLanterna/order-daemon` | Cloud backup mirror — full private copy |
| `gitea` | `localhost:2222/yakir-gitea/order-daemon-core` | Local backup mirror |
| `github-public` | `github.com/LanternaSolutions/OrderDaemon` | Public mirror — filtered, no dev artifacts |
| `gitlab-private` | `git.boundless.zone/...` | Legacy — old private GitLab instance (no longer used) |

**Normal day-to-day push:**
```bash
git push
```
This pushes to `origin` (GitHub private) only. The `gitlab` backup and `github-public` mirror are updated by CI automatically.

**To push to the local Gitea backup manually:**
```bash
git push gitea
```

---

```mermaid
graph TB
    subgraph local["Local Machine"]
        OD["order-daemon\n(free plugin)"]
        ODP["order-daemon-pro\n(pro plugin)"]
        WSL["software-license\n(WP plugin)"]
    end

    subgraph remotes_free["Free Plugin Git Remotes"]
        GH_PRIV["GitHub Private\nYakirLantern/OrderDaemon-private\n(origin / CI)"]
        GH_PUB["GitHub Public\nLanternaSolutions/OrderDaemon\n(filtered mirror)"]
        GL["GitLab.com\nYakirLanterna/order-daemon\n(backup)"]
        GITEA["Gitea Local\nlocalhost:2222\n(local backup)"]
    end

    subgraph remotes_pro["Pro Plugin Git Remotes"]
        GH_PRO["GitHub Private\nYakirLantern/OrderDaemon-pro-private\n(origin / CI)"]
    end

    subgraph website["orderdaemon.com (WordPress)"]
        WSL_INST["WSL Plugin\n(licensing engine)"]
        WC["WooCommerce\n(products + orders)"]
        ADV["Advanced Scripts Pro\n(webhook handlers)"]
        FREE_ZIP["odcm-releases/\n(free plugin zips)"]
        PRO_ZIP["woocommerce_uploads/\nodcm-pro-releases/\n(pro plugin zips)"]
    end

    subgraph wp_org["WordPress.org SVN"]
        SVN_TRUNK["trunk/"]
        SVN_TAGS["tags/1.x.x/"]
        SVN_ASSETS["assets/\n(banners, icons)"]
    end

    OD -->|"git push (daily)"| GH_PRIV
    OD -->|"git push gitea (manual)"| GITEA
    ODP -->|"git push (daily)"| GH_PRO

    GH_PRIV -->|"on version tag:\npackage + deploy_svn"| SVN_TRUNK
    GH_PRIV -->|"on push to main:\nmirror_gitlab"| GL
    GH_PRIV -->|"manual:\npush_github_public (filter-repo)"| GH_PUB
    GH_PRIV -->|"on version tag:\nwebhook POST"| ADV

    GH_PRO -->|"on version tag:\nwebhook POST"| ADV

    ADV -->|"saves free zip"| FREE_ZIP
    ADV -->|"saves pro zip"| PRO_ZIP
    ADV -->|"updates options:\nodcm_free_version\nodcm_free_download_url"| WC
    ADV -->|"updates downloadable files\non pro WC product"| WC
    WC --- WSL_INST

    ODP -->|"requires free plugin active\n(reads ODCM_VERSION)"| OD
    ODP -->|"license validation +\nplugin update check (WSL API)"| WSL_INST
    WSL_INST -->|"serves pro zip\n(after license check)"| PRO_ZIP

    SVN_TRUNK --> SVN_TAGS
    SVN_TRUNK --> SVN_ASSETS
```

---

## What Gets Filtered from Public Releases

These paths exist in the private repo but are **never** included in the zip artifact, WordPress.org SVN, or the public GitHub mirror:

| Path / Pattern | Reason |
|---|---|
| `notes/` | Internal dev notes, planning docs, WP.org correspondence |
| `*.code-workspace` | VSCode workspace config files |
| `*.bak` | Editor backup files |
| Dev composer dependencies | Only production deps are bundled (`composer install --no-dev`) |

---

## Directory Structure

```
order-daemon/
├── .github/
│   └── workflows/
│       └── release.yml       # CI/CD pipeline (GitHub Actions)
├── assets/
│   ├── css/                  # Plugin stylesheets
│   ├── js/                   # Plugin scripts
│   ├── banner-772x250.png    # WordPress.org directory page assets
│   ├── banner-1544x500.png
│   ├── icon-128x128.png
│   ├── icon-256x256.png
│   ├── icon.svg
│   └── screenshot-*.png
├── languages/                # Translation files (.po / .mo / .php)
├── notes/                    # Private dev notes (never published)
├── src/                      # PHP source files
├── vendor/                   # Composer dependencies
├── changelog.txt             # Full version history (not shown in README.txt)
├── composer.json
├── order-daemon.php          # Plugin entry point and version header
├── README.txt                # WordPress.org readme (plain text format, required)
├── REPOSITORIES.md           # This file
└── update-version.sh         # Version bump and tag script
```

---

## CI/CD Pipeline (GitHub Actions)

The pipeline lives at [.github/workflows/release.yml](.github/workflows/release.yml) and runs on the **private GitHub repo** (`origin`).

### What triggers what

| Trigger | Jobs that run |
|---|---|
| Push to `main` | `mirror_gitlab` (backup to GitLab.com) |
| Push a version tag (`v1.2.3`) | `package` → `deploy_svn` → `mirror_gitlab` |
| Manual dispatch (Actions UI) | `push_github_public` (publish to public GitHub) |

### Job descriptions

**`package`** — Builds the release zip:
- Validates that the version has a changelog entry in `README.txt` (fails the release if missing)
- Runs `composer install --no-dev` for production-only dependencies
- Uses `rsync` to copy only plugin files into a staging directory (filtered list)
- Zips the staging directory into `order-daemon-v1.2.3.zip`
- Uploads the zip as a workflow artifact (kept 90 days)
- Creates a GitHub release with the zip attached

**`deploy_svn`** — Deploys to WordPress.org:
- Does a sparse SVN checkout of `plugins.svn.wordpress.org/order-daemon` (trunk and assets only)
- Syncs plugin files into `trunk/` using the same filter as the zip
- Syncs WP.org directory assets (banners, icons, screenshots) into `svn/assets/`
- Stages new and deleted files
- Copies `trunk/` to `tags/1.2.3/`
- Commits everything in one SVN transaction

**`mirror_gitlab`** — Backs up to GitLab.com:
- Pushes all branches and tags to `gitlab.com/YakirLanterna/order-daemon`
- Runs on every push to `main` and on every version tag push

**`push_github_public`** — Publishes to the public GitHub (manual only):
- Requires typing `publish` in the confirmation prompt in the Actions UI
- Uses `git filter-repo` to strip `notes/`, `*.bak`, `*.code-workspace` from the full git history
- Force-pushes the filtered history to `github.com/LanternaSolutions/OrderDaemon`
- Pushes all version tags

> **Note:** The public GitHub repo has different commit SHAs from the private repo because `filter-repo` rewrites history. This is expected and intentional.

---

## WordPress.org SVN Layout

```
plugins.svn.wordpress.org/order-daemon/
├── trunk/          ← live plugin files (what users get on "install latest")
├── tags/
│   ├── 1.3.23/    ← snapshot of trunk at release time
│   ├── 1.3.24/
│   └── ...
└── assets/         ← WordPress.org directory page images ONLY (not in the plugin zip)
                       banner-772x250.png
                       banner-1544x500.png
                       icon-128x128.png
                       icon-256x256.png
                       icon.svg
                       screenshot-1.png, screenshot-2.png, ...
```

The `Stable tag` in `README.txt` tells WordPress.org which tag to serve to users. The CI sets this automatically via `update-version.sh`.

---

## Required CI Secrets

These must be set in:
**GitHub → `YakirLantern/OrderDaemon-private` → Settings → Secrets and variables → Actions**

| Secret name | Description |
|---|---|
| `WP_SVN_USERNAME` | wordpress.org account username |
| `WP_SVN_PASSWORD` | wordpress.org account password |
| `PUBLIC_DEPLOY_KEY` | SSH private key with write access to `LanternaSolutions/OrderDaemon` |
| `GITLAB_DEPLOY_KEY` | SSH private key with write access to `gitlab.com/YakirLanterna/order-daemon` |
| `WEBSITE_WEBHOOK_URL` | `https://orderdaemon.com/` |
| `WEBSITE_WEBHOOK_TOKEN` | Shared secret — must match `ODCM_WEBHOOK_TOKEN` constant in `wp-config.php` |

> **Important:** GitHub does not allow secret names that start with `GITHUB_`. Use `PUBLIC_DEPLOY_KEY`, not `GITHUB_PUBLIC_DEPLOY_KEY`.

To add a secret:
1. Go to the repo → **Settings** → **Secrets and variables** → **Actions**
2. Click **New repository secret**
3. Paste the name and value, click **Add secret**

---

## One-Time Machine Setup

### 1. Add your SSH public key to GitHub and GitLab

```bash
cat ~/.ssh/id_ed25519.pub
```

Copy the output and add it to:
- **GitHub:** github.com → Settings → SSH and GPG keys → New SSH key
- **GitLab.com:** gitlab.com → user menu → Preferences → SSH Keys → Add new key

### 2. Add GitHub to known hosts (first time only)

```bash
ssh-keyscan github.com >> ~/.ssh/known_hosts
ssh-keyscan gitlab.com >> ~/.ssh/known_hosts
```

### 3. Test the connections

```bash
ssh -T git@github.com
ssh -T git@gitlab.com
```

Expected output:
```
Hi YakirLantern! You've successfully authenticated...
Welcome to GitLab, @YakirLanterna!
```

### 4. Add your key to the SSH agent (avoids passphrase prompts)

```bash
ssh-add ~/.ssh/id_ed25519
```

On KDE, `ksshaskpass` handles this automatically on login if configured.

---

## Setting Up Deploy Keys (one-time, if re-creating from scratch)

### For the public GitHub mirror

```bash
# Generate a dedicated keypair (no passphrase)
ssh-keygen -t ed25519 -f ~/.ssh/od_github_public_deploy -N "" -C "order-daemon github public deploy"

# Print the public key — add this to LanternaSolutions/OrderDaemon
cat ~/.ssh/od_github_public_deploy.pub

# Print the private key — add this as the PUBLIC_DEPLOY_KEY CI secret
cat ~/.ssh/od_github_public_deploy
```

Add the public key at:
**github.com/LanternaSolutions/OrderDaemon → Settings → Deploy keys → Add deploy key**
Check **Allow write access**.

### For the GitLab.com backup

```bash
# Generate a dedicated keypair (no passphrase)
ssh-keygen -t ed25519 -f ~/.ssh/od_gitlab_deploy -N "" -C "order-daemon gitlab deploy"

# Print the public key — add this to gitlab.com/YakirLanterna/order-daemon
cat ~/.ssh/od_gitlab_deploy.pub

# Print the private key — add this as the GITLAB_DEPLOY_KEY CI secret
cat ~/.ssh/od_gitlab_deploy
```

Add the public key at:
**gitlab.com/YakirLanterna/order-daemon → Settings → Repository → Deploy keys → Add new key**
Check **Grant write permissions**.

---

## Day-to-Day Development

Normal development flow — just work on `main` or a feature branch and push:

```bash
# Make your changes, then:
git add .
git commit -m "Your commit message"
git push
```

This pushes to the private GitHub (`origin`) only. Nothing is published anywhere.

---

## How to Cut a Release

### Step 1 — Update the changelog

Before bumping the version, **you must update `README.txt`** (the CI will block the release if you don't).

Open [README.txt](README.txt) and add a new entry at the top of the `== Changelog ==` section:

```
== Changelog ==

= 1.3.25 =
* Your change description here
* Another change

= 1.3.24 =
* ...
```

Also update [changelog.txt](changelog.txt) with the same entry for the full historical record.

### Step 2 — Run the version bump script

The script updates the version in `order-daemon.php`, `README.txt` stable tag, all `@since next` placeholders, creates a commit, and creates the git tag.

```bash
# Increment patch version (e.g. 1.3.24 → 1.3.25)
./update-version.sh patch

# Increment minor version (e.g. 1.3.24 → 1.4.0)
./update-version.sh minor

# Increment major version (e.g. 1.3.24 → 2.0.0)
./update-version.sh major

# Set a specific version
./update-version.sh 1.3.25

# Preview what would happen without making any changes
./update-version.sh --dry-run patch
```

### Step 3 — Push the commit and tag

```bash
git push && git push --tags
```

This triggers the CI pipeline automatically:
- Builds the zip
- Deploys to WordPress.org SVN
- Backs up to GitLab.com

### Step 4 — Monitor the pipeline

Go to:
**github.com/YakirLantern/OrderDaemon-private → Actions**

You'll see the workflow run with two jobs: `Package plugin zip` and `Deploy to WordPress.org SVN`.

### Step 5 — Verify the release

Once the pipeline is green, check:
- **wordpress.org/plugins/order-daemon** — new version should appear within a few minutes
- **github.com/YakirLantern/OrderDaemon-private/releases** — zip should be attached to the release

---

## How to Publish to the Public GitHub

This is a **manual step** — it is never done automatically.

1. Go to: **github.com/YakirLantern/OrderDaemon-private → Actions**
2. In the left sidebar, click **Release Pipeline**
3. Click the **Run workflow** button (top right, above the list of runs)
4. A dropdown appears — leave the branch as `main`
5. In the input field, type exactly: `publish`
6. Click **Run workflow**

The job strips `notes/`, `*.bak`, and `*.code-workspace` from the full git history and force-pushes the filtered result to `github.com/LanternaSolutions/OrderDaemon`.

> The public repo's commit SHAs will differ from the private repo. This is normal — `filter-repo` rewrites history to remove the private files.

---

## What to Do If the Changelog Validation Fails

If you pushed a tag before updating the changelog, the pipeline will fail with:

```
ERROR: No changelog entry found for version X.X.X in README.txt
```

Fix it like this:

**1. Update `README.txt` and `changelog.txt`** with the missing version entry (see Step 1 above).

**2. Commit the fix:**
```bash
git add README.txt changelog.txt
git commit -m "Add changelog entry for X.X.X"
```

**3. Force-move the tag to the new commit:**
```bash
git tag -f -a vX.X.X -m "vX.X.X"
```

**4. Push the commit and force-push the tag:**
```bash
git push
git push --force origin refs/tags/vX.X.X
```

This re-triggers the pipeline with the same version number. No version bump needed.

---

## Handling External Contributions

The public GitHub repo (`LanternaSolutions/OrderDaemon`) accepts issues and pull requests.

**Issues:** Handle normally — read, reproduce, fix in the private repo, release as usual.

**Pull requests:** Do not merge PRs directly on the public repo (the next publish would overwrite them). Instead:
1. Download the diff from the PR
2. Apply it in the private repo:
   ```bash
   # Save the patch URL from the PR (append .patch to the PR URL)
   curl -L https://github.com/LanternaSolutions/OrderDaemon/pull/123.patch | git am
   ```
3. Credit the contributor in the commit message (e.g. `Co-authored-by: Name <email>`)
4. Close the PR on the public repo with a comment explaining it was applied upstream
5. Release normally — the change will appear in the next published version

---

## Website Integration (orderdaemon.com)

The release pipeline automatically updates the plugin download page on `orderdaemon.com` after each successful SVN deploy. This section covers the one-time setup required on the WordPress site.

---

### How it works

1. CI builds the zip and deploys to WP.org SVN
2. CI POSTs the zip + version number to a webhook on `orderdaemon.com`
3. The webhook saves the zip to `wp-content/uploads/odcm-releases/` and:
   - Updates two WordPress options used by the `/get/` download page:
     - `odcm_free_version` — the current version string
     - `odcm_free_download_url` — the full URL to the zip file
   - Updates the `Order Daemon` downloadable file entry on every WooCommerce pro product (identified by having an `Order Daemon Pro` download attached), so pro customers can download both plugins from their My Account page
4. The download page at `/get/` reads these options live — no Elementor editing required after initial setup

---

### Required CI secrets

Add these to **GitHub → `YakirLantern/OrderDaemon-private` → Settings → Secrets and variables → Actions**:

| Secret name | Value |
|---|---|
| `WEBSITE_WEBHOOK_URL` | `https://orderdaemon.com/` |
| `WEBSITE_WEBHOOK_TOKEN` | *(see below — store this carefully)* |

The token is a long random hex string generated once. It is the shared secret between GitHub Actions and your WordPress site.

---

### Step 1 — Store the webhook token in wp-config.php

Add this line to `wp-config.php` on `orderdaemon.com` (above the `/* That's all */` line). Using a constant instead of a database option means the token survives database restores.

```php
define( 'ODCM_WEBHOOK_TOKEN', 'your-token-here' );
```

> **Recovery:** The token is always available in GitHub → repo Settings → Secrets → `WEBSITE_WEBHOOK_TOKEN`. If `wp-config.php` is ever lost, just re-add the constant with the same value.

---

### Step 2 — Advanced Scripts Pro: `odcm-free-helpers`

Create a new PHP snippet in Advanced Scripts Pro. Name it `odcm-free-helpers`. Set it to **frontend**, always active.

This script provides:
- `[odcm_free_version]` — shortcode for displaying the current version in Elementor text widgets
- `[odcm_download_trigger]` — shortcode for the download page (auto-starts download via JS with a manual fallback link)

```php
<?php
// Shortcode [odcm_free_version] — use in Elementor text widgets
add_shortcode( 'odcm_free_version', function () {
    return esc_html( get_option( 'odcm_free_version', '—' ) );
} );

// Shortcode [odcm_download_trigger] — place in an HTML widget on the /get/ page.
// Auto-starts the download after 1.5s and shows a manual fallback link.
add_shortcode( 'odcm_download_trigger', function () {
    $url = get_option( 'odcm_free_download_url', '' );

    if ( ! $url ) {
        return '<p>Download link unavailable. Please <a href="/contact/">contact us</a>.</p>';
    }

    $url = esc_url( $url );
    ob_start();
    ?>
    <script>
    (function() {
        var url = <?php echo wp_json_encode( $url ); ?>;
        setTimeout( function() { window.location.href = url; }, 1500 );
    })();
    </script>
    <p>
        If your download does not start automatically,
        <a href="<?php echo $url; ?>">click here</a>.
    </p>
    <?php
    return ob_get_clean();
} );
```

---

### Step 3 — Advanced Scripts Pro: `odcm-free-webhook`

Create a second PHP snippet. Name it `odcm-free-webhook`. Set it to **frontend**, always active.

This script receives the POST request from GitHub Actions, validates the token, saves the zip, and updates the WordPress options.

The source of truth for this snippet is [`.deploy/website/odcm-free-webhook.php`](.deploy/website/odcm-free-webhook.php). Copy that file's contents verbatim into the Advanced Scripts Pro snippet.

---

### Step 4 — Create the download WordPress page

Go to **WordPress → Pages → Add New**:
- Title: `Get Order Daemon`
- Slug: `get`
- Content: leave empty for now — design in Elementor next
- Publish it

---

### Step 5 — Design the page in Elementor

The page at `orderdaemon.com/get/` is a fully designed Elementor page. Suggested layout:

```
[Order Daemon logo]

Your download is starting…
Order Daemon v[odcm_free_version]

[odcm_download_trigger]
(invisible — outputs JS auto-trigger + manual fallback link)

── While you wait ──
Links to docs, quick start guide, or pro plugin upsell
```

- Add `[odcm_free_version]` in any text widget to show the current version
- Add `[odcm_download_trigger]` in an **HTML widget** on this page — it outputs the JS trigger and fallback link
- The **Download button on your main/pricing page** points to `https://orderdaemon.com/get/` — set this once and never change it again

---

### Step 6 — Test the webhook manually

Before relying on CI, test the webhook from your terminal. Make sure you have a zip file available:

```bash
curl -v -X POST \
  "https://orderdaemon.com/?odcm_action=update_free&token=YOUR_TOKEN_HERE" \
  -F "version=1.3.24" \
  -F "zip=@/home/yakir/lab/order-daemon/order-daemon-v1.3.24.zip"
```

Expected response:
```json
{"success": true, "version": "1.3.24"}
```

Then visit `orderdaemon.com/get/` and confirm the download starts automatically.

---

### Webhook endpoint reference

| Parameter | Location | Value |
|---|---|---|
| `odcm_action` | GET | `update_free` |
| `token` | GET | value of `ODCM_WEBHOOK_TOKEN` |
| `version` | POST form field | e.g. `1.3.25` |
| `zip` | POST file upload | the plugin zip file |

---

## Gitea Local Backup

Run local Gitea instance at `localhost:2222`. Push to it manually whenever you want a local snapshot:

```bash
git push gitea
git push gitea --tags
```

This is separate from the automated GitLab.com backup and is entirely optional.

---

## Repository Architecture

```mermaid
graph TB
    subgraph local["Local Machine"]
        OD["order-daemon\n(free plugin)"]
        ODP["order-daemon-pro\n(pro plugin)"]
    end

    subgraph remotes_free["Free Plugin Git Remotes"]
        GH_PRIV["GitHub Private\nYakirLantern/OrderDaemon-private\n(origin / CI)"]
        GH_PUB["GitHub Public\nLanternaSolutions/OrderDaemon\n(filtered mirror)"]
        GL["GitLab.com\nYakirLanterna/order-daemon\n(backup)"]
        GITEA["Gitea Local\nlocalhost:2222\n(local backup)"]
    end

    subgraph remotes_pro["Pro Plugin Git Remotes"]
        GH_PRO["GitHub Private\nYakirLantern/OrderDaemon-pro-private\n(origin / CI)"]
    end

    subgraph website["orderdaemon.com (WordPress)"]
        WSL_INST["WSL Plugin\n(licensing engine)"]
        WC["WooCommerce\n(products + orders)"]
        ADV["Advanced Scripts Pro\n(webhook handlers)"]
        FREE_ZIP["odcm-releases/\n(free plugin zips)"]
        PRO_ZIP["woocommerce_uploads/\nodcm-pro-releases/\n(pro plugin zips)"]
    end

    subgraph wp_org["WordPress.org SVN"]
        SVN_TRUNK["trunk/"]
        SVN_TAGS["tags/1.x.x/"]
        SVN_ASSETS["assets/\n(banners, icons)"]
    end

    OD -->|"git push (daily)"| GH_PRIV
    OD -->|"git push gitea (manual)"| GITEA
    ODP -->|"git push (daily)"| GH_PRO

    GH_PRIV -->|"on version tag:\npackage + deploy_svn"| SVN_TRUNK
    GH_PRIV -->|"on push to main:\nmirror_gitlab"| GL
    GH_PRIV -->|"manual:\npush_github_public (filter-repo)"| GH_PUB
    GH_PRIV -->|"on version tag:\nwebhook POST"| ADV

    GH_PRO -->|"on version tag:\nwebhook POST"| ADV

    ADV -->|"saves free zip"| FREE_ZIP
    ADV -->|"saves pro zip"| PRO_ZIP
    ADV -->|"updates options:\nodcm_free_version\nodcm_free_download_url"| WC
    ADV -->|"updates downloadable files\non pro WC product"| WC
    WC --- WSL_INST

    ODP -->|"requires free plugin active\n(reads ODCM_VERSION)"| OD
    ODP -->|"license validation +\nplugin update check (WSL API)"| WSL_INST
    WSL_INST -->|"serves pro zip\n(after license check)"| PRO_ZIP

    SVN_TRUNK --> SVN_TAGS
    SVN_TRUNK --> SVN_ASSETS
```
