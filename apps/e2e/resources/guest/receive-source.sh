#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $# -eq 7 ]] || exit 64
repo=$1; sha=$2; bundle=$3; archive=$4; manifest=$5; deletions=$6; expected_tree=$7
[[ "$repo" == /* && "$sha" =~ ^[0-9a-f]{40}$ && "$expected_tree" =~ ^[0-9a-f]{64}$ ]] || exit 64
for path in "$bundle" "$archive" "$manifest" "$deletions"; do [[ "$path" == /* && -f "$path" ]] || exit 64; done
mkdir -p "$repo"
git -C "$repo" init --quiet
git -C "$repo" bundle verify "$bundle" >/dev/null
mapfile -t bundle_heads < <(git -C "$repo" bundle list-heads "$bundle")
[[ ${#bundle_heads[@]} -eq 1 ]] || exit 65
read -r bundle_sha bundle_ref extra <<< "${bundle_heads[0]}"
[[ -z "${extra:-}" && "$bundle_sha" == "$sha" && "$bundle_ref" =~ ^refs/orbit/e2e-source/[0-9a-f]{32}$ ]] || exit 65
git -C "$repo" fetch --quiet "$bundle" "$bundle_ref:$bundle_ref"
[[ "$(git -C "$repo" rev-parse --verify "$bundle_ref^{commit}")" == "$sha" ]] || exit 65
previous="$repo/.git/orbit-overlay.paths"
validate_overlay_path() {
    local path=$1 component parent=$repo index
    [[ "$path" != /* && "$path" != *'//'* && "$path" != *$'\n'* && "$path" != *$'\r'* ]] || return 1
    IFS='/' read -r -a components <<< "$path"
    [[ ${#components[@]} -gt 0 ]] || return 1
    for component in "${components[@]}"; do
        [[ -n "$component" && "$component" != . && "$component" != .. ]] || return 1
        case "${component,,}" in .git|vendor|node_modules|.env|.env.*|credential|credentials|credentials.*|id_rsa|id_dsa) return 1 ;; esac
    done
    for ((index=0; index < ${#components[@]} - 1; index++)); do
        parent="$parent/${components[$index]}"
        [[ ! -L "$parent" ]] || return 1
    done
}
if [[ -f "$previous" ]]; then
    while IFS= read -r -d '' path; do
        validate_overlay_path "$path" || exit 65
        rm -f -- "$repo/$path"
    done < "$previous"
fi
git -C "$repo" reset --hard --quiet "$sha"
git -C "$repo" clean -ffdq
while IFS= read -r -d '' path; do
    validate_overlay_path "$path" || exit 65
    rm -f -- "$repo/$path"
done < "$deletions"
if [[ -s "$archive" ]]; then tar -C "$repo" --no-same-owner --no-same-permissions -xf "$archive"; fi
while IFS= read -r -d '' path; do validate_overlay_path "$path" || exit 65; done < "$manifest"
cp -- "$manifest" "$previous"
index=$(mktemp)
trap 'rm -f -- "$index"' EXIT
rm -f "$index"
GIT_INDEX_FILE="$index" git -C "$repo" read-tree HEAD
GIT_INDEX_FILE="$index" git -C "$repo" add -A -- .
tree=$(GIT_INDEX_FILE="$index" git -C "$repo" write-tree)
rm -f "$index"
trap - EXIT
tree_hash=$(printf '%s' "$tree" | sha256sum | cut -d ' ' -f 1)
[[ "$tree_hash" == "$expected_tree" ]] || exit 66
printf '{"sha":"%s","tree_hash":"%s"}\n' "$sha" "$tree_hash"
