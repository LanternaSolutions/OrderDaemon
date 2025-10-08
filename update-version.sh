#!/bin/bash

show_instructions() {
    echo "Usage: $0 <version>"
    echo "Example: $0 1.2.3"
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

update_version_and_commit() {
    local file_name="$1"
    local current_version="$2"
    local new_version="$3"
    echo "Updating version from $current_version to $new_version..."
    sed -i -E "s/(Version:)[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+/\1 $new_version/g" $file_name
    sed -i -E "s/(define\('ODCM_VERSION',[[:space:]]*')[0-9]+\.[0-9]+\.[0-9]+(')/\1$new_version\2/g" "$file_name"

    git add $file_name
    git commit -m "Update plugin version from $current_version to $new_version"
    tag="v$new_version"
    git tag -a "$tag" -m "Release $tag"
}

main() {
    if [[ -z "$1" || "$1" == "-h" || "$1" == "--help" ]]; then
        show_instructions
        exit 1
    fi
    new_version="$1"
    file_name="./order-daemon.php"
    current_version=$(grep -Eo 'Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' $file_name | awk '{print $2}')

    if [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        if [[ -z "$current_version" ]]; then
            echo "Could not find current version in $file_name"
            exit 1
        elif [[ "$current_version" == "$new_version" ]]; then
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
    else
        echo "Invalid version: $1"
        show_incremented_patch_version "$current_version"
        exit 1
    fi
}

main "$@"
