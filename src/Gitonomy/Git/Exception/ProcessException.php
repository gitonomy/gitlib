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

namespace Gitonomy\Git\Exception;

use Gitonomy\Git\Util\Credentials;
use Symfony\Component\Process\Process;

final class ProcessException extends DriverException implements GitExceptionInterface
{
    /**
     * The message hides the credentials of the URLs, unlike getErrorOutput() and getOutput().
     */
    public function __construct(private readonly Process $process)
    {
        parent::__construct(Credentials::redact(
            "Error while running git command:\n".
            $process->getCommandLine()."\n".
            "\n".
            $process->getErrorOutput()."\n".
            "\n".
            $process->getOutput()
        ));
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
