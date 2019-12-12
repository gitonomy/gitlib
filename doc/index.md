# gitlib - library to manipulate git

gitlib requires PHP 5.3 and class autoloading (PSR-0) to work properly.
Internally, it relies on `git` method calls to fetch informations from
repository.

``` {.sourceCode .php}
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repository');

foreach ($repository->getReferences()->getBranches() as $branch) {
    echo "- ".$branch->getName();
}

$repository->run('fetch', array('--all'));
```

## Documentation
* [Debug](https://github.com/gitonomy/gitlib/blob/master/doc/debug.md)
* [Development](https://github.com/gitonomy/gitlib/blob/master/doc/development.md)
* [Installation](https://github.com/gitonomy/gitlib/blob/master/doc/installation.md)

## API Reference
* [Admin](https://github.com/gitonomy/gitlib/blob/master/doc/api/admin.md)
* [Blame](https://github.com/gitonomy/gitlib/blob/master/doc/api/blame.md)
* [Blob](https://github.com/gitonomy/gitlib/blob/master/doc/api/blob.md)
* [Branch](https://github.com/gitonomy/gitlib/blob/master/doc/api/branch.md)
* [Commit](https://github.com/gitonomy/gitlib/blob/master/doc/api/commit.md)
* [Diff](https://github.com/gitonomy/gitlib/blob/master/doc/api/diff.md)
* [Hooks](https://github.com/gitonomy/gitlib/blob/master/doc/api/hooks.md)
* [Log](https://github.com/gitonomy/gitlib/blob/master/doc/api/log.md)
* [References](https://github.com/gitonomy/gitlib/blob/master/doc/api/references.md)
* [Repository](https://github.com/gitonomy/gitlib/blob/master/doc/api/repository.md)
* [Revision](https://github.com/gitonomy/gitlib/blob/master/doc/api/revision.md)
* [Tree](https://github.com/gitonomy/gitlib/blob/master/doc/api/tree.md)
* [Working Copy](https://github.com/gitonomy/gitlib/blob/master/doc/api/workingcopy.md)

## Missing features

Some major features are still missing from gitlib:

- Remotes
- Submodules

If you want to run git commands on repository, call method `Repository::run` with method and arguments.
