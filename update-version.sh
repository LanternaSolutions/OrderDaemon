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

show_incremented_patch_version() {
    local current_version="$1"
    IFS='.' read -r -a version_parts <<<"$current_version"
    patch_version="${version_parts[2]}"
    patch_version=$((patch_version + 1))

    version_parts[2]="$patch_version"
    new_expected_version="${version_parts[0]}.${version_parts[1]}.${version_parts[2]}"
    echo "Expected new version: $new_expected_version"
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
    local new_version="$3"
    echo "Updating version from $current_version to $new_version..."

    sed -E "${SED_INPLACE[@]}" \
        "s/(Version:)[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+/\1 $new_version/g" "$file_name"

    sed -E "${SED_INPLACE[@]}" \
        "s/(define\([[:space:]]*'ODCM_VERSION',[[:space:]]*')[0-9]+\.[0-9]+\.[0-9]+(')/\1$new_version\2/g" "$file_name"

    git add "$file_name"
    git commit --allow-empty -m "$new_version"

    tag="v$new_version"
    if [[ "$FORCE" == true ]]; then
        echo "Forcing tag $tag to point to the new commit."
        git tag -a -f "$tag" -m "$tag"
    else
        git tag -a "$tag" -m "$tag"
    fi
}

main() {
    # Parse arguments
    FORCE=false
    new_version=""

    if [[ $# -eq 0 ]]; then
        show_instructions
        exit 1
    fi

    while [[ $# -gt 0 ]]; do
        case "$1" in
            -h|--help)
                show_instructions
                exit 1
                ;;
            -f|--force)
                FORCE=true
                shift
                ;;
            *)
                if [[ -z "$new_version" ]]; then
                    new_version="$1"
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

    if [[ -z "$new_version" ]]; then
        show_instructions
        exit 1
    fi

    file_name="./order-daemon.php"
    current_version=$(grep -Eo 'Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "$file_name" | awk '{print $2}')

    if ! [[ "$new_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "Invalid version: $new_version"
        if [[ -n "$current_version" ]]; then
            show_incremented_patch_version "$current_version"
        fi
        exit 1
    fi

    if [[ -z "$current_version" ]]; then
        echo "Could not find current version in $file_name"
        exit 1
    fi

    if [[ "$FORCE" == true ]]; then
        echo "Forcing version update to $new_version (current: $current_version)."
        update_version_and_commit "$file_name" "$current_version" "$new_version"
        exit 0
    fi

    if [[ "$current_version" == "$new_version" ]]; then
        echo -e "Current version ($current_version) is the same as new version ($new_version).\nNo update needed."
        show_incremented_patch_version "$current_version"
        exit 0
    elif [[ "$(echo -e "$new_version\n$current_version" | sort -V | head -n 1)" == "$new_version" ]]; then
        echo -e "New version ($new_version) is less than current version ($current_version) \nVersion must be incremented."
        show_incremented_patch_version "$current_version"
        exit 1
    else
        update_version_and_commit "$file_name" "$current_version" "$new_version"
        exit 0
    fi
}

main "$@"