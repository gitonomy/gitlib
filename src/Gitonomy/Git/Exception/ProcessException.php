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
     * The message is a sentence, with the error of git, from its error
     * output: "The git command "clone" failed (exit 128): fatal: ...". It
     * hides the credentials of the URLs, unlike getErrorOutput() and getOutput().
     *
     * @param string|null $command The git command, like "clone", which the message names
     */
    public function __construct(
        private readonly Process $process,
        ?string $command = null,
    ) {
        $message = (null === $command ? 'The git command' : \sprintf('The git command "%s"', $command)).\sprintf(' failed (exit %d)', $process->getExitCode());
        // One line of the error output only: getOutput() may be long, and
        // hold the data of the repository. The first error, as warnings may
        // come before it, like the one of a HOME git can't read; the first
        // line otherwise. A colon announces the next lines: "error: Your
        // local changes ... would be overwritten by checkout:".
        $errorOutput = $process->getErrorOutput();
        $error = 1 === preg_match('/^(?:fatal|error):.*$/m', $errorOutput, $matches) ? $matches[0] : explode("\n", trim($errorOutput), 2)[0];
        $error = rtrim(trim($error), ':');
        if ('' !== $error) {
            $message .= ': '.$error;
        }

        parent::__construct(Credentials::redact(1 === preg_match('/[.!?]$/', $message) ? $message : $message.'.'));
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
