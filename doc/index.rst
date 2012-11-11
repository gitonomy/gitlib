gitlib - PHP-library to access git
==================================

View on Github: https://github.com/gitonomy/gitlib

This library is a PHP-library for browsing git repositories.

This library relies on ``git`` command.

Example
-------

.. code-block:: php

    $repository = new Gitonomy\Git\Repository('/path/to/repository');
    $master     = $repository->getReferences()->getBranch('master');

    $author = $master->getCommit()->getAuthorName();

    echo "Last modification on master made by ".$author;

Documentation
-------------

.. toctree::
   :maxdepth: 2

   api

Features
--------

* Create repositories
* Manage hooks in repositories

Missing features
----------------

* Remote management
* Clone

Requirements
------------

**Minimum PHP version**: PHP 5.3.3

To use this library, you must have *git* installed and available in shell.
