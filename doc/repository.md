Repository methods
==================

Creating a *Repository* object is possible, providing a *path* argument
to the constructor:

```php
$repository = new Repository('/path/to/repo');
```

Repository options
------------------

The constructor of Repository takes an additional parameter: `$options`.
This parameter can be used used to tune behavior of library.

Available options are:

-   **logger**: (default: none) Logger to use for reporting of execution
    (a `Psr\Log\LoggerInterface`)
-   **working\_dir**: If you are using multiple working directories,
    this option is for you

An example:

```php
$repository = new Repository('/path/to/repo', [
    'logger' => new Monolog\Logger(),
]);
```

Its last parameter, `$driver`, is the backend running git operations: see
"Choosing a driver" below.

Test if a repository is bare
----------------------------

On a *Repository* object, you can call method *isBare* to test if your
repository is bare or not:

```php
$repository->isBare();
```

Compute size of a repository
----------------------------

To know how much size a repository is using on your drive, you can use
`getSize` method on a *Repository* object.

> **warning**
>
> This command was only tested with linux.

The returned size is in kilobytes:

```php
$size = $repository->getSize();

echo 'Your repository size is '.$size.'KB';
```

Access HEAD
-----------

`HEAD` represents in git the version you are working on (in working
tree). Your `HEAD` can be attached (using a reference) or detached
(using a commit).

```php
$head = $repository->getHead(); // Commit or Reference
$head = $repository->getHeadCommit(); // Commit

if ($repository->isHeadDetached()) {
    echo 'Sorry man'.PHP_EOL;
}
```

Options for repository
----------------------

### Choosing a driver

By default, gitlib spawns a `git` process for every operation, with
`CliDriver`, which requires git 1.8.4 or later. You can instead run
[libgit2](https://libgit2.org/) in-process, through PHP's `FFI` extension,
by passing a `LibGit2Driver`:

```php
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;

$repository = new Repository('/path/to/repo', driver: new LibGit2Driver());
```

> **warning**
>
> `LibGit2Driver` is experimental: it isn't covered by the backward
> compatibility promise, and can change in a minor version. It reproduces
> git's behavior with libgit2, and may still differ from git in cases its
> tests don't cover.

A driver holds no state of a repository: create it once, and pass it to
every `Repository` and `Admin` call, as a service of your container for
instance.

`LibGit2Driver` avoids spawning a process for every operation. It requires
the `ext-ffi` PHP extension and libgit2 1.9: libgit2 changes its binary
interface with every minor version, so any other version is refused. If
either is missing, constructing the `LibGit2Driver` throws an
`UnsupportedOperationException` right away — there is no silent fallback to
`CliDriver`. You can check availability upfront:

```php
use Gitonomy\Git\Driver\Cli\CliDriver;

$driver = LibGit2Driver::isSupported() ? new LibGit2Driver() : new CliDriver();
```

If libgit2 isn't discoverable under one of the usual shared-library names
(`libgit2.so.1.9`, `libgit2.so`, `libgit2.dylib`, `git2.dll`...), pass its
path to the driver:

```php
$driver = new LibGit2Driver('/opt/libgit2/lib/libgit2.so.1.9');
```

Differences with `CliDriver`:

-   `Repository::run()` and `Repository::runProcess()` throw an
    `UnsupportedOperationException`: there is no `git` process to run.
-   Nothing is logged, and there is no timeout on network operations.
-   `WorkingCopy::merge()` only supports fast-forward and conflict-free
    merges, with `--no-ff` as the only extra argument: a conflict throws
    instead of leaving conflict markers in the working copy.
-   `WorkingCopy::checkout()` doesn't create a tracking branch for a name
    that only exists on a remote, like `git checkout` does: it throws.
-   `Admin::cloneRepository()` only supports the `--bare`, `--mirror` and
    `--branch` arguments.
-   Rename detection in diffs may pair files differently.
-   Blame line ranges are limited to `<start>,<end>` and `<start>,+<count>`.

#### With php-fpm

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
CLI, only preloaded code can use FFI. Without preloading, `LibGit2Driver`
throws an `UnsupportedOperationException` unless `ffi.enable` is `true`,
which lets any code of the application call C functions.

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
in a long-running process, and check with your own repository:

```bash
php benchmark.php /path/to/repository path/to/a/file
```

### Configuring `CliDriver`

The arguments of `CliDriver` set the git binary to run (`command`), the
environment variables to pass to it (`environmentVariables`, where `false`
removes an inherited one), whether git inherits the environment variables of
PHP (`inheritEnvironmentVariables`), and the timeout of every git process,
in seconds (`processTimeout`, `null` for none):

```php
use Gitonomy\Git\Driver\Cli\CliDriver;

$repository = new Gitonomy\Git\Repository('/tmp/foo', driver: new CliDriver(
    command: '/home/alice/bin/git',
    environmentVariables: ['GIT_SSH_COMMAND' => 'ssh -i /home/alice/.ssh/deploy'],
    processTimeout: 60,
));
```

### Using your own driver

`$driver` accepts any `Gitonomy\Git\Driver\DriverInterface` implementation:
its `open()` method returns the `Gitonomy\Git\Driver\RepositoryDriverInterface`
the repository runs its operations with. In tests, a stub of both lets you
exercise code built on gitlib without any git repository:

```php
$repositoryDriver = $this->createStub(RepositoryDriverInterface::class);
$repositoryDriver->method('getReferences')->willReturn([new ReferenceData('refs/heads/main', $hash)]);

$driver = $this->createStub(DriverInterface::class);
$driver->method('open')->willReturn($repositoryDriver);

$repository = new Repository('/path/to/any/directory', driver: $driver);
```

### Logger

If you are developing, you may appreciate to have a logger inside
repository, telling you every executed command (`CliDriver` only).

You call method `setLogger` as an option on repository creation:

```php
$repository->setLogger(new Monolog\Logger('repository'));

$repository->run('fetch', ['--all']);
```

You can also specify as an option on repository creation:

```php
$logger = new MonologLogger('repository');
$repository = new Repository('/path/foo', ['logger' => $logger]);
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
exception it throws reports the failure.

### Errors

Gitlib throws an exception when git reports a failure: a
`Gitonomy\Git\Exception\DriverException` (a `ProcessException` with
`CliDriver`), or a more specific one where it makes sense, like a
`ReferenceNotFoundException` for an unknown revision. Operations never return
empty data instead.
