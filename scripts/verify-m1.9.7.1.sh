#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

printf '%s\n' '=== M1.9.7.1 late-fault audit baseline hotfix regression ==='
"$SCRIPT_DIR/verify-m1.9.7.sh"

printf '%s\n' 'M1.9.7.1 late-fault audit baseline hotfix verification completed successfully.'
