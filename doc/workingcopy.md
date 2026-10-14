Working copy
============

Working copy is the folder associated to a git repository. In *gitlib*,
you can access this object using the *getWorkingCopy* on a *Repository*
object:

```php
use Gitonomy\Git\Repository;

$repo = new Repository('/path/to/working-dir');
$wc = $repo->getWorkingCopy();
```

A bare repository has no working copy: `getWorkingCopy()` throws a
`LogicException`.

Checkout a revision
-------------------

You can checkout any revision using *checkout* method. Like `git checkout`,
it attaches `HEAD` to a local branch, and detaches it on anything else: a
tag, a commit, a remote branch...

```php
// git checkout master
$wc->checkout('master');

// git checkout v1.0 (HEAD is detached)
$wc->checkout('v1.0');
```

You can also pass a *Revision*: a *Commit*, a *Reference*... A local *Branch*
object attaches `HEAD` to the branch.

```php
$wc->checkout($repo->getReferences()->getBranch('master'));
```

A name that only exists as a remote branch, like `feature` for
`origin/feature`, creates the local tracking branch with `CliDriver`, like
`git checkout` does. `LibGit2Driver` throws instead.

To create a branch and check it out, use *checkoutNewBranch*. Its second
argument is the revision to start the branch from, `HEAD` by default:

```php
// git checkout -b feature
$wc->checkoutNewBranch('feature');

// git checkout -b develop origin/develop
$wc->checkoutNewBranch('develop', 'origin/develop');
```

Like `git checkout`, both methods keep the local changes, and refuse to
overwrite the ones the checkout would change: they throw a
`DriverException`.

Passing the branch to create as second argument of *checkout* is deprecated
since 2.0: use *checkoutNewBranch*.

Merge a revision
----------------

You can merge any revision into the current branch using the *merge* method.
A second argument, a `FastForward`, tells whether the merge fast-forwards
the branch when its history allows it:

```php
use Gitonomy\Git\FastForward;

// git merge origin/develop
$wc->merge('origin/develop');

// git merge --no-ff origin/develop
$wc->merge('origin/develop', FastForward::Never);

// git merge --ff-only origin/develop
$wc->merge('origin/develop', FastForward::Only);
```

`FastForward::Allow`, the default, fast-forwards when possible and creates a
merge commit otherwise. `FastForward::Never` always creates a merge commit.
`FastForward::Only` throws instead of creating one.

You can also pass a *Revision*. A `DriverException` is thrown if the merge
fails: a conflict, staged changes, a revision whose history is unrelated to
the branch...

With `CliDriver`, a conflict leaves the working copy merging, with conflict
markers in the files, like `git merge`. To cancel the merge:

```php
$repo->run('merge', ['--abort']);
```

> **note**
>
> With `LibGit2Driver` (see [Choosing a driver](repository.md#choosing-a-driver)),
> `merge()` only supports fast-forward and conflict-free merges: a conflict
> throws without changing anything, instead of leaving conflict markers in
> the working copy.

Passing arguments of `git merge` as an array (`[]`, `['--no-ff']` or
`['--ff-only']`) is deprecated since 2.0: pass a `FastForward`.

Modifications since a revision
------------------------------

You can get the modifications of the working copy, staged or not, since a
revision, `HEAD` by default:

```php
// git diff HEAD
$diff = $wc->getDiff();

// git diff master
$diff = $wc->getDiff('master');
```

Calling `Repository::getDiff()` with one revision, to compare it with the
working copy, is deprecated since 2.0: use `WorkingCopy::getDiff()`.

Staged modifications
--------------------

You can get a diff of modifications pending in staging area. To get the
`Diff` object, call method `getDiffStaged()`:

```php
$diff = $wc->getDiffStaged();
```

Pending modifications
---------------------

You can get pending modifications on tracked files, not staged yet, by
calling method `getDiffPending()`:

```php
$diff = $wc->getDiffPending();
```

During a merge conflict, `getDiffStaged()` and `getDiffPending()` leave the
unmerged files out, and `getDiff()` compares them, conflict markers included,
to the revision, like `git diff HEAD`.

See [Diff](diff.md) to read these diffs.

Untracked files
---------------

To list the files git doesn't track, ignored files excluded, call
`getUntrackedFiles()`. It returns their paths, relative to the root of the
working copy:

```php
$files = $wc->getUntrackedFiles(); // ['new-file.txt', 'new-directory/other-file.txt']
```
