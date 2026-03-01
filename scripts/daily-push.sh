#!/bin/bash
# Daily auto-push to GitHub for Access100 API
# Commits any changes and pushes to origin/main

REPO_DIR="/home/patrickgartside/dev/Access100/app website"
cd "$REPO_DIR" || exit 1

# Check for changes
if git diff --quiet && git diff --cached --quiet && [ -z "$(git ls-files --others --exclude-standard)" ]; then
    echo "$(date): No changes to push"
    exit 0
fi

# Stage, commit, push
git add -A
git commit -m "Daily sync: $(date '+%Y-%m-%d')"
git push origin main

echo "$(date): Pushed to GitHub"
