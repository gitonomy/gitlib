<?php

namespace Gitonomy\Git\Exception;

use Gitonomy\Git\Util\Credentials;
use Symfony\Component\Process\Process;

/**
 * @final since gitonomy/gitlib 1.7
 */
class ProcessException extends RuntimeException implements GitExceptionInterface
{
    protected $process;

    /**
     * The message hides the credentials of the URLs, unlike getErrorOutput()
     * and getOutput().
     */
    public function __construct(Process $process)
    {
        parent::__construct(Credentials::redact(
            "Error while running git command:\n".
            $process->getCommandLine()."\n".
            "\n".
            $process->getErrorOutput()."\n".
            "\n".
            $process->getOutput()
        ));

        $this->process = $process;
    }

    public function getErrorOutput()
    {
        return $this->process->getErrorOutput();
    }

    public function getOutput()
    {
        return $this->process->getOutput();
    }
}
