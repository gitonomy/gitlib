<?php

namespace Gitonomy\Git\Event;

class PostCommandEvent extends CommandEvent
{
    /**
     * @var float duration in seconds
     */
    protected $duration;

    public function isSuccessful()
    {
        return $this->process->isSuccessful();
    }

    public function setDuration($duration)
    {
        $this->duration = $duration;
    }

    public function getDuration()
    {
        return $this->duration;
    }

    public function getOutput()
    {
        return $this->process->getOutput();
    }

    public function getErrorOutput()
    {
        return $this->process->getErrorOutput();
    }
}
