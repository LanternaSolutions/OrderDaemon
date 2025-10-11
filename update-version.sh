#!/bin/bash

show_instructions() {
    echo "Usage: $0 [-f|--force] <version>"
    echo "Options:"
    echo "  -f, --force   Force update even if the new version is the same or lower"
    echo "  -h, --help    Show these instructions"
    echo "Examples:"
    echo "  $0 1.2.3"
    echo "  $0 --force 1.2.3"
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

get_incremented_patch_version() {
    local current_version="$1"
    IFS='.' read -r -a version_parts <<<"$current_version"

    local patch="${version_parts[2]}"
    local incremented_patch=$((patch + 1))

    local incremented_version="${version_parts[0]}.${version_parts[1]}.$incremented_patch"

    echo "$incremented_version"
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

    echo "Updating version from $current_version to $requested_version..."

    sed -E "${SED_INPLACE[@]}" \
        "s/(Version:)[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+/\1 $requested_version/g" "$file_name"

    sed -E "${SED_INPLACE[@]}" \
        "s/(define\([[:space:]]*'ODCM_VERSION',[[:space:]]*')[0-9]+\.[0-9]+\.[0-9]+(')/\1$requested_version\2/g" "$file_name"

    # Replace common placeholders used before release
    # Matches: @since next
    while IFS= read -r -d '' file; do
        sed -E "${SED_INPLACE[@]}" \
            -e "s/@since([[:space:]]+)next/@since\1$requested_version/g" \
            "$file"
    done < <(find . -type f \( -name "*.php" -o -name "*.js" -o -name "*.css" \) -print0)

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

    if [[ "$requested_version" == 'patch' && -n "$current_version" ]]; then
        normalized_version="$(get_incremented_patch_version "$current_version")"
        echo "Bumping patch version."
    else
        normalized_version="$requested_version"
    fi

    if ! [[ "$normalized_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "Invalid version: $requested_version"
        exit 1
    fi

    if [[ "$force" == true ]]; then
        echo "Forcing version update."
        update_version_and_commit "$file_name" "$current_version" "$normalized_version" true
        exit 0
    fi

    if [[ "$current_version" == "$normalized_version" ]]; then
        echo -e "Current version ($current_version) is the same as new version ($normalized_version).\nNo update needed."
        exit 0
    elif [[ "$(echo -e "$normalized_version\n$current_version" | sort -V | head -n 1)" == "$normalized_version" ]]; then
        echo -e "New version ($normalized_version) is less than current version ($current_version).\nVersion must be incremented."
        exit 1
    else
        update_version_and_commit "$file_name" "$current_version" "$normalized_version"
        exit 0
    fi
}

main "$@"