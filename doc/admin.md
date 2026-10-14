Create and access git repositories
==================================

The static methods of `Repository` create new repositories, and return them.
They replace `Admin`, deprecated since 2.0: see
[UPGRADE-2.0.md](../UPGRADE-2.0.md#creating-repositories).

To open a repository that exists already, use `new Repository($path)`: see
[Repository](repository.md).

Create a repository
-------------------

```php
use Gitonomy\Git\Repository;

// Initialize a repository with a working directory
$repository = Repository::init('/path/to/repository');

// Initialize a bare repository
$repository = Repository::initBare('/path/to/repository');
```

Cloning repositories
--------------------

You can clone a repository from an URL by doing:

```php
// Clone with a working directory
$repository = Repository::cloneFrom('https://github.com/gitonomy/gitlib.git', '/tmp/gitlib');

// Clone to a bare repository
$repository = Repository::cloneBareFrom('https://github.com/gitonomy/gitlib.git', '/tmp/gitlib');
```

You can also clone a repository and point it to a specific branch. With a
working directory, this branch will be checked out:

```php
$repository = Repository::cloneFrom('https://github.com/gitonomy/gitlib.git', '/tmp/gitlib', 'a-branch');
```

Like `git clone --branch`, the branch may also be a tag: the clone checks
its commit out, `HEAD` detached.

Like `git clone`, the methods refuse a directory with files in it. When a
clone fails, they remove what they created.

To clone a `Repository` object, use its git directory as URL:

```php
$new = Repository::cloneBareFrom($repository->getGitDir(), '/tmp/clone');
```

These methods support no other argument of `git clone`. With `CliDriver`,
run `git clone` yourself, then open the repository:

```php
use Symfony\Component\Process\Process;

new Process(['git', 'clone', '--depth', '1', $url, '/tmp/gitlib'])->mustRun();

$repository = new Repository('/tmp/gitlib');
```

Mirror a repository
-------------------

If you want to mirror fully a repository and all references, use the
`mirrorFrom` method. It clones into a bare repository, set up to mirror the
remote when fetching, like `git clone --mirror`:

```php
$mirror = Repository::mirrorFrom('https://github.com/gitonomy/gitlib.git', '/tmp/mirror');
```

Check a repository URL
----------------------

You can check that a repository exists, and that it has a branch, without
cloning it:

```php
Repository::isValidRepository('https://github.com/gitonomy/gitlib.git'); // true
Repository::isValidRepositoryAndBranch('https://github.com/gitonomy/gitlib.git', 'main'); // true
```

They return `false` as well for a repository that can't be reached, or that
refuses access. The branch must have this name exactly: `release` doesn't
name `feature/release`. A relative path is the path of a repository, never
the name of a remote.

Errors
------

When git fails (an unknown branch, a directory with files, a network
error...), the methods creating repositories throw a
`Gitonomy\Git\Exception\DriverException`: a `ProcessException`, with git's
error output, when `CliDriver` runs git. With `CliDriver`, every method,
`isValidRepository()` included, throws a `DriverException` when git can't
start, like a missing binary. See [Errors](repository.md#errors).

A URL that makes git run a command, or use a file of PHP, throws an
`InvalidArgumentException`, with both drivers:

-   a URL starting with a dash, which git would read as an option, like
    `--upload-pack=...`; a path may start with one;
-   an `ext::` URL, like `ext::sh -c ...`, whose command git runs, unless its
    `protocol.allow` setting forbids it, as it does by default since git 2.12;
-   an `fd::` URL, like `fd::7`, which makes git talk through a file
    descriptor it inherits from PHP, like the socket of a database: git waits
    on it until it times out;
-   an ssh URL whose host or user starts with a dash, even encoded (`%2D`),
    like `ssh://-oProxyCommand=.../repository` or
    `ssh://-oProxyCommand=...@host/repository`: before 2.14.1, git passes it
    to ssh, which reads it as an option and runs the command.

The message of an exception hides the credentials of the URL
(`https://***@example.com/repository.git`). gitlib doesn't check the URLs
any further: a path, for instance, names a repository of the server.

Driver and logger
-----------------

All these methods take the driver running git as their last argument, see
["Choosing a driver"](repository.md#choosing-a-driver). The repositories they
return use it too:

```php
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use Gitonomy\Git\Repository;

$repository = Repository::initBare('/path/to/repository', driver: new LibGit2Driver());
```

They take no logger: the logger is an argument of `CliDriver`, which logs the
commands initializing and cloning repositories too. See
[Logger](repository.md#logger):

```php
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Repository;

$repository = Repository::cloneFrom($url, '/tmp/gitlib', driver: new CliDriver(logger: $logger));
```

### References

-   <https://git-scm.com/docs/git-init>
-   <https://git-scm.com/docs/git-clone>
-   <https://git-scm.com/docs/git-ls-remote>
