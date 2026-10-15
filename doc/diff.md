Computing diff
==============

Even if git is a diff-less storage engine, it's possible to compute
them.

To compute a diff, pass the two revisions to compare to the *getDiff*
method of the repository:

```php
$diff = $repository->getDiff('master@{2 days ago}', 'master');
```

For more informations about git revisions: *man gitrevisions*.

To compare with the best common ancestor, like `git diff a...b`:

```php
$diff = $repository->getDiff($repository->getMergeBase('a', 'b'), 'b');
```

To compare the working tree, staged or not, with a revision:

```php
$diff = $repository->getWorkingCopy()->getDiff('master');
```

Calling `getDiff()` with one argument, a range or a list of revisions, is
deprecated, as `Log::getDiff()`.

To parse the output of `git diff --raw --patch` yourself, give the
repository to read the blobs from:

```php
use Gitonomy\Git\Diff\Diff;

$diff = Diff::parse($output, $repository);
```

`Diff::setRepository()` and `File::setRepository()` are deprecated.

Iterating a diff
----------------

When you have a *Diff* object, you can iterate over files using method
*getFiles()*. This method returns a list of *File* objects, who
represents the modifications for a single file.

```php
$files = $diff->getFiles();
echo sprintf('%s files modified%s', count($files), PHP_EOL);

foreach ($files as $fileDiff) {
    echo sprintf('Old name: (%s) %s%s', $fileDiff->getOldMode(), $fileDiff->getOldName(), PHP_EOL);
    echo sprintf('New name: (%s) %s%s', $fileDiff->getNewMode(), $fileDiff->getNewName(), PHP_EOL);
}
```

The File object
---------------

Here is an exhaustive list of the *File* class methods:

```php
$file->getOldName();
$file->getNewName();
$file->getOldDiff();
$file->getNewDiff();

$file->isCreation();
$file->isDeletion();
$file->isModification();

$file->isRename();
$file->isModeChange();

$file->getAdditions(); // Number of added lines
$file->getDeletions(); // Number of deleted lines

$file->isBinary(); // Binary files have no "lines"

$file->getChanges(); // See next chapter
```

The FileChange object
---------------------

> **note**
>
> This part of API is not very clean, very consistent. If you have any
> idea or suggestion on how to enhance this, your comment would be
> appreciated.

A *File* object is composed of many changes. For each of those changes,
a *FileChange* object is associated.

To access changes from a file, use the *getChanges* method. The
*getDiffLines* method of a change returns its lines, `DiffLine` objects:

```php
use Gitonomy\Git\Diff\DiffLineType;

foreach ($file->getChanges() as $change) {
    foreach ($change->getDiffLines() as $line) {
        echo match ($line->getType()) {
            DiffLineType::Addition => '+',
            DiffLineType::Context => ' ',
            DiffLineType::Deletion => '-',
        }.$line->getContent().PHP_EOL;
    }
}
```

`DiffLine::getContent()` returns the line, without its prefix (`+`, `-`
or a space) nor its line break.

`FileChange::getLines()`, which returns the lines as arrays
(`[$type, $content]`), the `FileChange::LINE_ADD`, `LINE_CONTEXT` and
`LINE_REMOVE` constants, and passing the lines to the constructor of
`FileChange` as arrays are deprecated: use `getDiffLines()`, `DiffLineType`
and `DiffLine` objects.

To get line numbers, use the range methods:

```php
echo sprintf('Previously %s lines from line %s%s', $change->getRangeOldCount(), $change->getRangeOldStart(), PHP_EOL);
echo sprintf('Now %s lines from line %s%s', $change->getRangeNewCount(), $change->getRangeNewStart(), PHP_EOL);
```
