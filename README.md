Gitlib for Gitonomy
===================

[![Build Status](https://img.shields.io/travis/com/gitonomy/gitlib/master.svg?style=flat-square)](https://travis-ci.com/gitonomy/gitlib)
[![StyleCI](https://github.styleci.io/repos/5709354/shield?branch=master)](https://github.styleci.io/repos/5709354)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](https://opensource.org/licenses/MIT)

This library provides methods to access Git repository from PHP 5.6 or 7.

It makes shell calls, which makes it less performant than any solution.

Anyway, it's convenient and don't need to build anything to use it. That's how we love it.

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
        "gitonomy/gitlib": "^1.2"
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

+ [Admin](doc/admin.md)
+ [Blame](doc/blame.md)
+ [Blob](doc/blob.md)
+ [Branch](doc/branch.md)
+ [Commit](doc/commit.md)
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
