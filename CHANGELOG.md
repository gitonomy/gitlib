# Changelog

All notable changes to this project are documented in this file.

## [v1.7.1] - 2026-11-07

Changes since [v1.7.0](https://github.com/gitonomy/gitlib/releases/tag/v1.7.0).

1.7.1 has the security fixes of 2.0 that break nothing of 1.6. Where 2.0 refuses every option,
1.7.1 only refuses what reads or writes a file: the other options of `git log` as revisions stay
deprecated, and work. The checks of a URL keep returning `false`, and clones keep throwing a
`RuntimeException`, where 2.0 throws an `InvalidArgumentException`.

### Security

-   Refuse `--output` as a revision, which made git write a file: `getRevision('--output=/path')`
    and everything reading a revision through it, `getLog()`, `getDiff()`, `getMergeBase()` or
    `Branch::moveTo()` for instance.
-   Refuse the options of `git blame` reading or writing a file as a revision:
    `getBlame('--contents=/path', 'README.md')` returned the lines of any file, and the error of
    `--ignore-revs-file` or `-S` printed its first line.
-   Refuse a URL starting with a dash, which git read as an option:
    `Admin::isValidRepository('--upload-pack=...')` ran the command in a repository with a remote,
    `Admin::isValidRepositoryAndBranch()` and the clones when the second argument was a
    repository. A path may start with a dash: `init()` and the clones end the options of git with
    `--`, as `cloneFrom($url, '--upload-pack=...')` ran the command too.
-   Refuse a hash starting with a dash in `ReferenceBag::createBranch()` and `createTag()`:
    `createBranch('foo', '-d')` deleted the branch. So do the branch and the start point of
    `WorkingCopy::checkoutNewBranch()`.
-   Refuse an `fd::` URL, which makes git talk through a file descriptor it inherits from PHP, like
    the socket of a database, and wait on it.
-   Refuse an ssh URL whose host or user starts with a dash, even encoded (`%2D`), like
    `ssh://-oProxyCommand=.../repository`: git before 2.14.1 passes it to ssh, which reads it as an
    option and runs the command (CVE-2017-1000117). A `git://` URL whose host starts with a dash
    is refused too: git passes it to the command of `core.gitProxy`.
-   Hide the credentials of URLs (`https://user:token@host`), a password holding a `#` or a `?`
    included, in the messages of `ProcessException` and of failed clones, in the logs, and in the
    messages of the exceptions of Symfony Process, a timeout for instance, which keep their class.
    From PHP 8.2, the traces hide them too (`#[\SensitiveParameter]`).
-   Refuse a hook name leading out of the hooks directory: `Hooks::set('../../file', ...)`,
    `remove('../config')` and `get('../config')` wrote, deleted and read files of the repository.
    The names staying in the directory, like `post-receive.d/notify`, still work.
-   Fix `Hooks` writing through a symbolic link to a missing file, out of the hooks directory:
    `has()` returned `false` for it, and `set()` followed it.
-   Ignore `core.fsmonitor`: git ran the command the configuration of a repository names there to
    read the working tree, and even to diff a commit.
-   Fix deleting a symbolic reference, which deleted the branch it points to instead:
    `ReferenceBag::delete('refs/remotes/origin/HEAD')` deleted `origin/main`.
-   Fix `ReferenceBag::delete('HEAD')` deleting a detached `HEAD`, which left no repository: it
    throws an `InvalidArgumentException`.
-   Require `symfony/process` 5.4.51, 6.4.33, 7.3.11, 7.4.5 or 8.0.5 and later: the older versions
    escape the arguments wrongly on Windows (CVE-2024-51736, CVE-2026-24739).

### Not fixed

These fixes of 2.0 would break code written for 1.6. An application giving gitlib values it can't
trust has to check them itself:

-   `ext::` URLs stay accepted, as an application may use this transport on purpose. git refuses
    them by default since 2.12: before that, `ext::sh -c ...` runs the command.
-   The replacements of objects (`git replace`, `refs/replace/`) stay read, as `git replace --graft`
    relies on them: whoever can push such a reference changes the message of a commit, or the
    content of a blob, gitlib reads for a hash. Set `GIT_NO_REPLACE_OBJECTS` to `1` in the
    `environmentVariables` of `CliDriver` to ignore them.
-   `WorkingCopy::checkout()` still takes a path or an option: `checkout('README.md')` discards the
    changes of the file, and `checkout('--force')` all of them.
-   Moving a symbolic reference, with `Branch::moveTo()` or `ReferenceBag::update()`, still moves
    the branch it points to.

## [v1.7.0] - 2026-10-15

Changes since [v1.6.0](https://github.com/gitonomy/gitlib/releases/tag/v1.6.0).

1.7 prepares the upgrade to 2.0: it has the API of 2.0 that can be added without breaking 1.x,
and deprecates what 2.0 replaces or breaks. Code without deprecations with 1.7 runs with 2.0
too, so a library can require `^1.7 || ^2.0` with a single code path. The exceptions, the
changes of 2.0 1.7 can't bring, are listed in the `UPGRADE-2.0.md` of 2.0.

1.7 breaks nothing of 1.6: the parameters keep their names, the arguments added to the methods
of classes that aren't final are read with `func_get_arg()`, commented out in their signature
(`@psalm-variadic` for Psalm), and gitlib keeps calling the deprecated methods that a subclass
overrides. Deprecations are triggered through `symfony/deprecation-contracts`, a new
dependency.

1.7 requires PHP 8.1, for the `DiffLineType` enum: on PHP 8.0, Composer keeps 1.6.

### Features

-   `Gitonomy\Git\Driver\Cli\CliDriver` configures git: the binary, the environment variables,
    the timeout and the logger. It takes the arguments of the `CliDriver` of 2.0.
-   `new Repository($dir, $workingDir, $driver)`: the second argument may be the working
    directory, the third one is a `CliDriver`.
-   `Repository::init()`, `initBare()`, `cloneFrom()`, `cloneBareFrom()`, `mirrorFrom()`,
    `isValidRepository()` and `isValidRepositoryAndBranch()` take the URL before the path and a
    `CliDriver` as last argument. Like in 2.0, `isValidRepositoryAndBranch()` checks the exact
    name of the branch, and both read a relative path as a path, never as the name of a remote
    of the working directory of PHP.
-   `Repository::getDiff($from, $to)`, with two arguments, compares two revisions, `Repository::getMergeBase()`
    returns their best common ancestor, `WorkingCopy::getDiff()` compares the working tree with
    a revision. Like in 2.0, they throw a `ReferenceNotFoundException` for an unknown revision,
    as `WorkingCopy::checkoutNewBranch()`.
-   `Diff::parse()`, `Diff::fromArray()` and `File::fromArray()` take the repository to read the
    blobs from, the constructor of `Diff\File` the changes and the repository.
    `File::isModeChange()`, `File::isCopy()`, `FileChange::getAdditions()` and
    `FileChange::getDeletions()`.
-   `FileChange::getDiffLines()` returns the lines of a hunk as `Diff\DiffLine` objects, with
    `getType()`, a `Diff\DiffLineType`, and `getContent()`. The constructor of `FileChange`
    takes `DiffLine` objects.
-   `Repository::getBlame()` and `Blame` take the first and the last lines to blame, as
    integers.
    `Blame::getHunks()` returns `Blame\Hunk` objects, numbered like the file;
    `Blame\Line::getNumber()` and `getOriginalNumber()`.
-   `Log::withOffset()` and `withLimit()` return another log.
-   `Branch::isMergedInto()` and `Branch::moveTo()`. `ReferenceBag::createBranch()` and
    `createTag()` take a `Revision` too.
-   `PushReference::getFullname()`, `getRange()`, `isCreation()` and `isDeletion()`.
-   Iterating a `Tree` gives its entries, `TreeEntry` objects with `getName()`, `getMode()` and
    `getObject()`, by name; `Tree::getBlobs()`, `getTrees()` and `getSubmodules()` return those
    of one type.
-   `Submodule`, with `getHash()`. The submodules of trees are still `CommitReference`
    objects, which extend `Submodule`.
-   `WorkingCopy::checkoutNewBranch()`. `WorkingCopy::checkout()` takes any `Revision`.
-   `Repository::getSizeInBytes()`, which doesn't follow links.

### Deprecations

-   An array of options as second argument of `Repository`, the `debug` option set to `false`,
    `Repository::setLogger()` and `getLogger()`: use the `workingDir` and `driver` arguments.
-   `Admin` and `Repository::cloneTo()`: use the static methods of `Repository`.
-   `Repository::getDiff()` with one argument and `Log::getDiff()`: use
    `Repository::getDiff($from, $to)` or `WorkingCopy::getDiff()`.
-   `Diff::setRepository()`, `File::setRepository()`, `File::addChange()`,
    `File::isChangeMode()`, `File::isDelete()`, `File::getAnchor()` and `FileChange::getCount()`.
-   `FileChange::getLines()`, which returns the lines as arrays, the `FileChange::LINE_*`
    constants, and passing the lines to the constructor of `FileChange` as arrays: use
    `getDiffLines()`, `DiffLineType` and `DiffLine` objects.
-   A range of lines as a string in `Repository::getBlame()` and `Blame`,
    `Blame::getGroupedLines()` and `Blame\Line::getLine()`.
-   `Log::setOffset()`, `setLimit()`, a negative offset or limit, an option of `git log` but
    `--all` as revision, and `count()` of a log whose offset or limit leaves commits out: it
    counts them all, where 2.0 counts the commits the log iterates. Use `countCommits()`.
-   `ReferenceBag::update()`, `Branch::isMergedTo()`, arguments of
    `Commit::getIncludingBranches()`, creating a branch or a tag that exists already, deleting
    a reference by another name than its full name under `refs/`.
-   Passing an argument to `Repository::getReferences()`, to read the references again: open
    a new `Repository`.
-   `PushReference::getReference()`, `getRevision()`, `isCreate()` and `isDelete()`.
-   `CommitReference`. `Tree::getEntries()`, `getBlobEntries()`, `getTreeEntries()` and
    `getCommitReferenceEntries()`, which return the entries as arrays: iterate the tree, or use
    `getBlobs()`, `getTrees()` and `getSubmodules()`.
-   The second argument of `WorkingCopy::checkout()`: use `checkoutNewBranch()`. A local
    `Branch` object, which `checkout()` detaches HEAD on, where 2.0 attaches HEAD to the branch:
    pass its name, or its commit.
-   Passing a revision name to `Repository::getTree()` or `getBlob()` instead of a hash, and
    passing data to the constructor of `Commit`, internal in 2.0.
-   `Repository::getSize()`, `Repository::shell()`, `Commit::getShortMessage()`,
    `Commit::getFixedShortHash()` and `Util\StringHelper`.
-   The parsers that 2.0 moves to its `CliDriver`, all but `DiffParser` and `ParserBase`.
-   The classes `final` in 2.0 are `@final since gitonomy/gitlib 1.7`. The constructors
    internal in 2.0 (`Commit`, `Tree`, `Blob`, `Revision`, `Reference`, `ReferenceBag`) say so.

## [v1.6.0] - 2025-12-09

_Earlier versions are not documented here. See the
[GitHub releases](https://github.com/gitonomy/gitlib/releases) and
[commit history](https://github.com/gitonomy/gitlib/commits/v1.6.0) for prior changes._

[v1.7.1]: https://github.com/gitonomy/gitlib/compare/v1.7.0...v1.7.1
[v1.7.0]: https://github.com/gitonomy/gitlib/compare/v1.6.0...v1.7.0
[v1.6.0]: https://github.com/gitonomy/gitlib/releases/tag/v1.6.0
