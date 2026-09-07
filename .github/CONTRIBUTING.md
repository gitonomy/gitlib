# CONTRIBUTION GUIDELINES

Contributions are **welcome** and will be fully **credited**.

We accept contributions via pull requests on GitHub. Please review these guidelines before continuing.

## Guidelines

* Please follow the [PSR-12 Coding Style Guide](https://www.php-fig.org/psr/psr-12/).
* Ensure that the current tests pass, and if you've added something new, add the tests where relevant.
* Send a coherent commit history, making sure each commit in your pull request is meaningful.
* You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
* If you are changing or adding to the behaviour or public API, you may need to update the docs.
* Please remember that we follow [Semantic Versioning](https://semver.org/).

You will need [Castor](https://castor.jolicode.com/) to run the tests, fix CS
violations and run the static analysis. See [Castor's documentation](https://castor.jolicode.com/getting-started/installation/)
for installation instructions.

To install all the dependencies and tools, run:

```bash
$ castor install
```

## Running Tests

First, install the dependencies using [Composer](https://getcomposer.org/):

```bash
$ composer install
```

Then run [PHPUnit](https://phpunit.de/):

```bash
$ vendor/bin/phpunit
```

* A script `test-git-versions.sh` is available in repository to test gitlib against many git versions.
* The tests will be automatically run by [GitHub Actions](https://github.com/features/actions) against pull requests.
* Tests run fully offline: no network access is required.

## Test fixtures

Most tests run against a fixture repository cloned from `tests/fixtures/foobar.bundle`,
a local git bundle. Using a bundle instead of a network clone keeps the tests fast and
fully offline.

If you need a new fixture scenario (a specific merge, encoding, or signed-commit shape,
for example), regenerate the bundle locally, entirely from the one already in the repo:

```bash
$ git clone tests/fixtures/foobar.bundle /tmp/foobar-fixture && cd /tmp/foobar-fixture
$ for b in $(git branch -r | grep -v HEAD | sed 's#origin/##'); do
$     git branch --track "$b" "origin/$b"
$ done
# ... add your commits, branches or tags ...
$ git bundle create foobar.bundle \
    HEAD refs/heads/master refs/heads/new-feature refs/heads/diff-features \
    refs/heads/pagination refs/heads/path-resolving refs/tags/0.1 refs/tags/annotated
$ cp foobar.bundle /path/to/gitlib/tests/fixtures/foobar.bundle
```

Then update the commit SHA constants in `AbstractTestCase` to match, and run
`tests/fixtures/verify-bundle.sh`. It checks the bundle's integrity, its ref list against
an allow-list, and its size, since GitHub renders any change to this binary file as an
opaque diff. If your change intentionally adds a ref or grows the file, update
`ALLOWED_REFS` or `MAX_SIZE_KB` in that script as part of the same pull request, so the
reason for the change is explicit and reviewable rather than a silent binary diff.

## Standard code

Use PHP-CS-Fixer to make your code compliant with gitlib's coding standards:

```bash
$ castor cs
```

## Static analysis

Use PHPStan to ensure the code is free of errors:

```bash
$ castor phpstan
```

Both checks run automatically via GitHub Actions against pull requests.
