#!/bin/sh
# 把本分支相对 LibreNMS 某个正式版本改动过的文件复制到 docker/febnms/src/，作为镜像的构建上下文。
# Copies the files this fork changed against a LibreNMS release into docker/febnms/src/,
# the build context of the image. Usage: docker/febnms/build-context.sh 26.11.0
set -eu
version="${1:?usage: $0 <LibreNMS version, e.g. 26.11.0>}"
cd "$(git rev-parse --show-toplevel)"

# The fork does not carry upstream's tags; fetch the release tag when it is missing.
if ! git rev-parse -q --verify "refs/tags/$version" >/dev/null; then
    git fetch --no-tags https://github.com/librenms/librenms.git "refs/tags/$version:refs/tags/$version"
fi

# Only runtime files: tests, docs, CI and this packaging are not part of the image.
set -- . ':(exclude)tests' ':(exclude)doc' ':(exclude).github' ':(exclude)docker' \
    ':(exclude)*.md' ':(exclude)mkdocs.yml'

# Copying files over the official image cannot delete one; refuse instead of shipping it.
deleted="$(git diff --name-only --diff-filter=D "$version" HEAD -- "$@")"
if [ -n "$deleted" ]; then
    echo "The fork deletes files of $version, which this image cannot do:" >&2
    echo "$deleted" >&2
    exit 1
fi

out=docker/febnms/src
rm -rf "$out"
mkdir -p "$out"
git diff --name-only --diff-filter=ACMR "$version" HEAD -- "$@" | while IFS= read -r file; do
    mkdir -p "$out/$(dirname "$file")"
    cp -p "$file" "$out/$file"
done
echo "Copied $(find "$out" -type f | wc -l | tr -d ' ') files changed since $version into $out"
