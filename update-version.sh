#!/bin/bash

# ============================================================================
# Version Update Script
# ============================================================================
#
# PURPOSE:
#   Updates version numbers in project files and creates git commits/tags.
#
# USAGE:
#   ./update-version.sh [-f|--force] [-n|--dry-run] <version|major|minor|patch>
#
# OPTIONS:
#   -f, --force     Force update even if the new version is the same or lower
#   -n, --dry-run   Do everything except creating git commits and tags
#   -h, --help      Show help instructions
#
# ARGUMENTS:
#   version         Specific version number (e.g., 1.2.3)
#   major           Increment major version (e.g., 1.2.3 -> 2.0.0)
#   minor           Increment minor version (e.g., 1.2.3 -> 1.3.0)
#   patch           Increment patch version (e.g., 1.2.3 -> 1.2.4)
#
# EXAMPLES:
#   # Increment patch version (1.2.3 -> 1.2.4)
#   ./update-version.sh patch
#
#   # Increment minor version (1.2.3 -> 1.3.0)
#   ./update-version.sh minor
#
#   # Increment major version (1.2.3 -> 2.0.0)
#   ./update-version.sh major
#
#   # Set a specific version
#   ./update-version.sh 1.5.0
#
#   # Force update to a specific version (even if lower or same)
#   ./update-version.sh --force 1.2.3
#
#   # Dry-run to see what would happen without making changes
#   ./update-version.sh --dry-run minor
#
#   # Show help
#   ./update-version.sh --help
#
# WHAT IT DOES:
#   1. Reads current version from ./order-daemon.php
#   2. Updates version in:
#      - ./order-daemon.php (Version header and ODCM_VERSION constant)
#      - ./README.txt (Stable tag)
#   3. Replaces "@since next" placeholders with the new version
#   4. Creates a git commit with the version as the message
#   5. Creates an annotated git tag (e.g., v1.2.3)
#
# AFTER RUNNING:
#   To push the versioned code to the remote repository, run:
#     git push && git push --tags
#
# REQUIREMENTS:
#   - Git must be initialized in the project
#   - ./order-daemon.php must exist with a Version header
#   - ./README.txt must exist with a Stable tag
#
# ============================================================================

show_instructions() {
    echo "Usage: $0 [-f|--force] [-n|--dry-run] <version|major|minor|patch>"
    echo "Options:"
    echo "  -f, --force   Force update even if the new version is the same or lower"
    echo "  -n, --dry-run Do everything except creating git commits and tags"
    echo "  -h, --help    Show these instructions"
    echo "Examples:"
    echo "  $0 patch"
    echo "  $0 1.2.3"
    echo "  $0 --force 1.2.3"
    echo "  $0 --dry-run minor"
    echo "  $0 --help"
}

get_current_version() {
    local current_version=""
    current_version=$(grep -Eo 'Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "$file_name" | awk '{print $2}')

    if [[ -z "$current_version" ]]; then
        echo "Could not find current version in $file_name."
        exit 1
    fi

    echo "Current version: $current_version"
    echo "$current_version"
}

get_incremented_version() {
    local current_version="$1"
    local target_segment="${2:-patch}" # accepted: major|minor|patch

    IFS='.' read -r -a version_parts <<<"$current_version"

    local major="${version_parts[0]:-0}"
    local minor="${version_parts[1]:-0}"
    local patch="${version_parts[2]:-0}"

    case "$target_segment" in
        major)
            major=$((major + 1))
            minor=0
            patch=0
            ;;
        minor)
            minor=$((minor + 1))
            patch=0
            ;;
        patch)
            patch=$((patch + 1))
            ;;
        *)
            echo "Invalid target segment: $target_segment" >&2
            return 1
            ;;
    esac

    echo "$major.$minor.$patch"
}

# Detect GNU vs BSD sed for in-place editing
setup_sed_inplace() {
    if sed --version >/dev/null 2>&1; then
        SED_INPLACE=(-i)       # GNU sed
    else
        SED_INPLACE=(-i '')    # BSD/macOS sed (empty backup extension)
    fi
}

