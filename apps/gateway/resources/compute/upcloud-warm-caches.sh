#!/bin/sh
# Warms the Composer and npm caches of the orbit account for the UpCloud sandbox base template
# (ADR 0204). The Gateway uploads each Project's manifest and lock files to
# /var/tmp/orbit-warm/projects/<slug>/. No Project code runs: Composer skips scripts and plugins,
# and npm skips lifecycle scripts. Each install runs in a temporary directory that is then deleted.
# A failed install is recorded and the next one continues. Run it as root.
set -u
root=/var/tmp/orbit-warm
results="$root/results"
work="$root/work"
: > "$results"
rm -rf "$work" "$root/node"
install -d -o orbit -g orbit -m 0700 "$work"

as_orbit() {
    timeout 1200 runuser -u orbit -- env HOME=/home/orbit PATH="$path" COMPOSER_NO_INTERACTION=1 "$@"
}

record() {
    printf '%s %s %s\n' "$1" "$2" "$3" >> "$results"
    if [ "$3" = failed ]; then
        echo "$1 $2 failed:"
        tail -n 20 "$4"
    fi
}

# npm comes from the official Node 22 build, verified against its published checksum, and is removed afterwards.
path=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
node=
if ls "$root"/projects/*/package-lock.json >/dev/null 2>&1; then
    dist=https://nodejs.org/dist/latest-v22.x
    if curl -fsSL --max-time 60 "$dist/SHASUMS256.txt" -o "$root/SHASUMS256.txt"; then
        line=$(grep -E '  node-v22\.[0-9]+\.[0-9]+-linux-x64\.tar\.xz$' "$root/SHASUMS256.txt" | head -n 1)
        file=${line##* }
        if [ -n "$file" ] && curl -fsSL --max-time 300 "$dist/$file" -o "$root/$file" \
            && (cd "$root" && printf '%s\n' "$line" | sha256sum -c --status) \
            && mkdir "$root/node" && tar -xJf "$root/$file" -C "$root/node" --strip-components=1; then
            node="$root/node"
            path="$node/bin:$path"
        fi
        rm -f "$root/$file"
    fi
fi

for dir in "$root"/projects/*/; do
    [ -d "$dir" ] || continue
    slug=$(basename "$dir")
    if [ -f "$dir/composer.lock" ]; then
        target="$work/$slug-composer"
        install -d -o orbit -g orbit -m 0700 "$target"
        install -o orbit -g orbit -m 0600 "$dir/composer.json" "$dir/composer.lock" "$target/"
        if (cd "$target" && as_orbit composer install --no-scripts --no-plugins --no-autoloader \
            --no-progress --ignore-platform-reqs --prefer-dist) > "$target.log" 2>&1; then
            record "$slug" composer ok
        else
            record "$slug" composer failed "$target.log"
        fi
        rm -rf "$target" "$target.log"
    fi
    if [ -f "$dir/package-lock.json" ]; then
        if [ -z "$node" ]; then
            record "$slug" npm unavailable
            continue
        fi
        target="$work/$slug-npm"
        install -d -o orbit -g orbit -m 0700 "$target"
        install -o orbit -g orbit -m 0600 "$dir/package.json" "$dir/package-lock.json" "$target/"
        if (cd "$target" && as_orbit npm ci --ignore-scripts --no-audit --no-fund) > "$target.log" 2>&1; then
            record "$slug" npm ok
        else
            record "$slug" npm failed "$target.log"
        fi
        rm -rf "$target" "$target.log"
    fi
done
rm -rf "$work" "$root/node" "$root/SHASUMS256.txt"
echo "orbit-image: warm"
