<?php

/*
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Driver\Cli\CliRepositoryDriver;
use Gitonomy\Git\Driver\Cli\LegacyOptions;
use Gitonomy\Git\Driver\Data\ObjectType;
use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\Util\WeakCache;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Git repository object.
 *
 * Main entry point for browsing a Git repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @phpstan-import-type Options from LegacyOptions
 */
final class Repository
{
    public const string DEFAULT_DESCRIPTION = "Unnamed repository; edit this file 'description' to name the repository.\n";

    /**
     * Directory containing git files.
     */
    private readonly string $gitDir;

    /**
     * Working directory.
     */
    private readonly ?string $workingDir;

    /**
     * The commits, by hash, as long as the application uses them.
     *
     * @var WeakCache<Commit>
     */
    private readonly WeakCache $commits;

    /**
     * The trees, by hash, as long as the application uses them.
     *
     * @var WeakCache<Tree>
     */
    private readonly WeakCache $trees;

    /**
     * The blobs, by hash, as long as the application uses them.
     *
     * @var WeakCache<Blob>
     */
    private readonly WeakCache $blobs;

    /**
     * Reference bag associated to this repository.
     */
    private ?ReferenceBag $referenceBag = null;

    /**
     * Backend used to run operations.
     */
    private DriverInterface $driver;

    /**
     * Operations on this repository, opened by the driver.
     */
    private RepositoryDriverInterface $repositoryDriver;

    /**
     * Opens the repository in $dir: a bare repository, or a working tree with
     * its ".git" directory or file (a linked worktree, a submodule...).
     *
     * An array of options as second argument is deprecated: its "working_dir"
     * option is the $workingDir argument, and its "logger", "command",
     * "environment_variables", "inherit_environment_variables" and
     * "process_timeout" options configure a CliDriver.
     *
     * @param Options|string|null $workingDir The working tree, when $dir is a git directory kept apart from it
     * @param DriverInterface     $driver     Backend running the operations
     *
     * @throws InvalidArgumentException      $dir doesn't exist or isn't a repository, or an option is unknown
     * @throws DriverException               The driver can't open the repository
     * @throws UnsupportedOperationException The repository uses SHA-256 hashes, or with LibGit2Driver the reftable format
     */
    public function __construct(string $dir, array|string|null $workingDir = null, DriverInterface $driver = new CliDriver())
    {
        if (\is_array($workingDir)) {
            trigger_deprecation('gitonomy/gitlib', '2.0', 'Passing an array of options to "%s()" is deprecated, pass the working directory as "workingDir" argument, and configure the logger and git through the "driver" argument.', __METHOD__);

            $options = new LegacyOptions($workingDir);
            $optionsDriver = $options->repositoryDriver();
            if (null !== $optionsDriver) {
                // The driver is the third argument.
                if (\func_num_args() > 2) {
                    throw new InvalidArgumentException('The driver argument can\'t be combined with the "logger" or git options: configure the driver instead.');
                }

                $driver = $optionsDriver;
            }

            $workingDir = $options->workingDir;
        }

        $this->commits = new WeakCache();
        $this->trees = new WeakCache();
        $this->blobs = new WeakCache();
        $this->driver = $driver;
        $this->repositoryDriver = $driver->open($dir, $workingDir);
        $this->gitDir = $this->repositoryDriver->getGitDir();
        $this->workingDir = $this->repositoryDriver->getWorkingDir();
    }

    /**
     * Initializes a repository with a working directory in $path.
     *
     * @throws DriverException The directory can't be written, or is already a file
     */
    public static function init(string $path, DriverInterface $driver = new CliDriver()): self
    {
        $driver->init($path);

        return new self($path, null, $driver);
    }

    /**
     * Initializes a bare repository in $path.
     *
     * @throws DriverException The directory can't be written, or is already a file
     */
    public static function initBare(string $path, DriverInterface $driver = new CliDriver()): self
    {
        $driver->initBare($path);

        return new self($path, null, $driver);
    }

