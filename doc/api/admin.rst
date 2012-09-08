Administration methods
======================

Create a new repository
-----------------------

You can create a new repository like this:

.. code-block:: php

    <?php
    $repository = Gitonomy\Git\Admin::create('/path/to/folder');
    // Folder /path/to/folder contains git repository files: objects, HEAD...

By default, repository will be a bare repository. If you want to create a
repository with a working directory, pass ``false`` as second argument:

.. code-block:: php

    <?php
    $repository = Gitonomy\Git\Admin::create('/path/to/folder', false);
    // Folder /path/to/folder contains .git folder
