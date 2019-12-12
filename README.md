Git lib for Gitonomy
====================

[![Build Status](https://img.shields.io/travis/gitonomy/gitlib/master.svg?style=flat-square)](https://travis-ci.org/gitonomy/gitlib)
[![StyleCI](https://github.styleci.io/repos/5709354/shield?branch=master)](https://github.styleci.io/repos/5709354)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](https://opensource.org/licenses/MIT)

This library provides methods to access Git repository from PHP.

It makes shell calls, which makes it less performant than any solution.

Anyway, it's convenient and don't need to build anything to use it. That's how we love it.

## Documentation

* [Overview](doc/index.md)
* [Debug](doc/debug.md)
* [Development](doc/development.md)
* [Installation](doc/installation.md)
* API
  + [Admin](doc/api/admin.md)
  + [Blame](doc/api/blame.md)
  + [Blob](doc/api/blob.md)
  + [Branch](doc/api/branch.md)
  + [Commit](doc/api/commit.md)
  + [Diff](doc/api/diff.md)
  + [Hooks](doc/api/hooks.md)
  + [Log](doc/api/log.md)
  + [References](doc/api/references.md)
  + [Repository](doc/api/repository.md)
  + [Revision](doc/api/revision.md)
  + [Tree](doc/api/tree.md)
  + [Working Copy](doc/api/workingcopy.md)

## Quick Start

You can install git lib using [Composer](https://getcomposer.org/). Simply require the version you need:

```bash
$ composer require gitonomy/gitlib
```

or edit your `composer.json` file by hand:

```json
{
    "require": {
        "gitonomy/gitlib": "^1.1"
    }
}
```

## For Enterprise

Available as part of the Tidelift Subscription

The maintainers of `gitonomy/gitlib` and thousands of other packages are working with Tidelift to deliver commercial support and maintenance for the open source dependencies you use to build your applications. Save time, reduce risk, and improve code health, while paying the maintainers of the exact dependencies you use. [Learn more.](https://tidelift.com/subscription/pkg/packagist-gitonomy-gitlib?utm_source=packagist-gitonomy-gitlib&utm_medium=referral&utm_campaign=enterprise&utm_term=repo)

