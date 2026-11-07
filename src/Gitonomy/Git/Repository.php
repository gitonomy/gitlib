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
use Gitonomy\Git\Driver\Data\ObjectType;
use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ProcessTimedOutException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Util\WeakCache;
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
     * Operations on this repository, opened by the driver.
     */
    private readonly RepositoryDriverInterface $repositoryDriver;

    /**
     * Opens the repository in $dir: a bare repository, or a working tree with
     * its ".git" directory or file (a linked worktree, a submodule...).
     *
     * @param string|null     $workingDir The working tree, when $dir is a git directory kept apart from it
     * @param DriverInterface $driver     Backend running the operations
     *
     * @throws InvalidArgumentException      $dir doesn't exist or isn't a repository
     * @throws DriverException               The driver can't open the repository
     * @throws UnsupportedOperationException The repository uses SHA-256 hashes, or with LibGit2Driver the reftable format
     */
    public function __construct(string $dir, ?string $workingDir = null, DriverInterface $driver = new CliDriver())
    {
        $this->commits = new WeakCache();
        $this->trees = new WeakCache();
        $this->blobs = new WeakCache();
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
     *
     * @throws ReferenceNotFoundException HEAD points to a branch without commits yet
     */
    public function getHeadCommit(): Commit
    {
        return $this->getRevision('HEAD')->getCommit();
    }

    /**
     * @return Branch|Commit The branch HEAD points to, or the commit when HEAD is detached
     *
     * @throws ReferenceNotFoundException HEAD points to a branch without commits yet, or to a reference that isn't a branch
     */
    public function getHead(): Branch|Commit
    {
        $reference = $this->repositoryDriver->getHeadReference();

        if (null === $reference) {
            return $this->getCommit($this->repositoryDriver->resolveRevision('HEAD'));
        }

        $references = $this->getReferences();
        // A branch another process checked out since gitlib read the references.
        if (!$references->has($reference)) {
            $references->invalidate();
        }

        $head = $references->get($reference);
        // git lets HEAD point to any reference under "refs/", a tag included.
        if (!$head instanceof Branch) {
            throw new ReferenceNotFoundException(\sprintf('HEAD points to "%s", which is not a branch.', $reference));
        }

        return $head;
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
     * Instantiates a revision.
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
     */
    public function getReferences(): ReferenceBag
    {
        return $this->referenceBag ??= new ReferenceBag($this);
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
     * @param int|null $firstLine The first line to blame, from 1, the first one of the file when null
     * @param int|null $lastLine  The last line to blame, the last one of the file when null
     *
     * @throws InvalidArgumentException An invalid range of lines
     */
    public function getBlame(Revision|string $revision, string $file, ?int $firstLine = null, ?int $lastLine = null): Blame
    {
        return new Blame($this, \is_string($revision) ? $this->getRevision($revision) : $revision, $file, $firstLine, $lastLine);
    }

    /**
     * Returns log for a given set of revisions and paths.
     *
     * All those values can be null, meaning everything.
     *
     * @param RevisionList|Revision|string|array<Revision|string>|null $revisions An array of revisions to show logs from. Can be
     *                                                                            any text value type
     * @param string[]|string|null                                     $paths     restrict log to modifications occurring on given
     *                                                                            paths
     * @param int|null                                                 $offset    start from a given offset in results
     * @param int|null                                                 $limit     limit number of total results
     *
     * @throws InvalidArgumentException $offset or $limit is negative
     */
    public function getLog(RevisionList|Revision|string|array|null $revisions = null, array|string|null $paths = null, ?int $offset = null, ?int $limit = null): Log
    {
        return new Log($this, $revisions, $paths, $offset, $limit);
    }

    /**
     * Returns the changes from the commit $from to the commit $to.
     */
    public function getDiff(Revision|string $from, Revision|string $to): Diff
    {
        return Diff::fromData($this->repositoryDriver->getDiff($this->commitHash($from), $this->commitHash($to)), $this);
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
     * @param string                        $command Git command to run (checkout, branch, tag)
     * @param array<int|string, int|string> $args    Arguments of git command
     *
     * @return string output of the process
     *
     * @throws ProcessException              Error while executing git command
     * @throws ProcessTimedOutException      git runs longer than the timeout of the CliDriver
     * @throws DriverException               git can't start, or is killed
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
     * @param string                        $command Git command to run (checkout, branch, tag)
     * @param array<int|string, int|string> $args    Arguments of git command
     *
     * @throws ProcessException              Error while executing git command
     * @throws ProcessTimedOutException      git runs longer than the timeout of the CliDriver
     * @throws DriverException               git can't start, or is killed
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

        try {
            $fullHash = $this->repositoryDriver->resolveRevision($hash.'^{'.$type->value.'}');
        } catch (ReferenceNotFoundException) {
            // One more call, only on failure: an object of another type, or none.
            try {
                $this->repositoryDriver->resolveRevision($hash);
            } catch (ReferenceNotFoundException) {
                throw new ReferenceNotFoundException(\sprintf('The object "%s" does not exist.', $hash));
            }

            throw new ReferenceNotFoundException(\sprintf('The object "%s" is not a %s.', $hash, $type->value));
        }

        // "^{tree}" also peels a commit to its tree.
        if (!str_starts_with($fullHash, $hash)) {
            throw new ReferenceNotFoundException(\sprintf('The object "%s" is not a %s.', $hash, $type->value));
        }

        return $fullHash;
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

            throw new RuntimeException(\sprintf('Unable to read the directory "%s".', $dir), 0, $e);
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
                    throw new RuntimeException(\sprintf('Unable to read the size of "%s".', $file->getPathname()), 0, $e);
                }
            }
        }

        return $size;
    }
}
