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
use Gitonomy\Git\Exception\ProcessException;
use Psr\Log\LoggerInterface;

/**
 * Runs the git binary, through Symfony Process.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class CliDriver implements DriverInterface
{
    private GitProcessFactory $processFactory;

    /**
     * @param string                                  $command                     The git binary
     * @param array<string, string|\Stringable|false> $environmentVariables        Set on every git process, false removing an inherited one
     * @param bool                                    $inheritEnvironmentVariables Also pass every entry of $_SERVER to git processes
     * @param float|null                              $processTimeout              In seconds, for the whole process and without output; null for none
     */
    public function __construct(
        string $command = 'git',
        array $environmentVariables = [],
        bool $inheritEnvironmentVariables = false,
        ?float $processTimeout = 3600,
    ) {
        $this->processFactory = new GitProcessFactory($command, $environmentVariables, $inheritEnvironmentVariables, $processTimeout);
    }

    public function open(string $gitDir, ?string $workingDir, ?LoggerInterface $logger): CliRepositoryDriver
    {
        return new CliRepositoryDriver($this->processFactory, $gitDir, $workingDir, $logger);
    }

    public function init(string $path): void
    {
        $this->mustRun(['init', '-q', $path]);
    }

    public function initBare(string $path): void
    {
        $this->mustRun(['init', '-q', '--bare', $path]);
    }

    public function cloneRepository(string $path, string $url, array $args): void
    {
        $this->mustRun(['clone', '-q', ...$args, $url, $path]);
    }

    public function isValidRepository(string $url): bool
    {
        return 0 === $this->processFactory->create(['ls-remote', $url])->run();
    }

    public function isValidRepositoryAndBranch(string $url, string $branchName): bool
    {
        $process = $this->processFactory->create(['ls-remote', '--heads', $url, $branchName]);

        // git only lists the branches matching $branchName.
        return 0 === $process->run() && '' !== $process->getOutput();
    }

    /**
     * @param string[] $args
     */
    private function mustRun(array $args): void
    {
        $process = $this->processFactory->create($args);

        if (0 !== $process->run()) {
            throw new ProcessException($process);
        }
    }
}
