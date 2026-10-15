Getting log history
===================

Crawling manually commits and parents to browse history is surely a good
solution. But when it comes to ordering them or aggregate them from
multiple branches, we tend to use `git log`.

To get a *Log* object from a repository:

```php
$log = $repository->getLog();
```

You can pass four arguments to *getLog* method:

```php
// Global log for repository
$log = $repository->getLog();

// Log for master branch
$log = $repository->getLog('master');

// Returns last 10 commits on README file
$log = $repository->getLog('master', 'README', 0, 10);

// Returns last 10 commits on README or UPGRADE files
$log = $repository->getLog('master', ['README', 'UPGRADE'], 0, 10);
```

Counting
--------

If you want to count overall commits, without offset or limit, use the
*countCommits* method:

```php
echo sprintf('This log contains %s commits%s', $log->countCommits(), PHP_EOL);
```

`count($log)` counts them too, which is deprecated when the offset or
the limit of the log leaves commits out: gitlib 2.0 counts the commits
the log iterates.

Offset and limit
----------------

Use those methods, which return another log:

```php
$page = $log->withOffset(32)->withLimit(40);

// or read it:
$page->getOffset();
$page->getLimit();
```

`setOffset()` and `setLimit()`, which changed the log, are deprecated, as
a negative offset or limit.