update_version_and_commit() {
    local file_name="$1"
    local current_version="$2"
    local requested_version="$3"
    local force="${4:-false}"
    local dry_run="${5:-false}"

    echo "Updating version from $current_version to $requested_version..."

    sed -E "${SED_INPLACE[@]}" \
        "s/(Version:)[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+/\1 $requested_version/g" "$file_name"

    sed -E "${SED_INPLACE[@]}" \
        "s/(define\([[:space:]]*'ODCM_VERSION',[[:space:]]*')[0-9]+\.[0-9]+\.[0-9]+(')/\1$requested_version\2/g" "$file_name"

    sed -E "${SED_INPLACE[@]}" \
        "s/(Stable tag:)[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+/\1 $requested_version/g" "./README.txt"

    # Replace common placeholders used before release
    # Matches: @since next
    while IFS= read -r -d '' file; do
        sed -E "${SED_INPLACE[@]}" \
            -e "s/@since([[:space:]]+)next/@since\1$requested_version/g" \
            "$file"
    done < <(find . -type f \( -name "*.php" -o -name "*.js" -o -name "*.css" \) -print0)

    if [[ "$dry_run" == true ]]; then
        echo "[dry-run] Skipping git add/commit and tag creation."
        echo "[dry-run] Would have created commit with message: $requested_version"
        echo "[dry-run] Would have created tag: v$requested_version"
        return 0
    fi

    git add -A
    git commit --allow-empty -m "$requested_version"

    tag="v$requested_version"
    if [[ "$force" == true ]]; then
        echo "Forcing tag $tag to point to the new commit."
        git tag -a -f "$tag" -m "$tag"
    else
        echo "Adding version tag $tag."
        git tag -a "$tag" -m "$tag"
    fi
}

main() {
    local file_name="./order-daemon.php"

    local current_version=""
    current_version=$(grep -Eo 'Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "$file_name" | awk '{print $2}')

    if [[ -z "$current_version" ]]; then
        echo "Could not find current version in $file_name."
        exit 1
    else
        echo "Current version: $current_version"
    fi

    if [[ $# -eq 0 ]]; then
        show_instructions
        exit 1
    fi

    local requested_version=""
    local force=false
    local dry_run=false

    while [[ $# -gt 0 ]]; do
        case "$1" in
            -h|--help)
                show_instructions
                exit 1
                ;;
            -f|--force)
                force=true
                shift
                ;;
            -n|--dry-run)
                dry_run=true
                echo "Dry-run mode enabled: commits and tags will NOT be created."
                shift
                ;;
            *)
                if [[ -z "$requested_version" ]]; then
                    requested_version="$1"
                    shift
                else
                    echo "Unknown argument: $1"
                    show_instructions
                    exit 1
                fi
                ;;
        esac
    done

    setup_sed_inplace

    if [[ -z "$requested_version" ]]; then
        show_instructions
        exit 1
    fi

    local normalized_version=""

    if [[ "$requested_version" =~ ^(major|minor|patch)$ && -n "$current_version" ]]; then
        normalized_version="$(get_incremented_version "$current_version" "$requested_version")"
        echo "Bumping $requested_version version."
    else
        normalized_version="$requested_version"
    fi

    if ! [[ "$normalized_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "Invalid version: $requested_version"
        exit 1
    fi

    if [[ "$force" == true ]]; then
        echo "Forcing version update."
        update_version_and_commit "$file_name" "$current_version" "$normalized_version" true "$dry_run"
        exit 0
    fi

    if [[ "$current_version" == "$normalized_version" ]]; then
        echo -e "Current version ($current_version) is the same as new version ($normalized_version).\nNo update needed."
        exit 0
    elif [[ "$(echo -e "$normalized_version\n$current_version" | sort -V | head -n 1)" == "$normalized_version" ]]; then
        echo -e "New version ($normalized_version) is less than current version ($current_version).\nVersion must be incremented."
        exit 1
    else
        update_version_and_commit "$file_name" "$current_version" "$normalized_version" false "$dry_run"
        exit 0
    fi
}

main "$@"
