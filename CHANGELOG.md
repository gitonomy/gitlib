# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

Changes since [v1.6.0](https://github.com/gitonomy/gitlib/releases/tag/v1.6.0). To upgrade, see
[UPGRADE-2.0.md](UPGRADE-2.0.md): most of the API of 1.x that 2.0 replaces keeps working,
deprecated until 3.0.

### Breaking changes

-   Run every git operation through a driver (`Gitonomy\Git\Driver`): the domain objects call a
    `Driver\RepositoryDriverInterface`, typed (hashes, enums, value objects of `Driver\Data`),
    instead of building processes and parsing their output. A failing operation always throws, a
    missing revision or object a `ReferenceNotFoundException`, a timeout a
    `ProcessTimedOutException`; dates are `\DateTimeImmutable`; names in diffs and trees are
    UTF-8; references are read again after every write gitlib makes, and peel annotated tags;
    the diff of a merge commit is against its first parent; blame lines, tree entries, diff
    lines and logs are typed objects; the subject of a message is its first paragraph. See
    [UPGRADE-2.0.md](UPGRADE-2.0.md) for every change.
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Return the dates of commits and tags at the UTC offset of their author, committer or tagger,
    like `git log` shows them, instead of in UTC: the instant doesn't change, but `format()`
    returns their local time.
-   Require PHP 8.4 or later, Symfony 7.4/8.1+: most classes are `final` (some `readonly`),
    and every method has native types. ([#238](https://github.com/gitonomy/gitlib/pull/238))
-   Remove `Gitonomy\Git\Util\StringHelper`, replaced by `symfony/string`, and its configurable
    encoding: gitlib reads everything in UTF-8. ([#252](https://github.com/gitonomy/gitlib/pull/252))
-   Require git 1.8.4 or later, the oldest version the test suite passes with: it simplifies the
    log of a range filtered by path like the current versions, and `Log::count()` needs git
    1.7.2. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   `Diff::parse()` throws for a diff without its raw information, deprecated since 1.6.0
    ([#229](https://github.com/gitonomy/gitlib/pull/229)): parse the output of
    `git diff --raw --patch`. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   The constructors of `Commit`, `Tree`, `Blob`, `Revision`, the references and `ReferenceBag`
    are internal: get these objects from `Repository`, which returns the same commit, tree or
    blob while the application uses it, and reads the references again after its writes.
    Extending `Revision` or `Reference` isn't supported anymore.
-   `ProcessException` has a message of one line, with the error of git, the first line of its
    error output starting with `fatal:` or `error:`, after the warnings:
    `The git command "clone" failed (exit 128): fatal: ...`. The command line, the rest of the
    error output and the output are no longer in the message: `getErrorOutput()` and
    `getOutput()` return the outputs of git.
-   When git can't start, a missing binary for instance, `CliDriver` throws a `DriverException`
    instead of a `ProcessException`, with a message of one line, without the command line:
    `The git command "clone" could not start: ...`.
-   `WorkingCopy::checkout()` throws a `ReferenceNotFoundException` for a name that no revision has,
    with both drivers: the name of a remote branch alone, like `feature` for `origin/feature`,
    created the local branch. Use `checkoutNewBranch()`.

### Features

-   Add an experimental `Driver\Ffi\LibGit2Driver`, running libgit2 1.9 in-process through PHP's
    FFI extension, as an alternative to the default `Driver\Cli\CliDriver`: pass it as the driver of
    `Repository`. It avoids spawning a `git` process for every operation; see
    ["Choosing a driver"](doc/repository.md#choosing-a-driver) for its requirements, its
    differences with `CliDriver`, and when it is faster. `benchmark.php`, from a clone of gitlib,
    compares both drivers on a repository. With php-fpm, `LibGit2Driver::preload()` keeps libgit2
    loaded from `opcache.preload`. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   `Repository` takes the working directory and the driver as arguments: a `Driver\Cli\CliDriver`
    by default, configured through its constructor (git binary, environment, timeout and logger,
    which gets every command, clones included), or any `Driver\DriverInterface`, to replace it in
    tests; see ["Using your own driver"](doc/repository.md#using-your-own-driver). The driver
    finds the repository: linked worktrees, submodules and reftable repositories open, and a
    directory that isn't a repository throws right away.
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Add static methods to `Repository` creating repositories, `init()`, `initBare()`,
    `cloneFrom()`, `cloneBareFrom()` and `mirrorFrom()`, and checking a repository URL,
    `isValidRepository()` and `isValidRepositoryAndBranch()`; see
    ["Create and access git repositories"](doc/admin.md).
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Add `Repository::getDiff($from, $to)`, `Repository::getMergeBase()`,
    `Repository::getSizeInBytes()` and `WorkingCopy::getDiff()`,
    `WorkingCopy::checkoutNewBranch()`, `Branch::moveTo()` and `Branch::isMergedInto()`,
    `Log::withOffset()` and `withLimit()`, `Blame::getHunks()` and the line numbers of
    `Blame\Line`, `TreeEntry` and `Submodule`, `Diff\DiffLine` and `Diff\DiffLineType`,
    `File::isModeChange()`, `FileChange::getAdditions()` and `getDeletions()`,
    `PushReference::getFullname()` and `getRange()`, and the SSH and X.509 signatures of tags.
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Add `WorkingCopy::merge()` to support `git merge`, fast-forwarding or not, with a
    `FastForward` enum. ([#248](https://github.com/gitonomy/gitlib/pull/248), fixes #118)
-   Add `Repository::runProcess()` to expose stderr from successful commands: `run()` only
    ever returned stdout, even on success, but some git commands (e.g. `push`) write
    meaningful output to stderr even when they succeed. `run()` now delegates to it and keeps
    its exact original behavior.
    ([#247](https://github.com/gitonomy/gitlib/pull/247), fixes #205)
-   Support git's mnemonic diff prefixes (`diff.mnemonicPrefix`) in the diff parser: enabling
    this option replaces the default `a/`/`b/` prefixes with context-specific ones
    (`c/`/`i/`/`o/`/`w/`), which used to raise a `RuntimeException` in tools like GrumPHP that
    enable it. ([#245](https://github.com/gitonomy/gitlib/pull/245), fixes #114)
-   Support `"copy from"`/`"copy to"` in the diff parser: diffs produced with copy detection
    enabled (e.g. `diff.renames = copies`) used to fail to parse. `File` gains a new `isCopy()`
    flag, and `isRename()` no longer reports `true` for copies, since a copy's source file
    still exists afterward.
    ([#244](https://github.com/gitonomy/gitlib/pull/244), fixes #14)

### Deprecations

All are removed in 3.0; see [UPGRADE-2.0.md](UPGRADE-2.0.md).
([#253](https://github.com/gitonomy/gitlib/pull/253))

-   `Admin`, use the static methods of `Repository`, and `Repository::cloneTo()`.
-   The array of options of `Repository` and `Admin` (`working_dir`, `logger`, `command`,
    `environment_variables`, `inherit_environment_variables`, `process_timeout`, `debug`): pass
    the working directory and a `CliDriver` instead; `inherit_environment_variables` has no
    counterpart, pass the variables git needs instead. `Repository::setLogger()` and `getLogger()`.
-   Passing `$reload` to `Repository::getReferences()`, `ReferenceBag::update()`,
    `Branch::isMergedTo()`, and arguments to `Commit::getIncludingBranches()`.
-   Calling `Repository::getDiff()` with one argument, `Log::getDiff()`, `Diff::setRepository()`,
    `File::setRepository()`, `addChange()`, `isChangeMode()`, `isDelete()` and `getAnchor()`,
    `FileChange::getCount()` and its `LINE_*` constants, reading diff lines as arrays.
-   `Log::setOffset()` and `setLimit()`.
-   A range of lines as a string in `Repository::getBlame()`, `Blame::getGroupedLines()` and
    `Blame\Line::getLine()`.
-   `CommitReference`, `Tree::getCommitReferenceEntries()`, reading tree entries as arrays.
-   The branch to create of `WorkingCopy::checkout()`, the arguments of `git merge` of
    `WorkingCopy::merge()`.
-   `Commit::getShortMessage()` and `getFixedShortHash()`, `Repository::shell()`,
    `PushReference::getReference()` and `getRevision()`.
-   `Repository::getSize()`, in kilobytes: use `getSizeInBytes()`.
-   `Exception\UnexpectedValueException`, which gitlib no longer throws.

### Security

-   Refuse a revision, a reference, a hash or a URL starting with a dash, which git read as an
    option: `getRevision('--output=/path')`, `WorkingCopy::checkout()` or `merge()` could write
    a file, and `Repository::isValidRepository('--upload-pack=...')` run a command. A path may
    start with a dash: `init()` and the clones end the options of git with `--`.
-   Refuse an `ext::` URL, like `ext::sh -c ...`, whose command git runs, by default before git
    2.12.
-   Refuse an ssh URL whose host or user starts with a dash, even encoded (`%2D`), like
    `ssh://-oProxyCommand=.../repository`: git before 2.14.1 passes it to ssh, which reads it as an
    option and runs the command (CVE-2017-1000117). A `git://` URL whose host starts with a dash
    is refused too: git passes it to the command of `core.gitProxy`.
-   Hide the credentials of URLs (`https://user:token@host`), a password holding a `#` or a `?`
    included, in the messages of exceptions, in their traces (`#[\SensitiveParameter]`), and in
    the logs. The exceptions of Symfony Process, which hold the command line, no longer leak, not
    even as the previous exception.
-   Refuse a hook name that isn't the one of a file of the hooks directory:
    `Hooks::set('../config', ...)` and `remove('../config')` wrote and deleted the
    configuration of the repository.
-   Fix `Hooks` writing through a symbolic link to a missing file, out of the hooks directory:
    `has()` returned `false` for it, and `set()` followed it.
-   Ignore the replacements of objects (`git replace`, `refs/replace/`) with `CliDriver`, like
    `LibGit2Driver`: whoever could push such a reference to a repository changed the message of a
    commit, or the content of a blob, gitlib read for a hash.
-   Fix `WorkingCopy::checkout()` of a path, like `checkout('README.md')`, discarding the changes
    of the file: a path is no revision, and throws a `ReferenceNotFoundException`.
-   Fix moving or deleting a symbolic reference, which changed the branch it points to instead:
    `ReferenceBag::delete('refs/remotes/origin/HEAD')` deleted `origin/main`.
-   Fix `ReferenceBag::delete('HEAD')` deleting `HEAD`, which left no repository: a name outside
    `refs/` throws an `InvalidArgumentException`.

### Bug fixes

-   Fix the configuration of the user changing what `CliDriver` reads: `color.ui=always`,
    `log.showSignature`, `diff.noprefix`, `diff.external`, `diff.submodule`, textconv filters,
    `column.ui`... made reads throw or return wrong data, and `diff.algorithm`, `diff.context`,
    `diff.orderFile`, `log.follow` or `branch.sort` changed diffs, logs and the branches of a
    commit. gitlib sets them back to git's defaults; see
    ["Git configuration"](doc/repository.md#git-configuration).
-   Fix `Commit::getShortHash()`, `Commit::getDiff()` and `Repository::getDiff()` failing when a
    file of the working tree has the name of the revision they read, which git found ambiguous.
-   Fix logs of thousands of revisions exceeding the limit of the command line: `CliDriver` passes
    them to git on its standard input.
-   Fix `Commit::getDiff()` returning an empty diff for a root commit, and throwing for a merge
    commit (`CliDriver`). ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix `Repository::getDiff()`, `WorkingCopy::getDiffPending()` and `WorkingCopy::getDiffStaged()`
    failing to parse the output of git before 2.16, which writes "..." after abbreviated hashes,
    and throwing during a conflict. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix the names of files with spaces in diffs, split at the wrong place, the hashes of the files
    following a type change, shifted, and hunks of one line, counted as empty.
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix annotated tags never resolving to their commit (`ReferenceBag::resolveTags()`,
    `Commit::resolveReferences()`), tags created or deleted missing from `getTags()`, and
    `getHead()` throwing after a checkout of a new branch: references are read again after
    every write gitlib makes. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix `createBranch()` and `createTag()` moving an existing reference instead of refusing it,
    a branch named "remotes/x" taken for a remote one, and branches whose name ends with "HEAD"
    missing. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix `isValidRepositoryAndBranch()` matching the end of the name of a branch ("release" for
    "feature/release"), and `isValidRepository()` reading a relative path as the name of a remote
    of PHP's working directory, or returning `false` when git can't run.
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix `getTree()` reading the tree of a commit, and arguments holding a NUL byte throwing a
    `ValueError`: they throw a `ReferenceNotFoundException` and an `InvalidArgumentException`.
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix the log of a merge of an unsigned or SSH-signed tag throwing, or swallowing commits up to
    the next PGP signature. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix `Commit::getBodyMessage()` losing the second line of a message without an empty line
    after its subject, tags without tagger throwing, and SSH-signed tags not seen as signed.
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix blame lines numbered from 1 for a range of lines. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix `Hooks` failing silently, in a repository without hooks directory, and writing the hooks
    of a linked worktree where git doesn't run them. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix `Repository::getSize()` throwing for a link to a missing file in the git directory, like
    a hook not deployed yet: it no longer follows links. A file git deletes meanwhile, like a lock
    during a `gc` or a push, no longer makes it throw either.
-   Fix blame lines starting with a TAB character (`Blame\Line::getContent()`): code removing it
    must stop doing so. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix reading a commit whose `encoding` header isn't UTF-8, which threw: its message, author and
    committer are now re-encoded to UTF-8, like `git log` does. ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix file names holding non-ASCII or special characters (tab, quote, backslash) in trees
    (`Tree::getEntries()`), and special characters in diffs, which kept git's C-style quoting.
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   Fix relative paths not resolving against the repository in `Repository::run()`: the git
    process spawned by `run()` never had its working directory set, so commands such as
    `apply` resolved paths against the calling script's cwd instead of `--work-tree`, failing
    when run from outside the repository directory.
    ([#249](https://github.com/gitonomy/gitlib/pull/249), fixes #67)
-   Fix diff parsing for dirty submodules without an index line: a submodule whose working
    tree changed but still points at the same commit is reported by git without an `index `
    line, which made the parser choke on the next hunk.
    ([#246](https://github.com/gitonomy/gitlib/pull/246), fixes #165)
-   Fix bugs and hardening issues found by a code audit:
    ([#239](https://github.com/gitonomy/gitlib/pull/239))
    -   `ReferenceBag::getFirstBranch()` could return a non-branch reference, or throw, when no
        branch existed.
    -   `Tag::getBodyMessage()` dropped or truncated a line for annotated tags using the
        standard `subject\n\nbody` convention.
    -   With the `debug` option set to `false`, several call sites (`Tag::isAnnotated()`,
        `PushReference::isForce()`, `Blob::getContent()`, ...) produced wrong data or crashed
        when git failed.
    -   `Repository::shell()` interpolated the repository path into the shell command without
        escaping it; it is now passed through `escapeshellarg()`.
    -   `Hooks::set()` created hook scripts world-writable (`0777`); they are now created
        `0700`.
    -   Fixed the `isSuccessFul()` casing typo in `Admin`.

### Minor

-   CI tests git 1.8.4 and 2.25.1, analyses the tests and the libgit2 driver with PHPStan,
    without baseline, checks the archive Composer installs, pins the third-party actions by
    commit, and the suite deletes what it creates, in any order.
    ([#253](https://github.com/gitonomy/gitlib/pull/253))
-   The tests run with a git configuration of their own, whatever the developer's
    (`tests/fixtures/home`), which sets what gitlib sets back to git's defaults, and without the
    variables of git of their environment: run from a hook, they wrote the repository of the hook
    (`GIT_DIR`, `GIT_INDEX_FILE`...).
-   Add a regression test for reading a blob's content in a tree that also contains a
    submodule. ([#243](https://github.com/gitonomy/gitlib/pull/243))
-   Replace the network-dependent test fixture (cloned from GitHub on every run) with a local,
    audited git bundle, plus a Console tool to regenerate it.
    ([#241](https://github.com/gitonomy/gitlib/pull/241),
    [#242](https://github.com/gitonomy/gitlib/pull/242))
-   CI: bump `actions/checkout` to v7 to fix a Node 20 deprecation warning.
    ([#240](https://github.com/gitonomy/gitlib/pull/240))

## [v1.6.0] - 2025-12-09

_Earlier versions are not documented here. See the
[GitHub releases](https://github.com/gitonomy/gitlib/releases) and
[commit history](https://github.com/gitonomy/gitlib/commits/v1.6.0) for prior changes._

[Unreleased]: https://github.com/gitonomy/gitlib/compare/v1.6.0...HEAD
[v1.6.0]: https://github.com/gitonomy/gitlib/releases/tag/v1.6.0
