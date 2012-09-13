Tags and branches
=================

Accessing tags and branches
---------------------------

With *gitlib*, you can access them via the *ReferenceBag* object. To get this
object from a *Repository*, use the *getReferences* method:

.. code-block:: php

    $references = $repository->getReferences();

If you want to access all branches or all tags:

.. code-block:: php

    $branches = $repository->getBranches();
    $tags     = $repository->getTags();
    $all      = $repository->getAll();

To get a branch or a tag, use the *getBranch* or *getTag* method on the
*ReferenceBag*. This method will return a *Branch* object or a *Tag* object:

.. code-block:: php

    $master = $references->getBranch('master');
    $v0_1 = $references->getTag('0.1');

If the reference cannot be resolved, a *ReferenceNotFoundException* will be
thrown.

On each of those objects, you can access those informations:

.. code-block:: php

    // Get the associated commit
    $commit = $master->getCommit();

    // Get the commit hash
    $hash = $master->getCommitHash();

    // Get the last modification
    $lastModification = $master->getLastModification();

Resolution from a commit
------------------------

To resolve a branch or a commit from a commit, you can use the *resolveTags*
and *resolveBranches* methods on it:

.. code-block:: php

    $branches = $references->resolveBranches($commit);
    $tags     = $references->resolveTags($commit);

    // Resolve branches and tags
    $all      = $references->resolve($commit);

You can pass a *Commit* object or a hash to the method, gitlib will handle it.
