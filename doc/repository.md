Repository methods
==================

Creating a *Repository* object is possible, providing a *path* argument
to the constructor:

```php
$repository = new Repository('/path/to/repo');
```

When the git directory is kept apart from the working directory, pass the
working directory as second argument:

```php
$repository = new Repository('/path/to/repo.git', '/path/to/working-dir');
```

Configure git
-------------

The last argument of the constructor is a `Gitonomy\Git\Driver\Cli\CliDriver`,
which configures how gitlib runs git:

-   **command** (default: `git`): the git binary;
-   **environmentVariables** (default: none): environment variables set on
    every git process;
-   **processTimeout** (default: 3600): in seconds, `null` for none;
-   **logger** (default: none): a `Psr\Log\LoggerInterface` receiving every
    git command run.

```php
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repo', driver: new CliDriver(
    command: '/home/alice/bin/git',
    environmentVariables: ['GIT_SSH_COMMAND' => 'ssh -i /path/to/key'],
    processTimeout: 60,
    logger: new Monolog\Logger('repository'),
));
```

`CliDriver` has the arguments of the one of gitlib 2.0, where it runs git
itself: code using it works with both versions.

An array of options as second argument is deprecated: its `working_dir`
option is the second argument, and its `logger`, `command`,
`environment_variables` and `process_timeout` options configure a
`CliDriver`. Setting its `debug` option to `false` is deprecated: gitlib
2.0 always throws when git fails.

Test if a repository is bare
----------------------------

On a *Repository* object, you can call method *isBare* to test if your
repository is bare or not:

```php
$repository->isBare();
```

Compute size of a repository
----------------------------

To know how much size the git directory of a repository is using on your
drive, you can use `getSizeInBytes` method on a *Repository* object. It
doesn't follow the links.

```php
$size = $repository->getSizeInBytes();

echo 'Your repository size is '.$size.' bytes';
```

`getSize()`, in kilobytes, is deprecated.

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

Logger
------

If you are developing, you may appreciate to have a logger inside
repository, telling you every executed command:

```php
$repository = new Repository('/path/foo', driver: new CliDriver(logger: new Monolog\Logger('repository')));
$repository->run('fetch', ['--all']);
```

This will output:

```
info run command: fetch "--all"
debug last command (fetch) duration: 23.24ms
debug last command (fetch) return code: 0
debug last command (fetch) output: Fetching origin
```

`setLogger()` and `getLogger()` are deprecated.
