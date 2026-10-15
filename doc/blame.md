Blaming files
=============

Line-per-line iteration
-----------------------

To iterate on lines of a blame:

```php
$blame = $repository->getBlame('master', 'README.md');

foreach ($blame->getLines() as $lineNumber => $line) {
    $commit = $line->getCommit();
    echo $lineNumber.': '.$line->getContent().'    - '.$commit->getAuthorName().PHP_EOL;
}
```

The *getLines* method returns an array indexed starting from 1.

As you can see, you can access the commit object related to the line you
are iterating on.

If you want to access directly a line:

```php
$line = $blame->getLine(32);
```

To blame some lines only, pass the first and the last ones:

```php
$blame = $repository->getBlame('master', 'README.md', 10, 20);
```

`getLines()` and `getLine()` number these lines from 1, where gitlib 2.0
numbers them like the file: use `Line::getNumber()`. A range of lines as
a string (`'10,20'`, `'10,+11'`, a regular expression), passed to
`git blame -L`, is deprecated.

The Line object
---------------

LineObject represents an item of the blame file. It is composed of those
informations:

```php
$line->getCommit();         // returns a Commit
$line->getContent();        // returns text
$line->getNumber();         // the number of the line in the file
$line->getOriginalNumber(); // its number in the file of the commit

// you can access author from commmit:
$author = $line->getCommit()->getAuthorName();
```

Group reading by commit
-----------------------

If you plan to display it, you'll probably need a version where lines
from same commit are grouped.

To do so, use the *getHunks* method, which returns `Blame\Hunk` objects,
the consecutive lines last changed by the same commit, by number in the
file:

```php
foreach ($blame->getHunks() as $hunk) {
    echo $hunk->getCommit()->getHash().PHP_EOL;

    foreach ($hunk->getLines() as $number => $line) {
        echo $number.': '.$line->getContent().PHP_EOL;
    }
}
```

`getGroupedLines()` and `Line::getLine()` are deprecated.
