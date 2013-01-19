Administration of git repositories
==================================

Initializing repositories
:::::::::::::::::::::::::

Create a bare repository
------------------------

You can create a bare repository using ``Admin::init``:

.. code-block:: php

    $repository = Gitonomy\Git\Admin::init('/path/to/repository');

Create a repository with a working copy
---------------------------------------

If you want to create a repository with a working directory, pass ``false`` as
second argument:

.. code-block:: php

    $repository = Gitonomy\Git\Admin::init('/path/to/folder', false);

References
::::::::::

- http://linux.die.net/man/1/git-init
