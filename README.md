Git lib for Gitonomy
====================

[![Build Status](https://img.shields.io/travis/gitonomy/gitlib/master.svg?style=flat-square)](https://travis-ci.org/gitonomy/gitlib)
[![StyleCI](https://github.styleci.io/repos/5709354/shield?branch=master)](https://github.styleci.io/repos/5709354)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](https://opensource.org/licenses/MIT)

This library provides methods to access Git repository from PHP.

It makes shell calls, which makes it less performant than any solution.

Anyway, it's convenient and don't need to build anything to use it. That's how we love it.

## Documentation

* [Overview](https://github.com/gitonomy/gitlib/blob/master/doc/index.md)
* [Debug](https://github.com/gitonomy/gitlib/blob/master/doc/debug.md)
* [Development](https://github.com/gitonomy/gitlib/blob/master/doc/development.md)
* [Installation](https://github.com/gitonomy/gitlib/blob/master/doc/installation.md)
* API
  + [Admin](https://github.com/gitonomy/gitlib/blob/master/doc/api/admin.md)
  + [Blame](https://github.com/gitonomy/gitlib/blob/master/doc/api/blame.md)
  + [Blob](https://github.com/gitonomy/gitlib/blob/master/doc/api/blob.md)
  + [Branch](https://github.com/gitonomy/gitlib/blob/master/doc/api/branch.md)
  + [Commit](https://github.com/gitonomy/gitlib/blob/master/doc/api/commit.md)
  + [Diff](https://github.com/gitonomy/gitlib/blob/master/doc/api/diff.md)
  + [Hooks](https://github.com/gitonomy/gitlib/blob/master/doc/api/hooks.md)
  + [Log](https://github.com/gitonomy/gitlib/blob/master/doc/api/log.md)
  + [References](https://github.com/gitonomy/gitlib/blob/master/doc/api/references.md)
  + [Repository](https://github.com/gitonomy/gitlib/blob/master/doc/api/repository.md)
  + [Revision](https://github.com/gitonomy/gitlib/blob/master/doc/api/revision.md)
  + [Tree](https://github.com/gitonomy/gitlib/blob/master/doc/api/tree.md)
  + [Working Copy](https://github.com/gitonomy/gitlib/blob/master/doc/api/workingcopy.md)

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

