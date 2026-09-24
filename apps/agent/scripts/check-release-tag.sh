#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 <tag> <repository>" >&2
    exit 2
fi

tag="$1"
repository="$2"
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
version="${tag#agent-v}"

if [[ "$tag" != "agent-v$version" || ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([+-][0-9A-Za-z.-]+)?$ ]]; then
    echo "Invalid agent release tag: $tag" >&2
    exit 1
fi

manifest_version="$(sed -n 's/^version = "\([^"]*\)"$/\1/p' "$script_dir/../Cargo.toml" | head -n 1)"
if [[ -z "$manifest_version" || "$version" != "$manifest_version" ]]; then
    echo "Tag version $version does not match apps/agent/Cargo.toml version ${manifest_version:-<missing>}" >&2
    exit 1
fi

if gh release view "$tag" --repo "$repository" >/dev/null 2>&1; then
    echo "Release $tag already exists; refusing to replace its assets" >&2
    exit 1
fi

echo "Validated new release $tag for orbit-agent $manifest_version"
