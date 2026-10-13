Tree and files
==============

Git stores folders as trees. In gitlib, a tree is a `Tree` object.

The root tree of a commit is the entry point of all its files:

```php
$tree = $commit->getTree();
```

`Repository::getTree()` takes the hash of a tree, abbreviated or not.

Entries of a tree
-----------------

`getEntries()` returns the entries of a tree, keyed by their name. Each one
is a `TreeEntry` object, with the name of the entry, its mode, and its
object: a `Blob` for a file (see [blob.md](blob.md)), a `Tree` for a folder,
or a `Submodule`.

```php
foreach ($tree->getEntries() as $name => $entry) {
    $entry->getName();   // The name of the file or the folder, the same as $name
    $entry->getMode();   // The mode git stores: "100644", "100755" (executable), "120000" (symbolic link), "040000" (tree), "160000" (submodule)
    $entry->getObject(); // A Blob, a Tree or a Submodule
}
```

To keep one kind of entries only, also keyed by their name:

```php
$folders = $tree->getTreeEntries();
$files = $tree->getBlobEntries();
$submodules = $tree->getSubmoduleEntries();
```

To get the object of an entry by its name:

```php
$readme = $tree->getEntry('README.md'); // A Blob
```

It throws an `InvalidArgumentException` when the tree has no such entry.

Here is a function displaying a tree and its folders, recursively:

```php
use Gitonomy\Git\Submodule;
use Gitonomy\Git\Tree;

function displayTree(Tree $tree, int $depth = 0): void
{
    foreach ($tree->getEntries() as $name => $entry) {
        $object = $entry->getObject();

        echo str_repeat('    ', $depth).match (true) {
            $object instanceof Tree => $name.'/',
            $object instanceof Submodule => $name.' @ '.$object->getHash(),
            default => $name,
        }.PHP_EOL;

        if ($object instanceof Tree) {
            displayTree($object, $depth + 1);
        }
    }
}

displayTree($commit->getTree());
```

Reading an entry as an array (`[$mode, $object]`) is deprecated since 2.0:
use `getMode()` and `getObject()`.

Submodules
----------

A submodule is a commit of another repository. Its `Submodule` object only
holds the hash of this commit: gitlib doesn't read the submodule. Open it as
a repository, from its folder in the working tree, to read it.

```php
foreach ($tree->getSubmoduleEntries() as $name => $entry) {
    echo $name.' is at '.$entry->getObject()->getHash().PHP_EOL;
}
```

`CommitReference` is deprecated since 2.0: it is `Submodule`. So is
`Tree::getCommitReferenceEntries()`: use `getSubmoduleEntries()`.

Resolve a path
--------------

To access a file or a folder from its path, use `resolvePath()`. It returns
a `Blob`, a `Tree` or a `Submodule`:

```php
$folder = $tree->resolvePath('src/Gitonomy/Git'); // A Tree
$file = $tree->resolvePath('src/Gitonomy/Git/Repository.php'); // A Blob
```

-   The path is relative to the tree.
-   `""` and `"/"` resolve to the tree itself. Leading, trailing and
    repeated slashes are ignored.
-   A path that doesn't exist throws an `InvalidArgumentException`, as a
    path going through a file or a submodule (`README.md/foo`).
