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

namespace Gitonomy\Git\Driver\Cli;

use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Driver\Cli\Parser\BlameParser;
use Gitonomy\Git\Driver\Cli\Parser\LogParser;
use Gitonomy\Git\Driver\Cli\Parser\ReferenceParser;
use Gitonomy\Git\Driver\Cli\Parser\TagParser;
use Gitonomy\Git\Driver\Cli\Parser\TreeParser;
use Gitonomy\Git\Driver\Data\CommitData;
use Gitonomy\Git\Driver\Data\TagData;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\ProcessException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Runs a git process for every operation on a repository.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final readonly class CliRepositoryDriver implements RepositoryDriverInterface
{
    // --no-abbrev: before git 2.16, --raw abbreviates hashes with a trailing "...".
    private const array DIFF_ARGS = ['-r', '-p', '--raw', '-m', '-M', '--full-index', '--no-abbrev'];

    public function __construct(
        private GitProcessFactory $processFactory,
        private string $gitDir,
        private ?string $workingDir,
        private ?LoggerInterface $logger,
    ) {
    }

    public function resolveRevision(string $revision): string
    {
        return trim($this->run('rev-parse', ['--verify', $revision]));
    }

    public function getObjectType(string $revision): string
    {
        return trim($this->run('cat-file', ['-t', $revision]));
    }

    public function getShortHash(string $revision): string
    {
        return trim($this->run('log', ['--abbrev-commit', '--format=%h', '-n', '1', $revision]));
    }

    public function getCommitData(string $hash): CommitData
    {
        return $this->getCommitLog([$hash], [], null, 1)[$hash] ?? throw new DriverException(\sprintf('Commit "%s" not found.', $hash));
    }

    public function getCommitLog(?array $revisions, array $paths, ?int $offset, ?int $limit): array
    {
        // Re-encodes the commits whose "encoding" header isn't UTF-8.
        $args = ['--encoding=UTF-8', '--format=raw'];

        if (null !== $offset) {
            $args[] = '--skip='.$offset;
        }

        if (null !== $limit) {
            $args[] = '-n';
            $args[] = (string) $limit;
        }

        $parser = new LogParser();
        $parser->parse($this->run('log', [...$args, ...$revisions ?? ['--all'], '--', ...$paths]));

        return $parser->commits;
    }

    public function countCommits(?array $revisions, array $paths): int
    {
        return (int) $this->run('rev-list', ['--count', ...$revisions ?: ['--all'], '--', ...$paths]);
    }

    public function getMergeBase(string $revision1, string $revision2): ?string
    {
        $process = $this->execute('merge-base', [$revision1, $revision2]);

        // git exits with 1 and no output when there is no common ancestor; any
        // other failure (unknown revision, corrupt repository...) is an error.
        if (1 === $process->getExitCode() && '' === $process->getOutput()) {
            return null;
        }

        if (!$process->isSuccessful()) {
            throw new ProcessException($process);
        }

        return trim($process->getOutput());
    }

    public function getTreeEntries(string $hash): array
    {
        $parser = new TreeParser();
        $parser->parse($this->run('ls-tree', ['-z', $hash]));

        return $parser->entries;
    }

    public function getBlobContent(string $hash): string
    {
        return $this->run('cat-file', ['blob', $hash]);
    }

    public function getTagData(string $revision): TagData
    {
        $parser = new TagParser();
        $parser->parse($this->run('cat-file', ['tag', $revision]));

        return $parser->data;
    }

    public function getReferences(): array
    {
        // Unlike for-each-ref in git 1.x, show-ref skips a dangling symbolic reference.
        $process = $this->execute('show-ref', []);

        // git exits with 1 and no output when there is no reference.
        if (1 === $process->getExitCode() && '' === $process->getOutput()) {
            return [];
        }

        if (!$process->isSuccessful()) {
            throw new ProcessException($process);
        }

        $parser = new ReferenceParser();
        $parser->parse($process->getOutput());

        return $parser->references;
    }

    public function updateReference(string $fullname, string $hash): void
    {
        $this->run('update-ref', [$fullname, $hash]);
    }

    public function deleteReference(string $fullname): void
    {
        $this->run('update-ref', ['-d', $fullname]);
    }

    public function getIncludingBranches(string $hash): array
    {
        // for-each-ref only accepts --contains since git 2.7.
        $output = $this->run('branch', ['-a', '--no-color', '--contains', $hash]);

        $branches = [];
        foreach (explode("\n", rtrim($output, "\n")) as $line) {
            // "* " flags the current branch, "(HEAD detached at ...)" a detached
            // HEAD, and "origin/HEAD -> origin/main" a symbolic reference.
            $name = substr($line, 2);
            if ('' === $name || str_starts_with($name, '(') || str_contains($name, ' -> ')) {
                continue;
            }

            $branches[] = str_starts_with($name, 'remotes/') ? 'refs/'.$name : 'refs/heads/'.$name;
        }

        return $branches;
    }

    public function getDiff(array $revisions): Diff
    {
        return Diff::parse($this->run('diff', [...self::DIFF_ARGS, '--no-commit-id', ...$revisions]));
    }

    public function getCommitDiff(string $hash): Diff
    {
        return Diff::parse($this->run('diff-tree', [...self::DIFF_ARGS, '--no-commit-id', '--root', $hash]));
    }

    public function getDiffPending(): Diff
    {
        return Diff::parse($this->run('diff', self::DIFF_ARGS));
    }

    public function getDiffStaged(): Diff
    {
        return Diff::parse($this->run('diff', [...self::DIFF_ARGS, '--staged']));
    }

    public function getBlameLines(string $revision, string $file, ?string $lineRange): array
    {
        $args = ['-p'];
        if (null !== $lineRange) {
            $args[] = '-L';
            $args[] = $lineRange;
        }

        $parser = new BlameParser();
        $parser->parse($this->run('blame', [...$args, $revision, '--', $file]));

        return $parser->lines;
    }

    public function getUntrackedFiles(): array
    {
        $files = [];
        foreach (explode("\0", $this->run('status', ['--porcelain', '--untracked-files=all', '-z'])) as $entry) {
            if (str_starts_with($entry, '?? ')) {
                $files[] = substr($entry, 3);
            }
        }

        return $files;
    }

    public function checkout(string $revision): void
    {
        $this->run('checkout', [$revision]);
    }

    public function checkoutNewBranch(string $branch, string $revision): void
    {
        $this->run('checkout', ['-b', $branch, $revision]);
    }

    public function merge(string $revision, array $args): void
    {
        $this->run('merge', [...$args, $revision]);
    }

    /**
     * Runs any git command, for what no driver method covers.
     *
     * @param array<int|string, int|string> $args
     *
     * @throws ProcessException The command failed
     */
    public function runProcess(string $command, array $args = []): Process
    {
        $process = $this->execute($command, array_map(strval(...), $args));

        if (!$process->isSuccessful()) {
            throw new ProcessException($process);
        }

        return $process;
    }

    /**
     * @param string[] $args
     *
     * @throws ProcessException
     */
    private function run(string $command, array $args): string
    {
        return $this->runProcess($command, $args)->getOutput();
    }

    /**
     * @param string[] $args
     */
    private function execute(string $command, array $args): Process
    {
        $base = ['--git-dir', $this->gitDir];
        if (null !== $this->workingDir) {
            $base = [...$base, '--work-tree', $this->workingDir];
        }

        // Relative paths, in arguments as in pathspecs, resolve against the repository.
        $process = $this->processFactory->create([...$base, $command, ...$args], $this->workingDir ?? $this->gitDir);

        $this->logger?->info(\sprintf('run command: %s "%s" ', $command, implode(' ', $args)));
        $before = microtime(true);

        $process->run();

        if (null !== $this->logger) {
            $this->logger->debug(\sprintf('last command (%s) duration: %.2fms', $command, (microtime(true) - $before) * 1000));
            $this->logger->debug(\sprintf('last command (%s) return code: %s', $command, $process->getExitCode()));
            $this->logger->debug(\sprintf('last command (%s) output: %s', $command, $process->getOutput()));
            if (!$process->isSuccessful()) {
                $this->logger->debug(\sprintf('last command (%s) error output: %s', $command, $process->getErrorOutput()));
            }
        }

        return $process;
    }
}
