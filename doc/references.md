Tags and branches
=================

Accessing tags and branches
---------------------------

With *gitlib*, you can access them via the *ReferenceBag* object. To get
this object from a *Repository*, use the *getReferences* method:

```php
$references = $repository->getReferences();
```

The bag reads the references once. To see the writes of
`Repository::run()` or of other processes, open a new `Repository`:
passing an argument to `getReferences()`, to read them again, is
deprecated.

First, you can test existence of tags and branches like this:

```php
if ($references->hasBranch('master') && $references->hasTag('0.1')) {
    echo 'Good start!'.PHP_EOL;
}
```

If you want to access all branches or all tags:

```php
$branches       = $references->getBranches();
$localBranches  = $references->getLocalBranches();
$remoteBranches = $references->getRemoteBranches();
$tags           = $references->getTags();
$all            = $references->getAll();
```

To get a given branch or tag, call *getBranch* or *getTag* on the
*ReferenceBag*. Those methods return *Branch* and *Tag* objects:

```php
$master  = $references->getBranch('master');
$feat123 = $references->getLocalBranch('feat123');
$feat456 = $references->getRemoteBranch('origin/feat456');
$v0_1    = $references->getTag('0.1');
```

If the reference cannot be resolved, a *ReferenceNotFoundException* will
be thrown.

On each of those objects, you can access those informations:

```php
// Get the associated commit
$commit = $master->getCommit();

// Get the commit hash
$hash = $master->getCommitHash();

// Get the last modification
$lastModification = $master->getLastModification();
```

Create and delete reference
---------------------------

You can create new tags and branches on repository, using helper methods
on ReferenceBag object:

```php
// create a branch
$references = $repository->getReferences();
$branch     = $references->createBranch('foobar', 'a8b7e4...'); // commit to reference, or a Revision

// create a tag
$references = $repository->getReferences();
$tag        = $references->createTag('0.3', 'a8b7e4...'); // commit to reference, or a Revision

// move a branch, to a revision or its name
$branch = $branch->moveTo('master');

// delete a branch or a tag
$branch->delete();
```

Creating a branch or a tag that exists already, which moves it, is
deprecated, as `ReferenceBag::update()`: use `Branch::moveTo()`. Deleting
a reference by another name than its full name, like `HEAD` or `master`,
is deprecated.

Resolution from a commit
------------------------

To resolve a branch or a commit from a commit, you can use the
*resolveTags* and *resolveBranches* methods on it:

```php
$branches = $references->resolveBranches($commit);
$tags     = $references->resolveTags($commit);

// Resolve branches and tags
$all      = $references->resolve($commit);
```

You can pass a *Commit* object or a hash to the method, gitlib will
handle it.
