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
"Configuring `CliDriver`" below.

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

### Configuring `CliDriver`

By default, gitlib spawns a `git` process for every operation, with
`CliDriver`, which requires git 1.8.4 or later. A driver holds no state of a
repository: create it once, and pass it to every `Repository` and `Admin`
call, as a service of your container for instance.

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
