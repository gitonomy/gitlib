#!/bin/bash
#
# Guards tests/fixtures/foobar.bundle against unexpected changes: any pull
# request touching it should be reviewed by hand (see CONTRIBUTING.md), but
# this catches the obvious cases automatically:
#   - a corrupted or incomplete bundle
#   - refs that were not in the original fixture
#   - a file that has silently grown well past its expected size
#
# Run it locally with: tests/fixtures/verify-bundle.sh

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")" > /dev/null

BUNDLE="foobar.bundle"
MAX_SIZE_KB=200
ALLOWED_REFS="$(cat bundle-refs.txt)"

echo "== Verifying $BUNDLE =="

VERIFY_OUTPUT="$(git bundle verify "$BUNDLE")"
echo "$VERIFY_OUTPUT"

SIZE_KB=$(( $(stat -c%s "$BUNDLE") / 1024 ))
echo "Size: ${SIZE_KB}KB (limit: ${MAX_SIZE_KB}KB)"
if [ "$SIZE_KB" -gt "$MAX_SIZE_KB" ]; then
    echo "ERROR: $BUNDLE is ${SIZE_KB}KB, which exceeds the ${MAX_SIZE_KB}KB limit." >&2
    echo "If this growth is expected, bump MAX_SIZE_KB in $0 as part of the same PR." >&2
    exit 1
fi

ACTUAL_REFS="$(grep -oE '(refs/[^ ]+|HEAD)$' <<< "$VERIFY_OUTPUT" | sort -u)"
UNEXPECTED_REFS="$(comm -23 <(echo "$ACTUAL_REFS") <(sort -u <<< "$ALLOWED_REFS"))"

if [ -n "$UNEXPECTED_REFS" ]; then
    echo "ERROR: $BUNDLE contains refs that are not in the allow-list:" >&2
    echo "$UNEXPECTED_REFS" >&2
    echo "If this is expected, update tests/fixtures/bundle-refs.txt as part of the same PR." >&2
    exit 1
fi

echo "OK: bundle structure and size are within expected bounds."
