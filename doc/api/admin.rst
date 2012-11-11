Create git repositories
=======================

Initialize a repository
-----------------------

Bare repository
:::::::::::::::

You can create a bare repository using ``Admin::init``:

.. code-block:: php

    use Gitonomy\Git\Admin;

    $repository = Admin::init('/path/to/repository');

Non-bare repository
:::::::::::::::::::

If you want to create a repository with a working directory, pass ``false`` as
second argument:

.. code-block:: php

    $repository = Gitonomy\Git\Admin::init('/path/to/folder', false);
