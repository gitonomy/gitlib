Repository methods
==================

.. code-block:: php

    $repository = new Repository('/path/to/repository');

    if ($repository->isBare()) {
        echo "This repository is bare\n";
    }

    echo "Your repository size is ".$repository->getSize()."kb";

Creating a *Repository* object is possible, providing a *path* argument to the
constructor:

.. code-block:: php

    $repository = new Repository('/path/to/repo');

Test if a repository is bare
----------------------------

On a *Repository* object, you can method *isBare* to test it:

.. code-block:: php

    if ($repository->isBare()) {
        echo "This repository is bare\n";
    }

Compute size of a repository
----------------------------

To know how much size a repository is using on your drive, you can use
``getSize`` method on a *Repository* object.

This method will basically compute the size of the folder, using system ``du`` tool.

The returned size is in kilobytes:

.. code-block:: php

    $size = $repository->getSize();
    echo "Your repository size is ".$size."KB";
