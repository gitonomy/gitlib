# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

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

[Unreleased]: https://github.com/gitonomy/gitlib/compare/v1.6.0...1.x
[v1.6.0]: https://github.com/gitonomy/gitlib/releases/tag/v1.6.0
