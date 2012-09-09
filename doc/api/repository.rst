Repository methods
==================

Creating a *Repository* object is possible, providing a *path* argument to the
constructor:

.. code-block:: php

    $repository = new Repository('/path/to/repo');

Compute size of a repository
----------------------------

To know how much size a repository is using on your drive, you can use
``getSize`` method on a *Repository* object.

This method will basically compute the size of the folder, using system ``du`` tool.

The returned size is in kilobytes:

.. code-block:: php

    <?php

    $size = $repository->getSize();
    echo "Your repository size is ".$size."KB";
