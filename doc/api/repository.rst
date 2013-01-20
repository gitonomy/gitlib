Repository methods
==================

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

Access HEAD
-----------

``HEAD`` represents in git the version you are working on (in working tree).
Your ``HEAD`` can be attached (using a reference) or detached (using a commit).

.. code-block:: php

    $head = $repository->getHead(); // Commit or Reference
    $head = $repository->getHeadCommit(); // Commit

    if ($repository->isHeadDetached()) {
        echo "Sorry man\n";
    }

Logger
------

If you are developing, you may appreciate to have a logger inside repository, telling
you every executed command.

To do so, inject a logger inside the Repository:

.. code-block:: php

    $repository->setLogger(new Monolog\Logger('repository'));

    $repository->run('fetch', array('--all'));

This will output:

.. code-block:: text

    info run command: fetch "--all"
    debug last command (fetch) duration: 23.24ms
    debug last command (fetch) return code: 0
    debug last command (fetch) output: Fetching origin
