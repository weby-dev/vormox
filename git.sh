#!/bin/bash

clear

echo "=== GitHub Auto Setup ==="

read -p "Enter GitHub Repo URL: " REPO_URL

GIT_USERNAME="weby-dev"
GIT_EMAIL="dev05varshney@gmail.com"

if ! command -v git &> /dev/null
then
    echo "Git not found. Installing..."
    apt update && apt install git -y
fi

git config --global user.name "$GIT_USERNAME"
git config --global user.email "$GIT_EMAIL"
git config --global init.defaultBranch main

if [ ! -d ".git" ]; then
    git init
fi

cat > .gitignore <<EOL
__pycache__/
*.pyc
*.log
.env
venv/
node_modules/
*.db
*.sqlite3
.cache/
tmp/
*.zip
*.tar.gz
*.bak
EOL

if git remote get-url origin &>/dev/null; then
    git remote remove origin
fi

git remote add origin "$REPO_URL"

git add .

git commit -m "Initial commit" || echo "No new changes to commit"

git branch -M main

echo "Pushing to GitHub..."
git push -u origin main

echo ""
echo "Done!"
echo "Repository pushed successfully."
