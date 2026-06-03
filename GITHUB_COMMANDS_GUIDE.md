# GitHub Commands Guide

Use this file when you want to manually test, upload, or practice Git commands for this project.

## Check The Current Repository

```bash
git status --short --branch
```

Shows the current branch and changed files in a short format. Run this first before making a commit or pushing to GitHub.

```bash
git remote -v
```

Shows the GitHub repository connected to this folder. Use this to confirm you are pushing to the correct repo.

```bash
git branch --show-current
```

Shows only the current branch name.

```bash
git branch -a
```

Shows all local branches and remote GitHub branches.

```bash
git branch -vv
```

Shows each local branch, its latest commit, and which GitHub branch it tracks.

```bash
git log -1 --oneline
```

Shows the latest commit in short form.

## Get The Latest Update From GitHub

Use this when you want to download the newest version from GitHub into this folder.

```bash
git status --short --branch
git pull --ff-only origin testing
```

`git status --short --branch` checks your current branch and confirms whether you have local changes first.

`git pull --ff-only origin testing` gets the latest update from the GitHub `testing` branch. The `--ff-only` option keeps the update clean and stops if Git needs a manual merge.

If you are not sure which branch you are on, run:

```bash
git branch --show-current
```

If the current branch is not `testing`, switch to it first:

```bash
git switch testing
git pull --ff-only origin testing
```

If Git says you have local changes, check them before pulling:

```bash
git status --short
git diff
```

## Daily Save And Upload Workflow

Use this common flow when you edited files and want to upload them to GitHub:

```bash
git status --short --branch
git add -A
git diff --cached --stat
git commit -m "Describe your change"
git push
```

What each command does:

1. `git status --short --branch` checks your branch and changed files.
2. `git add -A` stages all new, edited, and deleted files.
3. `git diff --cached --stat` previews what will be committed.
4. `git commit -m "Describe your change"` saves the staged files into a commit.
5. `git push` uploads the commit to GitHub.

If this is the first push for a new branch, use:

```bash
git push -u origin testing
```

After that, normal `git push` is enough.

## Create A Testing Branch

```bash
git switch -c testing
```

Creates a new branch named `testing` and switches to it.

If the branch already exists locally, use:

```bash
git switch testing
```

This switches to the existing `testing` branch.

## Stage And Review Changes

Stage one specific file:

```bash
git add path/to/file.php
```

Stage all changes:

```bash
git add -A
```

Stages all changes in the folder, including new files, edited files, and deleted files.

```bash
git diff --cached --stat
```

Shows a short summary of the staged changes before committing.

```bash
git diff --cached
```

Shows the full staged file changes before committing.

```bash
git status --short --branch
```

Checks what is staged and confirms the branch before committing.

## Commit Changes

```bash
git commit -m "Describe your change"
```

Creates a commit from the staged changes. Replace `Describe your change` with a short message explaining what you changed.

Example:

```bash
git commit -m "Upload Ella POS folder for testing"
```

## Push To GitHub

```bash
git push -u origin testing
```

Uploads the local `testing` branch to GitHub and sets it to track `origin/testing`.

After the branch is already tracking GitHub, future uploads can use:

```bash
git push
```

Push the current branch to GitHub even if you are not sure of the branch name:

```bash
git push -u origin HEAD
```

## Clone A Fresh Copy

Use this when you want a brand-new folder from GitHub instead of updating the current folder.

From `C:\xampp\htdocs`:

```bash
cd C:\xampp\htdocs
git clone https://github.com/ellamotorpartsrace-spec/ERP_SYSTEM.git ella-pos-new
cd ella-pos-new
git switch testing
```

This creates a new folder named `ella-pos-new`.

## Upload The Whole Folder Itself

If you are inside `C:\xampp\htdocs\ella-pos`, normal Git commands upload the files inside the folder. GitHub will show those files at the top level of the branch.

If you want GitHub to show the folder name itself, like this:

```text
ella-pos/
```

use the parent folder as the Git repository root.

Example from `C:\xampp\htdocs`:

