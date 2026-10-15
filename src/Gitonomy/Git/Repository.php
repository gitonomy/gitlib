<?php

/**
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
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Git repository object.
 *
 * Main entry point for browsing a Git repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class Repository
{
    const DEFAULT_DESCRIPTION = "Unnamed repository; edit this file 'description' to name the repository.\n";

    /**
     * Directory containing git files.
     *
     * @var string
     */
    protected $gitDir;

    /**
     * Working directory.
     *
     * @var string
     */
    protected $workingDir;

    /**
     * Cache containing all objects of the repository.
     *
     * Associative array, indexed by object hash
     *
     * @var array
     */
    protected $objects;

    /**
     * Reference bag associated to this repository.
     *
     * @var ReferenceBag
     */
    protected $referenceBag;

    /**
     * Logger (can be null).
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Path to git command.
     */
    protected $command;

    /**
     * Debug flag, indicating if errors should be thrown.
     *
     * @var bool
     */
    protected $debug;

    /**
     * Environment variables that should be set for every running process.
     *
     * @var array
     */
    protected $environmentVariables;

    /**
     * @var bool
     */
    protected $inheritEnvironmentVariables;

    /**
     * Timeout that should be set for every running process.
     *
     * @var int
     */
    protected $processTimeout;

    /**
     * Constructs a new repository.
     *
     * The second argument is the working directory, when $dir is a git
     * directory kept apart from it. An array of options there is deprecated:
     * its "working_dir" option is this argument, and its "logger", "command",
     * "environment_variables" and "process_timeout" options configure a
     * CliDriver. Available options are:
     *
     * * working_dir : specify where working copy is located (option --work-tree)
     *
     * * debug : (default: true) enable/disable minimize errors and reduce log level
     *
     * * logger : a logger to use for logging actions (Psr\Log\LoggerInterface)
     *
     * * environment_variables : define environment variables for every ran process
     *
     * @param string            $dir     path to git repository
     * @param string|array|null $options The working tree, or an array of options, deprecated
     * @param CliDriver|null    $driver  Configures git, a CliDriver with its default arguments when null
     *
     * @throws InvalidArgumentException The folder does not exists
     */
    public function __construct($dir, $options = [], ?CliDriver $driver = null)
    {
        if (!is_array($options)) {
            $options = ['working_dir' => $options];
        } elseif ([] !== $options) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing an array of options to "%s()" is deprecated, pass the working directory as second argument, and configure the logger and git through the "driver" argument.', __METHOD__);

            if (null !== $driver && [] !== array_intersect(array_keys($options), ['command', 'environment_variables', 'inherit_environment_variables', 'logger', 'process_timeout'])) {
                throw new InvalidArgumentException('The driver argument can\'t be combined with the "logger" or git options: configure the driver instead.');
            }

            if (array_key_exists('debug', $options) && !$options['debug']) {
                trigger_deprecation('gitonomy/gitlib', '1.7', 'Setting the "debug" option of "%s()" to false is deprecated, a failing git operation always throws in 2.0: catch its exception instead.', __METHOD__);
            }
        }

        if (null !== $driver) {
            $options = array_merge($options, $driver->getOptions());
        }

        $options = array_merge([
            'working_dir'                   => null,
            'debug'                         => true,
            'logger'                        => null,
            'command'                       => 'git',
            'environment_variables'         => [],
            'inherit_environment_variables' => false,
            'process_timeout'               => 3600,
        ], $options);

        if (null !== $options['logger'] && !$options['logger'] instanceof LoggerInterface) {
            throw new InvalidArgumentException(sprintf('Argument "logger" passed to Repository should be a Psr\Log\LoggerInterface. A %s was provided', is_object($options['logger']) ? get_class($options['logger']) : gettype($options['logger'])));
        }

        $this->logger = $options['logger'];
        $this->initDir($dir, $options['working_dir']);

        $this->objects = [];
        $this->command = $options['command'];
        $this->debug = (bool) $options['debug'];
        $this->processTimeout = $options['process_timeout'];

        if (defined('PHP_WINDOWS_VERSION_BUILD') && isset($_SERVER['PATH']) && !isset($options['environment_variables']['PATH'])) {
            $options['environment_variables']['PATH'] = $_SERVER['PATH'];
        }

        $this->environmentVariables = $options['environment_variables'];
        $this->inheritEnvironmentVariables = $options['inherit_environment_variables'];

        if (true === $this->debug && null !== $this->logger) {
            $this->logger->debug(sprintf('Repository created (git dir: "%s", working dir: "%s")', $this->gitDir, $this->workingDir ?: 'none'));
        }
    }

    /**
     * Initializes a repository with a working directory in $path.
     *
     * @param CliDriver|null $driver Configures git, a CliDriver with its default arguments when null
     *
     * @throws RuntimeException The directory can't be written, or is already a file
     */
    public static function init(string $path, ?CliDriver $driver = null): self
    {
        $driver = $driver ?? new CliDriver();
        $driver->init($path);

        return new self($path, null, $driver);
    }

    /**
     * Initializes a bare repository in $path.
     *
     * @param CliDriver|null $driver Configures git, a CliDriver with its default arguments when null
     *
     * @throws RuntimeException The directory can't be written, or is already a file
     */
    public static function initBare(string $path, ?CliDriver $driver = null): self
    {
        $driver = $driver ?? new CliDriver();
        $driver->initBare($path);

        return new self($path, null, $driver);
    }

    /**
     * Clones $url with a working directory in $path.
     *
     * @param string|null    $branch The branch to check out, the default branch of $url when null
     * @param CliDriver|null $driver Configures git, a CliDriver with its default arguments when null
     *
     * @throws RuntimeException
     */
    public static function cloneFrom(string $url, string $path, ?string $branch = null, ?CliDriver $driver = null): self
    {
        $driver = $driver ?? new CliDriver();
        $driver->cloneFrom($url, $path, $branch);

        return new self($path, null, $driver);
    }

    /**
     * Clones the branches and the tags of $url into a bare repository in $path.
     *
     * @param string|null    $branch The branch HEAD points to, the default branch of $url when null
     * @param CliDriver|null $driver Configures git, a CliDriver with its default arguments when null
     *
     * @throws RuntimeException
     */
    public static function cloneBareFrom(string $url, string $path, ?string $branch = null, ?CliDriver $driver = null): self
    {
        $driver = $driver ?? new CliDriver();
        $driver->cloneBareFrom($url, $path, $branch);

        return new self($path, null, $driver);
    }

    /**
     * Clones every reference of $url into a bare repository in $path, set up
     * to mirror $url when fetching.
     *
     * @param CliDriver|null $driver Configures git, a CliDriver with its default arguments when null
     *
     * @throws RuntimeException
     */
    public static function mirrorFrom(string $url, string $path, ?CliDriver $driver = null): self
    {
        $driver = $driver ?? new CliDriver();
        $driver->mirrorFrom($url, $path);

        return new self($path, null, $driver);
    }

    /**
     * Checks, without cloning it, that $url is a repository git can read:
     * false when it doesn't exist, can't be reached, or refuses access.
     *
     * @param CliDriver|null $driver Configures git, a CliDriver with its default arguments when null
     */
    public static function isValidRepository(string $url, ?CliDriver $driver = null): bool
    {
        return ($driver ?? new CliDriver())->isValidRepository($url);
    }

    /**
     * Checks, without cloning it, that $url is a repository git can read,
     * and that it has the $branch branch.
     *
     * @param CliDriver|null $driver Configures git, a CliDriver with its default arguments when null
     */
    public static function isValidRepositoryAndBranch(string $url, string $branch, ?CliDriver $driver = null): bool
    {
        return ($driver ?? new CliDriver())->isValidRepositoryAndBranch($url, $branch);
    }

    /**
     * Initializes directory attributes on repository:.
     *
     * @param string $gitDir     directory of a working copy with files checked out
     * @param string $workingDir directory containing git files (objects, config...)
     */
    private function initDir($gitDir, $workingDir = null)
    {
        $realGitDir = realpath($gitDir);

        if (false === $realGitDir) {
            throw new InvalidArgumentException(sprintf('Directory "%s" does not exist or is not a directory', $gitDir));
        } elseif (!is_dir($realGitDir)) {
            throw new InvalidArgumentException(sprintf('Directory "%s" does not exist or is not a directory', $realGitDir));
        } elseif (null === $workingDir && is_dir($realGitDir.'/.git')) {
            $workingDir = $realGitDir;
            $realGitDir = $realGitDir.'/.git';
        }

        $this->gitDir = $realGitDir;
        $this->workingDir = $workingDir;
    }

    /**
     * Tests if repository is a bare repository.
     *
     * @return bool
     */
    public function isBare()
    {
        return null === $this->workingDir;
    }

    /**
     * Returns the HEAD resolved as a commit.
     *
     * @return Commit|null returns a Commit or ``null`` if repository is empty
     */
    public function getHeadCommit()
    {
        $head = $this->getHead();

        if ($head instanceof Reference) {
            return $head->getCommit();
        }

        return $head;
    }

    /**
     * @throws RuntimeException Unable to find file HEAD (debug-mode only)
     *
     * @return Reference|Commit|null current HEAD object or null if error occurs
     */
    public function getHead()
    {
        $file = $this->gitDir.'/HEAD';

        if (!file_exists($file)) {
            $message = sprintf('Unable to find HEAD file ("%s")', $file);

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
        } elseif (preg_match('/^[0-9a-f]{40}$/', $content)) {
            return $this->getCommit($content);
        }

        $message = sprintf('Unexpected HEAD file content (file: %s). Content of file: %s', $file, $content);

        if (null !== $this->logger) {
            $this->logger->error($message);
        }

        if (true === $this->debug) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @return bool
     */
    public function isHeadDetached()
    {
        return !$this->isHeadAttached();
    }

    /**
     * @return bool
     */
    public function isHeadAttached()
    {
        return $this->getHead() instanceof Reference;
    }

    /**
     * Returns the path to the git repository.
     *
     * @return string A directory path
     */
    public function getPath()
    {
        return $this->workingDir === null ? $this->gitDir : $this->workingDir;
    }

    /**
     * Returns the directory containing git files (git-dir).
     *
     * @return string
     */
    public function getGitDir()
    {
        return $this->gitDir;
    }

    /**
     * Returns the work-tree directory. This may be null if repository is
     * bare.
     *
     * @return string path to repository or null if repository is bare
     */
    public function getWorkingDir()
    {
        return $this->workingDir;
    }

    /**
     * Instanciates a revision.
     *
     * @param string $name Name of the revision
     *
     * @return Revision
     */
    public function getRevision($name)
    {
        return new Revision($this, $name);
    }

    /**
     * @return WorkingCopy
     */
    public function getWorkingCopy()
    {
        return new WorkingCopy($this);
    }

    /**
     * Returns the reference list associated to the repository.
     *
     * @param bool $reload Reload references from the filesystem, deprecated: open a new Repository instead
     *
     * @return ReferenceBag
     */
    public function getReferences($reload = false)
    {
        if (\func_num_args() > 0) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing an argument to "%s()" is deprecated, open a new Repository to read the references again.', __METHOD__);
        }

        if (null === $this->referenceBag || $reload) {
            $this->referenceBag = new ReferenceBag($this);
        }

        return $this->referenceBag;
    }

    /**
     * Instanciates a commit object or fetches one from the cache.
     *
     * @param string $hash A commit hash, with a length of 40
     *
     * @return Commit
     */
    public function getCommit($hash)
    {
        if (!isset($this->objects[$hash])) {
            $this->objects[$hash] = new Commit($this, $hash);
        }

        return $this->objects[$hash];
    }

    /**
     * Instanciates a tree object or fetches one from the cache.
     *
     * Passing anything but a hash, like a revision name, is deprecated.
     *
     * @param string $hash A tree hash, with a length of 40
     *
     * @return Tree
     */
    public function getTree($hash)
    {
        if (!preg_match('/^[0-9a-f]{4,40}$/', $hash)) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing "%s", which is not a hash, to "%s()" is deprecated, it throws in 2.0: use "getRevision($name)->getCommit()->getTree()" instead.', $hash, __METHOD__);
        }

        if (!isset($this->objects[$hash])) {
            $this->objects[$hash] = new Tree($this, $hash);
        }

        return $this->objects[$hash];
    }

    /**
     * Instanciates a blob object or fetches one from the cache.
     *
     * Passing anything but a hash, like "HEAD:README.md", is deprecated.
     *
     * @param string $hash A blob hash, with a length of 40
     *
     * @return Blob
     */
    public function getBlob($hash)
    {
        if (!preg_match('/^[0-9a-f]{4,40}$/', $hash)) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing "%s", which is not a hash, to "%s()" is deprecated, it throws in 2.0: use "Tree::resolvePath()" instead.', $hash, __METHOD__);
        }

        if (!isset($this->objects[$hash])) {
            $this->objects[$hash] = new Blob($this, $hash);
        }

        return $this->objects[$hash];
    }

    /**
     * Returns the commits that last changed the lines of $file.
     *
     * The third argument is the first line to blame, and a fourth one the last
     * line, like in 2.0. A string as third argument, a range of lines like
     * "git blame -L", is deprecated.
     *
     * @param int|string|null $lineRange The first line to blame, from 1, the first one of the file when null
     * @param int|null        $lastLine  The last line to blame, the last one of the file when null
     *
     * @return Blame
     *
     * @psalm-variadic The arguments this method gets with func_get_arg(), commented out in its signature
     */
    public function getBlame($revision, $file, $lineRange = null /* , ?int $lastLine = null */)
    {
        // A fourth argument, without breaking the classes overriding this method.
        $lastLine = 3 < \func_num_args() ? func_get_arg(3) : null;

        if (is_string($revision)) {
            $revision = $this->getRevision($revision);
        }

        return new Blame($this, $revision, $file, $lineRange, $lastLine);
    }

    /**
     * Returns log for a given set of revisions and paths.
     *
     * All those values can be null, meaning everything.
     *
     * @param array $revisions An array of revisions to show logs from. Can be
     *                         any text value type
     * @param array $paths     Restrict log to modifications occurring on given
     *                         paths.
     * @param int   $offset    Start from a given offset in results.
     * @param int   $limit     Limit number of total results.
     *
     * @return Log
     */
    public function getLog($revisions = null, $paths = null, $offset = null, $limit = null)
    {
        return new Log($this, $revisions, $paths, $offset, $limit);
    }

    /**
     * Returns the best common ancestor of two commits, the one "git diff a...b" compares to.
     *
     * @return Commit|null Null when they have no common history
     *
     * @throws ReferenceNotFoundException A revision names no commit
     */
    public function getMergeBase(Revision|string $one, Revision|string $two): ?Commit
    {
        $process = $this->execute('merge-base', [$this->commitHash($one), $this->commitHash($two)]);

        // git exits with 1, writing nothing, when the commits have no common ancestor.
        if (1 === $process->getExitCode() && '' === $process->getErrorOutput()) {
            return null;
        }

        if (!$process->isSuccessful()) {
            // Returns with the "debug" option set to false.
            $this->fail('merge-base', $process);

            return null;
        }

        return $this->getCommit(trim($process->getOutput()));
    }

    /**
     * Returns the changes from the commit of the first argument to the commit $to.
     *
     * Passing one argument is deprecated: an "a..b" or "a...b" range, two
     * revisions in an array or a RevisionList, or one revision to compare
     * with the working tree, like WorkingCopy::getDiff() does.
     *
     * @param RevisionList|Revision|string|array $revisions The revision to compare from, when a second argument is given
     * @param Revision|string|null               $to
     *
     * @return Diff
     *
     * @throws ReferenceNotFoundException A revision names no commit
     *
     * @psalm-variadic The arguments this method gets with func_get_arg(), commented out in its signature
     */
    public function getDiff($revisions /* , $to = null */)
    {
        // A second argument, without breaking the classes overriding this method.
        $to = 1 < \func_num_args() ? func_get_arg(1) : null;

        if (null === $to) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Calling "%s()" with one argument is deprecated, pass the revisions to compare as first and second arguments, or use "%s::getDiff()" to compare a revision with the working tree.', __METHOD__, WorkingCopy::class);

            return $this->getLegacyDiff($revisions);
        }

        if (!$revisions instanceof Revision && !is_string($revisions)) {
            throw new InvalidArgumentException('The first argument of "Repository::getDiff()" must be a revision, when a second one is given.');
        }

        return $this->runDiff([$this->commitHash($revisions), $this->commitHash($to)]);
    }

    /**
     * For the deprecated forms of getDiff() and Log::getDiff(), to be removed in 2.0.
     *
     * @param RevisionList|Revision|string|array $revisions
     *
     * @internal
     */
    public function getLegacyDiff($revisions): Diff
    {
        if (null !== $revisions && !$revisions instanceof RevisionList) {
            $revisions = new RevisionList($this, $revisions);
        }

        return $this->runDiff($revisions->getAsTextArray());
    }

    /**
     * @return int The size of the files of the git directory, working tree excluded, in bytes
     *
     * @throws RuntimeException A file or a directory of the git directory can't be read
     */
    public function getSizeInBytes(): int
    {
        return self::directorySize($this->gitDir);
    }

    /**
     * Returns the size of repository, in kilobytes.
     *
     * Unlike getSizeInBytes(), it counts the files the links point to.
     *
     * @return int A sum, in kilobytes
     *
     * @deprecated since gitonomy/gitlib 1.7, use getSizeInBytes() instead
     */
    public function getSize()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getSizeInBytes()" instead.', __METHOD__);

        $totalBytes = 0;
        $path = realpath($this->gitDir);
        if ($path && file_exists($path)) {
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
     *
     * @deprecated since gitonomy/gitlib 1.7, run "git shell" with Symfony Process instead
     */
    public function shell($command, array $env = [])
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, run "git shell" with Symfony Process instead: it ignores the driver.', __METHOD__);

        $argument = sprintf('%s \'%s\'', $command, $this->gitDir);

        $prefix = '';
        foreach ($env as $name => $value) {
            $prefix .= sprintf('export %s=%s;', escapeshellarg($name), escapeshellarg($value));
        }

        proc_open($prefix.'git shell -c '.escapeshellarg($argument), [STDIN, STDOUT, STDERR], $pipes);
    }

    /**
     * Returns the hooks object.
     *
     * @return Hooks
     */
    public function getHooks()
    {
        return new Hooks($this);
    }

    /**
     * Returns description of repository from description file in git directory.
     *
     * @return string The description
     */
    public function getDescription()
    {
        $file = $this->gitDir.'/description';
        $exists = is_file($file);

        if (null !== $this->logger && true === $this->debug) {
            if (false === $exists) {
                $this->logger->debug(sprintf('no description file in repository ("%s")', $file));
            } else {
                $this->logger->debug(sprintf('reading description file in repository ("%s")', $file));
            }
        }

        if (false === $exists) {
            return static::DEFAULT_DESCRIPTION;
        }

        return file_get_contents($this->gitDir.'/description');
    }

    /**
     * Tests if repository has a custom set description.
     *
     * @return bool
     */
    public function hasDescription()
    {
        return static::DEFAULT_DESCRIPTION !== $this->getDescription();
    }

    /**
     * Changes the repository description (file description in git-directory).
     *
     * @return Repository the current repository
     */
    public function setDescription($description)
    {
        $file = $this->gitDir.'/description';

        if (null !== $this->logger && true === $this->debug) {
            $this->logger->debug(sprintf('change description file content to "%s" (file: %s)', $description, $file));
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
     * @throws RuntimeException Error while executing git command (debug-mode only)
     *
     * @return string Output of a successful process or null if execution failed and debug-mode is disabled.
     */
    public function run($command, $args = [])
    {
        $process = $this->execute($command, $args);

        if (!$process->isSuccessful()) {
            $this->fail($command, $process);

            return;
        }

        return $process->getOutput();
    }

    /**
     * Set repository logger.
     *
     * @param LoggerInterface $logger A logger
     *
     * @return Repository The current repository
     *
     * @deprecated since gitonomy/gitlib 1.7, pass a CliDriver configured with the logger as driver argument instead
     */
    public function setLogger(LoggerInterface $logger)
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, pass a "%s" configured with the logger as driver argument instead.', __METHOD__, CliDriver::class);

        $this->logger = $logger;

        return $this;
    }

    /**
     * Returns repository logger.
     *
     * @return LoggerInterface the logger or null
     *
     * @deprecated since gitonomy/gitlib 1.7, the logger is an argument of CliDriver
     */
    public function getLogger()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, the logger is an argument of "%s".', __METHOD__, CliDriver::class);

        return $this->logger;
    }

    /**
     * Clones the current repository to a new directory and return instance of new repository.
     *
     * @param string $path path to the new repository in which current repository will be cloned
     * @param bool   $bare flag indicating if repository is bare or has a working-copy
     *
     * @return Repository the newly created repository
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::cloneBareFrom() or Repository::cloneFrom() with getGitDir() as URL instead
     */
    public function cloneTo($path, $bare = true, array $options = [])
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::%s()" with "getGitDir()" as URL instead.', __METHOD__, self::class, $bare ? 'cloneBareFrom' : 'cloneFrom');

        CliDriver::fromOptions($options)->cloneRepository($this->gitDir, $path, $bare ? ['--bare'] : []);

        return new self($path, $options);
    }

    /**
     * @param string[] $revisions
     */
    private function runDiff(array $revisions): Diff
    {
        $args = array_merge(['-r', '-p', '--raw', '-m', '-M', '--no-commit-id', '--full-index'], $revisions);

        return Diff::parse($this->run('diff', $args), $this);
    }

    /**
     * @throws ReferenceNotFoundException $revision names no commit
     */
    private function commitHash(Revision|string $revision): string
    {
        return ($revision instanceof Revision ? $revision : $this->getRevision($revision))->getCommit()->getHash();
    }

    /**
     * Runs a git command, and logs it.
     */
    private function execute($command, $args = []): Process
    {
        $process = $this->getProcess($command, $args);

        if ($this->logger) {
            $this->logger->info(sprintf('run command: %s "%s" ', $command, implode(' ', $args)));
            $before = microtime(true);
        }

        $process->run();

        if ($this->logger && $this->debug) {
            $duration = microtime(true) - $before;
            $this->logger->debug(sprintf('last command (%s) duration: %sms', $command, sprintf('%.2f', $duration * 1000)));
            $this->logger->debug(sprintf('last command (%s) return code: %s', $command, $process->getExitCode()));
            $this->logger->debug(sprintf('last command (%s) output: %s', $command, $process->getOutput()));
        }

        return $process;
    }

    /**
     * Logs the failure of a git command, and throws unless the "debug" option is false.
     *
     * @throws ProcessException
     */
    private function fail($command, Process $process): void
    {
        if ($this->logger) {
            $this->logger->error(sprintf("error while running %s\n output: \"%s\"", $command, $process->getErrorOutput()));
        }

        if ($this->debug) {
            throw new ProcessException($process);
        }
    }

    /**
     * This internal method is used to create a process object.
     *
     * Made private to be sure that process creation is handled through the run method.
     * run method ensures logging and debug.
     *
     * @see self::run
     */
    private function getProcess($command, $args = [])
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

    /**
     * git may delete a file or a directory while it is counted, during a gc
     * or a push: a lock, a temporary pack, the quarantine of a push... It no
     * longer counts.
     *
     * @throws RuntimeException A file or a directory of $dir can't be read
     */
    private static function directorySize(string $dir): int
    {
        try {
            $files = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        } catch (\UnexpectedValueException $e) {
            if (!file_exists($dir)) {
                return 0;
            }

            throw new RuntimeException(sprintf('Unable to read the directory "%s".', $dir), 0, $e);
        }

        $size = 0;
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            // A link isn't followed: it may point to a file not deployed yet, like a hook.
            if ($file->isLink()) {
                continue;
            }

            if ($file->isDir()) {
                $size += self::directorySize($file->getPathname());

                continue;
            }

            try {
                $size += $file->getSize();
            } catch (\RuntimeException $e) {
                if (file_exists($file->getPathname())) {
                    throw new RuntimeException(sprintf('Unable to read the size of "%s".', $file->getPathname()), 0, $e);
                }
            }
        }

        return $size;
    }
}
