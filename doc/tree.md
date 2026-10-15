Tree and files
==============

To organize folders, git uses trees. In gitlib, those trees are
represented via *Tree* object.

To get the root tree associated to a commit, use the *getTree* method on
the commit object:

```php
$tree = $commit->getTree();
```

This tree is the entry point of all of your files.

Iterating a tree gives its entries, `TreeEntry` objects, by name. An
entry has a name, a mode and an object: a `Blob`, a `Tree` or a
`Submodule`.

Let's understand how it works with a concrete example:

```php
function displayTree(Tree $tree, $indent = 0)
{
    foreach ($tree as $name => $entry) {
        if ($entry->getObject() instanceof Tree) {
            echo str_repeat(' ', $indent).$name.'/'.PHP_EOL;
            displayTree($entry->getObject(), $indent + 1);
        } else {
            echo str_repeat(' ', $indent).$name.PHP_EOL;
        }
    }
}

displayTree($commit->getTree());
```

This method will recursively display all entries of a tree.

`getBlobs()`, `getTrees()` and `getSubmodules()` return the entries of
one type, by name. A submodule is a `Submodule`, with the hash of the
commit it points to: check it with `instanceof Submodule`. The
`CommitReference` class of its objects, which extends `Submodule`, is
deprecated.

`getEntries()`, `getBlobEntries()`, `getTreeEntries()` and
`getCommitReferenceEntries()`, which return the entries as arrays
(`[$mode, $object]`), are deprecated: iterate the tree, or use
`getBlobs()`, `getTrees()` and `getSubmodules()`.

Resolve a path
--------------

To access directly a sub-file, the easier is probably to use the
*resolvePath* method.

An example:

```php
$source = $tree->resolvePath('src/Gitonomy/Git');

$source instanceof Tree;
```
