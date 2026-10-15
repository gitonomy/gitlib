Getting log history
===================

Walking the parents of commits browses a history. To order the commits, or
to gather the history of several branches, use a log, like `git log`.

To get a `Log` object from a repository:

```php
$log = $repository->getLog();
```

`getLog()` takes four arguments, all optional: the revisions, the paths,
the offset and the limit.

```php
// The history of every reference
$log = $repository->getLog();

// The history of the master branch
$log = $repository->getLog('master');

// The last 10 commits of master changing the README.md file
$log = $repository->getLog('master', 'README.md', 0, 10);

// The last 10 commits of master changing README or README.md
$log = $repository->getLog('master', ['README', 'README.md'], 0, 10);
```

A `Revision` object, like a `Commit` or a `Branch`, has a shortcut, taking
the paths, the offset and the limit:

```php
$log = $repository->getRevision('master')->getLog('README.md', 0, 10);
```

Revisions
---------

The revisions are a string, a `Revision` object, or an array of them. Each
one can be:

-   a name: a branch, a tag, a hash, `HEAD~3`..., for its history;
-   a range, `a..b`: the history of `b` without the history of `a`;
-   a symmetric range, `a...b`: the commits in the history of `a` or `b`,
    but not in both;
-   a name to exclude, `^a`: the history of the other revisions, without the
    history of `a`;
-   `--all`: every reference and `HEAD`, like no revision at all.

An empty end of a range is `HEAD`: `..master` is `HEAD..master`.

```php
// The commits of master since the tag 0.1
$log = $repository->getLog('0.1..master');

// The same
$log = $repository->getLog(['master', '^0.1']);

// The commits of feature and master since they diverged
$log = $repository->getLog('feature...master');
```

A revision naming no commit throws a `ReferenceNotFoundException` naming
it. Other options of `git log` are not supported: they throw an
`InvalidArgumentException`.

Paths
-----

The paths filter the log: it lists the commits changing them. They are
relative to the root of the repository, and support the wildcards of git:

```php
// The commits changing a PHP file, in any folder
$log = $repository->getLog('master', '*.php');

// The commits changing a file in the src folder
$log = $repository->getLog('master', 'src');
```

With `CliDriver`, they support the magic of git's pathspecs too, like
`:(exclude)src`, which git knows since 1.9. `LibGit2Driver` doesn't: a path
starting with `:` throws an `UnsupportedOperationException`.

Iterating
---------

A log is iterable:

```php
foreach ($log as $commit) {
    echo $commit->getShortHash().' '.$commit->getSubjectMessage().PHP_EOL;
}
```

`getCommits()` returns them as an array. `getSingleCommit()` returns the
first commit of the log, and throws a `ReferenceNotFoundException` when the
log is empty:

```php
$commits = $log->getCommits();

$lastChange = $repository->getLog('master', 'README.md')->getSingleCommit();
```

Counting
--------

`count($log)` returns the number of commits the log iterates, after its
offset and up to its limit. To count every commit of the log, without offset
nor limit, use `countCommits()`:

```php
echo sprintf('This page has %d commits%s', count($log), PHP_EOL);
echo sprintf('This log has %d commits%s', $log->countCommits(), PHP_EOL);
```

Offset and limit
----------------

A log is immutable: `withOffset()` and `withLimit()` return another log.

```php
$log = $repository->getLog('master');

$firstPage = $log->withLimit(10);
$secondPage = $firstPage->withOffset(10);

// To read them
$secondPage->getOffset(); // 10
$secondPage->getLimit();  // 10
```

`setOffset()` and `setLimit()`, which changed the log, are deprecated since
2.0: use `withOffset()` and `withLimit()`.

Diff of a log
-------------

`Log::getDiff()` is deprecated since 1.7: it ignored the paths of the log.
Use `Repository::getDiff($from, $to)`, see [diff.md](diff.md).
