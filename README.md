Gitlib for Gitonomy
===================

This library provides methods to access Git repository from PHP 8.4+.

By default, it runs the `git` binary (1.8.4 or later) for every operation: convenient, and it
doesn't need anything built to use. It can instead run [libgit2](https://libgit2.org/) in-process
via PHP's `FFI` extension, an experimental driver which is faster for small reads but slower on
long histories: see
["Choosing a driver"](doc/repository.md#choosing-a-driver) and
["Performance"](doc/repository.md#performance).

Quick Start
-----------

You can install gitlib using [Composer](https://getcomposer.org/). Simply require the version you need:

```bash
$ composer require gitonomy/gitlib
```

or edit your `composer.json` file by hand:

```json
{
    "require": {
        "gitonomy/gitlib": "^1.3"
    }
}
```

Example Usage
-------------

```php
<?php

use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repository');

foreach ($repository->getReferences()->getBranches() as $branch) {
    echo '- '.$branch->getName().PHP_EOL;
}

$repository->run('fetch', ['--all']);
```

API Documentation
-----------------

+ [Blame](doc/blame.md)
+ [Blob](doc/blob.md)
+ [Branch](doc/branch.md)
+ [Commit](doc/commit.md)
+ [Creating repositories](doc/admin.md)
+ [Diff](doc/diff.md)
+ [Hooks](doc/hooks.md)
+ [Log](doc/log.md)
+ [References](doc/references.md)
+ [Repository](doc/repository.md)
+ [Revision](doc/revision.md)
+ [Tree](doc/tree.md)
+ [Working Copy](doc/workingcopy.md)

For Enterprise
--------------

Available as part of the Tidelift Subscription

The maintainers of `gitonomy/gitlib` and thousands of other packages are working with Tidelift to deliver commercial support and maintenance for the open source dependencies you use to build your applications. Save time, reduce risk, and improve code health, while paying the maintainers of the exact dependencies you use. [Learn more.](https://tidelift.com/subscription/pkg/packagist-gitonomy-gitlib?utm_source=packagist-gitonomy-gitlib&utm_medium=referral&utm_campaign=enterprise&utm_term=repo)
