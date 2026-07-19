#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

"$SCRIPT_DIR/verify-m1.9.4.sh"
printf '%s\n' 'M1.9.4.1 accounting schema enforcement hotfix validated successfully.'
