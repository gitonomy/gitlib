Blaming files
=============

To iterate on lines of a blame:

.. code-block:: php

    $blame = $repository->getBlame('master', 'README.md');

    foreach ($blame->getLines() as $line) {
        $commit = $line->getCommit();
        echo $line->getLine().': '.$line->getContent()."    - ".$commit->getAuthorName()."\n";
    }

As you can see, you can access the commit object related to the line you are iterating on.

If you want to access directly a line:

.. code-block:: php

    $blame->getLine(32)
