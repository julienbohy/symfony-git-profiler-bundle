# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The panel's "Behind upstream" count is now a summary card (shown as "–" without an upstream).
  When nothing is ahead but the upstream is, the "Unpushed commits" section shows a single
  coherent message instead of both "behind" and "up to date"; when both ahead and behind, the
  behind line is worded "Also N commits behind … (as of the last fetch)".

### Fixed

- Commit dates are now rendered as `<time data-convert-to-user-timezone>` elements, so the
  profiler converts them to the viewer's timezone like its own dates (the server-side fallback
  text includes its timezone). The graph date column is widened accordingly.
- A detached HEAD is now shown as "Detached HEAD" (instead of a branch named "HEAD") in the panel
  and the toolbar, and the unpushed section no longer talks about "this branch".
- The toolbar counters no longer touch the branch name: they are now spaced apart.

## [0.3.0] - 2026-11-05

### Added

- Commits **behind** the upstream (↓) in the toolbar and the panel, as of the last fetch.
- "Git commands" panel section listing every git command run for the request, with its duration and
  outcome.
- Optional bundle configuration: `git_binary`, `timeout`, `graph_commit_limit`.
- From 0.3.0, Symfony Flex support via the `symfony/recipes-contrib` recipe: it registers the bundle
  for `dev` and `test` and creates `config/packages/git_profiler.yaml` (options commented out).

### Changed

- Supported Symfony versions are now **6.4**, **7.4** and **8.x**; the end-of-life 7.0–7.3 lines
  are no longer allowed. CI now tests every supported Symfony line (and the lowest dependencies).
- Git is now read through the `git` CLI directly; `gitonomy/gitlib` is no longer a dependency.
  3 to 7 git processes per request instead of about 13, run after the response is sent
  (`lateCollect`), without ever taking git's index lock (`GIT_OPTIONAL_LOCKS=0`, git 2.15+).
  **Requires git 2.11 or later.**

### Fixed

- The commit-graph date column no longer wraps and bleeds out of its row on systems whose
  font renders `YYYY-MM-DD HH:MM` wider than the previous fixed pixel width: the column now
  uses a font-relative width (`ch`) with tabular digits and forbids wrapping.
- The panel now reads projects checked out as a linked git worktree or as a submodule. In
  both cases `.git` is a file (`gitdir: …`) rather than a directory, which made the bundle
  treat the project directory as a bare repository and report "not a Git repository"; the
  `gitdir:` pointer is now resolved before git is run.
- The unpushed files of a branch that diverged from its upstream are now computed from the merge
  base (`@{u}...HEAD`), so changes that only exist upstream are no longer listed as local.
- The inherited `GIT_DIR`, `GIT_WORK_TREE`, `GIT_INDEX_FILE`, `GIT_COMMON_DIR` and `GIT_PREFIX`
  variables are no longer passed to git, so running from a git hook no longer reads another repository.

## [0.2.1] - 2026-08-14

### Fixed

- The release workflow now promotes the CHANGELOG through a short-lived squash-merged pull
  request instead of pushing to `main` directly, which branch protection (pull request required,
  admins included) rejects.

## [0.2.0] - 2026-08-14

### Added

- Commit graph in the profiler panel: the recent commits of `HEAD` and its upstream drawn as an
  SVG graph (lanes, merge and divergence curves), with `HEAD`/upstream badges and hollow dots for
  commits not pushed to the upstream yet.

### Changed

- Exclude development files (`tests/`, `docs/`, `.github/`, and other repository-only files) from the Composer dist archive via `.gitattributes`, so installs pull only the runtime code.

### Fixed

- A commit subject containing the raw field separator byte (0x1F) no longer empties the whole
  unpushed-commits list: the subject is now the last parsed field (kept whole by the explode
  limit) and malformed lines are skipped individually, as already done for the commit graph.

## [0.1.2] - 2026-07-17

### Changed

- Document Packagist installation in the README.

## [0.1.1] - 2026-07-17

### Added

- Web Profiler panel exposing the Git state of the current repository.
- Toolbar showing the current branch with counters for locally modified files and unpushed commits.
- Detailed working-tree changes (staged, unstaged, untracked) with their status.
- List of commits ahead of the upstream branch, with the files they touch.
- Graceful degradation when the directory is not a Git repository or `git` is unavailable.

[Unreleased]: https://github.com/julienbohy/symfony-git-profiler-bundle/compare/0.3.0...HEAD
[0.3.0]: https://github.com/julienbohy/symfony-git-profiler-bundle/releases/tag/0.3.0
