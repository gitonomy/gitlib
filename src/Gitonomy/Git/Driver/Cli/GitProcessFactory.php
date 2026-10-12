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

use Symfony\Component\Process\Process;

/**
 * Creates the processes running git, as configured on CliDriver.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final readonly class GitProcessFactory
{
    /**
     * @param array<string, string|\Stringable|false> $environmentVariables
     */
    public function __construct(
        private string $command,
        private array $environmentVariables,
        private bool $inheritEnvironmentVariables,
        private ?float $timeout,
    ) {
    }

    /**
     * @param string[] $args
     */
    public function create(array $args, ?string $workingDirectory = null): Process
    {
        $process = new Process([$this->command, ...$args], $workingDirectory);

        if ($this->inheritEnvironmentVariables) {
            // $_SERVER also holds entries that aren't environment variables (argv, REQUEST_TIME...)
            $process->setEnv(array_replace(array_filter($_SERVER, \is_string(...)), $this->environmentVariables));
        } else {
            $process->setEnv($this->environmentVariables);
        }

        $process->setTimeout($this->timeout);
        $process->setIdleTimeout($this->timeout);

        return $process;
    }
}
