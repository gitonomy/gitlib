Repository methods
==================

A `Repository` object opens a repository on the disk. It is the entry point
to everything else: commits, references, working copy...

To create a repository, or to clone one, see
[Creating repositories](admin.md).

Opening a repository
--------------------

Give the path of the repository to the constructor:

```php
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repo');
```

The path may be:

-   a bare repository;
-   a working tree, with its `.git` directory;
-   a working tree whose `.git` is a file pointing to its git directory: a
    linked worktree (`git worktree add`), a submodule, or a clone with
    `--separate-git-dir`.

The constructor takes two more arguments:

```php
public function __construct(string $dir, ?string $workingDir = null, DriverInterface $driver = new CliDriver())
```

-   `$workingDir`: the working tree, when `$dir` is a git directory kept apart
    from it, like `git --git-dir=$dir --work-tree=$workingDir`;
-   `$driver`: the backend running git. See
    ["Choosing a driver"](#choosing-a-driver),
    ["Configuring `CliDriver`"](#configuring-clidriver) and
    ["Logger"](#logger).

```php
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Repository;

$repository = new Repository('/srv/project.git', '/srv/project', new CliDriver(processTimeout: 60));
```

An array of options as second argument (`working_dir`, `logger`, `command`,
`process_timeout`...) is deprecated since 2.0: pass the working directory,
and configure a `CliDriver` instead. See
[UPGRADE-2.0.md](../UPGRADE-2.0.md#opening-a-repository).

A directory that doesn't exist, or that isn't a repository, throws an
`InvalidArgumentException`.

gitlib doesn't support the repositories using SHA-256 hashes yet: opening one
throws an `UnsupportedOperationException`.

Paths
-----

```php
$repository->isBare();        // true for a bare repository
$repository->getGitDir();     // the git directory, "/path/to/repo/.git"
$repository->getWorkingDir(); // the working tree, "/path/to/repo", or null for a bare repository
$repository->getPath();       // the working tree, or the git directory of a bare repository
```

Access HEAD
-----------

`HEAD` represents in git the version you are working on (in working
tree). Your `HEAD` can be attached (pointing to a branch) or detached
(pointing to a commit).

```php
$head = $repository->getHead(); // the Branch HEAD points to, or the Commit when it is detached
$commit = $repository->getHeadCommit(); // the Commit, in both cases

if ($repository->isHeadAttached()) {
    echo 'On branch '.$head->getName().PHP_EOL;
}

if ($repository->isHeadDetached()) {
    echo 'HEAD detached at '.$commit->getShortHash().PHP_EOL;
}
```

In a repository without commits yet, `HEAD` points to a branch that doesn't
exist yet: `getHead()` and `getHeadCommit()` throw a
`ReferenceNotFoundException`, and `isHeadAttached()` returns `true`.
`getHead()` throws one too when `HEAD` points to a reference that isn't a
branch, like a tag, which git allows.

Commits, trees and blobs
------------------------

```php
// A revision: a branch, a tag, a hash, "HEAD~2"...
$commit = $repository->getRevision('master')->getCommit();

// A full hash only
$commit = $repository->getCommit('7e9ba14939b0ce67ab95d83e03bd64710db91ef4');

// A hash, abbreviated or not
$tree = $repository->getTree('56953e3');
$blob = $repository->getBlob('412a0dad');
```

`getCommit()` only takes a full hash: anything else throws a
`ReferenceNotFoundException`. Use `getRevision($name)->getCommit()` for a
name or an abbreviated hash, see [Revision](revision.md). `getTree()` and
`getBlob()` take a hash, abbreviated or not, and throw for anything else: to
read a file of a commit, go through its tree, see [Tree](tree.md).

An object that doesn't exist throws a `ReferenceNotFoundException`, at the
latest when its data is read.

The repository returns the same object for a hash, as long as your
application uses it:

```php
$repository->getCommit($hash) === $repository->getCommit($hash); // true
```

It keeps no reference to the objects your application doesn't use anymore:
reading a long history doesn't fill the memory.

`getMergeBase()` returns the best common ancestor of two revisions, the
commit `git diff a...b` compares with, or `null` when they have no common
history:

```php
$base = $repository->getMergeBase('master', 'feature'); // Commit|null
```

References
----------

`getReferences()` returns the branches, tags... of the repository: see
[References](references.md).

```php
$references = $repository->getReferences();
```

gitlib reads them again after every write it makes: creating or deleting a
reference, a checkout, a merge, `run()`... It doesn't see the writes of other
processes (a push to the repository, another worker...): open a new
`Repository` to see them. The `$reload` argument of `getReferences()` is
deprecated since 2.0.

Description
-----------

```php
$repository->getDescription(); // the content of the "description" file of the git directory
$repository->hasDescription(); // false while it is git's default one
$repository->setDescription('My project');
```

Compute size of a repository
----------------------------

`getSizeInBytes()` returns the size of the files of the git directory,
working tree excluded, in bytes. It doesn't follow links, and leaves out a
file git deletes meanwhile, like a lock during a `gc` or a push:

```php
$size = $repository->getSizeInBytes();

echo 'Your repository size is '.round($size / 1000).' KB';
```

`getSize()`, which returns kilobytes (1,000 bytes), is deprecated since 2.0:
use `getSizeInBytes()`.

Running git commands
--------------------

With `CliDriver`, `run()` runs any git command on the repository, and returns
its standard output:

```php
$output = $repository->run('log', ['--oneline', '-n', '5']);
```

Some commands, like `push`, write their meaningful output to the error
output, even when they succeed. `runProcess()` returns the Symfony `Process`,
already run:

```php
$process = $repository->runProcess('push', ['origin', 'main']);

echo $process->getErrorOutput();
```

Both throw a `ProcessException` when git fails, a `DriverException` when it
can't start, a `ProcessTimedOutException` when it runs longer than
[the timeout](#configuring-clidriver), and an `UnsupportedOperationException`
with another driver than `CliDriver`. The relative paths of the arguments are
relative to the repository. The arguments go to git as they are: a value from
your users starting with a dash is an option for git, unless `--` comes
before it. gitlib reads the references again after the command, which may
have written them.

`shell()` is deprecated since 2.0: run `git shell` with Symfony Process
instead.

Errors
------

Gitlib throws an exception when an operation fails. It never returns `null`
or empty data instead. Every exception of gitlib implements
`Gitonomy\Git\Exception\GitExceptionInterface`, and lives in the
`Gitonomy\Git\Exception` namespace:

-   `ReferenceNotFoundException`, an `InvalidArgumentException`: a revision,
    a reference or an object doesn't exist (`getRevision('unknown')->getCommit()`,
    `getReferences()->getBranch('unknown')`...).
-   `DriverException`, a `RuntimeException`: git reports a failure, like a
    merge conflict or a clone of an unknown repository, or can't start, like
    a missing binary (`The git command "clone" could not start: ...`). With
    `CliDriver`, it is mostly a `ProcessException`, whose message has the
    error of git, the first `fatal:` or `error:` line of its error output
    (`The git command "clone" failed (exit 128): fatal: ...`), and whose
    `getErrorOutput()` and `getOutput()` return the output of git, or a
    `ProcessTimedOutException`, when git runs longer than
    [the timeout](#configuring-clidriver).
-   `UnsupportedOperationException`, a `RuntimeException`: the driver can't
    perform the operation at all, where another driver may: `run()`, cloning
    a bundle or opening a reftable repository with `LibGit2Driver`, libgit2
    that isn't installed...
-   `InvalidArgumentException`: an argument gitlib doesn't support, like an
    option of `git log` in the revisions of a log, a string holding a NUL
    byte, which git doesn't accept, a revision, a reference, a hash or a URL
    starting with a dash, which git would read as an option
    (`getRevision('--output=/path')`), an `ext::` URL, whose command git
    would run, a URL whose ssh host or user starts with a dash, which ssh
    would read as an option, or a reference to delete outside `refs/`, like
    `HEAD`.
-   `RuntimeException`: any other failure, like a hook that exists already
    (`Hooks::set()`), a file of the git directory that can't be written, or
    an output of git that gitlib can't read. `DriverException` and
    `UnsupportedOperationException` extend it.
-   `LogicException`: a call that makes no sense, like the working copy of a
    bare repository.

```php
use Gitonomy\Git\Exception\ReferenceNotFoundException;

try {
    $commit = $repository->getRevision($name)->getCommit();
} catch (ReferenceNotFoundException) {
    echo 'No commit named '.$name.PHP_EOL;
}
```

The message of an exception hides the credentials of the URLs it holds
(`https://***@example.com/repository.git`): `getErrorOutput()` and
`getOutput()` of a `ProcessException` return the output of git as it is.

Choosing a driver
-----------------

By default, gitlib spawns a `git` process for every operation, with
`CliDriver`, which requires git 1.8.4 or later. You can instead run
[libgit2](https://libgit2.org/) in-process, through PHP's `FFI` extension,
by passing a `LibGit2Driver`:

```php
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repo', driver: new LibGit2Driver());
```

> **warning**
>
> `LibGit2Driver` is experimental: it isn't covered by the backward
> compatibility promise, and can change in a minor version. It reproduces
> git's behavior with libgit2, and may still differ from git in cases its
> tests don't cover.

A driver holds no state of a repository: create it once, and pass it to
every `Repository` you open or create, as a service of your container for
instance.

`LibGit2Driver` avoids spawning a process for every operation. It requires
the `ext-ffi` PHP extension and libgit2 1.9, built for SHA-1 only: libgit2
changes its binary interface with every minor version, and its build
supporting SHA-256 changes its structures, so any other build is refused. If
either is missing, constructing the `LibGit2Driver` throws an
`UnsupportedOperationException` right away — there is no silent fallback to
`CliDriver`. You can check availability upfront:

```php
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;

$driver = LibGit2Driver::isSupported() ? new LibGit2Driver() : new CliDriver();
```

If libgit2 isn't discoverable under one of the usual shared-library names
(`libgit2.so.1.9`, `libgit2.so`, `libgit2.dylib`, `git2.dll`...), pass its
path to the driver:

```php
$driver = new LibGit2Driver('/opt/libgit2/lib/libgit2.so.1.9');
```

`isSupported()` only looks for the usual names: to check a path, construct
the driver and catch the exception instead:

```php
use Gitonomy\Git\Exception\UnsupportedOperationException;

try {
    $driver = new LibGit2Driver('/opt/libgit2/lib/libgit2.so.1.9');
} catch (UnsupportedOperationException) {
    $driver = new CliDriver();
}
```

Differences with `CliDriver`:

-   `Repository::run()` and `Repository::runProcess()` throw an
    `UnsupportedOperationException`: there is no `git` process to run.
-   Nothing is logged, and nothing times out, not even network operations.
-   SSH and git's credential helpers aren't supported: clone a private
    repository through HTTPS, with the credentials in the URL
    (`https://user:token@example.com/repo.git`).
-   Bundles (`.bundle` files) can't be cloned, and repositories storing
    their references in the reftable format can't be opened: both throw an
    `UnsupportedOperationException`.
-   The paths of a log don't support the magic of git's pathspecs
    (`:(exclude)src`, `:/src`...): a path starting with `:` throws an
    `UnsupportedOperationException`.
-   Neither hooks nor external filters (Git LFS...) are run: a checkout or a
    merge writes the files without them.
-   Like git, `LibGit2Driver` doesn't read `/etc/gitconfig` when the
    `GIT_CONFIG_NOSYSTEM` environment variable is true, but it doesn't read
    `/etc/gitattributes` either, which git only skips with
    `GIT_ATTR_NOSYSTEM`.
-   `Diff::getRawDiff()` returns an empty string.
-   Short hashes (`Commit::getShortHash()`) have 7 characters, more when 7
    are ambiguous: git adapts their length to the size of the repository.
-   `WorkingCopy::merge()` only supports fast-forward and conflict-free
    merges: a conflict throws instead of leaving conflict markers in the
    working copy. A merge commit is never signed, whatever
    `commit.gpgSign`, and its message never lists the merged commits,
    whatever `merge.log`, nor has the output of gpg checking a signed tag.
-   Blame is much slower on a long history: see
    ["Performance"](#performance).
-   Rename detection in diffs may pair files differently.
-   Network operations go through the proxy of `https_proxy`, or of
    `http.proxy`, for HTTPS only: libgit2 1.9 uses no proxy for a plain
    `http://` URL.
-   With php-fpm, libgit2 must be preloaded: see
    ["With php-fpm"](#with-php-fpm).

These limits apply to both drivers, so that they behave the same:

-   The revisions of a log are names, ranges (`a..b`, `a...b`), names to
    exclude (`^a`) or `--all`: any other option of `git log` throws an
    `InvalidArgumentException`.
-   A blame takes the numbers of its first and last lines, none of the other
    forms of `git blame -L`.

### With php-fpm

Call `LibGit2Driver::preload()` from the
[`opcache.preload`](https://www.php.net/manual/en/opcache.preloading.php)
script, with the library path you give to `LibGit2Driver`, if any:

```php
// preload.php
require __DIR__.'/vendor/autoload.php';

Gitonomy\Git\Driver\Ffi\LibGit2Driver::preload();
```

libgit2 then stays loaded and initialized for the lifetime of php-fpm,
instead of every request loading it again (about 10 ms, see below), and the
driver works with the default `ffi.enable` setting, `preload`: outside the
CLI, only preloaded code can use FFI.

Without preloading, `LibGit2Driver` throws an `UnsupportedOperationException`
unless `ffi.enable` is `true`, which lets any code of the application call C
functions. Even then, don't: every request initializes libgit2 again, and
leaks its global state, about 700 KB per request.

### Performance

`CliDriver` starts a `git` process for every operation, which costs a few
milliseconds each time. `LibGit2Driver` calls libgit2 directly, once
loaded. Which one is faster depends on the operation and on the size of the
history:

| Operation (ms)                       | gitlib `CliDriver` | gitlib `LibGit2Driver` | symfony `CliDriver` | symfony `LibGit2Driver` |
|--------------------------------------|-------------------:|-----------------------:|--------------------:|------------------------:|
| Load libgit2, once per process       |                  - |                   10.5 |                   - |                    10.5 |
| Resolve HEAD                         |                3.6 |                    0.6 |                 9.3 |                     4.5 |
| Read a commit                        |                3.1 |                    0.3 |                 4.0 |                     0.3 |
| Read a file                          |               18.5 |                    0.7 |                24.4 |                     1.0 |
| List the branches                    |                3.0 |                    0.6 |                10.0 |                     4.4 |
| Log 100 commits                      |                9.9 |                    4.0 |                11.3 |                     3.8 |
| Last commit of a file                |                3.7 |                    0.9 |                 7.9 |                     5.1 |
| Log 100 commits of a directory       |               13.8 |                   11.0 |                  95 |                     262 |
| Count the commits of HEAD            |                7.3 |                    5.1 |                  43 |                   1,332 |
| List the branches including a commit |                8.4 |                    3.3 |                  16 |                     912 |
| Diff a commit                        |               23.3 |                   17.4 |                 5.1 |                    18.4 |
| Blame a file                         |                 23 |                     78 |                 613 |           about 580,000 |

These are medians of 10 runs, each on a new `Repository`, as a web request
would open it. gitlib is this repository (350 commits), with
`src/Gitonomy/Git/Repository.php` as the file. symfony/symfony has 80,000
commits, 910 references and a commit-graph (which `git gc` writes by
default), with `src/Symfony/Component/Process/Process.php` (1,700 lines, 750
commits) as the file. Measured with PHP 8.5, git 2.53 and libgit2 1.9.1 on
an AMD Ryzen AI 9 HX 370.

-   `LibGit2Driver` reads objects (commits, files, references) 2 to 28
    times faster: no process to start.
-   Loading libgit2 costs about 10 ms, mostly libgit2 setting up OpenSSL,
    which reads the system's CA certificates. A command or a worker pays it
    once. PHP unloads libgit2 at the end of every request: with php-fpm, each
    request pays it again, unless
    [libgit2 is preloaded](#with-php-fpm), which brings it down to 0.1 ms.
-   libgit2 doesn't use git's commit-graph, and caches objects per
    repository only: walking a long history (counting commits, finding the
    branches including a commit, logging a path) is up to 60 times slower.
-   libgit2's blame is much slower than git's on a long history: about 10
    minutes instead of 0.6 s for symfony's `Process.php`. It diffs trees for
    every commit it goes through, where git compares the hash of the file
    ([libgit2#3027](https://github.com/libgit2/libgit2/issues/3027)).

Keep the default `CliDriver`, unless your application runs many small reads
in a long-running process, and check with your own repository, from a clone
of gitlib (Composer doesn't install `benchmark.php`, nor `symfony/console`,
which it requires):

```bash
git clone https://github.com/gitonomy/gitlib.git
cd gitlib
composer install
php benchmark.php /path/to/repository path/to/a/file
```

Configuring `CliDriver`
-----------------------

The arguments of `CliDriver` are:

-   `command`: the git binary to run, `git` by default;
-   `environmentVariables`: the environment variables to set on every git
    process, where `false` removes an inherited one: git inherits the
    environment variables of PHP;
-   `processTimeout`: the timeout of every git process, in seconds, for its
    whole duration and without output, `3600` by default, `null` for none. A
    git process running longer throws a `ProcessTimedOutException`;
-   `logger`: see ["Logger"](#logger).

```php
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Repository;

$repository = new Repository('/tmp/foo', driver: new CliDriver(
    command: '/home/alice/bin/git',
    environmentVariables: ['GIT_SSH_COMMAND' => 'ssh -i /home/alice/.ssh/deploy'],
    processTimeout: 60,
));
```

The `command`, `environment_variables` and `process_timeout` options of
`Repository` and `Admin`, from 1.x, are deprecated since 2.0 in favor of these
arguments. So is `inherit_environment_variables`, which passed the entries of
`$_SERVER` to git, the headers and credentials of an HTTP request included:
pass the variables git needs to `environmentVariables`.

Git configuration
-----------------

git reads the configuration of the user running PHP (`~/.gitconfig`,
`/etc/gitconfig`...) and of the repository. Where it changes what git
prints, which gitlib reads, what a read returns, or the branches gitlib
creates, `CliDriver` sets it back to git's defaults:

-   colors and columns;
-   `branch.autoSetupMerge` and `branch.autoSetupRebase`: like with
    `LibGit2Driver`, a branch `WorkingCopy::checkoutNewBranch()` creates from
    a remote branch tracks it, without rebasing;
-   `branch.sort`, `log.follow` and `log.showSignature`;
-   `core.fsmonitor`, whose command git runs to read the working tree, and
    even to diff a commit;
-   for diffs, `diff.algorithm`, `diff.context`, `diff.external`,
    `diff.indentHeuristic`, `diff.interHunkContext`, `diff.orderFile`,
    `diff.submodule`, `diff.suppressBlankEmpty`, the prefixes
    (`diff.noprefix`, `diff.mnemonicPrefix`...), and the textconv filters, of
    blames too.

Like libgit2, `CliDriver` reads the objects as they are, ignoring their
replacements (`git replace`, `refs/replace/`): whoever can push such a
reference to a repository would change what your application shows for a
hash.

The rest of the configuration applies, like with git: the identity, the
credentials and the rewrites of URLs, line endings, hooks, `merge.log`,
`blame.ignoreRevsFile`, or `core.abbrev`, the length of short hashes, with
`LibGit2Driver` too.

`safe.directory` doesn't apply: `CliDriver` runs git with `--git-dir`, which
git trusts, whoever owns the repository. Like git, gitlib then runs the
commands the configuration of the repository names: its hooks, and the
filters of its `.gitattributes`, when it checks files out, merges, or reads
the working tree. Only open a repository whose `.git` directory you trust:
not one another user of the machine owns, nor one extracted from an archive
your users send. `LibGit2Driver` runs neither hooks nor external filters.

`run()` and `runProcess()` get `branch.autoSetupMerge`,
`branch.autoSetupRebase`, `branch.sort`, `core.fsmonitor`, `log.follow`,
`log.showSignature`, `diff.indentHeuristic` and `diff.suppressBlankEmpty` set
back too, and ignore the replacements, but not the rest, which gitlib sets with
the options of the commands it runs: pass `--no-color` or `--no-ext-diff`
yourself.

Logger
------

`CliDriver` takes a PSR-3 logger, and logs every git command it runs,
initializing and cloning repositories included:

```php
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Repository;

$repository = new Repository('/path/foo', driver: new CliDriver(logger: new Monolog\Logger('git')));

$repository->run('fetch', ['--all']);
```

This will output:

```
info run command: fetch "--all"
debug last command (fetch) duration: 23.24ms
debug last command (fetch) return code: 0
debug last command (fetch) output: Fetching origin
```

A failing command also logs its error output, at the `debug` level: the
exception it throws reports the failure. The logs hide the credentials of
URLs, like exceptions. `LibGit2Driver` logs nothing.

`Repository::setLogger()` and `Repository::getLogger()` are deprecated since
2.0: give the logger to `CliDriver`.

Using your own driver
---------------------

`$driver` accepts any `Gitonomy\Git\Driver\DriverInterface` implementation.
Its `open($dir, $workingDir)` method returns the
`Gitonomy\Git\Driver\RepositoryDriverInterface` the repository runs its
operations with: `getGitDir()`, `getWorkingDir()`, `getHeadReference()`,
`getReferences()`, `getCommitData()`... In tests, a stub of both lets you
exercise code built on gitlib without any git repository:

```php
use Gitonomy\Git\Driver\Data\ReferenceData;
use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Repository;

$repositoryDriver = $this->createStub(RepositoryDriverInterface::class);
$repositoryDriver->method('getGitDir')->willReturn('/path/to/repo.git');
$repositoryDriver->method('getReferences')->willReturn([new ReferenceData('refs/heads/main', $hash)]);

$driver = $this->createStub(DriverInterface::class);
$driver->method('open')->willReturn($repositoryDriver);

$repository = new Repository('/path/to/repo.git', driver: $driver);
$repository->getReferences()->getBranch('main')->getCommitHash(); // $hash
```

The other methods of `DriverInterface` create repositories: `init()`,
`initBare()`, `cloneFrom()`, `cloneBareFrom()`, `mirrorFrom()`,
`isValidRepository()` and `isValidRepositoryAndBranch()`.
