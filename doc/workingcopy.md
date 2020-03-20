Working copy
============

Working copy is the folder associated to a git repository. In *gitlib*,
you can access this object using the *getWorkingCopy* on a *Repository*
object:

```php
$repo = new Repository('/path/to/working-dir');
$wc = $repo->getWorkingCopy();
```

Checkout a revision
-------------------

You can checkout any revision using *checkout* method. You can also pass
a second argument, which will be passed as argument with `-b`:

```php
// git checkout master
$wc->checkout('master');

// git checkout origin/master -b master
$wc->checkout('origin/master', 'master');
```

You can also pass a *Reference* or a *Commit*.

Staged modifications
--------------------

You can get a diff of modifications pending in staging area. To get the
`Diff` object, call method `getDiffStaged()`:

```php
$diff = $wc->getDiffStaged();
```

Pending modifications
---------------------

You can get pending modifications on tracked files by calling method
`getDiffPending()`:

```php
$diff = $wc->getDiffPending();
```

Staging file(s)
---------------

You can stage changed files by calling `stage(...files)`:

```php
$wc->stage('file1.txt', 'file2.txt');
```

Unstaging file(s)
-----------------

You can unstage files by calling `unstage(...files)`:

```php
$wc->unstage('file1.txt', 'file2.txt');
```

Discard file(s) changes
-----------------------

You can discard file(s) changed by calling `discard(...files)`:

```php
$wc->discard('file1.txt', 'file2.txt');
```

Committing
---------

You can commit changed files by calling `commit(message, author, ...files)`:

NOTE :: this will commit all staged files, the ...files argument simply stages those files before commiting, if you already have files staged by having called `stage(...files)` these files are included in the commit

```php
$wc->commit('summary', 'author', 'file1.txt', 'file2.txt');
```
