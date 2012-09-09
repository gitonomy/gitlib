Commit
======

To access a *Commit*, starting from a repository object:

.. code-block:: php

    <?php
    $repository = new Gitonomy\Git\Repository('/path/to/repository');
    $commit = $repository->getCommit('a7c8d2b4');

Browsing parents
----------------

A *Commit* can have a natural number of parents:

* **no parent**: it's an initial commit, the root of a tree
* **one parent**: it means it's not a merge, just a regular commit
* **many parents**: it's a merge-commit

You have 2 methods available for accessing parents:

.. code-block:: php

    // Access parent hashes
    $hashes = $commit->getParentHashes();

    // Access parent commit objects
    $commits = $commit->getParents();

For example, if you want to display all parents, starting from a commit:

.. code-block:: php

    <?php
    function displayLog(Gitonomy\Git\Commit $commit) {
        echo '- '.$commit->getShortMessage()."\n";
        foreach ($commit->getParents() as $parent) {
            displayLog($parent);
        }
    }

Notice that this function will first display all commits from first merged
branch and then display all commits from next branch, and so on.

Accessing tree
--------------

The tree object contains the reference to the files associated to a given
commit. Every commit has one and only one tree, referencing all files and
folders of a given state for a project. For more informations about the tree,
see the chapter dedicated to it.

To access a tree starting from a commit:

.. code-block:: php

    <?php

    // Returns the tree hash
    $tree = $commit->getTreeHash();

    // Returns the tree object
    $tree = $commit->getTree();
