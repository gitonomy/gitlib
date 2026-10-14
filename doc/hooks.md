Hooks
=====

It's possible to define custom hooks on any repository with git. Those
hooks are located in the *hooks* folder of the git directory: *.git/hooks*,
or *hooks* in a bare repository. A linked worktree (`git worktree add`) runs
the hooks of the repository it belongs to. gitlib ignores the `core.hooksPath`
setting of git.

Those files need to be executable: gitlib makes them executable by their
owner only (*0700*). If another user runs them (a git daemon, a web
server...), change their permissions yourself.

A hook is a file of the hooks directory: its name, like `pre-receive`, is
made of letters, digits, `-`, `_` and `.`, and doesn't start with a dot. Any
other name, like `../config`, throws a
`Gitonomy\Git\Exception\InvalidArgumentException`.

git runs them, when it receives a push for instance, or when `CliDriver`
checks out or merges. `LibGit2Driver` doesn't run them (see
["Choosing a driver"](repository.md#choosing-a-driver)).

With *gitlib*, you can manage hooks over a repository using the *Hooks*
object.

To access it from a repository, use the *getHooks* method on a
*Repository* object:

```php
$hooks = $repository->getHooks();
```

Reading hooks
-------------

To read the content of a hook, use the *get* method like this:

```php
$content = $hooks->get('pre-receive'); // returns a string
```

If the hook does not exist, a `Gitonomy\Git\Exception\InvalidArgumentException`
is thrown, and a `Gitonomy\Git\Exception\RuntimeException` if it can't be
read.

You can test if a hook is present using the method *has*:

```php
$hooks->has('pre-receive'); // a boolean indicating presence
```

A symbolic link is a hook, even when the file it points to doesn't exist
yet: *get* can't read it, and *set* doesn't write through it.

Inserting hooks
---------------

You can modify a hook in two different ways: creating a new file or
using a symlink.

To create the hook using a symlink:

```php
$hooks->setSymlink('pre-receive', '/path/to/file-to-link');
```

If you want to directly create a new file in hooks directory, use the
method *set*. This method will create a new file, put content in it and
make it executable:

```php
$content = <<<HOOK
#!/bin/bash
echo "Push is disabled"
exit 1

HOOK;

// this hook will reject every push

$hooks->set('pre-receive', $content);
```

Both methods create the hooks directory when the repository has none, like
one initialized with an empty template. They throw a
`Gitonomy\Git\Exception\RuntimeException` if the hook already exists, or if
it can't be created.

Removing hooks
--------------

To remove a hook from a repository, use the function *remove*:

```php
$hooks->remove('pre-receive');
```

It throws a `Gitonomy\Git\Exception\RuntimeException` if the hook does not
exist, or if it can't be removed.
