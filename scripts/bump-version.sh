#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version_file="${root_dir}/VERSION"
assets_version_file="${root_dir}/assets/version.js"

if [[ ! -f "${version_file}" ]]; then
  echo "Missing VERSION file at ${version_file}" >&2
  exit 1
fi

current_version="$(cat "${version_file}")"
IFS="." read -r major minor patch <<<"${current_version}"

if [[ -z "${major:-}" || -z "${minor:-}" || -z "${patch:-}" ]]; then
  echo "VERSION must be in MAJOR.MINOR.PATCH format." >&2
  exit 1
fi

input="${1:-patch}"
case "${input}" in
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
    if [[ "${input}" =~ ^[0-9]+\\.[0-9]+\\.[0-9]+$ ]]; then
      IFS="." read -r major minor patch <<<"${input}"
    else
      echo "Usage: $(basename "$0") [major|minor|patch|X.Y.Z]" >&2
      exit 1
    fi
    ;;
esac

new_version="${major}.${minor}.${patch}"
printf "%s\n" "${new_version}" > "${version_file}"
printf "window.GLITCHLET_VERSION = \"%s\";\n" "${new_version}" > "${assets_version_file}"

echo "Bumped version to ${new_version}"
