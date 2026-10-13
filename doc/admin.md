Create and access git repositories
==================================

The static methods of `Repository` create new repositories, and return them.
They replace `Admin`, deprecated since 2.0: see
[UPGRADE-2.0.md](../UPGRADE-2.0.md#creating-repositories).

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
refuses access.

Logger and driver
-----------------

The methods returning a `Repository` take its [logger](repository.md#logger),
and all of them the driver running git as their last argument — see
["Choosing a driver"](repository.md#choosing-a-driver):

```php
$repository = Repository::initBare('/path/to/repository', driver: new Gitonomy\Git\Driver\Ffi\LibGit2Driver());
```

### References

-   <http://linux.die.net/man/1/git-init>
-   <http://linux.die.net/man/1/git-clone>
