<?php

namespace Gitonomy\Git\Exception;

use Symfony\Component\Process\Process;

final class ProcessException extends RuntimeException implements GitExceptionInterface
{
    public function __construct(private readonly Process $process)
    {
        parent::__construct(
            "Error while running git command:\n".
            $process->getCommandLine()."\n".
            "\n".
            $process->getErrorOutput()."\n".
            "\n".
            $process->getOutput()
        );
    }

    public function getErrorOutput(): string
    {
        return $this->process->getErrorOutput();
    }

    public function getOutput(): string
    {
        return $this->process->getOutput();
    }
}