```bash
cd C:\xampp\htdocs
git init
git remote add origin https://github.com/ellamotorpartsrace-spec/ERP_SYSTEM.git
git switch -c testing-whole-folder
git add ella-pos
git commit -m "Upload ella-pos folder"
git push -u origin testing-whole-folder
```

This uploads the `ella-pos` folder itself, not only the files inside it.

If the Git repository already exists in the parent folder, skip `git init` and `git remote add origin ...`.

## Ignore Files

Add a file or folder to `.gitignore` when you want Git to ignore it:

```bash
echo path/to/file.php >> .gitignore
```

Example:

```bash
echo config/local.php >> .gitignore
git status --short
```

If the file is already tracked by Git, stop tracking it but keep it on your computer:

```bash
git rm --cached config/local.php
git commit -m "Ignore local config file"
```

## Delete Commands

Use delete commands carefully. Always check your branch and status first:

```bash
git status --short --branch
git branch -a
```

Delete a local branch:

```bash
git branch -d branch-name
```

This deletes a local branch only if Git thinks it was safely merged.

Force delete a local branch:

```bash
git branch -D branch-name
```

This deletes a local branch even if it was not merged. Use this only when you are sure you do not need that branch locally.

Delete a GitHub branch:

```bash
git push origin --delete branch-name
```

This deletes the branch from GitHub, but it does not delete your local branch.

Delete a file from Git tracking and from the folder:

```bash
git rm path/to/file
git commit -m "Remove unused file"
git push
```

Delete a file from Git tracking but keep it on your computer:

```bash
git rm --cached path/to/file
git commit -m "Stop tracking local file"
git push
```

This is useful for files that should stay local, like machine-specific config files.

## Undo Local Changes

Use these carefully. Always check status first:

```bash
git status --short --branch
```

Unstage files that were added with `git add` but keep the file edits:

```bash
git restore --staged path/to/file.php
```

Unstage everything but keep the file edits:

```bash
git restore --staged .
```

Discard local edits in one file:

```bash
git restore path/to/file.php
```

Discard all local edits in tracked files:

```bash
git restore .
```

Do not use discard commands unless you are sure you do not need those local changes.

## Manual Testing Workflow

Use this flow when testing a new branch upload:

```bash
git status --short --branch
git remote -v
git switch -c testing
git add -A
git diff --cached --stat
git commit -m "Upload project for testing"
git push -u origin testing
git status --short --branch
git branch -vv
```

If `testing` already exists, use this instead:

```bash
git status --short --branch
git remote -v
git switch testing
git add -A
git diff --cached --stat
git commit -m "Update testing branch"
git push
git status --short --branch
git branch -vv
```

## Useful Debug Commands

```bash
git config --get-regexp "^(remote\\.|branch\\.)"
```

Shows remote and branch tracking settings. Use this when Git is pushing to the wrong repo or the branch tracking looks broken.

```bash
git ls-files --others --exclude-standard
```

Shows untracked files that are not ignored by Git.

```bash
git ls-files
```

Shows files already tracked by Git.

## Good Habits

1. Always run `git status --short --branch` before committing.
2. Always run `git remote -v` before pushing if you are unsure about the connected GitHub repo.
3. Use clear commit messages.
4. Use testing branches before changing the main branch.
5. Run `git branch -vv` after pushing to confirm the branch tracks GitHub.

-single dash  = short option, usually one letter
--double dash = long option, usually a word

============================================================
HIGHLIGHT: COPY BASH COMMANDS USED FOR LATEST PUSH
============================================================

```bash
git init
git status --short --branch --untracked-files=all
git add ella-pos/views/shopee/allocation.php
git commit -m "MSG UPDATE"
git push origin testing
git pull --ff-only origin testing

git checkout -b name of branch = delete branch
git merge testing

git add .
git pull 
git push


git remote add origin https://github.com/ellamotorpartsrace-spec/ERP_SYSTEM.git
```

============================================================
UPDATE MAIN BRANCH FROM TESTING
============================================================

```bash
git status --short --branch
git switch testing
git pull --ff-only origin testing
git switch main
git pull --ff-only origin main
git merge testing
git push origin main
git status --short --branch
```
