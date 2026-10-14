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

use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ProcessTimedOutException;
use Gitonomy\Git\Util\Credentials;
use Gitonomy\Git\Util\NulByte;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as SymfonyRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the git processes, as configured on CliDriver, and logs them.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final readonly class GitRunner
{
    /**
     * @param array<string, string|\Stringable|false> $environmentVariables
     */
    public function __construct(
        private string $command,
        private array $environmentVariables,
        private ?float $timeout,
        private ?LoggerInterface $logger,
    ) {
    }

    /**
     * Runs a git command, successful or not.
     *
     * @param string[]    $args          Arguments of $command
     * @param string[]    $globalOptions Options of git itself, before $command (--git-dir...)
     * @param string|null $input         The standard input of git
     *
     * @throws InvalidArgumentException An argument contains a NUL byte
     * @throws ProcessException         The git binary can't be run
     */
    public function run(string $command, #[\SensitiveParameter] array $args, array $globalOptions = [], ?string $workingDirectory = null, ?string $input = null): Process
    {
        NulByte::assertNone(...$globalOptions, ...$args);

        $process = new Process([$this->command, ...$globalOptions, $command, ...$args], $workingDirectory);
        $process->setEnv($this->environmentVariables);
        $process->setInput($input);

        $process->setTimeout($this->timeout);
        $process->setIdleTimeout($this->timeout);

        // The logs hide the credentials of the URLs, like the exceptions.
        $this->logger?->info(Credentials::redact(\sprintf('run command: %s "%s" ', $command, implode(' ', $args))));
        $before = microtime(true);

        // The exceptions of Symfony aren't chained: their messages may hold the
        // command line, with the credentials of its URLs.
        try {
            $process->run();
        } catch (SymfonyProcessTimedOutException) {
            throw new ProcessTimedOutException(\sprintf('The git command "%s" ran longer than %s seconds.', $command, $this->timeout));
        } catch (SymfonyRuntimeException $e) {
            // A working directory removed since the repository was opened...
            throw new DriverException(Credentials::redact(\sprintf('Unable to run the git command "%s": %s', $command, $e->getMessage())));
        }

        // Symfony Process runs git through a shell, which exits with them when it can't find or run git.
        if (\in_array($process->getExitCode(), [126, 127], true)) {
            throw new ProcessException($process);
        }

        if (null !== $this->logger) {
            $this->logger->debug(\sprintf('last command (%s) duration: %.2fms', $command, (microtime(true) - $before) * 1000));
            $this->logger->debug(\sprintf('last command (%s) return code: %s', $command, $process->getExitCode()));
            $this->logger->debug(Credentials::redact(\sprintf('last command (%s) output: %s', $command, $process->getOutput())));
            if (!$process->isSuccessful()) {
                $this->logger->debug(Credentials::redact(\sprintf('last command (%s) error output: %s', $command, $process->getErrorOutput())));
            }
        }

        return $process;
    }
}
