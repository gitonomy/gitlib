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

You can checkout any revision using *checkout* method, and create a
branch with *checkoutNewBranch*:

```php
// git checkout master
$wc->checkout('master');

// git checkout -b master origin/master
$wc->checkoutNewBranch('master', 'origin/master');
```

You can also pass a *Revision*, like a *Reference* or a *Commit*. Passing
the branch to create as second argument of `checkout()` is deprecated, as
a local *Branch* object, which detaches HEAD: pass its name to attach HEAD
to it, like gitlib 2.0 does with the object.

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

All modifications
-----------------

You can get the modifications of the working tree, staged or not, since
a revision, `HEAD` by default:

```php
$diff = $wc->getDiff();
$diff = $wc->getDiff('master');
```
