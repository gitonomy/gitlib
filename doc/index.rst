Git lib
=======

This library is a PHP-library for browsing git repositories.

Whereas other libraries aim to have performance with system libraries, this
one aims to be usable without recompiling PHP. For this reason, the performance
of this library is out of scope if you have requirements on performance.

Requirements
------------

Minimum PHP version: PHP 5.3

To use this library, you must have *git* installed and available in shell.

This library will execute git commands, like ``git log --all --limit=30`` or
``git cat-file -p a7b8c4de``.

Table of contents
-----------------

.. toctree::
   :maxdepth: 2

   api

Example
-------

.. code-block:: php

    $repository = new Gitonomy\Git\Repository('/path/to/repository');
    $master = $repository
        ->getReferences()
        ->getBranch('master')
    ;

    $author = $master->getCommit()->getAuthorName();

    echo "Last modification on master made by ".$author;

