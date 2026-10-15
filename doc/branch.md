Branch
======

To access a *Branch*, starting from a repository object:

```php
$repository = new Gitonomy\Git\Repository('/path/to/repository');
$branch = $repository->getReferences()->getBranch('master');
```

You can check is the branch is a local or remote one:

```php
$branch->isLocal();
$branch->isRemote();
```

To check that the history of a revision contains the branch:

```php
$branch->isMergedInto('master');
```

`isMergedTo()` is deprecated.

To point the branch to another revision:

```php
$branch = $branch->moveTo('a8b7e4...');
```
