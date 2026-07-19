#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
"$SCRIPT_DIR/verify-m1.9.2.1.2.sh"
printf '%s\n' 'M1.9.2.1.3 snapshot reference detachment hotfix validated successfully.'
