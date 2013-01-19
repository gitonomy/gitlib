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

    $repository = Gitonomy\Git\Admin::init('/tmp/git_sandbox', false);

Cloning repositories
::::::::::::::::::::

You can clone a repository from an URL by doing:

.. code-block:: php

    $repository = Gitonomy\Git\Admin::cloneTo('/tmp/gitlib', 'https://github.com/gitonomy/gitlib.git');

You can pass ``false`` and third argument to get a repository with a working copy.

If you already have a Repository instance and want to clone it, you can use this shortcut:

.. code-block:: php

    $new = $repository->cloneTo('/tmp/clone');

References
::::::::::

* http://linux.die.net/man/1/git-init
* http://linux.die.net/man/1/git-clone
