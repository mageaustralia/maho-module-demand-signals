#!/usr/bin/env bash
# Installs the .githooks/ directory as this repo's hooksPath.
# Run once from the repo root after cloning.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
chmod +x .githooks/pre-commit
git config core.hooksPath .githooks
echo "installed: .githooks/pre-commit (blocks em-dash, en-dash, and live-brand-name leaks)"
