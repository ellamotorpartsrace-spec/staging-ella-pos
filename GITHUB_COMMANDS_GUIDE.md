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

```bash
git add -A
```

Stages all changes in the folder, including new files, edited files, and deleted files.

```bash
git diff --cached --stat
```

Shows a short summary of the staged changes before committing.

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