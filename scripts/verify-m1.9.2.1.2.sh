#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
"$SCRIPT_DIR/verify-m1.9.2.1.sh"
printf '%s\n' 'M1.9.2.1.2 PHPUnit Bridge runtime hotfix validated successfully.'
