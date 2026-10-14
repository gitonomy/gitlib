gitlib
======

Access git repositories from PHP 8.4+, through the `git` binary or libgit2.

```php
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repository');

// The last 5 commits of main that changed src/
foreach ($repository->getLog('main', 'src', limit: 5) as $commit) {
    echo $commit->getShortHash().' '.$commit->getSubjectMessage().PHP_EOL;
}

// The files the last commit changed
foreach ($repository->getHeadCommit()->getDiff()->getFiles() as $file) {
    echo $file->getName().': +'.$file->getAdditions().' -'.$file->getDeletions().PHP_EOL;
}

// Who wrote the first lines of README.md
foreach ($repository->getBlame('main', 'README.md', 1, 3)->getLines() as $line) {
    echo $line->getCommit()->getAuthorName().': '.$line->getContent().PHP_EOL;
}
```

The documentation is at **[gitonomy.github.io/gitlib](https://gitonomy.github.io/gitlib/)**.

What it does
------------

-   **Open** a bare repository, a working tree, a linked worktree or a submodule, or
    **create** one: init, clone, mirror. See [Opening a repository](doc/repository.md) and
    [Creating repositories](doc/admin.md).
-   **Read** commits, logs, trees, blobs, diffs, blames, branches and tags, as PHP objects.
-   **Change** the working copy: checkout, new branches, merges, and the diffs of what is
    pending or staged. See [Working copy](doc/workingcopy.md).
-   **Manage** the hooks of a repository: see [Hooks](doc/hooks.md).
-   **Run** any other git command: see
    [Running git commands](doc/repository.md#running-git-commands).

Two drivers
-----------

`Repository` runs git through a driver, its last argument:

-   `CliDriver`, the default, runs the `git` binary, 1.8.4 or later, and needs nothing else;
-   `LibGit2Driver`, experimental, runs [libgit2](https://libgit2.org/) 1.9 in-process, through
    PHP's `FFI` extension. It is faster for many small reads in a long-running process, and
    slower on long histories, like blames: see
    ["Choosing a driver"](doc/repository.md#choosing-a-driver).

```php
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use Gitonomy\Git\Repository;

$repository = new Repository('/path/to/repository', driver: new LibGit2Driver());
```

Installation
------------

```bash
composer require gitonomy/gitlib
```

Upgrading from 1.x? Read [UPGRADE-2.0.md](UPGRADE-2.0.md) and the [changelog](CHANGELOG.md).

Documentation
-------------

-   [Opening a repository](doc/repository.md): paths, drivers, errors, git configuration
-   [Creating repositories](doc/admin.md)
-   [Revisions](doc/revision.md)
-   [Commits](doc/commit.md)
-   [Log](doc/log.md)
-   [Trees](doc/tree.md)
-   [Blobs](doc/blob.md)
-   [Diffs](doc/diff.md)
-   [Blame](doc/blame.md)
-   [Tags and branches](doc/references.md)
-   [Branches](doc/branch.md)
-   [Working copy](doc/workingcopy.md)
-   [Hooks](doc/hooks.md)

For Enterprise
--------------

Available as part of the Tidelift Subscription

The maintainers of `gitonomy/gitlib` and thousands of other packages are working with Tidelift to deliver commercial support and maintenance for the open source dependencies you use to build your applications. Save time, reduce risk, and improve code health, while paying the maintainers of the exact dependencies you use. [Learn more.](https://tidelift.com/subscription/pkg/packagist-gitonomy-gitlib?utm_source=packagist-gitonomy-gitlib&utm_medium=referral&utm_campaign=enterprise&utm_term=repo)

License
-------

gitlib is released under the [MIT license](https://github.com/gitonomy/gitlib/blob/main/LICENSE).
