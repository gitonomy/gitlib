# gitlib - library to manipulate git

gitlib requires PHP 5.3 and class autoloading (PSR-0) to work properly.
Internally, it relies on `git` method calls to fetch informations from
repository.

```php
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repository');

foreach ($repository->getReferences()->getBranches() as $branch) {
    echo "- ".$branch->getName();
}

$repository->run('fetch', array('--all'));
```

## Documentation
* [Debug](debug.md)
* [Development](development.md)
* [Installation](installation.md)

## API Reference
* [Admin](api/admin.md)
* [Blame](api/blame.md)
* [Blob](api/blob.md)
* [Branch](api/branch.md)
* [Commit](api/commit.md)
* [Diff](api/diff.md)
* [Hooks](api/hooks.md)
* [Log](api/log.md)
* [References](api/references.md)
* [Repository](api/repository.md)
* [Revision](api/revision.md)
* [Tree](api/tree.md)
* [Working Copy](api/workingcopy.md)

## Missing features

Some major features are still missing from gitlib:

- Remotes
- Submodules

If you want to run git commands on repository, call method `Repository::run` with method and arguments.
