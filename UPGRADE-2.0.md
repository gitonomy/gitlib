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

Requirements
------------

 * PHP 8.4 or later is required.
 * git 1.8.4 or later is required by `CliDriver`, the default driver: before,
   git simplifies the log of a range filtered by path differently, and it
   can't count commits (`Log::count()`) before 1.7.2.
 * `symfony/process` must be `^6.4`, `^7.4` or `^8.1`.
 * `symfony/string` `^7.4.6` or `^8.0.6`, `symfony/deprecation-contracts`
   `^2.5` or `^3`, and `psr/log` `^1.1`, `^2.0` or `^3.0` are new
   dependencies.
 * Repositories using SHA-256 hashes are refused, with an
   `UnsupportedOperationException`, by both drivers.

Opening a repository
--------------------

 * The second argument of `Repository` is the working directory, when the
   git directory is kept apart from it. An array of options there is
   deprecated: its `working_dir` option is this argument, and its `logger`,
   `command`, `environment_variables` and `process_timeout` options configure
   a `Gitonomy\Git\Driver\Cli\CliDriver`, which `Repository` takes as its last
   argument. They can't be combined with this argument.
 * The `inherit_environment_variables` option has no counterpart in
   `CliDriver`: git inherits the environment variables of PHP anyway, and
   `$_SERVER` holds, under php-fpm, the headers and the credentials of the
   request. Pass the variables git needs to the `environmentVariables`
   argument instead.

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

   $repository = new Repository($path, driver: new CliDriver(command: '/usr/bin/git', processTimeout: 60, logger: $logger));
   ```

 * The logger is an argument of `CliDriver`, which logs every git command it
   runs, cloning and initializing included: `Repository::setLogger()` and
   `Repository::getLogger()` are deprecated. `LibGit2Driver` runs no command,
   and logs nothing.
 * An unknown option throws an `InvalidArgumentException`, instead of being
   ignored.
 * The `debug` option is deprecated, and setting it to `false` throws an
   `InvalidArgumentException`: see [Errors](#errors).
 * `$path` may be a linked worktree or a submodule, whose `.git` is a file.
 * A directory that isn't a repository throws an `InvalidArgumentException`
   right away, instead of a `ProcessException` at the first operation.

Creating repositories
---------------------

 * `Admin` is deprecated: use the static methods of `Repository`. They take
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
 * `isValidRepositoryAndBranch()` checks the exact name of the branch:
   `release` no longer matches `feature/release`, nor `rel*` `release`.
 * `isValidRepository()` and `isValidRepositoryAndBranch()` read a relative
   path as a path, never as the name of a remote of PHP's working directory,
   and throw a `ProcessException` when git can't run, instead of returning
   `false`.

   Before:

   ```php
   $repository = Admin::cloneTo($path, $url, false, ['process_timeout' => 60]);
   ```

   After:

   ```php
   use Gitonomy\Git\Driver\Cli\CliDriver;

   $repository = Repository::cloneFrom($url, $path, driver: new CliDriver(processTimeout: 60));
   ```

 * `Repository::cloneTo()` cloned with the driver of the repository: pass it
   to `cloneFrom()` or `cloneBareFrom()` as well.
 * `Admin::cloneRepository()` is deprecated, with no replacement for the
   arguments of `git clone` other than `--bare`, `--branch` and `--mirror`:
   run `git clone` yourself, then open the repository with
   `new Repository($path)`.

References
----------

 * `Repository::getReferences()` reads the references again after every
   write gitlib makes (creating or deleting a reference, a checkout, a merge,
   `run()`...): passing `true` to read them again is deprecated. Open a new
   `Repository` to see the writes of other processes. A `Reference` object
   keeps the commit it pointed to when it was read.
 * `Reference::getCommitHash()` returns the commit an annotated tag points
   to, instead of the hash of the tag object: `ReferenceBag::resolve()`,
   `resolveTags()` and `Commit::resolveReferences()` find annotated tags.
 * `ReferenceBag::createBranch()` and `createTag()` throw an
   `InvalidArgumentException` when the reference exists, instead of moving
   it, and take a `Revision` or a revision name as second argument, instead
   of a commit hash only.
 * `ReferenceBag::update()` is deprecated: use `Branch::moveTo()`.

   Before:

   ```php
   $repository->getReferences()->update(new Branch($repository, 'refs/heads/main', $hash));
   ```

   After:

   ```php
   $repository->getReferences()->getBranch('main')->moveTo($hash);
   ```

 * `Branch::isMergedTo()` is deprecated: use `isMergedInto()`, which takes
   the destination as a `Revision` or a revision name, without a default
   (`'master'` before) nor `$compareOnlyWithRemote`.
 * Passing arguments to `Commit::getIncludingBranches()` is deprecated: it
   returns the local and remote branches, which `Branch::isLocal()` and
   `isRemote()` filter.

   Before:

   ```php
   $branches = $commit->getIncludingBranches(true, false);
   ```

   After:

   ```php
   $branches = array_filter($commit->getIncludingBranches(), static fn (Branch $branch): bool => $branch->isLocal());
   ```

 * `ReferenceBag::getFirstBranch()` returns `null` instead of `false` when
   there is no branch, and only branches.
 * The getters of `Reference\Tag` (`getTaggerName()`, `getTaggerEmail()`,
   `getTaggerDate()`, `getMessage()`, `getSubjectMessage()`,
   `getBodyMessage()`, `getGpgSignature()`) return `null` instead of `false`
   for a lightweight tag. The tagger is `null` for an annotated tag without
   one too, where gitlib threw. `getGpgSignature()` returns `null` for an
   unsigned tag instead of throwing, and finds X.509 and SSH signatures too.
 * A branch whose name ends with `HEAD` is listed: only the `HEAD` of a
   remote is left out.

History
-------

 * `Log::setOffset()` and `setLimit()` are deprecated: `withOffset()` and
   `withLimit()` return another `Log`.
 * `count($log)` returns the number of commits the log iterates, after its
   offset and up to its limit; `countCommits()` still counts them all.
 * The revisions of a log are names, ranges (`a..b`, `a...b`), names to
   exclude (`^a`) or `--all`: any other option of `git log` throws an
   `InvalidArgumentException`, instead of being passed to git. An unknown
   revision throws a `ReferenceNotFoundException` naming it.
 * `Log::getDiff()` is deprecated: it ignored the paths of the log. Use
   `Repository::getDiff()`.

Diffs
-----

 * `Repository::getDiff()` takes the commits to compare: `$from` and `$to`.
   Calling it with one argument is deprecated.

   | 1.x                                | 2.0                                                              |
   |------------------------------------|------------------------------------------------------------------|
   | `$repository->getDiff('a..b')`     | `$repository->getDiff('a', 'b')`                                 |
   | `$repository->getDiff(['a', 'b'])` | `$repository->getDiff('a', 'b')`                                 |
   | `$repository->getDiff('a...b')`    | `$repository->getDiff($repository->getMergeBase('a', 'b'), 'b')` |
   | `$repository->getDiff('a')`        | `$repository->getWorkingCopy()->getDiff('a')`                    |

 * The diff of a merge commit, `Commit::getDiff()`, is against its first
   parent, like GitHub shows it, with both drivers.
 * `Diff`, `Diff\File` and `Diff\FileChange` get their repository and their
   changes when constructed: `Diff::setRepository()`,
   `File::setRepository()` and `File::addChange()` are deprecated.
   `Diff::parse()` and `fromArray()` take the repository to read blobs from.
 * The lines of a hunk, `FileChange::getLines()`, are `Diff\DiffLine`
   objects, with `getType()`, a `Diff\DiffLineType`, and `getContent()`:
   reading them as arrays is deprecated, as the `FileChange::LINE_*`
   constants and `FileChange::getCount()`, replaced by `getAdditions()` and
   `getDeletions()`.

   Before:

   ```php
   foreach ($change->getLines() as [$type, $content]) {
       if (FileChange::LINE_ADD === $type) {
           // ...
       }
   }
   ```

   After:

   ```php
   use Gitonomy\Git\Diff\DiffLineType;

   foreach ($change->getLines() as $line) {
       if (DiffLineType::Addition === $line->getType()) {
           // ... $line->getContent()
       }
   }
   ```

 * `File::isChangeMode()`, `isDelete()` and `getAnchor()` are deprecated:
   use `isModeChange()`, `isDeletion()`, and
   `substr($file->getNewIndex(), 0, 12)`.
 * `File::getOldIndex()` and `getNewIndex()` return `null` for a missing
   hash, never an empty string; `getName()` returns a `string`.
 * `File::getOldBlob()` and `getNewBlob()` throw a `LogicException` without
   repository, and a `RuntimeException` without hash.
 * `Diff::parse()` throws an `InvalidArgumentException` for a diff without
   its raw information, deprecated since 1.6.0: parse the output of
   `git diff --raw --patch`, with `-z` or not. It reads the names of the
   files from the raw information: names with spaces are no longer split at
   the wrong place.
 * During a merge conflict, `WorkingCopy::getDiffPending()` and
   `getDiffStaged()` leave the unmerged files out, where they threw, and
   `WorkingCopy::getDiff()` compares them, conflict markers included, to the
   revision, like `git diff HEAD`.

Blame
-----

 * `Repository::getBlame()` takes the first and the last lines to blame:
   a range as a string (`'3,5'`, `'3,+3'`) is deprecated, and a regular
   expression is no longer supported.
 * `Blame::getLines()` and `getLine()` number the lines like the file, from
   the first blamed line, instead of from 1.
 * `Blame\Line::getNumber()` and `getOriginalNumber()` return the number of
   the line in the file and in the commit that changed it, as integers:
   `Line::getLine()` is deprecated.
 * `Blame::getGroupedLines()` is deprecated: `getHunks()` returns
   `Blame\Hunk` objects.

Trees
-----

 * `Tree::getEntries()`, `getTreeEntries()`, `getBlobEntries()` and
   `getSubmoduleEntries()` return `TreeEntry` objects, with `getName()`,
   `getMode()` and `getObject()`: reading them as arrays
   (`[$mode, $object]`) is deprecated.
 * `CommitReference` is deprecated: it is `Submodule`. So is
   `Tree::getCommitReferenceEntries()`: use `getSubmoduleEntries()`.
 * `Tree::resolvePath()` throws an `InvalidArgumentException` explaining
   why a path doesn't resolve, and resolves `/` to the tree.

Working copy
------------

 * `WorkingCopy::checkout()` takes a `Revision` or a revision name: a local
   `Branch` object attaches `HEAD` to the branch, instead of detaching it.
   Its second argument, a branch to create, is deprecated: use
   `checkoutNewBranch($branch, $startPoint)`.
 * `WorkingCopy::merge()` takes a `FastForward` instead of arguments of
   `git merge`, deprecated.

   Before:

   ```php
   $workingCopy->merge('feature', ['--no-ff']);
   ```

   After:

   ```php
   use Gitonomy\Git\FastForward;

   $workingCopy->merge('feature', FastForward::Never);
   ```

Commits, trees and blobs
------------------------

 * `Repository::getCommit()` takes a full hash. `getTree()` and `getBlob()`
   take a hash, abbreviated or not, instead of any revision: use
   `getRevision($name)->getCommit()` for a name.
 * Reading an object of another type throws a `ReferenceNotFoundException`
   ("The object ... is not a tree."): `getTree()` read the tree of a commit,
   and `getBlob()` threw a `ProcessException`.
 * The subject of a commit or a tag is its first paragraph, on one line, and
   its body what follows, like `git log --format=%s` and `%b`: a message
   without an empty line after its first line has no body, where its second
   line was lost.
 * `Commit::getShortMessage()` and `getFixedShortHash()` are deprecated: use
   `symfony/string` (`u($commit->getSubjectMessage())->truncate(50, '...')`)
   and `substr()`.
 * `Blob::getMimetype()` returns the mimetype alone (`text/plain`, instead
   of `text/plain; charset=us-ascii`), and throws instead of returning
   `false`.
 * `Repository` keeps the objects it returns only while your application
   uses them: memory no longer grows with every commit read.

Errors
------

 * A failing git operation always throws, a
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

 * Failures are no longer logged at the `error` level: the exception reports
   them, and the logger gets their error output at the `debug` level. Alerts
   based on `error` logs must rely on exceptions instead.
 * A missing revision, reference or object throws a
   `ReferenceNotFoundException`, with both drivers: it extends
   `Gitonomy\Git\Exception\InvalidArgumentException`, still an
   `\InvalidArgumentException`, and takes a whole message instead of a name.
 * A timeout throws a `Gitonomy\Git\Exception\ProcessTimedOutException`,
   and the other failures of Symfony Process a `DriverException`: no
   exception of Symfony or of PHP leaks anymore.
 * `ProcessException` extends the new
   `Gitonomy\Git\Exception\DriverException`, itself a `RuntimeException`:
   catching `RuntimeException` or `GitExceptionInterface` still works.
 * These methods throw instead of returning a fallback value:
   * `Commit::getIncludingBranches()` threw nothing and returned `[]` when git
     failed;
   * `ReferenceBag` (`Repository::getReferences()`) only returns an empty bag
     for a repository without references, and throws for any other failure.
 * `Commit::getShortHash()` throws a `ReferenceNotFoundException` for an
   unknown commit, instead of a `ProcessException`.
 * `Hooks` throws a `RuntimeException` when a hook exists already, doesn't
   exist, or can't be written, instead of a `LogicException` or a silent
   failure, and creates the hooks directory.
 * The `Admin` methods throw a `ProcessException`, still a
   `RuntimeException`, with git's error output as message.
 * An argument holding a NUL byte throws an `InvalidArgumentException`,
   where PHP threw a `ValueError`: git and libgit2 read their arguments as C
   strings, which end at the first NUL byte.
 * A revision, a reference, a hash or a URL starting with a dash throws an
   `InvalidArgumentException`, with both drivers: git read it as an option,
   `getRevision('--output=/path')` writing a file, or
   `isValidRepository('--upload-pack=...')` running a command. A path may
   start with a dash.
 * `Hooks` throws an `InvalidArgumentException` for a name that isn't the one
   of a file of the hooks directory, like `../config` or `.hidden`.
 * The messages of exceptions and the logs hide the credentials of URLs
   (`https://***@example.com/repository.git`). `getErrorOutput()` and
   `getOutput()` of `ProcessException` don't.
 * Every message is a sentence ending with a dot.

