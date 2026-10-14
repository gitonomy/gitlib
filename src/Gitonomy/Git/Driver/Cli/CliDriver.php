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
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Util\RemoteUrl;
use Psr\Log\LoggerInterface;

/**
 * Runs the git binary, through Symfony Process.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class CliDriver implements DriverInterface
{
    /**
     * git inherits the environment variables of PHP, like any process run by Symfony Process.
     *
     * @param string                                  $command              The git binary
     * @param array<string, string|\Stringable|false> $environmentVariables Set on every git process, false removing an inherited one
     * @param float|null                              $processTimeout       In seconds, for the whole process and without output; null for none
     * @param LoggerInterface|null                    $logger               Receives every git command run, with its output
     */
    public function __construct(
        private string $command = 'git',
        private array $environmentVariables = [],
        private ?float $processTimeout = 3600,
        private ?LoggerInterface $logger = null,
    ) {
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

        return $this->runner()->run('ls-remote', [self::absoluteUrl($url)])->isSuccessful();
    }

    public function isValidRepositoryAndBranch(#[\SensitiveParameter] string $url, string $branch): bool
    {
        RemoteUrl::assertSafe($url);

        // git lists the branches whose name ends with the pattern: "release" matches "feature/release".
        $process = $this->runner()->run('ls-remote', ['--heads', self::absoluteUrl($url), 'refs/heads/'.$branch]);
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
     * @internal For the deprecated Admin::cloneRepository(), to be removed in 3.0
     *
     * @param string[] $args Arguments of ``git clone``
     *
     * @throws ProcessException
     */
    public function cloneRepository(string $path, #[\SensitiveParameter] string $url, array $args): void
    {
        // Like isValidRepository(), whose ls-remote can't take "--".
        RemoteUrl::assertSafe($url);

        // --: a path may start with a dash.
        $this->mustRun('clone', ['-q', ...$args, '--', $url, $path]);
    }

    /**
     * @internal For the deprecated Repository::getLogger(), to be removed in 3.0
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @internal For the deprecated Repository::setLogger(), to be removed in 3.0
     */
    public function withLogger(LoggerInterface $logger): self
    {
        return new self($this->command, $this->environmentVariables, $this->processTimeout, $logger);
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
            throw new ProcessException($process);
        }
    }

    /**
     * Run in a repository, ls-remote reads a relative path as the name of a
     * remote first: "origin" would be the URL of the remote of PHP's working
     * directory.
     */
    private static function absoluteUrl(#[\SensitiveParameter] string $url): string
    {
        if (str_starts_with($url, '/') || 1 === preg_match('#^[a-zA-Z]:[\\\/]#', $url)) {
            return $url;
        }

        // Like git: a URL, or an address like "host:path", has a colon before any slash.
        $colon = strpos($url, ':');
        $slash = strpos($url, '/');
        if (false !== $colon && (false === $slash || $colon < $slash)) {
            return $url;
        }

        $cwd = getcwd();

        return false === $cwd ? $url : $cwd.'/'.$url;
    }
}
