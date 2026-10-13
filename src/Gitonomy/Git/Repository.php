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
use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Git repository object.
 *
 * Main entry point for browsing a Git repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @phpstan-type Options array{command?: string, debug?: bool, environment_variables?: array<string, string|\Stringable|false>, inherit_environment_variables?: bool, logger?: LoggerInterface|null, process_timeout?: float|null, working_dir?: string|null}
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
     * Cache of commit objects, indexed by hash.
     *
     * @var array<string, Commit>
     */
    private array $commits = [];

    /**
     * Cache of tree objects, indexed by hash.
     *
     * @var array<string, Tree>
     */
    private array $trees = [];

    /**
     * Cache of blob objects, indexed by hash.
     *
     * @var array<string, Blob>
     */
    private array $blobs = [];

    /**
     * Reference bag associated to this repository.
     */
    private ?ReferenceBag $referenceBag = null;

    /**
     * Logger (can be null).
     */
    private ?LoggerInterface $logger;

    /**
     * Backend used to run operations.
     */
    private readonly DriverInterface $driver;

    /**
     * Operations on this repository, opened by the driver.
     */
    private RepositoryDriverInterface $repositoryDriver;

    /**
     * Constructs a new repository.
     *
     * Available options are:
     *
     * * working_dir : specify where working copy is located (option --work-tree)
     *
     * * logger : a logger to use for logging actions (Psr\Log\LoggerInterface)
     *
     * The command, environment_variables, inherit_environment_variables and
     * process_timeout options of 1.x are deprecated: they configure a
     * CliDriver, as the arguments of its constructor.
     *
     * @param string          $dir     path to git repository
     * @param Options         $options array of options values
     * @param DriverInterface $driver  backend used to run operations
     *
     * @throws InvalidArgumentException The folder does not exists, or an option is unknown
     */
    public function __construct(string $dir, array $options = [], DriverInterface $driver = new CliDriver())
    {
        if (\array_key_exists('debug', $options)) {
            if (!$options['debug']) {
                throw new InvalidArgumentException('The "debug" option set to false is not supported anymore: a failing git operation always throws, catch its exception instead.');
            }

            trigger_deprecation('gitonomy/gitlib', '2.0', 'Passing "debug" in the options of "%s()" is deprecated, failing git operations always throw.', __METHOD__);
            unset($options['debug']);
        }

        $gitOptions = LegacyOptions::find($options);
        if ([] !== $gitOptions) {
            // The driver is the third argument.
            if (\func_num_args() > 2) {
                throw new InvalidArgumentException(\sprintf('The driver argument can\'t be combined with "%s" in the options: configure the driver instead.', implode('", "', $gitOptions)));
            }

            trigger_deprecation('gitonomy/gitlib', '2.0', 'Passing "%s" in the options of "%s()" is deprecated, pass a "%s" as driver argument instead.', implode('", "', $gitOptions), __METHOD__, CliDriver::class);
            $driver = LegacyOptions::createDriver($options);
            $options = LegacyOptions::remove($options);
        }

        $unknownOptions = array_diff(array_keys($options), ['logger', 'working_dir']);
        if ([] !== $unknownOptions) {
            throw new InvalidArgumentException(\sprintf('Unknown options "%s": expected "logger" or "working_dir".', implode('", "', $unknownOptions)));
        }

        $options = array_merge([
            'working_dir' => null,
            'logger' => null,
        ], $options);

        // PHP doesn't check the types of the options, unlike PHPStan.
        if (null !== $options['logger'] && !$options['logger'] instanceof LoggerInterface) { // @phpstan-ignore instanceof.alwaysTrue, booleanAnd.alwaysFalse
            throw new InvalidArgumentException(\sprintf('Argument "logger" passed to Repository should be a Psr\Log\LoggerInterface. A %s was provided', get_debug_type($options['logger'])));
        }

        $this->logger = $options['logger'];
        [$this->gitDir, $this->workingDir] = self::resolveDir($dir, $options['working_dir']);

        $this->driver = $driver;
        $this->repositoryDriver = $driver->open($this->gitDir, $this->workingDir, $this->logger);

        $this->logger?->debug(\sprintf('Repository created (git dir: "%s", working dir: "%s")', $this->gitDir, $this->workingDir ?? 'none'));
    }

    /**
     * Initializes a repository with a working directory in $path.
     *
     * @throws DriverException The directory can't be written, or is already a file
     */
    public static function init(string $path, ?LoggerInterface $logger = null, DriverInterface $driver = new CliDriver()): self
    {
        $driver->init($path);

        return new self($path, ['logger' => $logger], $driver);
    }

    /**
     * Initializes a bare repository in $path.
     *
     * @throws DriverException The directory can't be written, or is already a file
     */
    public static function initBare(string $path, ?LoggerInterface $logger = null, DriverInterface $driver = new CliDriver()): self
    {
        $driver->initBare($path);

        return new self($path, ['logger' => $logger], $driver);
    }

    /**
     * Clones $url with a working directory in $path.
     *
     * @param string|null $branch The branch to check out, the default branch of $url when null
     *
     * @throws DriverException
     */
    public static function cloneFrom(string $url, string $path, ?string $branch = null, ?LoggerInterface $logger = null, DriverInterface $driver = new CliDriver()): self
    {
        $driver->cloneFrom($url, $path, $branch);

        return new self($path, ['logger' => $logger], $driver);
    }

    /**
     * Clones the branches and the tags of $url into a bare repository in $path.
     *
     * @param string|null $branch The branch HEAD points to, the default branch of $url when null
     *
     * @throws DriverException
     */
    public static function cloneBareFrom(string $url, string $path, ?string $branch = null, ?LoggerInterface $logger = null, DriverInterface $driver = new CliDriver()): self
    {
        $driver->cloneBareFrom($url, $path, $branch);

        return new self($path, ['logger' => $logger], $driver);
    }

    /**
     * Clones every reference of $url into a bare repository in $path, set up
     * to mirror $url when fetching.
     *
     * @throws DriverException
     */
    public static function mirrorFrom(string $url, string $path, ?LoggerInterface $logger = null, DriverInterface $driver = new CliDriver()): self
    {
        $driver->mirrorFrom($url, $path);

        return new self($path, ['logger' => $logger], $driver);
    }

    /**
     * Checks, without cloning it, that $url is a repository the driver can
     * read: false when it doesn't exist, can't be reached, or refuses access.
     */
    public static function isValidRepository(string $url, DriverInterface $driver = new CliDriver()): bool
    {
        return $driver->isValidRepository($url);
    }

    /**
     * Checks, without cloning it, that $url is a repository the driver can
     * read, and that it has the $branchName branch.
     */
    public static function isValidRepositoryAndBranch(string $url, string $branchName, DriverInterface $driver = new CliDriver()): bool
    {
        return $driver->isValidRepositoryAndBranch($url, $branchName);
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
     * @return Reference|Commit current HEAD object
     *
     * @throws RuntimeException Unable to read the HEAD file
     */
    public function getHead(): Reference|Commit
    {
        $file = $this->gitDir.'/HEAD';

        if (!file_exists($file)) {
            throw new RuntimeException(\sprintf('Unable to find HEAD file ("%s").', $file));
        }

        $content = trim(file_get_contents($file));

        $this->logger?->debug('HEAD file read: '.$content);

        if (preg_match('/^ref: (.+)$/', $content, $vars)) {
            return $this->getReferences()->get($vars[1]);
        }
        if (preg_match('/^[0-9a-f]{40}$/', $content)) {
            return $this->getCommit($content);
        }

        throw new RuntimeException(\sprintf('Unexpected HEAD file content (file: %s). Content of file: %s.', $file, $content));
    }

    public function isHeadDetached(): bool
    {
        return !$this->isHeadAttached();
    }

    public function isHeadAttached(): bool
    {
        return $this->getHead() instanceof Reference;
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
     * Returns the reference list associated to the repository.
     *
     * @param bool $reload Reload references from the filesystem
     */
    public function getReferences(bool $reload = false): ReferenceBag
    {
        if (null === $this->referenceBag || $reload) {
            $this->referenceBag = new ReferenceBag($this);
        }

        return $this->referenceBag;
    }

    /**
     * Instanciates a commit object or fetches one from the cache.
     *
     * @param string $hash A commit hash, with a length of 40
     */
    public function getCommit(string $hash): Commit
    {
        if (!isset($this->commits[$hash])) {
            $this->commits[$hash] = new Commit($this, $hash);
        }

        return $this->commits[$hash];
    }

    /**
     * Instanciates a tree object or fetches one from the cache.
     *
     * @param string $hash A tree hash, with a length of 40
     */
    public function getTree(string $hash): Tree
    {
        if (!isset($this->trees[$hash])) {
            $this->trees[$hash] = new Tree($this, $hash);
        }

        return $this->trees[$hash];
    }

    /**
     * Instanciates a blob object or fetches one from the cache.
     *
     * @param string $hash A blob hash, with a length of 40
     */
    public function getBlob(string $hash): Blob
    {
        if (!isset($this->blobs[$hash])) {
            $this->blobs[$hash] = new Blob($this, $hash);
        }

        return $this->blobs[$hash];
    }

    public function getBlame(string|Revision $revision, string $file, ?string $lineRange = null): Blame
    {
        if (\is_string($revision)) {
            $revision = $this->getRevision($revision);
        }

        return new Blame($this, $revision, $file, $lineRange);
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

    public function getDiff(RevisionList|Revision|string|array|null $revisions): Diff
    {
        if (null === $revisions) {
            throw new InvalidArgumentException('Cannot compute a diff without revisions.');
        }

        if (!$revisions instanceof RevisionList) {
            $revisions = new RevisionList($this, $revisions);
        }

        $diff = $this->repositoryDriver->getDiff($revisions->getAsTextArray());
        $diff->setRepository($this);

        return $diff;
    }

    /**
     * Returns the size of repository, in kilobytes.
     *
     * @return int A sum, in kilobytes
     */
    public function getSize(): int
    {
        $totalBytes = 0;
        $path = realpath($this->gitDir);
        if ($path && file_exists($path)) {
            /** @var \SplFileInfo $object */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $object) {
                $totalBytes += $object->getSize();
            }
        }

        return (int) ($totalBytes / 1000 + 0.5);
    }

    /**
     * Executes a shell command on the repository, using PHP pipes.
     *
     * @param string $command The command to execute
     */
    public function shell(string $command, array $env = []): void
    {
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
        $exists = is_file($file);

        if (false === $exists) {
            $this->logger?->debug(\sprintf('no description file in repository ("%s")', $file));
        } else {
            $this->logger?->debug(\sprintf('reading description file in repository ("%s")', $file));
        }

        if (false === $exists) {
            return self::DEFAULT_DESCRIPTION;
        }

        return file_get_contents($this->gitDir.'/description');
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
        $file = $this->gitDir.'/description';

        $this->logger?->debug(\sprintf('change description file content to "%s" (file: %s)', $description, $file));
        file_put_contents($file, $description);

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
    public function run(string $command, array $args = []): string
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
    public function runProcess(string $command, array $args = []): Process
    {
        if (!$this->repositoryDriver instanceof CliRepositoryDriver) {
            throw new UnsupportedOperationException('Only CliDriver can run git commands.');
        }

        return $this->repositoryDriver->runProcess($command, $args);
    }

    /**
     * Set repository logger.
     *
     * @param LoggerInterface $logger A logger
     *
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): static
    {
        $this->logger = $logger;
        $this->repositoryDriver = $this->driver->open($this->gitDir, $this->workingDir, $logger);

        return $this;
    }

    /**
     * Returns repository logger.
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
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
        $driver = [] === LegacyOptions::find($options) ? $this->driver : LegacyOptions::createDriver($options);
        if ($bare) {
            $driver->cloneBareFrom($this->gitDir, $path, null);
        } else {
            $driver->cloneFrom($this->gitDir, $path, null);
        }

        return new self($path, LegacyOptions::remove($options), $driver);
    }

    /**
     * Resolves directory attributes for the repository.
     *
     * @param string      $gitDir     directory of a working copy with files checked out
     * @param string|null $workingDir directory containing git files (objects, config...)
     *
     * @return array{string, string|null}
     */
    private static function resolveDir(string $gitDir, ?string $workingDir = null): array
    {
        $realGitDir = realpath($gitDir);

        if (false === $realGitDir) {
            throw new InvalidArgumentException(\sprintf('Directory "%s" does not exist or is not a directory', $gitDir));
        }
        if (!is_dir($realGitDir)) {
            throw new InvalidArgumentException(\sprintf('Directory "%s" does not exist or is not a directory', $realGitDir));
        }
        if (null === $workingDir && is_dir($realGitDir.'/.git')) {
            $workingDir = $realGitDir;
            $realGitDir .= '/.git';
        }

        return [$realGitDir, $workingDir];
    }
}
