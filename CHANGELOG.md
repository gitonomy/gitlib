# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

Changes since [v1.6.0](https://github.com/gitonomy/gitlib/releases/tag/v1.6.0).

### Features

- Add `WorkingCopy::merge()` to support `git merge`: accepts a `Commit`, `Reference` or
  string revision, plus optional extra CLI args (e.g. `['--no-ff']`), mirroring the existing
  `checkout()` method. ([#248](https://github.com/gitonomy/gitlib/pull/248), fixes #118)
- Add `Repository::runProcess()` to expose stderr from successful commands: `run()` only
  ever returned stdout, even on success, but some git commands (e.g. `push`) write
  meaningful output to stderr even when they succeed. `run()` now delegates to it and keeps
  its exact original behavior.
  ([#247](https://github.com/gitonomy/gitlib/pull/247), fixes #205)
- Support git's mnemonic diff prefixes (`diff.mnemonicPrefix`) in the diff parser: enabling
  this option replaces the default `a/`/`b/` prefixes with context-specific ones
  (`c/`/`i/`/`o/`/`w/`), which used to raise a `RuntimeException` in tools like GrumPHP that
  enable it. ([#245](https://github.com/gitonomy/gitlib/pull/245), fixes #114)
- Support `"copy from"`/`"copy to"` in the diff parser: diffs produced with copy detection
  enabled (e.g. `diff.renames = copies`) used to fail to parse. `File` gains a new `isCopy()`
  flag, and `isRename()` no longer reports `true` for copies, since a copy's source file
  still exists afterward.
  ([#244](https://github.com/gitonomy/gitlib/pull/244), fixes #14)

### Bug fixes

- Fix relative paths not resolving against the repository in `Repository::run()`: the git
  process spawned by `run()` never had its working directory set, so commands such as
  `apply` resolved paths against the calling script's cwd instead of `--work-tree`, failing
  when run from outside the repository directory.
  ([#249](https://github.com/gitonomy/gitlib/pull/249), fixes #67)
- Fix diff parsing for dirty submodules without an index line: a submodule whose working
  tree changed but still points at the same commit is reported by git without an `index `
  line, which made the parser choke on the next hunk.
  ([#246](https://github.com/gitonomy/gitlib/pull/246), fixes #165)
- Fix bugs and hardening issues found by a code audit:
  ([#239](https://github.com/gitonomy/gitlib/pull/239))
  - `ReferenceBag::getFirstBranch()` could return a non-branch reference, or throw, when no
    branch existed.
  - `Tag::getBodyMessage()` dropped or truncated a line for annotated tags using the
    standard `subject\n\nbody` convention.
  - `Repository::run()` can return `null` on failure when `debug=false`; several call sites
    (`Tag::isAnnotated()`, `PushReference::isForce()`, `Blob::getContent()`, ...) did not
    handle that case and produced wrong data or crashed. All call sites now check for `null`.
  - `Repository::shell()` interpolated the repository path into the shell command without
    escaping it; it is now passed through `escapeshellarg()`.
  - `Hooks::set()` created hook scripts world-writable (`0777`); they are now created
    `0700`.
  - Fixed the `isSuccessFul()` casing typo in `Admin`.

### Minor

- Replace `Gitonomy\Git\Util\StringHelper` with `symfony/string`'s `CodePointString`, which
  covers the same UTF-8-aware `strlen`/`substr`/`strpos`/`strrpos` operations.
  **Breaking:** `StringHelper` is removed, along with its configurable encoding
  (`getEncoding()`/`setEncoding()`); the library now consistently targets `UTF-8`.
  ([#252](https://github.com/gitonomy/gitlib/pull/252))
- Modernize for PHP 8.4+, Symfony 6.4/7.4/8.1+ and PHPUnit 12: full property/parameter/return
  type coverage, `readonly`/`final` where applicable, PHPUnit 12 attributes, and new
  php-cs-fixer/PHPStan/Castor tooling.
  **Breaking:** the minimum supported PHP version is now 8.4.
  ([#238](https://github.com/gitonomy/gitlib/pull/238))
- Add a regression test for reading a blob's content in a tree that also contains a
  submodule. ([#243](https://github.com/gitonomy/gitlib/pull/243))
- Replace the network-dependent test fixture (cloned from GitHub on every run) with a local,
  audited git bundle, plus a Console tool to regenerate it.
  ([#241](https://github.com/gitonomy/gitlib/pull/241),
  [#242](https://github.com/gitonomy/gitlib/pull/242))
- CI: bump `actions/checkout` to v7 to fix a Node 20 deprecation warning.
  ([#240](https://github.com/gitonomy/gitlib/pull/240))

## [v1.6.0] - 2025-12-09

_Earlier versions are not documented here. See the
[GitHub releases](https://github.com/gitonomy/gitlib/releases) and
[commit history](https://github.com/gitonomy/gitlib/commits/v1.6.0) for prior changes._

[Unreleased]: https://github.com/gitonomy/gitlib/compare/v1.6.0...HEAD
[v1.6.0]: https://github.com/gitonomy/gitlib/releases/tag/v1.6.0
