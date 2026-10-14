Commit
======

To get a `Commit`, resolve a revision: a branch, a tag, `HEAD`, `master~2`,
an abbreviated hash...

```php
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repository');
$commit = $repository->getRevision('master')->getCommit();
```

`Repository::getCommit()` takes the full hash of a commit, its 40
characters. It throws a `ReferenceNotFoundException` for anything else, an
abbreviated hash included:

```php
$commit = $repository->getCommit('7e9ba14939b0ce67ab95d83e03bd64710db91ef4');
```

The commit is read when you first need it: a hash naming no commit throws a
`ReferenceNotFoundException` then.

Hashes
------

```php
$commit->getHash();      // "7e9ba14939b0ce67ab95d83e03bd64710db91ef4"
$commit->getShortHash(); // "7e9ba14", abbreviated and unique in the repository
```

`getFixedShortHash()` is deprecated since 2.0: use
`substr($commit->getHash(), 0, 6)`.

Browsing parents
----------------

A commit has:

-   **no parent**: it's a root commit, the first one of a history;
-   **one parent**: it's a regular commit;
-   **several parents**: it's a merge commit. Its first parent is the
    commit the other ones were merged into.

```php
// The hashes of the parents
$hashes = $commit->getParentHashes();

// The parents, Commit objects
$parents = $commit->getParents();
```

For example, to display the first parents of a commit, like
`git log --first-parent`:

```php
for ($current = $commit; null !== $current; $current = $current->getParents()[0] ?? null) {
    echo $current->getShortHash().' '.$current->getSubjectMessage().PHP_EOL;
}
```

To list a history in order, with every parent, use a log: see
[log.md](log.md).

Accessing the tree
------------------

The tree of a commit holds its files and folders, as the commit recorded
them. Every commit has exactly one tree. See [tree.md](tree.md) to browse
it.

```php
// The hash of the tree
$hash = $commit->getTreeHash();

// The Tree object
$tree = $commit->getTree();
```

Author and committer
--------------------

A commit has an author and a committer. The author wrote the change. The
committer recorded it in the repository: they can differ when a commit is
rebased, cherry-picked or applied from a patch.

```php
// Author
$commit->getAuthorName();
$commit->getAuthorEmail();
$commit->getAuthorDate(); // A DateTimeImmutable

// Committer
$commit->getCommitterName();
$commit->getCommitterEmail();
$commit->getCommitterDate(); // A DateTimeImmutable
```

Message
-------

The message of a commit can have several lines. Like git, gitlib splits it
in a subject and a body:

```php
// The whole message
$commit->getMessage();

// The first paragraph, on one line, like "git log --format=%s"
$commit->getSubjectMessage();

// What follows the first paragraph and its empty lines, like "git log --format=%b"
$commit->getBodyMessage();
```

For a message `"Fix the parser\nof dates\n\nIt failed on leap years.\n"`,
the subject is `"Fix the parser of dates"`, and the body
`"It failed on leap years.\n"`. A message without an empty line has no
body.

`getShortMessage()` is deprecated since 2.0: truncate the subject with
`symfony/string`, a dependency of gitlib, instead:

```php
use function Symfony\Component\String\u;

echo u($commit->getSubjectMessage())->truncate(50, '...').PHP_EOL;
```

Diff of a commit
----------------

`getDiff()` returns the changes a commit made:

-   against its first parent, for a regular commit or a merge commit. For a
    merge, these are the changes it brought into the branch it was merged
    into, like GitHub shows a merge commit;
-   against nothing, for a root commit: every file is a creation.

```php
$diff = $commit->getDiff();
```

To compare any two commits, use `Repository::getDiff($from, $to)`. See
[diff.md](diff.md) for the diff API.

Last modification of a file
---------------------------

`getLastModification()` returns the last commit, in the history of this
one, that changed a path. It throws a `ReferenceNotFoundException` when no
commit changed it. The path is relative to the root of the repository.

```php
$last = $commit->getLastModification('README.md');

echo 'Last README.md modification'.PHP_EOL;
echo '  Author: '.$last->getAuthorName().PHP_EOL;
echo '    Date: '.$last->getAuthorDate()->format('d/m/Y').PHP_EOL;
echo ' Message: '.$last->getSubjectMessage().PHP_EOL;
```

References pointing to a commit
-------------------------------

`resolveReferences()` returns the branches and tags pointing to the commit,
annotated tags included:

```php
foreach ($commit->resolveReferences() as $reference) {
    echo $reference->getFullname().PHP_EOL; // "refs/heads/master", "refs/tags/1.0"...
}
```

See [references.md](references.md#resolution-from-a-commit).

Branches containing a commit
----------------------------

`getIncludingBranches()` returns the local and remote branches whose
history contains the commit, as `Branch` objects. Filter them with
`isLocal()` or `isRemote()`:

```php
use Gitonomy\Git\Reference\Branch;

$branches = $commit->getIncludingBranches();
$localBranches = array_filter($branches, static fn (Branch $branch): bool => $branch->isLocal());
$remoteBranches = array_filter($branches, static fn (Branch $branch): bool => $branch->isRemote());
```

Passing arguments to `getIncludingBranches()`, to keep the local or the
remote branches only, is deprecated since 2.0.
