Blob
====

In git, a blob represents a file content. You can't access the file name
directly from the *Blob* object; the filename information is stored within
the tree, not in the blob.

It means that for git, two files with different names but same content will
have the same hash.

To access a repository *Blob*, you need the hash identifier:

.. code-block:: php

    $repository = new Gitonomy\Git\Repository('/path/to/repository');
    $blob = $repository->getBlob('a7c8d2b4');

Get content
-----------

To get content from a *Blob* object:

.. code-block:: php

    echo $blob->getContent();
