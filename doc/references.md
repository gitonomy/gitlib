Tags and branches
=================

Accessing tags and branches
---------------------------

Branches and tags are references. To get them, call `getReferences()` on a
`Repository`. It returns a `ReferenceBag` object:

```php
$references = $repository->getReferences();
```

The bag reads the references again after every write gitlib makes: creating
or deleting a reference, a checkout, a merge, `Repository::run()`... To see
the writes of other processes, open a new `Repository`. Passing an argument
to `getReferences()`, to read them again, is deprecated since 2.0.

First, you can test the existence of tags and branches like this:

```php
if ($references->hasBranch('master') && $references->hasTag('0.1')) {
    echo 'Good start!'.PHP_EOL;
}

$references->hasRemoteBranch('origin/master');
$references->hasBranches();             // A branch at least, local or remote
$references->has('refs/heads/master'); // A reference, by its full name
```

To get all branches or all tags, sorted by full name (local branches come
before remote ones):

```php
$branches       = $references->getBranches(); // Branch objects, local and remote
$localBranches  = $references->getLocalBranches();
$remoteBranches = $references->getRemoteBranches();
$tags           = $references->getTags(); // Tag objects
$all            = $references->getAll(); // Every reference, keyed by its full name
```

The bag is also iterable and countable, like `getAll()`. The default branch
of a remote (`refs/remotes/origin/HEAD`) is left out, and the stash is a
`Reference\Stash` object.

To get a given branch or tag, call `getBranch()`, `getRemoteBranch()` or
`getTag()` on the `ReferenceBag`. They return `Branch` and `Tag` objects:

```php
$master  = $references->getBranch('master');
$feature = $references->getRemoteBranch('origin/feature');
$v0_1    = $references->getTag('0.1');

// A reference by its full name
$reference = $references->get('refs/heads/master');
```

If the reference doesn't exist, they throw a `ReferenceNotFoundException`.
`getFirstBranch()` returns the first branch of `getBranches()`, or `null`
when there is none.

Every reference is a `Revision`. On each one, you can access:

```php
// The name: "master", "origin/feature" for a remote branch, "0.1" for a tag
$name = $master->getName();

// The full name: "refs/heads/master", "refs/remotes/origin/feature", "refs/tags/0.1"
$fullname = $master->getFullname();

// The commit it points to
$commit = $master->getCommit();
$hash = $master->getCommitHash();

// The last commit changing a path, in its history
$lastModification = $master->getLastModification('README.md');
```

`getCommitHash()` is always a commit: for an annotated tag, the commit the
tag object points to. A reference object keeps the commit it pointed to when
it was read: get it again from the bag after a change.

Create and delete references
----------------------------

You can create new tags and branches, using methods of the `ReferenceBag`.
They take the revision to point to: a name, a hash, or a `Revision` object
like a `Commit` or a `Branch`.

```php
// Create a branch
$branch = $references->createBranch('foobar', 'master');

// Create a lightweight tag
$tag = $references->createTag('0.3', $repository->getHeadCommit());

// Delete a branch or a tag
$branch->delete();
$tag->delete();
```

`ReferenceBag::delete()` takes the full name of a reference under `refs/`,
like `refs/heads/foobar`, and does nothing when it doesn't exist, like
`git update-ref -d`. Another name, like `HEAD` or `foobar`, throws an
`InvalidArgumentException`.

`createBranch()` and `createTag()` throw an `InvalidArgumentException` when
the reference exists: they never move it. To move a branch, use
`Branch::moveTo()`: see [branch.md](branch.md). `ReferenceBag::update()` is
deprecated since 2.0: use `Branch::moveTo()`.

Tags
----

A tag is lightweight, a name pointing to a commit, or annotated: a tag
object, with a tagger, a message, and maybe a signature.

```php
$tag = $references->getTag('1.0');

if ($tag->isAnnotated()) {
    echo $tag->getTaggerName().' <'.$tag->getTaggerEmail().'>'.PHP_EOL;
    echo $tag->getTaggerDate()?->format('Y-m-d').PHP_EOL;
    echo $tag->getSubjectMessage().PHP_EOL;
}
```

-   `getTaggerName()`, `getTaggerEmail()` and `getTaggerDate()` (a
    `DateTimeImmutable`) return `null` for a lightweight tag, and for an
    annotated tag without tagger;
-   `getMessage()` returns the message, without the signature, `null` for a
    lightweight tag;
-   `getSubjectMessage()` and `getBodyMessage()` split the message like
    for a commit (see [commit.md](commit.md#message)), `null` for a
    lightweight tag;
-   `isSigned()` tells if the tag is signed, with GPG, X.509 or SSH;
-   `getGpgSignature()` returns the signature, without its armor lines,
    `null` for an unsigned or lightweight tag.

Resolution from a commit
------------------------

To find the branches or the tags pointing to a commit, use the
`resolveBranches()` and `resolveTags()` methods:

```php
$branches = $references->resolveBranches($commit);
$tags     = $references->resolveTags($commit); // Annotated tags included

// Resolve branches and tags
$all      = $references->resolve($commit);
```

You can pass a `Commit` object, a hash or any revision name.
`Commit::resolveReferences()` is a shortcut for `resolve()`.
