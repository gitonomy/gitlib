Branch
======

To access a `Branch`, starting from a repository object:

```php
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repository');
$branch = $repository->getReferences()->getBranch('master');
$remoteBranch = $repository->getReferences()->getRemoteBranch('origin/master');
```

See [references.md](references.md) to list, create and delete branches.

You can check if the branch is a local or a remote one:

```php
$branch->isLocal();  // A branch of refs/heads/
$branch->isRemote(); // A branch of refs/remotes/, like origin/master

$branch->getName(); // "master", or "origin/master" for a remote branch
```

Like every reference, a branch gives its commit:

```php
$commit = $branch->getCommit();
$hash = $branch->getCommitHash();
```

Moving a branch
---------------

`moveTo()` points the branch to a revision: a name, a hash, or a `Revision`
object. It returns the branch, read again: the object you called it on
keeps the commit it pointed to.

```php
$branch = $branch->moveTo('master~2');
```

Like `git update-ref`, it doesn't touch the working tree, even for the
branch checked out. `ReferenceBag::update()` is deprecated since 1.7: use
`moveTo()`.

Merged branches
---------------

`isMergedInto()` tells if the history of a revision (a name, a hash, or a
`Revision` object) contains the branch:

```php
if ($branch->isMergedInto('master')) {
    echo $branch->getName().' is merged into master'.PHP_EOL;
}
```

`isMergedTo()` is deprecated since 1.7: use `isMergedInto()`, which has no
default destination (`master`) nor flag to compare remote branches only.

Branches containing a commit
----------------------------

To find the branches whose history contains a commit, use
`Commit::getIncludingBranches()`: see
[commit.md](commit.md#branches-containing-a-commit).

To check a branch out, see [workingcopy.md](workingcopy.md).
