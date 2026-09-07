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
for example), regenerate the bundle with `tests/fixtures/generate-bundle.php`, entirely
from the one already in the repo:

```bash
$ php tests/fixtures/generate-bundle.php extract
# ... add your commits, branches or tags in the printed directory ...
$ php tests/fixtures/generate-bundle.php build /path/printed/above
```

`extract` clones the current bundle to a working directory with every branch checked out
locally, ready to receive new commits. `build` rebuilds `tests/fixtures/foobar.bundle`
from that directory, restricted to the refs listed in `tests/fixtures/bundle-refs.txt` —
add your new branch or tag there first if you introduced one.

Then update the commit SHA constants in `AbstractTestCase` to match, and run
`tests/fixtures/verify-bundle.sh`. It checks the bundle's integrity, its ref list against
`bundle-refs.txt`, and its size, since GitHub renders any change to this binary file as an
opaque diff. If your change intentionally adds a ref or grows the file, update
`tests/fixtures/bundle-refs.txt` or `MAX_SIZE_KB` in that script as part of the same pull
request, so the reason for the change is explicit and reviewable rather than a silent
binary diff.

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
