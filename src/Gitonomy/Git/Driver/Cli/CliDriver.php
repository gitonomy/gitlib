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

use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\RepositoryPaths;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Util\RemoteUrl;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Runs the git binary, through Symfony Process.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class CliDriver implements DriverInterface
{
    /**
     * A path under a file is never a directory: git finds no repository there.
     */
    private const string NO_GIT_DIR = __FILE__.'/none';

    /**
     * git inherits the environment variables of PHP, like any process run by
     * Symfony Process. LANGUAGE is "C", unless $environmentVariables has it:
     * gitlib reads messages of git, which a translation would change.
     *
     * @param string                                  $command              The git binary
     * @param array<string, string|\Stringable|false> $environmentVariables Set on every git process, false removing an inherited one
     * @param float|null                              $processTimeout       In seconds, for the whole process and without output; null or 0 for none
     * @param LoggerInterface|null                    $logger               Receives every git command run, with its output
     *
     * @throws InvalidArgumentException $processTimeout is negative
     */
    public function __construct(
        private string $command = 'git',
        private array $environmentVariables = [],
        private ?float $processTimeout = 3600,
        private ?LoggerInterface $logger = null,
    ) {
        if (null !== $processTimeout && $processTimeout < 0) {
            throw new InvalidArgumentException(\sprintf('The timeout of git processes can\'t be negative, got %s.', $processTimeout));
        }
    }

    public function open(string $dir, ?string $workingDir): CliRepositoryDriver
    {
        $paths = RepositoryPaths::resolve($dir, $workingDir);

        return new CliRepositoryDriver($this->runner(), $paths->gitDir, $paths->workingDir);
    }

    public function init(string $path): void
    {
        // --: a path may start with a dash.
        $this->mustRun('init', ['-q', '--', $path]);
    }

    public function initBare(string $path): void
    {
        $this->mustRun('init', ['-q', '--bare', '--', $path]);
    }

    public function cloneFrom(#[\SensitiveParameter] string $url, string $path, ?string $branch): void
    {
        $this->cloneRepository($path, $url, null === $branch ? [] : ['--branch', $branch]);
    }

    public function cloneBareFrom(#[\SensitiveParameter] string $url, string $path, ?string $branch): void
    {
        $this->cloneRepository($path, $url, null === $branch ? ['--bare'] : ['--branch', $branch, '--bare']);
    }

    public function mirrorFrom(#[\SensitiveParameter] string $url, string $path): void
    {
        $this->cloneRepository($path, $url, ['--mirror']);
    }

    public function isValidRepository(#[\SensitiveParameter] string $url): bool
    {
        // ls-remote only takes "--" since git 2.8.
        RemoteUrl::assertSafe($url);

        return $this->lsRemote([$url])->isSuccessful();
    }

    public function isValidRepositoryAndBranch(#[\SensitiveParameter] string $url, string $branch): bool
    {
        RemoteUrl::assertSafe($url);

        // git lists the branches whose name ends with the pattern: "release" matches "feature/release".
        $process = $this->lsRemote(['--heads', $url, 'refs/heads/'.$branch]);
        if (!$process->isSuccessful()) {
            return false;
        }

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_ends_with($line, "\trefs/heads/".$branch)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $args Arguments of ``git clone``
     *
     * @throws ProcessException
     */
    private function cloneRepository(string $path, #[\SensitiveParameter] string $url, array $args): void
    {
        // Like isValidRepository(), whose ls-remote can't take "--".
        RemoteUrl::assertSafe($url);

        // --: a path may start with a dash.
        $this->mustRun('clone', ['-q', ...$args, '--', $url, $path]);
    }

    private function runner(): GitRunner
    {
        return new GitRunner($this->command, $this->environmentVariables, $this->processTimeout, $this->logger);
    }

    /**
     * @param string[] $args
     */
    private function mustRun(string $command, #[\SensitiveParameter] array $args): void
    {
        $process = $this->runner()->run($command, $args);

        if (!$process->isSuccessful()) {
            throw new ProcessException($process, $command);
        }
    }

    /**
     * --git-dir keeps git from looking for a repository from the working
     * directory of PHP, which may be in one, like the checkout of an
     * application: ls-remote, unlike clone, would read its configuration,
     * whose rewrites of URLs may run a command, and a relative path as the
     * name of one of its remotes.
     *
     * @param string[] $args Arguments of ``git ls-remote``
     */
    private function lsRemote(#[\SensitiveParameter] array $args): Process
    {
        return $this->runner()->run('ls-remote', $args, ['--git-dir', self::NO_GIT_DIR]);
    }
}
