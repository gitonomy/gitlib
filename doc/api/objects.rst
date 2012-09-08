Accessing repository objects
============================

In git, everything is an object: commits, trees, and files.

Blob
----

A blob represents a file content. You can't access the file name directly from
the blob object; the filename information is stored within the tree.

It means that for git, two files with different names but same content will
have the sample hash.

To access a repository blob so, you need the hash identifier:

.. code-block:: php

    <?php
    $repository = new Gitonomy\Git\Repository('/path/to/repository');

    $blob =$repository->getBlob('a7c8d2b4');

    // Display blob hash
    echo $blob->getHash();

    // Displays content of the blob
    echo $blob->getContent();