    /**
     * Clones $url with a working directory in $path.
     *
     * @param string|null $branch The branch to check out, the default branch of $url when null
     *
     * @throws DriverException
     * @throws UnsupportedOperationException $url is a bundle, with LibGit2Driver
     */
    public static function cloneFrom(#[\SensitiveParameter] string $url, string $path, ?string $branch = null, DriverInterface $driver = new CliDriver()): self
    {
        $driver->cloneFrom($url, $path, $branch);

        return new self($path, null, $driver);
    }

    /**
     * Clones the branches and the tags of $url into a bare repository in $path.
     *
     * @param string|null $branch The branch HEAD points to, the default branch of $url when null
     *
     * @throws DriverException
     * @throws UnsupportedOperationException $url is a bundle, with LibGit2Driver
     */
    public static function cloneBareFrom(#[\SensitiveParameter] string $url, string $path, ?string $branch = null, DriverInterface $driver = new CliDriver()): self
    {
        $driver->cloneBareFrom($url, $path, $branch);

        return new self($path, null, $driver);
    }

    /**
     * Clones every reference of $url into a bare repository in $path, set up
     * to mirror $url when fetching.
     *
     * @throws DriverException
     * @throws UnsupportedOperationException $url is a bundle, with LibGit2Driver
     */
    public static function mirrorFrom(#[\SensitiveParameter] string $url, string $path, DriverInterface $driver = new CliDriver()): self
    {
        $driver->mirrorFrom($url, $path);

        return new self($path, null, $driver);
    }

    /**
     * Checks, without cloning it, that $url is a repository the driver can
     * read: false when it doesn't exist, can't be reached, or refuses access.
     */
    public static function isValidRepository(#[\SensitiveParameter] string $url, DriverInterface $driver = new CliDriver()): bool
    {
        return $driver->isValidRepository($url);
    }

    /**
     * Checks, without cloning it, that $url is a repository the driver can
     * read, and that it has the $branch branch.
     */
    public static function isValidRepositoryAndBranch(#[\SensitiveParameter] string $url, string $branch, DriverInterface $driver = new CliDriver()): bool
    {
        return $driver->isValidRepositoryAndBranch($url, $branch);
    }

    /**
     * Returns the operations on this repository, opened by the driver.
     *
     * @internal
     */
    public function getRepositoryDriver(): RepositoryDriverInterface
    {
        return $this->repositoryDriver;
    }

    /**
     * Tests if repository is a bare repository.
     */
    public function isBare(): bool
    {
        return null === $this->workingDir;
    }

    /**
     * Returns the HEAD resolved as a commit.
     */
    public function getHeadCommit(): Commit
    {
        $head = $this->getHead();

        if ($head instanceof Reference) {
            return $head->getCommit();
        }

        return $head;
    }

    /**
     * @return Reference|Commit The branch HEAD points to, or the commit when HEAD is detached
     *
     * @throws ReferenceNotFoundException HEAD points to a branch without commits yet
     */
    public function getHead(): Reference|Commit
    {
        $reference = $this->repositoryDriver->getHeadReference();

        if (null === $reference) {
            return $this->getCommit($this->repositoryDriver->resolveRevision('HEAD'));
        }

        return $this->getReferences()->get($reference);
    }

    public function isHeadDetached(): bool
    {
        return !$this->isHeadAttached();
    }

    public function isHeadAttached(): bool
    {
        return null !== $this->repositoryDriver->getHeadReference();
    }

    /**
     * Returns the path to the git repository.
     */
    public function getPath(): string
    {
        return null === $this->workingDir ? $this->gitDir : $this->workingDir;
    }

    /**
     * Returns the directory containing git files (git-dir).
     */
    public function getGitDir(): string
    {
        return $this->gitDir;
    }

    /**
     * Returns the work-tree directory. This may be null if repository is
     * bare.
     */
    public function getWorkingDir(): ?string
    {
        return $this->workingDir;
    }

    /**
     * Instanciates a revision.
     *
     * @param string $name Name of the revision
     */
    public function getRevision(string $name): Revision
    {
        return new Revision($this, $name);
    }

    public function getWorkingCopy(): WorkingCopy
    {
        return new WorkingCopy($this);
    }

    /**
     * Returns the references of the repository, read again after every write
     * gitlib makes. Open a new Repository to see the writes of other
     * processes.
     *
     * Passing true, to read them again, is deprecated.
     */
    public function getReferences(bool $reload = false): ReferenceBag
    {
        $this->referenceBag ??= new ReferenceBag($this);

        if (\func_num_args() > 0) {
            trigger_deprecation('gitonomy/gitlib', '2.0', 'Passing an argument to "%s()" is deprecated, gitlib reads the references again after its writes: open a new Repository to see the writes of other processes.', __METHOD__);
        }

        if ($reload) {
            $this->referenceBag->invalidate();
        }

        return $this->referenceBag;
    }

    /**
     * Returns a commit, the same object while the application uses it.
     *
     * @param string $hash The full hash of the commit: getRevision()->getCommit() resolves a name
     *
     * @throws ReferenceNotFoundException $hash isn't a full hash
     */
    public function getCommit(string $hash): Commit
    {
        $commit = $this->commits->get($hash);
        if (null === $commit) {
            $commit = new Commit($this, $hash);
            $this->commits->set($hash, $commit);
        }

        return $commit;
    }

    /**
     * Returns a tree, the same object while the application uses it.
     *
     * @param string $hash The hash of the tree, abbreviated or not
     *
     * @throws ReferenceNotFoundException $hash isn't a hash, or names nothing
     */
    public function getTree(string $hash): Tree
    {
        $hash = $this->fullHash($hash, ObjectType::Tree);
        $tree = $this->trees->get($hash);
        if (null === $tree) {
            $tree = new Tree($this, $hash);
            $this->trees->set($hash, $tree);
        }

        return $tree;
    }

    /**
     * Returns a blob, the same object while the application uses it.
     *
     * @param string $hash The hash of the blob, abbreviated or not
     *
     * @throws ReferenceNotFoundException $hash isn't a hash, or names nothing
     */
    public function getBlob(string $hash): Blob
    {
        $hash = $this->fullHash($hash, ObjectType::Blob);
        $blob = $this->blobs->get($hash);
        if (null === $blob) {
            $blob = new Blob($this, $hash);
            $this->blobs->set($hash, $blob);
        }

        return $blob;
    }

    /**
     * Returns the best common ancestor of two commits, the one "git diff a...b" compares to.
     *
     * @return Commit|null Null when they have no common history
     */
    public function getMergeBase(Revision|string $one, Revision|string $two): ?Commit
    {
        $hash = $this->repositoryDriver->getMergeBases($this->commitHash($one), $this->commitHash($two))[0] ?? null;

        return null === $hash ? null : $this->getCommit($hash);
    }

    /**
     * Returns the commits that last changed the lines of $file.
     *
     * A string as third argument, "<start>,<end>" or "<start>,+<count>" like
     * "git blame -L", is deprecated.
     *
     * @param int|string|null $firstLine The first line to blame, from 1, the first one of the file when null
     * @param int|null        $lastLine  The last line to blame, the last one of the file when null
     */
    public function getBlame(Revision|string $revision, string $file, int|string|null $firstLine = null, ?int $lastLine = null): Blame
    {
        if (\is_string($firstLine)) {
            trigger_deprecation('gitonomy/gitlib', '2.0', 'Passing a range of lines as a string to "%s()" is deprecated, pass the first and the last lines instead.', __METHOD__);

            if (!preg_match('/^(\d+),(\+?)(\d*)$/', $firstLine, $vars)) {
                throw new InvalidArgumentException(\sprintf('Unsupported range of lines "%s": pass the first and the last lines instead.', $firstLine));
            }

            $firstLine = (int) $vars[1];
            $lastLine = match (true) {
                '' === $vars[3] => null,
                '+' === $vars[2] => $firstLine + (int) $vars[3] - 1,
                default => (int) $vars[3],
            };
        }

        return new Blame($this, \is_string($revision) ? $this->getRevision($revision) : $revision, $file, $firstLine, $lastLine);
    }

    /**
     * Returns log for a given set of revisions and paths.
     *
     * All those values can be null, meaning everything.
     *
     * @param RevisionList|Revision|string|array|null $revisions An array of revisions to show logs from. Can be
     *                                                           any text value type
     * @param array|string|null                       $paths     restrict log to modifications occurring on given
     *                                                           paths
     * @param int|null                                $offset    start from a given offset in results
     * @param int|null                                $limit     limit number of total results
     */
    public function getLog(RevisionList|Revision|string|array|null $revisions = null, array|string|null $paths = null, ?int $offset = null, ?int $limit = null): Log
    {
        return new Log($this, $revisions, $paths, $offset, $limit);
    }

    /**
     * Returns the changes from the commit $from to the commit $to.
     *
     * Passing one argument is deprecated: an "a..b" or "a...b" range, two
     * revisions in an array or a RevisionList, or one revision to compare
     * with the working tree, like WorkingCopy::getDiff() does.
     *
     * @param RevisionList|Revision|string|array<Revision|string>|null $from
     */
    public function getDiff(RevisionList|Revision|string|array|null $from, Revision|string|null $to = null): Diff
    {
        if (null === $to) {
            trigger_deprecation('gitonomy/gitlib', '2.0', 'Calling "%s()" with one argument is deprecated, pass the revisions to compare as "from" and "to" arguments, or use "%s::getDiff()" to compare a revision with the working tree.', __METHOD__, WorkingCopy::class);

            return $this->getLegacyDiff($from);
        }

        if (\is_array($from) || $from instanceof RevisionList || null === $from) {
            throw new InvalidArgumentException('The "from" argument of "Repository::getDiff()" must be a revision, when "to" is given.');
        }

        return Diff::fromData($this->repositoryDriver->getDiff($this->commitHash($from), $this->commitHash($to)), $this);
    }

    /**
     * For the deprecated forms of getDiff() and Log::getDiff(), to be removed in 3.0.
     *
     * @param RevisionList|Revision|string|array<Revision|string>|null $revisions
     *
     * @internal
     */
    public function getLegacyDiff(RevisionList|Revision|string|array|null $revisions): Diff
    {
        if (null === $revisions) {
            throw new InvalidArgumentException('Cannot compute a diff without revisions.');
        }

        if (!$revisions instanceof RevisionList) {
            $revisions = new RevisionList($this, $revisions);
        }
        $revisions = $revisions->getAsTextArray();

        if (2 === \count($revisions)) {
            return $this->getDiff($revisions[0], $revisions[1]);
        }

        if (1 === \count($revisions) && str_contains($revisions[0], '..')) {
            $symmetric = str_contains($revisions[0], '...');
            [$from, $to] = explode($symmetric ? '...' : '..', $revisions[0], 2);
            $from = '' === $from ? 'HEAD' : $from;
            $to = '' === $to ? 'HEAD' : $to;

            if ($symmetric) {
                // Like git diff: from the best common ancestor.
                $from = $this->getMergeBase($from, $to) ?? throw new InvalidArgumentException(\sprintf('The revisions of "%s" have no common ancestor.', $revisions[0]));
            }

            return $this->getDiff($from, $to);
        }

        if (1 === \count($revisions)) {
            return $this->getWorkingCopy()->getDiff($revisions[0]);
        }

        throw new InvalidArgumentException(\sprintf('Cannot compute a diff of "%s".', implode('", "', $revisions)));
    }

    /**
     * Returns the size of repository, in kilobytes.
     *
     * @return int A sum, in kilobytes
     */
    public function getSize(): int
    {
        $totalBytes = 0;
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->gitDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $totalBytes += $file->getSize();
        }

        return (int) round($totalBytes / 1000);
    }

    /**
     * Executes a shell command on the repository, using PHP pipes.
     *
     * @param string $command The command to execute
     *
     * @deprecated since gitonomy/gitlib 2.0, run "git shell" with Symfony Process instead
     */
    public function shell(string $command, array $env = []): void
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, run "git shell" with Symfony Process instead: it ignores the driver.', __METHOD__);

        $argument = \sprintf('%s %s', $command, escapeshellarg($this->gitDir));

        $prefix = '';
        foreach ($env as $name => $value) {
            $prefix .= \sprintf('export %s=%s;', escapeshellarg($name), escapeshellarg($value));
        }

        proc_open($prefix.'git shell -c '.escapeshellarg($argument), [\STDIN, \STDOUT, \STDERR], $pipes);
    }

    /**
     * Returns the hooks object.
     */
    public function getHooks(): Hooks
    {
        return new Hooks($this);
    }

    /**
     * Returns description of repository from description file in git directory.
     *
     * @return string The description
     */
    public function getDescription(): string
    {
        $file = $this->gitDir.'/description';

        if (!is_file($file)) {
            return self::DEFAULT_DESCRIPTION;
        }

        $description = file_get_contents($file);
        if (false === $description) {
            throw new RuntimeException(\sprintf('Unable to read "%s".', $file));
        }

        return $description;
    }

    /**
     * Tests if repository has a custom set description.
     */
    public function hasDescription(): bool
    {
        return self::DEFAULT_DESCRIPTION !== $this->getDescription();
    }

    /**
     * Changes the repository description (file description in git-directory).
     *
     * @return $this
     */
    public function setDescription(string $description): static
    {
        if (false === file_put_contents($this->gitDir.'/description', $description)) {
            throw new RuntimeException(\sprintf('Unable to write the description of "%s".', $this->gitDir));
        }

        return $this;
    }

    /**
     * This command is a facility command. You can run any command
     * directly on git repository.
     *
     * Note that this only returns the standard output of the process. Some
     * git commands (`push`, for instance) write their meaningful output to
     * stderr even when they succeed: use {@see runProcess()} instead if you
     * need access to it.
     *
     * @param string $command Git command to run (checkout, branch, tag)
     * @param array  $args    Arguments of git command
     *
     * @return string output of the process
     *
     * @throws ProcessException              Error while executing git command
     * @throws UnsupportedOperationException The driver isn't a CliDriver
     */
    public function run(string $command, #[\SensitiveParameter] array $args = []): string
    {
        return $this->runProcess($command, $args)->getOutput();
    }

    /**
     * Same as {@see run()}, but returns the full, already-run process
     * instead of only its standard output on success.
     *
     * This is useful for commands like `push`, which write their
     * meaningful output to stderr even when they succeed, so it can't be
     * read through run().
     *
     * @param string $command Git command to run (checkout, branch, tag)
     * @param array  $args    Arguments of git command
     *
     * @throws ProcessException              Error while executing git command
     * @throws UnsupportedOperationException The driver isn't a CliDriver
     */
    public function runProcess(string $command, #[\SensitiveParameter] array $args = []): Process
    {
        if (!$this->repositoryDriver instanceof CliRepositoryDriver) {
            throw new UnsupportedOperationException('Only CliDriver can run git commands.');
        }

        try {
            return $this->repositoryDriver->runProcess($command, $args);
        } finally {
            // Any command may write references, even a failing one.
            $this->referenceBag?->invalidate();
        }
    }

    /**
     * Set repository logger.
     *
     * @return $this
     *
     * @throws UnsupportedOperationException The driver isn't a CliDriver
     *
     * @deprecated since gitonomy/gitlib 2.0, pass a CliDriver configured with the logger as driver argument instead
     */
    public function setLogger(LoggerInterface $logger): static
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, pass a "%s" configured with the logger as driver argument instead.', __METHOD__, CliDriver::class);

        if (!$this->driver instanceof CliDriver) {
            throw new UnsupportedOperationException('Only CliDriver logs the git commands it runs.');
        }

        $this->driver = $this->driver->withLogger($logger);
        $this->repositoryDriver = $this->driver->open($this->gitDir, $this->workingDir);

        return $this;
    }

    /**
     * Returns the logger of the CliDriver.
     *
     * @deprecated since gitonomy/gitlib 2.0, the logger is an argument of CliDriver
     */
    public function getLogger(): ?LoggerInterface
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, the logger is an argument of "%s".', __METHOD__, CliDriver::class);

        return $this->driver instanceof CliDriver ? $this->driver->getLogger() : null;
    }

    /**
     * Clones the current repository to a new directory and return instance of new repository.
     *
     * The clone uses the driver of the current repository.
     *
     * @param string  $path    path to the new repository in which current repository will be cloned
     * @param bool    $bare    flag indicating if repository is bare or has a working-copy
     * @param Options $options options of the new repository
     *
     * @return Repository the newly created repository
     *
     * @deprecated since gitonomy/gitlib 2.0, use Repository::cloneBareFrom() or Repository::cloneFrom() with getGitDir() as URL instead
     */
    public function cloneTo(string $path, bool $bare = true, array $options = []): self
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, use "%s::%s()" with "getGitDir()" as URL instead.', __METHOD__, self::class, $bare ? 'cloneBareFrom' : 'cloneFrom');

        // Like in 1.x, the options configuring git apply to the clone.
        $options = new LegacyOptions($options);
        $driver = $options->adminDriver() ?? $this->driver;
        if ($bare) {
            $driver->cloneBareFrom($this->gitDir, $path, null);
        } else {
            $driver->cloneFrom($this->gitDir, $path, null);
        }

        return new self($path, $options->workingDir, $options->repositoryDriver() ?? $driver);
    }

    private function commitHash(Revision|string $revision): string
    {
        return ($revision instanceof Revision ? $revision : $this->getRevision($revision))->getCommit()->getHash();
    }

    /**
     * @param ObjectType $type The type of the object, which an abbreviated hash must name
     *
     * @throws ReferenceNotFoundException $hash isn't a hash, or names no object of this type
     */
    private function fullHash(string $hash, ObjectType $type): string
    {
        if (!preg_match('/^[0-9a-f]{4,40}$/', $hash)) {
            throw new ReferenceNotFoundException(\sprintf('"%s" is not a hash.', $hash));
        }

        // The type of an object of a full hash is checked when it is read.
        if (40 === \strlen($hash)) {
            return $hash;
        }

        $fullHash = $this->repositoryDriver->resolveRevision($hash.'^{'.$type->value.'}');
        // "^{tree}" also peels a commit to its tree.
        if (!str_starts_with($fullHash, $hash)) {
            throw new ReferenceNotFoundException(\sprintf('The object "%s" is not a %s.', $hash, $type->value));
        }

        return $fullHash;
    }
}