Git configuration
-----------------

 * `CliDriver` sets back to git's defaults the configuration of the user that
   changes what git prints, or what a read returns: colors, columns,
   `branch.sort`, `log.follow`, `log.showSignature`, and for diffs
   `diff.algorithm`, `diff.context`, `diff.external`, `diff.orderFile`, the
   prefixes, the textconv filters... Before, they made reads throw or return
   other data: a log of a path following its renames with `log.follow`, for
   instance. See ["Git configuration"](doc/repository.md#git-configuration).

Dates
-----

 * `Commit::getAuthorDate()`, `Commit::getCommitterDate()` and
   `Reference\Tag::getTaggerDate()` return a `\DateTimeImmutable` instead of
   a `\DateTime`. Code that modifies the returned date must use the returned
   value of `modify()`, `setTimezone()`...

Extending gitlib
----------------

 * These classes are `final`: `Admin`, `Blame`, `Blob`, `Commit`,
   `Diff\File`, `Log`, `Parser\DiffParser`, `PushReference`,
   `Reference\Branch`, `Reference\Stash`, `Reference\Tag`, `ReferenceBag`,
   `Repository`, `Tree`, and the exceptions `LogicException`,
   `ProcessException`, `ReferenceNotFoundException` and
   `UnexpectedValueException`.
 * These classes are `final readonly`: `Blame\Line`, `Diff\Diff`,
   `Diff\FileChange`, `Hooks`, `RevisionList`, `WorkingCopy`.
 * Every method has native parameter and return types. A class extending
   `Revision`, `Reference` or `Parser\ParserBase`, which remain extendable,
   must declare the same types, and must not redeclare their properties.
   The constructor of `Reference` requires the commit hash.
 * To change how gitlib runs git, for example in tests, implement
   `Gitonomy\Git\Driver\DriverInterface` and pass it as the driver of
   `Repository`, instead of extending `Repository`: see
   ["Using your own driver"](doc/repository.md#using-your-own-driver).
 * `Commit::__construct()` takes a `Gitonomy\Git\Driver\Data\CommitData`
   instead of an array, and `Commit::setData()` is internal.
 * `Gitonomy\Git\Util\StringHelper` is removed, with its configurable
   encoding: use `symfony/string`. Logs are always read in UTF-8.
 * The parsers of the git output moved to `Gitonomy\Git\Driver\Cli\Parser`
   and are `final`, except `Parser\DiffParser` and `Parser\ParserBase`.
   `CommitParser` is merged into `LogParser`, and their results are objects
   of `Gitonomy\Git\Driver\Data`: `LogParser::$commits` (instead of `$log`),
   `TagParser::$data`, `ReferenceParser::$references`, `TreeParser::$entries`
   and `BlameParser::$lines`. `TreeParser` parses `git ls-tree -z`, and
   `BlameParser` no longer takes a `Repository`. `ParserBase::parse()`
   takes a `string`.
 * Objects serialized with 1.x (a `Commit` or a `Diff` in a cache...) can't
   be unserialized: clear such caches. `Diff::toArray()` and
   `Diff::fromArray()` remain compatible.

Data returned
-------------

 * File names in diffs (`Diff\File`) and tree entries (`Tree`) are returned
   in UTF-8, instead of git's quoted form (`"caf\303\251.txt"`) for names
   with non-ASCII or special characters.
 * `Blame\Line::getContent()` no longer starts with a TAB.
 * `Commit::getDiff()` lists the files of a root commit, instead of
   returning an empty diff.
 * A commit read through `Repository::getCommit()` has its message as
   `git log` prints it, like a commit of a `Log` already had: without blank
   lines around it, nor whitespace at the end of its lines. It also comes
   re-encoded to UTF-8 when the commit has an `encoding` header, instead of
   throwing.
 * `Reference\Tag::isSigned()` returns `false` for a lightweight tag.
 * `PushReference::isForce()` returns `true` for histories without a common
   ancestor, where the constructor threw. `PushReference` runs git when
   `isForce()` needs it, not when constructed. `getReference()` and
   `getRevision()` are deprecated: use `getFullname()` and `getRange()`.
 * `Blob::getContent()` only reads blobs, where `git cat-file -p` also
   printed trees and commits.
 * `WorkingCopy::getUntrackedFiles()` and `RevisionList::getAll()` return
   lists, without gaps in their keys.
 * `Diff\File::toArray()` has a new `is_copy` key.

Other changes
-------------

 * Relative paths, in the arguments of `Repository::run()` and in the paths
   of a log, are relative to the repository instead of the current directory
   of PHP.
 * `Hooks::set()` makes hooks executable by their owner only (`0700`,
   instead of `0777`): change their permissions if another user runs them.
 * `Repository::shell()` is deprecated: it ignored the driver. Run
   `git shell` with Symfony Process instead.
