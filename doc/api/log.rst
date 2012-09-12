Getting log history
===================

Crawling manually commits and parents to browse history is surely a good
solution. But when it comes to ordering them or aggregate them from multiple
branches, we tend to use ``git log``.

*gitlib* provides a dedicated object to manipulate log: *Log*.

To get a log from a repository:

.. code-block:: php

    $log = $repository->getLog('master'); // specify a revision
    $log = $repository->getLog(); // returns all branches history

This method will return a *Log* object. At this moment, the command was not
launched, we are still waiting for fine tuning:

Couting
-------

If you want to count overall commits, without offset or limit, use the *countCommits* method:

.. code-block:: php

    echo sprintf("This log contains %s commits\n", $log->countCommits());

Offset and limit
----------------

Use those methods:

.. code-block:: php

    $log->setOffset(32);
    $log->setLimit(40);

    // or read it:
    $log->getOffset();
    $log->getLimit();
