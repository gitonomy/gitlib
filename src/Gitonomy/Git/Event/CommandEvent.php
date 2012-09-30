<?php

namespace Gitonomy\Git\Event;

use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\Process\Process;

abstract class CommandEvent extends Event
{
    protected $process;
    protected $command;
    protected $args;

    public function __construct(Process $process, $command, array $args)
    {
        $this->process = $process;
        $this->command = $command;
        $this->args    = $args;
    }

    public function getCommandLine()
    {
        return $this->process->getCommandLine();
    }

    public function getCommand()
    {
        return $this->command;
    }

    public function getArgs()
    {
        return $this->args;
    }

    public function getSignature()
    {
        return spl_object_hash($this->process);
    }
}
