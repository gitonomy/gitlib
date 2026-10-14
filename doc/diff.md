Computing diffs
===============

Git stores snapshots of files, not diffs: gitlib computes them, like
`git diff`.

Comparing two commits
---------------------

`Repository::getDiff($from, $to)` returns the changes from the commit
`$from` to the commit `$to`. Each one is a revision name (a branch, a tag, a
hash, `master~2`...) or a `Revision` object (a `Commit`, a `Branch`...):

```php
$diff = $repository->getDiff('0.1', 'master');
```

Like `git diff 0.1 master`, it compares the files of both commits. To compare
a branch with the commit it forked from, like `git diff master...feature`,
start from their best common ancestor:

```php
$diff = $repository->getDiff($repository->getMergeBase('master', 'feature'), 'feature');
```

Calling `getDiff()` with one argument (a range like `a..b` or `a...b`, two
revisions in an array, or one revision to compare with the working tree)
is deprecated since 2.0: see [UPGRADE-2.0.md](../UPGRADE-2.0.md).
`Log::getDiff()` is deprecated too: it ignored the paths of the log.

Other diffs
-----------

-   `Commit::getDiff()` returns the changes of a commit, against its first
    parent: see [commit.md](commit.md#diff-of-a-commit).
-   `WorkingCopy::getDiff($revision)` returns the changes of the working
    tree, staged or not, since a revision (`HEAD` by default).
-   `WorkingCopy::getDiffPending()` returns the changes of the working tree
    not staged yet.
-   `WorkingCopy::getDiffStaged()` returns the changes staged in the index.

See [workingcopy.md](workingcopy.md) for the working copy.

Every diff detects renames, like `git diff -M`. `LibGit2Driver` computes
the similarity of files like libgit2 does: it can find a rename where git
finds a deletion and a creation (see
[Choosing a driver](repository.md#choosing-a-driver)). During a merge
conflict, `WorkingCopy::getDiffStaged()` and `getDiffPending()` leave the
unmerged files out, and `WorkingCopy::getDiff()` compares them, conflict
markers included, to the revision, like `git diff HEAD`.

Iterating a diff
----------------

`getFiles()` returns the files of a diff, `File` objects:

```php
$files = $diff->getFiles();
echo count($files).' files changed'.PHP_EOL;

foreach ($files as $file) {
    echo $file->getName().': +'.$file->getAdditions().' -'.$file->getDeletions().PHP_EOL;
}
```

The File object
---------------

A `File` tells what happened to a file:

```php
$file->isCreation();     // There is no old file
$file->isDeletion();     // There is no new file
$file->isModification(); // There are both: a rename, a copy or a mode change included
$file->isRename();
$file->isCopy();         // Only for a diff detecting copies, like "git diff -C"
$file->isModeChange();   // From "100644" to "100755", for example
$file->isBinary();       // A binary file has no changes
```

A type change, like a file replaced by a symbolic link, is a deletion
followed by a creation, like git shows it.

Its names, modes and blob hashes:

```php
$file->getName();    // The new name, or the old one for a deletion
$file->getOldName(); // null for a creation
$file->getNewName(); // null for a deletion

$file->getOldMode(); // "100644", "100755", "120000" (a symbolic link)... null for a creation
$file->getNewMode(); // null for a deletion

$file->getOldIndex(); // The hash of the old blob, null for a creation
$file->getNewIndex(); // The hash of the new blob, null for a deletion
```

`getOldBlob()` and `getNewBlob()` return the `Blob` objects, to read the
content of the file before and after (see [blob.md](blob.md)):

```php
if (!$file->isCreation() && !$file->isBinary()) {
    $before = $file->getOldBlob()->getContent();
}
```

They throw a `LogicException` when there is no such file (`getOldBlob()`
for a creation, `getNewBlob()` for a deletion), or when the diff has no
repository. The diffs gitlib returns have one: see
[Parsing a diff](#parsing-a-diff) for the others. They throw a
`RuntimeException` when the diff doesn't know the hash of the blob.

Two cases have no blob in the repository, and reading the content of their
`Blob` throws a `ReferenceNotFoundException`:

-   a submodule, of mode `160000`: its hashes are commits of the submodule;
-   a file of the working tree not staged yet: read it from the working
    tree instead.

The number of lines added and deleted, and the changes themselves:

```php
$file->getAdditions();
$file->getDeletions();

$file->getChanges(); // FileChange objects: see below
```

`isChangeMode()`, `isDelete()` and `getAnchor()` are deprecated since 2.0:
use `isModeChange()`, `isDeletion()` and `substr($file->getNewIndex(), 0, 12)`.

The FileChange object
---------------------

The changes of a file are its hunks, the blocks starting with `@@` in a
patch. Each one is a `FileChange` object, with the lines of the hunk,
`DiffLine` objects:

```php
use Gitonomy\Git\Diff\DiffLineType;

foreach ($file->getChanges() as $change) {
    echo sprintf('@@ -%d,%d +%d,%d @@', $change->getRangeOldStart(), $change->getRangeOldCount(), $change->getRangeNewStart(), $change->getRangeNewCount()).PHP_EOL;

    foreach ($change->getLines() as $line) {
        echo match ($line->getType()) {
            DiffLineType::Addition => '+',
            DiffLineType::Context => ' ',
            DiffLineType::Deletion => '-',
        }.$line->getContent().PHP_EOL;
    }
}
```

-   `getRangeOldStart()` and `getRangeOldCount()` are the first line of the
    hunk in the old file, and its number of lines there;
-   `getRangeNewStart()` and `getRangeNewCount()` are the same in the new
    file;
-   `DiffLine::getType()` returns a `DiffLineType`: `Addition`, `Context` or
    `Deletion`;
-   `DiffLine::getContent()` returns the line, without its prefix (`+`,
    `-` or a space) nor its line break.

`getAdditions()` and `getDeletions()` count the lines the hunk adds and
deletes.

Reading a line as an array (`[$type, $content]`), the `FileChange::LINE_ADD`,
`LINE_CONTEXT` and `LINE_REMOVE` constants, and `FileChange::getCount()` are
deprecated since 2.0: use `getType()` and `getContent()`, `DiffLineType`,
and `getAdditions()` or `getDeletions()`.

Parsing a diff
--------------

`Diff::parse()` reads the output of `git diff --raw --patch`, with `-z` or
not. The raw information gives the names, modes, hashes and status of the
files: a patch without it throws an `InvalidArgumentException`. What the
parser doesn't know throws a `RuntimeException`, like the log of a submodule
that `git diff` writes instead of its patch, when the configuration sets
`diff.submodule`: pass `--submodule=short`. Pass the repository to read the
blobs of the files. Here, the output comes from `Repository::run()`, which
needs `CliDriver`:

```php
use Gitonomy\Git\Diff\Diff;

$output = $repository->run('diff', ['--raw', '--patch', '--submodule=short', '0.1', 'master']);
$diff = Diff::parse($output, $repository);
```

`getRawDiff()` returns the output a diff was parsed from: for a diff gitlib
reads with `CliDriver`, the one of `git diff --raw -z --patch`, NUL bytes
included, with the options gitlib sets. It is empty for the diffs of
`LibGit2Driver`, which runs no git command.

Storing a diff
--------------

`toArray()` exports a diff as an array of scalars, to store it in a cache
or as JSON. `Diff::fromArray()` reads it back, with the repository to read
the blobs from:

```php
use Gitonomy\Git\Diff\Diff;

$array = $diff->toArray();

$diff = Diff::fromArray($array, $repository);
```

The arrays of 1.x are read too.

`Diff::setRepository()`, `File::setRepository()` and `File::addChange()` are
deprecated since 2.0: pass the repository to `Diff::parse()` or
`Diff::fromArray()`, and the changes to the constructor of `File`.
