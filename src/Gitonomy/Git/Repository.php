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
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\RuntimeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Git repository object.
 *
 * Main entry point for browsing a Git repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
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
     * Path to git command.
     */
    private readonly string $command;

    /**
     * Debug flag, indicating if errors should be thrown.
     */
    private readonly bool $debug;

    /**
     * Environment variables that should be set for every running process.
     */
    private readonly array $environmentVariables;

    private readonly bool $inheritEnvironmentVariables;

    /**
     * Timeout that should be set for every running process.
     */
    private readonly int $processTimeout;

    /**
     * Constructs a new repository.
     *
     * Available options are:
     *
     * * working_dir : specify where working copy is located (option --work-tree)
     *
     * * debug : (default: true) enable/disable minimize errors and reduce log level
     *
     * * logger : a logger to use for logging actions (Psr\Log\LoggerInterface)
     *
     * * environment_variables : define environment variables for every ran process
     *
     * @param string $dir     path to git repository
     * @param array  $options array of options values
     *
     * @throws InvalidArgumentException The folder does not exists
     */
    public function __construct(string $dir, array $options = [])
    {
        $options = array_merge([
            'working_dir' => null,
            'debug' => true,
            'logger' => null,
            'command' => 'git',
            'environment_variables' => [],
            'inherit_environment_variables' => false,
            'process_timeout' => 3600,
        ], $options);

        if (null !== $options['logger'] && !$options['logger'] instanceof LoggerInterface) {
            throw new InvalidArgumentException(\sprintf('Argument "logger" passed to Repository should be a Psr\Log\LoggerInterface. A %s was provided', \is_object($options['logger']) ? \get_class($options['logger']) : \gettype($options['logger'])));
        }

        $this->logger = $options['logger'];
        [$this->gitDir, $this->workingDir] = self::resolveDir($dir, $options['working_dir']);

        $this->command = $options['command'];
        $this->debug = (bool) $options['debug'];
        $this->processTimeout = $options['process_timeout'];

        if (\defined('PHP_WINDOWS_VERSION_BUILD') && isset($_SERVER['PATH']) && !isset($options['environment_variables']['PATH'])) {
            $options['environment_variables']['PATH'] = $_SERVER['PATH'];
        }

        $this->environmentVariables = $options['environment_variables'];
        $this->inheritEnvironmentVariables = $options['inherit_environment_variables'];

        if (true === $this->debug && null !== $this->logger) {
            $this->logger->debug(\sprintf('Repository created (git dir: "%s", working dir: "%s")', $this->gitDir, $this->workingDir ?: 'none'));
        }
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
     *
     * @return Commit|null returns a Commit or ``null`` if repository is empty
     */
    public function getHeadCommit(): ?Commit
    {
        $head = $this->getHead();

        if ($head instanceof Reference) {
            return $head->getCommit();
        }

        return $head;
    }

    /**
     * @return Reference|Commit|null current HEAD object or null if error occurs
     *
     * @throws RuntimeException Unable to find file HEAD (debug-mode only)
     */
    public function getHead(): Reference|Commit|null
    {
        $file = $this->gitDir.'/HEAD';

        if (!file_exists($file)) {
            $message = \sprintf('Unable to find HEAD file ("%s")', $file);

            if (null !== $this->logger) {
                $this->logger->error($message);
            }

            if (true === $this->debug) {
                throw new RuntimeException($message);
            }
        }

        $content = trim(file_get_contents($file));

        if (null !== $this->logger) {
            $this->logger->debug('HEAD file read: '.$content);
        }

        if (preg_match('/^ref: (.+)$/', $content, $vars)) {
            return $this->getReferences()->get($vars[1]);
        }
        if (preg_match('/^[0-9a-f]{40}$/', $content)) {
            return $this->getCommit($content);
        }

        $message = \sprintf('Unexpected HEAD file content (file: %s). Content of file: %s', $file, $content);

        if (null !== $this->logger) {
            $this->logger->error($message);
        }

        if (true === $this->debug) {
            throw new RuntimeException($message);
        }

        return null;
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

        $args = array_merge(['-r', '-p', '--raw', '-m', '-M', '--no-commit-id', '--full-index'], $revisions->getAsTextArray());

        $diff = Diff::parse($this->run('diff', $args));
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
        $argument = \sprintf('%s \'%s\'', $command, $this->gitDir);

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

        if (null !== $this->logger && true === $this->debug) {
            if (false === $exists) {
                $this->logger->debug(\sprintf('no description file in repository ("%s")', $file));
            } else {
                $this->logger->debug(\sprintf('reading description file in repository ("%s")', $file));
            }
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

        if (null !== $this->logger && true === $this->debug) {
            $this->logger->debug(\sprintf('change description file content to "%s" (file: %s)', $description, $file));
        }
        file_put_contents($file, $description);

        return $this;
    }

    /**
     * This command is a facility command. You can run any command
     * directly on git repository.
     *
     * @param string $command Git command to run (checkout, branch, tag)
     * @param array  $args    Arguments of git command
     *
     * @return string|null output of a successful process or null if execution failed and debug-mode is disabled
     *
     * @throws RuntimeException Error while executing git command (debug-mode only)
     */
    public function run(string $command, array $args = []): ?string
    {
        $process = $this->getProcess($command, $args);

        if ($this->logger) {
            $this->logger->info(\sprintf('run command: %s "%s" ', $command, implode(' ', $args)));
            $before = microtime(true);
        }

        $process->run();

        $output = $process->getOutput();

        if ($this->logger && $this->debug) {
            $duration = microtime(true) - $before;
            $this->logger->debug(\sprintf('last command (%s) duration: %sms', $command, \sprintf('%.2f', $duration * 1000)));
            $this->logger->debug(\sprintf('last command (%s) return code: %s', $command, $process->getExitCode()));
            $this->logger->debug(\sprintf('last command (%s) output: %s', $command, $output));
        }

        if (!$process->isSuccessful()) {
            $error = \sprintf("error while running %s\n output: \"%s\"", $command, $process->getErrorOutput());

            if ($this->logger) {
                $this->logger->error($error);
            }

            if ($this->debug) {
                throw new ProcessException($process);
            }

            return null;
        }

        return $output;
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
     * @param string $path path to the new repository in which current repository will be cloned
     * @param bool   $bare flag indicating if repository is bare or has a working-copy
     *
     * @return Repository the newly created repository
     */
    public function cloneTo(string $path, bool $bare = true, array $options = []): self
    {
        return Admin::cloneTo($path, $this->gitDir, $bare, $options);
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

    /**
     * This internal method is used to create a process object.
     *
     * Made private to be sure that process creation is handled through the run method.
     * run method ensures logging and debug.
     *
     * @see self::run
     */
    private function getProcess(string $command, array $args = []): Process
    {
        $base = [$this->command, '--git-dir', $this->gitDir];

        if ($this->workingDir) {
            $base = array_merge($base, ['--work-tree', $this->workingDir]);
        }

        $base[] = $command;

        $process = new Process(array_merge($base, $args));

        if ($this->inheritEnvironmentVariables) {
            $process->setEnv(array_replace($_SERVER, $this->environmentVariables));
        } else {
            $process->setEnv($this->environmentVariables);
        }

        $process->setTimeout($this->processTimeout);
        $process->setIdleTimeout($this->processTimeout);

        return $process;
    }
}
