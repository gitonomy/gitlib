UPGRADE FROM 1.x to 2.0
=======================

gitlib 2.0 runs every git operation through a driver: `CliDriver`, the
default, runs the `git` binary like 1.x did, and the experimental
`LibGit2Driver` runs libgit2 in-process (see
["Choosing a driver"](doc/repository.md#choosing-a-driver)). Code reading
repositories through `Repository`, `Commit`, `Log`... mostly keeps working:
check the points below, starting with the configuration of git, errors,
dates and classes you extend.

Requirements
------------

 * PHP 8.4 or later is required.
 * git 1.8.4 or later is required by `CliDriver`, the default driver: before,
   git simplifies the log of a range filtered by path differently, and it
   can't count commits (`Log::count()`) before 1.7.2.
 * `symfony/process` must be `^6.4`, `^7.4` or `^8.1`.
 * `symfony/string` `^7.4.6` or `^8.0.6`, and `symfony/deprecation-contracts`
   `^2.5` or `^3`, are new dependencies.

Configuring git
---------------

 * The `command`, `environment_variables`, `inherit_environment_variables`
   and `process_timeout` options of `Repository` and `Admin` are deprecated,
   and will be removed in 3.0: pass a `Gitonomy\Git\Driver\Cli\CliDriver`
   configured through its arguments as the last argument of `Repository`
   instead. They can't be combined with this argument.

   Before:

   ```php
   $repository = new Repository($path, [
       'command' => '/usr/bin/git',
       'process_timeout' => 60,
   ]);
   ```

   After:

   ```php
   use Gitonomy\Git\Driver\Cli\CliDriver;

   $repository = new Repository($path, driver: new CliDriver(command: '/usr/bin/git', processTimeout: 60));
   ```

 * `Repository` throws an `InvalidArgumentException` for an unknown option,
   instead of ignoring it.

Creating repositories
---------------------

 * `Admin` is deprecated, and will be removed in 3.0: use the static methods
   of `Repository`. They take the URL before the path, and have a method for
   each kind of repository instead of the `$bare` argument, whose default
   was a bare repository:

   | 1.x                                                  | 2.0                                                          |
   |------------------------------------------------------|--------------------------------------------------------------|
   | `Admin::init($path)`                                 | `Repository::initBare($path)`                                |
   | `Admin::init($path, false)`                          | `Repository::init($path)`                                    |
   | `Admin::cloneTo($path, $url)`                        | `Repository::cloneBareFrom($url, $path)`                     |
   | `Admin::cloneTo($path, $url, false)`                 | `Repository::cloneFrom($url, $path)`                         |
   | `Admin::cloneBranchTo($path, $url, $branch)`         | `Repository::cloneBareFrom($url, $path, $branch)`            |
   | `Admin::cloneBranchTo($path, $url, $branch, false)`  | `Repository::cloneFrom($url, $path, $branch)`                |
   | `Admin::mirrorTo($path, $url)`                       | `Repository::mirrorFrom($url, $path)`                        |
   | `Admin::isValidRepository($url)`                     | `Repository::isValidRepository($url)`                        |
   | `Admin::isValidRepositoryAndBranch($url, $branch)`   | `Repository::isValidRepositoryAndBranch($url, $branch)`      |
   | `$repository->cloneTo($path)`                        | `Repository::cloneBareFrom($repository->getGitDir(), $path)` |
   | `$repository->cloneTo($path, false)`                 | `Repository::cloneFrom($repository->getGitDir(), $path)`     |

   Until then, `Admin` keeps working like in 1.x, with `CliDriver`.
 * The new methods take the logger of the repository instead of options,
   and the driver as their last argument:

   Before:

   ```php
   $repository = Admin::cloneTo($path, $url, false, [
       'logger' => $logger,
       'process_timeout' => 60,
   ]);
   ```

   After:

   ```php
   use Gitonomy\Git\Driver\Cli\CliDriver;

   $repository = Repository::cloneFrom($url, $path, logger: $logger, driver: new CliDriver(processTimeout: 60));
   ```

 * `Repository::cloneTo()` used the driver of the repository: pass it to
   `Repository::cloneFrom()` or `Repository::cloneBareFrom()` as well.
 * `Admin::cloneRepository()` is deprecated, with no replacement for the
   arguments of `git clone` other than `--bare`, `--branch` and `--mirror`:
   run `git clone` yourself, then open the repository with
   `new Repository($path)`.

Errors
------

 * The `debug` option of `Repository` is deprecated, and setting it to
   `false` throws an `InvalidArgumentException`: a failing git operation
   always throws, a `Gitonomy\Git\Exception\ProcessException` with
   `CliDriver`. Nothing returns `null` or empty data instead anymore:
   `Repository::run()` returns a `string`, and `Repository::getHead()` and
   `Repository::getHeadCommit()` never return `null`.

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
 * `ProcessException` now extends the new
   `Gitonomy\Git\Exception\DriverException`, itself a `RuntimeException`:
   catching `RuntimeException` or `GitExceptionInterface` still works.
 * These methods throw instead of returning a fallback value:
   * `Commit::getIncludingBranches()` threw nothing and returned `[]` when git
     failed;
   * `Reference\Tag::isAnnotated()`, and the getters of a tag, return `false`
     for a lightweight tag but throw for a tag that doesn't exist;
   * `ReferenceBag` (`Repository::getReferences()`) only returns an empty bag
     for a repository without references, and throws for any other failure,
     like a directory that isn't a repository.
 * `Commit::getShortHash()` throws a `ReferenceNotFoundException` (an
   `\InvalidArgumentException`) for an unknown commit, instead of a
   `ProcessException`.
 * The `Admin` methods throw a `ProcessException`, still a
   `RuntimeException`, with git's error output as message.

Dates
-----

 * `Commit::getAuthorDate()`, `Commit::getCommitterDate()` and
   `Reference\Tag::getTaggerDate()` return a `\DateTimeImmutable` instead of
   a `\DateTime`. Code that modifies the returned date must use the returned
   value of `modify()`, `setTimezone()`...

Extending gitlib
----------------

 * These classes are `final`: `Admin`, `Blame`, `Blob`, `Commit`,
   `Diff\File`, `Log`, `Parser\DiffParser`, `Reference\Branch`,
   `Reference\Stash`, `Reference\Tag`, `ReferenceBag`, `Repository`, `Tree`,
   and the exceptions `InvalidArgumentException`, `LogicException`,
   `ProcessException`, `ReferenceNotFoundException` and
   `UnexpectedValueException`.
 * These classes are `final readonly`: `Blame\Line`, `CommitReference`,
   `Diff\Diff`, `Diff\FileChange`, `Hooks`, `PushReference`, `RevisionList`,
   `WorkingCopy`.
 * Every method has native parameter and return types. A class extending
   `Revision`, `Reference` or `Parser\ParserBase`, which remain extendable,
   must declare the same types, and must not redeclare their properties.
 * To change how gitlib runs git, for example in tests, implement
   `Gitonomy\Git\Driver\DriverInterface` and pass it as the driver of
   `Repository`, instead of extending `Repository`: see
   ["Using your own driver"](doc/repository.md#using-your-own-driver).
 * `Commit::__construct()` and `Commit::setData()` take a
   `Gitonomy\Git\Driver\Data\CommitData` instead of an array, and
   `setData()` replaces all the data instead of merging it.
 * `Gitonomy\Git\Util\StringHelper` is removed, with its configurable
   encoding: use `symfony/string`. Logs are always read in UTF-8.
 * The parsers of the git output moved to `Gitonomy\Git\Driver\Cli\Parser`
   and are `final`, except `Parser\DiffParser` and `Parser\ParserBase`.
   `CommitParser` is merged into `LogParser`, and their results are objects
   of `Gitonomy\Git\Driver\Data`: `LogParser::$commits` (instead of `$log`),
   `TagParser::$data`, `ReferenceParser::$references`, `TreeParser::$entries`
   and `BlameParser::$lines`. `TreeParser` parses `git ls-tree -z`, and
   `BlameParser` no longer takes a `Repository`.
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
 * `Commit::getShortMessage()` throws a
   `Symfony\Component\String\Exception\InvalidArgumentException` when the
   subject isn't valid UTF-8.
 * `Reference\Tag::isSigned()` returns `false` for a lightweight tag,
   `Reference\Tag::getBodyMessage()` no longer starts with a line break, and
   `Reference\Tag::getGPGSignature()` throws a
   `Gitonomy\Git\Exception\InvalidArgumentException` for an unsigned tag.
 * `ReferenceBag::getFirstBranch()` returns the first branch, instead of the
   first reference of any kind.
 * `PushReference::isForce()` returns `true` for histories without a common
   ancestor, where the constructor threw.
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
