Create and access git repositories
==================================

gitlib provides static methods of `Repository` to initialize and clone
repositories. Each of them takes a `CliDriver` as last argument, to
configure git (see [Repository](repository.md)).

`Admin` and `Repository::cloneTo()`, which did it before 1.7, are
deprecated.

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
// Clone to a repository with a working directory
$repository = Repository::cloneFrom('https://github.com/gitonomy/gitlib.git', '/tmp/gitlib');

// Clone to a bare repository
$repository = Repository::cloneBareFrom('https://github.com/gitonomy/gitlib.git', '/tmp/gitlib');
```

You can also clone a repository and point it to a specific branch. In a
repository with a working directory, this branch is checked out:

```php
$repository = Repository::cloneFrom('https://github.com/gitonomy/gitlib.git', '/tmp/gitlib', 'a-branch');

$repository = Repository::cloneBareFrom('https://github.com/gitonomy/gitlib.git', '/tmp/gitlib', 'a-branch');
```

To clone a `Repository` object, clone its git directory:

```php
$new = Repository::cloneBareFrom($repository->getGitDir(), '/tmp/clone');
```

Mirror a repository
-------------------

To mirror a repository and all its references into a bare repository,
use the `mirrorFrom` method:

```php
$mirror = Repository::mirrorFrom('https://github.com/gitonomy/gitlib.git', '/tmp/mirror');
```

Check a remote repository
-------------------------

To check that git can read a repository, without cloning it:

```php
Repository::isValidRepository('https://github.com/gitonomy/gitlib.git');

// And that it has the branch "main", with this exact name
Repository::isValidRepositoryAndBranch('https://github.com/gitonomy/gitlib.git', 'main');
```

### References

-   <http://linux.die.net/man/1/git-init>
-   <http://linux.die.net/man/1/git-clone>
