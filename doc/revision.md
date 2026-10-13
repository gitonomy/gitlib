Revision
========

A revision is a name git resolves to a commit: a branch, a tag, a hash,
abbreviated or not, `HEAD~2`, `master@{2 days ago}`...

To get a revision from a *Repository* object:

```php
$revision = $repository->getRevision('master@{2 days ago}');
```

`Commit` and `Reference` (a branch, a tag) are revisions too: you can pass
them wherever gitlib takes a revision.

Resolve a revision
------------------

To resolve a revision to a commit:

```php
$commit = $revision->getCommit();
```

It throws a `Gitonomy\Git\Exception\ReferenceNotFoundException` when the
revision names no commit. A tag, annotated or not, resolves to the commit it
points to.

`Repository::getCommit()` only takes a full hash: use
`getRevision($name)->getCommit()` for anything else.

Getting the log
---------------

You can access a *Log* object starting from a revision using the
*getLog* method. This method takes three parameters: *paths*, *offset* and
*limit*:

```php
// Returns the 100 last commits
$log = $revision->getLog(limit: 100);

// Returns the 100 last commits changing README.md
$log = $revision->getLog('README.md', limit: 100);

// Skips the 100 last commits
$log = $revision->getLog(null, 100);
```

The first argument filters on paths: `getLog(null, 100)` is an offset, not a
limit. See [Log](log.md).
