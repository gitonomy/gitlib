UPGRADE FROM 1.x to 2.0
=======================

gitlib 2.0 runs every git operation through a driver: `CliDriver`, the
default, runs the `git` binary like 1.x did, and the experimental
`LibGit2Driver` runs libgit2 in-process (see
["Choosing a driver"](doc/repository.md#choosing-a-driver)).

Most of the API of 1.x that 2.0 replaces keeps working, deprecated: each
call triggers a deprecation (`E_USER_DEPRECATED`, through
`symfony/deprecation-contracts`) naming its replacement, and 3.0 removes
them. Log the deprecations of your application to find them. The other
changes, listed below too, break code that relies on them.

Upgrade to 1.7 first, which requires PHP 8.1: it has most of the
replacements, and triggers the deprecations of the API they replace (see
[Supporting 1.x and 2.x](#supporting-1x-and-2x)).

Requirements
------------

*   PHP 8.4 or later is required.
*   git 1.8.4 or later is required by `CliDriver`, the default driver: before,
    git simplifies the log of a range filtered by path differently, and it
    can't count commits (`Log::count()`) before 1.7.2.
*   `symfony/process` must be `^6.4.33`, `^7.4.5` or `^8.1`: the older
    versions escape the arguments wrongly on Windows (CVE-2024-51736,
    CVE-2026-24739).
*   `symfony/string` `^7.4.6` or `^8.0.6`, `symfony/deprecation-contracts`
    `^2.5` or `^3`, and `psr/log` `^1.1`, `^2.0` or `^3.0` are new
    dependencies.
*   Repositories using SHA-256 hashes are refused, with an
    `UnsupportedOperationException`, by both drivers.

Supporting 1.x and 2.x
----------------------

gitlib 1.7 has the API of 2.0 that can be added without breaking 1.x, and
deprecates what 2.0 replaces or breaks: `new Repository($dir, $workingDir,
$driver)` with a `CliDriver` of the same arguments, the static methods of
`Repository` creating repositories, `Diff::parse($rawDiff, $repository)`,
`Repository::getDiff($from, $to)`, `FileChange::getDiffLines()`, iterating
a `Tree`, `Log::withLimit()`, `Branch::isMergedInto()`,
`Blame::getHunks()`... Fix the deprecations of 1.7, then require
`^1.7 || ^2.0`: a library supporting PHP 8.1 to 8.3 gets 1.7 there, and 2.0
from PHP 8.4, with the same code.

Before:

```php
$repository = new Repository($gitDir, ['working_dir' => $workingDir, 'logger' => $logger]);
$diff = Diff::parse($rawDiff);
$diff->setRepository($repository);
```

After, with 1.7 and 2.0:

```php
use Gitonomy\Git\Driver\Cli\CliDriver;

$repository = new Repository($gitDir, $workingDir, new CliDriver(logger: $logger));
$diff = Diff::parse($rawDiff, $repository);
```

1.7 breaks nothing of 1.x, so:

*   Pass by position the arguments whose name changes in 2.0 (see
    [Extending gitlib](#extending-gitlib)): 1.7 keeps the names of 1.x. So
    do the arguments 1.7 adds to `Repository::getDiff()`, `getBlame()`,
    `Diff::parse()`, `Diff::fromArray()` and `File::fromArray()`: their
    signature leaves them out, not to break the classes overriding these
    methods.
*   `ReferenceBag::createBranch()` and `createTag()` keep a revision name as
    the hash of the reference they return, like 1.x: pass a `Revision` to
    get the hash of its commit with both versions.
*   The submodules of trees are `CommitReference` objects, which extend
    `Submodule` in 1.7: check them with `instanceof Submodule`.
*   `File::toArray()` has no `is_copy` key in 1.7.

What 1.7 can't bring, as it would break 1.x, behaves differently with
both versions:

*   With a range of lines, `Blame::getLines()` and `getLine()` number the
    lines from 1 in 1.7, like the file in 2.0: use `Line::getNumber()`, or
    `Blame::getHunks()`, numbered like the file in 1.7 too.
*   1.7 doesn't read the references again after a write: open a new
    `Repository` to read them, with both versions. `getReferences(true)` is
    deprecated in 1.7 too.
*   The classes `final` in 2.0 are only `@final` in 1.7: see
    [Testing code using gitlib](#testing-code-using-gitlib).
*   The other changes listed below: dates, the stricter parser of diffs,
    the directory git runs in, the exceptions...

Opening a repository
--------------------

*   The second argument of `Repository` is the working directory, when the
    git directory is kept apart from it. An array of options there is
    deprecated: its `working_dir` option is this argument, and its `logger`,
    `command`, `environment_variables` and `process_timeout` options configure
    a `Gitonomy\Git\Driver\Cli\CliDriver`, which `Repository` takes as its last
    argument. They can't be combined with this argument.
*   The `inherit_environment_variables` option has no counterpart in
    `CliDriver`: git inherits the environment variables of PHP anyway, and
    `$_SERVER` holds, under php-fpm, the headers and the credentials of the
    request. Pass the variables git needs to the `environmentVariables`
    argument instead.

    git inherits `$_ENV`, and the variables of `getenv()` that `$_SERVER`
    holds too, like Symfony Process passes them: a variable an application
    only writes in `$_SERVER`, without `putenv()` nor `$_ENV`, which the
    option passed, no longer reaches git. A command line application
    relying on it passes `$_SERVER` itself:

    ```php
    $driver = new CliDriver(environmentVariables: array_filter($_SERVER, is_string(...)));
    ```

    Before:

    ```php
    $repository = new Repository($path, [
        'command' => '/usr/bin/git',
        'logger' => $logger,
        'process_timeout' => 60,
    ]);
    ```

    After:

    ```php
    use Gitonomy\Git\Driver\Cli\CliDriver;
    use Gitonomy\Git\Repository;

    $repository = new Repository($path, driver: new CliDriver(command: '/usr/bin/git', processTimeout: 60, logger: $logger));
    ```

*   The logger is an argument of `CliDriver`, which logs every git command it
    runs, cloning and initializing included: `Repository::setLogger()` and
    `Repository::getLogger()` are deprecated. `LibGit2Driver` runs no command,
    and logs nothing.
*   An unknown option throws an `InvalidArgumentException`, instead of being
    ignored.
*   The `debug` option is deprecated, and setting it to `false` throws an
    `InvalidArgumentException`: see [Errors](#errors).
*   `$path` may be a linked worktree, a submodule or a clone with
    `--separate-git-dir`, whose `.git` is a file.
*   A directory that isn't a repository throws an `InvalidArgumentException`
    right away, instead of a `ProcessException` at the first operation.

Creating repositories
---------------------

*   `Admin` is deprecated: use the static methods of `Repository`. They take
    the URL before the path, have a method for each kind of repository
    instead of the `$bare` argument, whose default was a bare repository, and
    take the driver as last argument:

    | 1.x                                                 | 2.0                                                          |
    |-----------------------------------------------------|--------------------------------------------------------------|
    | `Admin::init($path)`                                | `Repository::initBare($path)`                                |
    | `Admin::init($path, false)`                         | `Repository::init($path)`                                    |
    | `Admin::cloneTo($path, $url)`                       | `Repository::cloneBareFrom($url, $path)`                     |
    | `Admin::cloneTo($path, $url, false)`                | `Repository::cloneFrom($url, $path)`                         |
    | `Admin::cloneBranchTo($path, $url, $branch)`        | `Repository::cloneBareFrom($url, $path, $branch)`            |
    | `Admin::cloneBranchTo($path, $url, $branch, false)` | `Repository::cloneFrom($url, $path, $branch)`                |
    | `Admin::mirrorTo($path, $url)`                      | `Repository::mirrorFrom($url, $path)`                        |
    | `Admin::isValidRepository($url)`                    | `Repository::isValidRepository($url)`                        |
    | `Admin::isValidRepositoryAndBranch($url, $branch)`  | `Repository::isValidRepositoryAndBranch($url, $branch)`      |
    | `$repository->cloneTo($path)`                       | `Repository::cloneBareFrom($repository->getGitDir(), $path)` |
    | `$repository->cloneTo($path, false)`                | `Repository::cloneFrom($repository->getGitDir(), $path)`     |

    Until 3.0, `Admin` keeps working like in 1.x, with `CliDriver` and the
    options of 1.x.

*   `isValidRepositoryAndBranch()` checks the exact name of the branch:
    `release` no longer matches `feature/release`, nor `rel*` `release`.
*   `isValidRepository()` and `isValidRepositoryAndBranch()` read a relative
    path as a path, never as the name of a remote of PHP's working directory,
    and throw a `DriverException` when git can't start, instead of returning
    `false`.

    Before:

    ```php
    $repository = Admin::cloneTo($path, $url, false, ['process_timeout' => 60]);
    ```

    After:

    ```php
    use Gitonomy\Git\Driver\Cli\CliDriver;
    use Gitonomy\Git\Repository;

    $repository = Repository::cloneFrom($url, $path, driver: new CliDriver(processTimeout: 60));
    ```

*   `Repository::cloneTo()` cloned with the driver of the repository: pass it
    to `cloneFrom()` or `cloneBareFrom()` as well.
*   `Admin::cloneRepository()` is deprecated, with no replacement for the
    arguments of `git clone` other than `--bare`, `--branch` and `--mirror`:
    run `git clone` yourself, then open the repository with
    `new Repository($path)`.

References
----------

*   `Repository::getReferences()` reads the references again after every
    write gitlib makes (creating or deleting a reference, a checkout, a merge,
    `run()`...): passing `true` to read them again is deprecated. Open a new
    `Repository` to see the writes of other processes. A `Reference` object
    keeps the commit it pointed to when it was read.
*   `Reference::getCommitHash()` returns the commit an annotated tag points
    to, instead of the hash of the tag object: `ReferenceBag::resolve()`,
    `resolveTags()` and `Commit::resolveReferences()` find annotated tags.
*   `ReferenceBag::createBranch()` and `createTag()` throw an
    `InvalidArgumentException` when the reference exists, instead of moving
    it, and take a `Revision` or a revision name as second argument, instead
    of a commit hash only.
*   `ReferenceBag::update()` is deprecated: use `Branch::moveTo()`.

    Before:

    ```php
    $repository->getReferences()->update(new Branch($repository, 'refs/heads/main', $hash));
    ```

    After:

    ```php
    $repository->getReferences()->getBranch('main')->moveTo($hash);
    ```

*   `Branch::isMergedTo()` is deprecated: use `isMergedInto()`, which takes
    the destination as a `Revision` or a revision name, without a default
    (`'master'` before) nor `$compareOnlyWithRemote`.
*   Passing arguments to `Commit::getIncludingBranches()` is deprecated: it
    returns the local and remote branches, which `Branch::isLocal()` and
    `isRemote()` filter.

    Before:

    ```php
    $branches = $commit->getIncludingBranches(true, false);
    ```

    After:

    ```php
    use Gitonomy\Git\Reference\Branch;

    $branches = array_filter($commit->getIncludingBranches(), static fn (Branch $branch): bool => $branch->isLocal());
    ```

*   `ReferenceBag::getFirstBranch()` returns `null` instead of `false` when
    there is no branch, and only branches.
*   `Repository::getHead()` returns a `Reference\Branch` or a `Commit`.
    `HEAD` pointing to another reference, like a tag, which git allows,
    throws a `ReferenceNotFoundException`, where 1.x returned the reference:
    `getHeadCommit()` still returns its commit.
*   The getters of `Reference\Tag` (`getTaggerName()`, `getTaggerEmail()`,
    `getTaggerDate()`, `getMessage()`, `getSubjectMessage()`,
    `getBodyMessage()`, `getGpgSignature()`) return `null` instead of `false`
    for a lightweight tag. The tagger is `null` for an annotated tag without
    one too, where gitlib threw. `getGpgSignature()` returns `null` for an
    unsigned tag instead of throwing, and finds X.509 and SSH signatures too.
*   A branch whose name ends with `HEAD` is listed: only the `HEAD` of a
    remote is left out.
*   Moving or deleting a symbolic reference, like `refs/remotes/origin/HEAD`,
    changes it, instead of the branch it points to.
*   `ReferenceBag::delete()` throws an `InvalidArgumentException` for a name
    outside `refs/`: `HEAD`, which `CliDriver` deleted, leaving no
    repository, or a short name, like `master`, which did nothing.

History
-------

*   `Log::setOffset()` and `setLimit()` are deprecated: `withOffset()` and
    `withLimit()` return another `Log`.
*   `count($log)` returns the number of commits the log iterates, after its
    offset and up to its limit; `countCommits()` still counts them all.
*   A negative offset or limit throws an `InvalidArgumentException`.
*   The revisions of a log are names, ranges (`a..b`, `a...b`), names to
    exclude (`^a`) or `--all`: any other option of `git log` throws an
    `InvalidArgumentException`, instead of being passed to git. An unknown
    revision throws a `ReferenceNotFoundException` naming it.
*   `Log::getDiff()` is deprecated: it ignored the paths of the log. Use
    `Repository::getDiff()`.

Diffs
-----

*   `Repository::getDiff()` takes the commits to compare: `$from` and `$to`.
    Calling it with one argument is deprecated.

    | 1.x                                | 2.0                                                              |
    |------------------------------------|------------------------------------------------------------------|
    | `$repository->getDiff('a..b')`     | `$repository->getDiff('a', 'b')`                                 |
    | `$repository->getDiff(['a', 'b'])` | `$repository->getDiff('a', 'b')`                                 |
    | `$repository->getDiff('a...b')`    | `$repository->getDiff($repository->getMergeBase('a', 'b'), 'b')` |
    | `$repository->getDiff('a')`        | `$repository->getWorkingCopy()->getDiff('a')`                    |

*   The diff of a merge commit, `Commit::getDiff()`, is against its first
    parent, like GitHub shows it, with both drivers.
*   `Diff`, `Diff\File` and `Diff\FileChange` get their repository and their
    changes when constructed: `Diff::setRepository()`,
    `File::setRepository()` and `File::addChange()` are deprecated.
    `Diff::parse()` and `fromArray()` take the repository to read blobs from.
*   `FileChange::getDiffLines()` returns the lines of a hunk as
    `Diff\DiffLine` objects, with `getType()`, a `Diff\DiffLineType`, and
    `getContent()`: `FileChange::getLines()`, which returns them as arrays,
    is deprecated, as the `FileChange::LINE_*` constants and
    `FileChange::getCount()`, replaced by `getAdditions()` and
    `getDeletions()`. The constructor of `FileChange` takes `DiffLine`
    objects: arrays are deprecated.

    Before:

    ```php
    foreach ($change->getLines() as [$type, $content]) {
        if (FileChange::LINE_ADD === $type) {
            // ...
        }
    }
    ```

    After, with 1.7 and 2.0:

    ```php
    use Gitonomy\Git\Diff\DiffLineType;

    foreach ($change->getDiffLines() as $line) {
        if (DiffLineType::Addition === $line->getType()) {
            // ... $line->getContent()
        }
    }
    ```

*   `File::isChangeMode()`, `isDelete()` and `getAnchor()` are deprecated:
    use `isModeChange()`, `isDeletion()`, and
    `substr($file->getNewIndex(), 0, 12)`.
*   `File::getOldIndex()` and `getNewIndex()` return `null` for a missing
    hash, never an empty string; `getName()` returns a `string`.
*   `File::getOldBlob()` and `getNewBlob()` throw a `LogicException` without
    repository, and a `RuntimeException` without hash.
*   `Diff::parse()` throws an `InvalidArgumentException` for a diff without
    its raw information, deprecated since 1.6.0: parse the output of
    `git diff --raw --patch`, with `-z` or not. It reads the names of the
    files from the raw information: names with spaces are no longer split at
    the wrong place.
*   It reads the status of a file, a creation, a modification, a deletion,
    a rename..., from its raw line (`A`, `M`, `D`, `R100`...), instead of
    the headers of the patch (`new file mode`...): a diff written by hand
    where they disagree gives another result. Like git, the raw line
    separates the status and the name with a TAB: spaces throw a
    `RuntimeException`. Generate the diffs of your tests with git, with
    `git diff --raw --patch --full-index`, instead of writing them.
*   During a merge conflict, `WorkingCopy::getDiffPending()` and
    `getDiffStaged()` leave the unmerged files out, where they threw, and
    `WorkingCopy::getDiff()` compares them, conflict markers included, to the
    revision, like `git diff HEAD`.

Blame
-----

*   `Repository::getBlame()` takes the first and the last lines to blame:
    a range as a string (`'3,5'`, `'3,+3'`) is deprecated, and a regular
    expression is no longer supported.
*   `Blame::getLines()` and `getLine()` number the lines like the file, from
    the first blamed line, instead of from 1.
*   `Blame\Line::getNumber()` and `getOriginalNumber()` return the number of
    the line in the file and in the commit that changed it, as integers:
    `Line::getLine()` is deprecated.
*   `Blame::getGroupedLines()` is deprecated: `getHunks()` returns
    `Blame\Hunk` objects.

Trees
-----

*   Iterating a `Tree` gives its entries by name, `TreeEntry` objects with
    `getName()`, `getMode()` and `getObject()`; `getBlobs()`, `getTrees()`
    and `getSubmodules()` return them by kind. `getEntries()`,
    `getBlobEntries()` and `getTreeEntries()`, which return them as arrays
    (`[$mode, $object]`), are deprecated.

    Before:

    ```php
    foreach ($tree->getEntries() as $name => [$mode, $object]) {
        // ...
    }
    ```

    After, with 1.7 and 2.0:

    ```php
    foreach ($tree as $name => $entry) {
        // ... $entry->getMode(), $entry->getObject()
    }
    ```

*   `CommitReference` is deprecated: it is `Submodule`. So is
    `Tree::getCommitReferenceEntries()`: use `getSubmodules()`.
*   `Tree::resolvePath()` throws an `InvalidArgumentException` explaining
    why a path doesn't resolve, a path through a submodule included, which
    threw an `UnexpectedValueException`, and resolves `/` to the tree.

Working copy
------------

*   `WorkingCopy::checkout()` takes a `Revision` or a revision name: a local
    `Branch` object attaches `HEAD` to the branch, instead of detaching it.
    Its second argument, a branch to create, is deprecated: use
    `checkoutNewBranch($branch, $startPoint)`.
*   `WorkingCopy::checkout()` throws a `ReferenceNotFoundException` for a
    name that no revision has, with both drivers: the name of a remote
    branch alone, like `feature` for `origin/feature`, created the local
    branch, and a path, like `README.md`, discarded its changes.

    Before:

    ```php
    $workingCopy->checkout('feature');
    ```

    After:

    ```php
    $workingCopy->checkoutNewBranch('feature', 'origin/feature');
    ```

Commits, trees and blobs
------------------------

*   `Repository::getCommit()` takes a full hash. `getTree()` and `getBlob()`
    take a hash, abbreviated or not, instead of any revision: use
    `getRevision($name)->getCommit()` for a name.
*   Reading an object of another type throws a `ReferenceNotFoundException`
    ("The object ... is not a tree."): `getTree()` read the tree of a commit,
    and `getBlob()` threw a `ProcessException`.
*   The subject of a commit or a tag is its first paragraph, on one line, and
    its body what follows, like `git log --format=%s` and `%b`: a message
    without an empty line after its first line has no body, where its second
    line was lost.
*   `Commit::getShortMessage()` and `getFixedShortHash()` are deprecated: use
    `symfony/string` (`u($commit->getSubjectMessage())->truncate(50, '...')`)
    and `substr()`.
*   `Blob::getMimetype()` returns the mimetype alone (`text/plain`, instead
    of `text/plain; charset=us-ascii`), and throws instead of returning
    `false`.
*   `Repository` keeps the objects it returns only while your application
    uses them: memory no longer grows with every commit read.

Errors
------

*   A failing git operation always throws, a
    `Gitonomy\Git\Exception\ProcessException` with `CliDriver`. Nothing
    returns `null` or empty data instead anymore: `Repository::run()` returns
    a `string`, and `Repository::getHead()` and `Repository::getHeadCommit()`
    never return `null`.

    Before:

    ```php
    $repository = new Repository($path, ['debug' => false]);

    if (null === $output = $repository->run('fetch', ['--all'])) {
        // handle the failure
    }
    ```

    After:

    ```php
    $repository = new Repository($path);

    try {
        $output = $repository->run('fetch', ['--all']);
    } catch (ProcessException $e) {
        // handle the failure
    }
    ```

*   Failures are no longer logged at the `error` level: the exception reports
    them, and the logger gets their error output at the `debug` level. Alerts
    based on `error` logs must rely on exceptions instead.
*   A missing revision, reference or object throws a
    `ReferenceNotFoundException`, with both drivers: it extends
    `Gitonomy\Git\Exception\InvalidArgumentException`, still an
    `\InvalidArgumentException`, and takes a whole message instead of a name.
*   A timeout throws a `Gitonomy\Git\Exception\ProcessTimedOutException`,
    and the other failures of Symfony Process a `DriverException`: no
    exception of Symfony or of PHP leaks anymore, not even as the previous
    exception, as they hold the command line.
*   `ProcessException` extends the new
    `Gitonomy\Git\Exception\DriverException`, itself a `RuntimeException`:
    catching `RuntimeException` or `GitExceptionInterface` still works.
*   An unknown revision throws a `ReferenceNotFoundException`, an
    `InvalidArgumentException`, where `Repository::getDiff()`, `getBlame()`
    or `WorkingCopy::checkout()` threw a `ProcessException`: a
    `catch (RuntimeException)` doesn't catch it anymore. Catch
    `GitExceptionInterface`, which every exception of gitlib implements.
*   The message of a `ProcessException` is one line, with the error of git:
    the first line of its error output starting with `fatal:` or `error:`,
    after the warnings, or its first line: `The git command "clone" failed
    (exit 128): fatal: ...`, or `The git command "diff" failed (exit 1).`
    when git writes no error, but for the conflict of a merge, which git
    writes on its output (`CONFLICT (content): ...`). The command line, the
    rest of the error output and the output are no longer in the message:
    `getErrorOutput()` and `getOutput()` return the outputs of git. Its
    constructor takes the git command as second argument, optional, and
    reads the exit code and the outputs of the `Process`: a test double of
    `Process` must return them.
*   When git can't start, a missing binary for instance, `CliDriver` throws
    a `DriverException` instead of a `ProcessException`, with a message of
    one line, without the command line: `The git command "clone" could not
    start: ...`.
*   These methods throw instead of returning a fallback value:
    *   `Commit::getIncludingBranches()` threw nothing and returned `[]` when git
        failed;
    *   `ReferenceBag` (`Repository::getReferences()`) only returns an empty bag
        for a repository without references, and throws for any other failure.
*   gitlib no longer throws `Gitonomy\Git\Exception\UnexpectedValueException`:
    catch `InvalidArgumentException` for `Tree::resolvePath()`.
*   `Commit::getShortHash()` throws a `ReferenceNotFoundException` for an
    unknown commit, instead of a `ProcessException`.
*   `Hooks` throws a `RuntimeException` when a hook exists already, or can't
    be written, instead of a `LogicException` or a silent failure, and
    creates the hooks directory. `remove()` of a hook that doesn't exist
    throws an `InvalidArgumentException`, like `get()`, still a
    `LogicException`.
*   The `Admin` methods throw a `ProcessException`, still a
    `RuntimeException`, with the first line of git's error output in its
    message, or a `DriverException` when git can't start.
*   An argument holding a NUL byte throws an `InvalidArgumentException`,
    where PHP threw a `ValueError`: git and libgit2 read their arguments as C
    strings, which end at the first NUL byte.
*   A revision, a reference, a hash or a URL starting with a dash throws an
    `InvalidArgumentException`, with both drivers: git read it as an option,
    `getRevision('--output=/path')` writing a file, or
    `isValidRepository('--upload-pack=...')` running a command. A path may
    start with a dash.
*   An empty URL throws an `InvalidArgumentException`, with both drivers.
*   An `ext::` URL throws an `InvalidArgumentException`, with both drivers:
    git runs its command, by default before git 2.12. So does an `fd::` URL,
    which makes git talk through a file descriptor it inherits from PHP.
*   An ssh URL whose host or user starts with a dash, even encoded (`%2D`),
    like `ssh://-oProxyCommand=.../repository` or
    `ssh://-oProxyCommand=...@host/path`, or a `git://` URL whose host does,
    throws an `InvalidArgumentException`, with both drivers: git before 2.14.1
    passes it to ssh, or to the command of `core.gitProxy`, which reads it as
    an option.
*   `Hooks` throws an `InvalidArgumentException` for a name that isn't the one
    of a file of the hooks directory, like `../config` or `.hidden`.
*   The messages of exceptions, their traces (`#[\SensitiveParameter]`) and
    the logs hide the credentials of URLs
    (`https://***@example.com/repository.git`). `getErrorOutput()` and
    `getOutput()` of `ProcessException` don't.
*   Every message is a sentence ending with a dot.

Git configuration
-----------------

*   `CliDriver` sets back to git's defaults the configuration of the user that
    changes what git prints, what a read returns, or the upstream of a new
    branch: colors, columns, `branch.autoSetupMerge`,
    `branch.autoSetupRebase`, `branch.sort`, `log.follow`,
    `log.showSignature`, and for diffs `diff.algorithm`, `diff.context`,
    `diff.external`, `diff.orderFile`, `diff.submodule`, the prefixes, the
    textconv filters... Before, they made reads throw or return other data: a
    log of a path following its renames with `log.follow`, for instance. See
    ["Git configuration"](doc/repository.md#git-configuration).
*   `CliDriver` ignores the replacements of objects (`git replace`,
    `refs/replace/`), like `LibGit2Driver`: `run()` and `runProcess()` too.
    Run git yourself to read them.
*   `CliDriver` ignores `core.fsmonitor`, whose command git runs to read the
    working tree: `run()` and `runProcess()` too.

Dates
-----

*   `Commit::getAuthorDate()`, `Commit::getCommitterDate()` and
    `Reference\Tag::getTaggerDate()` return a `\DateTimeImmutable` instead of
    a `\DateTime`. Code that modifies the returned date must use the returned
    value of `modify()`, `setTimezone()`...
*   These dates are at the UTC offset of their author, committer or tagger,
    like `git log` shows them (`2016-08-17T12:00:00+02:00`), instead of in
    UTC. The instant doesn't change: comparisons and `getTimestamp()` return
    the same results, but `format()` returns the local time of the author,
    committer or tagger. To keep UTC, convert the date:

    Before:

    ```php
    $date = $commit->getAuthorDate()->format('Y-m-d H:i');
    ```

    After:

    ```php
    $date = $commit->getAuthorDate()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i');
    ```

Extending gitlib
----------------

*   These classes are `final`: `Admin`, `Blame`, `Blob`, `Commit`,
    `Diff\File`, `Log`, `Parser\DiffParser`, `PushReference`,
    `Reference\Branch`, `Reference\Stash`, `Reference\Tag`, `ReferenceBag`,
    `Repository`, `Tree`, and the exceptions `LogicException`,
    `ProcessException`, `ReferenceNotFoundException` and
    `UnexpectedValueException`.
*   These classes are `final readonly`: `Blame\Line`, `Diff\Diff`,
    `Diff\FileChange`, `Hooks`, `RevisionList`, `WorkingCopy`.
*   Every method has native parameter and return types. A class extending
    `Parser\ParserBase`, which remains extendable, must declare the same
    types, and must not redeclare its properties.
*   The constructors of `Commit`, `Tree`, `Blob`, `Revision`, `Reference`,
    `Reference\Tag` and `ReferenceBag` are internal: get these objects from
    `Repository` (`getCommit()`, `getTree()`, `getBlob()`, `getRevision()`,
    `getReferences()`), which returns the same commit, tree or blob while your
    application uses it, and reads the references again after its writes.
    The constructor of `Reference` requires the commit hash. Extending
    `Revision` or `Reference` isn't supported anymore, and won't be possible
    in 3.0: `Reference` declares `getName()`, abstract.
*   With named arguments, some parameters are renamed:
    `Repository::__construct()` `$options` (`$workingDir`), `getBlame()`
    `$lineRange` (`$firstLine`), `getDiff()` `$revisions` (`$from`),
    `ReferenceBag::createBranch()` and `createTag()` `$commitHash`
    (`$revision`), `resolve()`, `resolveBranches()` and `resolveTags()`
    `$hash` (`$commit`), and `PushReference::__construct()` `$reference`
    (`$fullname`).
*   To change how gitlib runs git, for example in tests, implement
    `Gitonomy\Git\Driver\DriverInterface` and pass it as the driver of
    `Repository`, instead of extending `Repository`: see
    ["Using your own driver"](doc/repository.md#using-your-own-driver).
    gitlib may add methods to the interfaces of the drivers in a minor
    version of 2.x.
*   `Commit::__construct()` takes a `Gitonomy\Git\Driver\Data\CommitData`
    instead of an array, and `Commit::setData()` is internal.
*   `Gitonomy\Git\Util\StringHelper` is removed, with its configurable
    encoding: use `symfony/string`. Logs are always read in UTF-8.
*   The parsers of the git output moved to `Gitonomy\Git\Driver\Cli\Parser`,
    and are `final` and internal, used by `CliDriver` only, except
    `Parser\DiffParser` and `Parser\ParserBase`. `ParserBase` loses
    `consumeGPGSignature()` and `consumeMergeTag()`, and gets
    `consumeNameEmailDate()`, `parseSignature()` and `signatureDate()`.
    `CommitParser` is merged into `LogParser`, and their results are objects
    of `Gitonomy\Git\Driver\Data`: `LogParser::$commits` (instead of `$log`),
    `TagParser::$data`, `ReferenceParser::$references`, `TreeParser::$entries`
    and `BlameParser::$lines`. `TreeParser` parses `git ls-tree -z`, and
    `BlameParser` no longer takes a `Repository`. `ParserBase::parse()`
    takes a `string`.
*   Objects serialized with 1.x (a `Commit` or a `Diff` in a cache...) can't
    be unserialized: clear such caches. `Diff::toArray()` and
    `Diff::fromArray()` remain compatible.

Testing code using gitlib
-------------------------

The classes `final` in 2.0 can't be doubled: `createMock()` of PHPUnit,
Prophecy and Mockery refuse them ("Class "Gitonomy\Git\WorkingCopy" is
declared "final" and cannot be doubled", "could not reflect class
Gitonomy\Git\WorkingCopy as it is marked final"). Instead, from the
simplest:

1.  Construct the value objects: `Diff`, `Diff\File`, `Diff\FileChange`
    and `Diff\DiffLine` have public constructors. `new Diff([new File(...)],
    '')` works with 1.x too.
2.  Parse the real output of git: `Diff::parse()` of
    `git diff --raw --patch --full-index` (see [Diffs](#diffs)).
3.  Use a real repository, in a temporary directory:
    `Repository::init($path)`, or `git init` with 1.x before 1.7. A
    `WorkingCopy` needs one.
4.  Implement `Gitonomy\Git\Driver\DriverInterface`, and pass it as the
    driver of `Repository`: see
    ["Using your own driver"](doc/repository.md#using-your-own-driver).

Data returned
-------------

*   File names in diffs (`Diff\File`) and tree entries (`Tree`) are returned
    in UTF-8, instead of git's quoted form (`"caf\303\251.txt"`) for names
    with non-ASCII or special characters.
*   `Blame\Line::getContent()` no longer starts with a TAB.
*   `Commit::getDiff()` lists the files of a root commit, instead of
    returning an empty diff.
*   A commit read through `Repository::getCommit()` has its message as
    `git log` prints it, like a commit of a `Log` already had: without blank
    lines around it, nor whitespace at the end of its lines. It also comes
    re-encoded to UTF-8 when the commit has an `encoding` header, instead of
    throwing.
*   `Reference\Tag::isSigned()` returns `false` for a lightweight tag.
*   `PushReference::isForce()` returns `true` for histories without a common
    ancestor, where the constructor threw. `PushReference` runs git when
    `isForce()` needs it, not when constructed. `getReference()`,
    `getRevision()`, `isCreate()` and `isDelete()` are deprecated: use
    `getFullname()`, `getRange()`, `isCreation()` and `isDeletion()`, named
    like the ones of `Diff\File`. The `$reference` argument of its
    constructor is named `$fullname`.
*   `Blob::getContent()` only reads blobs, where `git cat-file -p` also
    printed trees and commits.
*   `WorkingCopy::getUntrackedFiles()` and `RevisionList::getAll()` return
    lists, without gaps in their keys.
*   `Diff\File::toArray()` has a new `is_copy` key.
*   `Diff::getRawDiff()` of a diff gitlib reads returns the output of
    `git diff --raw -z --patch`, NUL bytes included, with the options gitlib
    sets, instead of the text of `--raw --patch`; with `LibGit2Driver`, an
    empty string.

Other changes
-------------

*   `Repository::run()` and `runProcess()` run git in the working directory
    of the repository, or its git directory when it is bare, instead of the
    current directory of PHP. Relative paths, in the arguments of `run()`
    and in the paths of a log, are relative to the repository. When PHP runs
    in a subdirectory of the repository, the output of the commands relative
    to the current directory changes too, and so does their default scope:

    | `run()` in `repository/sub`  | 1.x               | 2.0                           |
    |------------------------------|-------------------|-------------------------------|
    | `ls-files`                   | `in-sub.txt`      | `root.txt`, `sub/in-sub.txt`  |
    | `status --short`             | ` M ../root.txt`  | ` M root.txt`                 |
    | `rev-parse --show-prefix`    | `sub/`            | (empty)                       |

    Nothing changes when PHP runs out of the repository.
*   `Hooks::set()` makes hooks executable by their owner only (`0700`,
    instead of `0777`): change their permissions if another user runs them.
*   `Repository::shell()` is deprecated: it ignored the driver. Run
    `git shell` with Symfony Process instead.
*   `Hooks::has()` returns `true` for a symbolic link to a missing file:
    `get()` throws a `RuntimeException`, as it can't read it, `set()` and
    `setSymlink()` refuse it, as a hook that exists already, and `remove()`
    removes the link.
*   `Repository::getSize()` is deprecated: `getSizeInBytes()` returns bytes,
    instead of kilobytes. Neither follows links: a link in the git directory,
    to a hook not deployed yet for instance, counts for nothing, where
    `getSize()` counted the file it points to, and threw for a missing one.
