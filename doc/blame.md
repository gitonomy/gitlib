Blaming files
=============

`Repository::getBlame()` returns, for each line of a file, the commit that
last changed it, like `git blame`. It takes a revision (a name or a
`Revision` object) and the path of the file, relative to the root of the
repository:

```php
$blame = $repository->getBlame('master', 'README.md');
```

To blame some lines only, pass the first and the last ones, numbered from
1:

```php
// Lines 2 to 3
$blame = $repository->getBlame('master', 'README.md', 2, 3);

// From line 2 to the end of the file
$blame = $repository->getBlame('master', 'README.md', 2);
```

Like git, the blame stops at the end of the file, and a first line after it
throws a `DriverException`. A last line before the first one blames the lines
in order, like `git blame -L 5,3`.

Passing a range as a string (`'2,3'` or `'2,+2'`, like `git blame -L`) is
deprecated since 2.0: pass the first and the last lines instead.

Line-per-line iteration
-----------------------

`getLines()` returns the lines of the blame, `Blame\Line` objects, keyed by
their number in the file:

```php
foreach ($blame->getLines() as $number => $line) {
    echo $number.': '.$line->getContent().'    - '.$line->getCommit()->getAuthorName().PHP_EOL;
}
```

With a range, the keys start at its first line, not at 1.

To get a line by its number in the file:

```php
$line = $blame->getLine(2);
```

It throws an `InvalidArgumentException` when the blame doesn't have this
line. `count($blame)` returns the number of lines blamed.

The Line object
---------------

A `Blame\Line` is a line of the blamed file:

```php
$line->getNumber();         // Its number in the blamed file
$line->getOriginalNumber(); // Its number in the file of the commit that last changed it
$line->getContent();        // Its text, without its line break
$line->getCommit();         // The commit that last changed it

// The author of the commit
$author = $line->getCommit()->getAuthorName();
```

`Line::getLine()` is deprecated since 2.0: use `getOriginalNumber()`, which
returns an integer.

Group reading by commit
-----------------------

To display a blame, you'll probably group the consecutive lines last
changed by the same commit. `getHunks()` returns these groups, `Blame\Hunk`
objects, with their commit and their lines, keyed by their number in the
file:

```php
foreach ($blame->getHunks() as $hunk) {
    echo $hunk->getCommit()->getShortHash().' '.$hunk->getCommit()->getSubjectMessage().PHP_EOL;

    foreach ($hunk->getLines() as $number => $line) {
        echo '    '.$number.': '.$line->getContent().PHP_EOL;
    }
}
```

`getGroupedLines()` is deprecated since 2.0: use `getHunks()`.
