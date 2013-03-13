<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the GPL license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

use Gitonomy\Git\Exception\LogicException;

/**
 * Push reference contains a commit interval. This object aggregates methods
 * for this interval.
 *
 * @author Julien DIDIER <genzo.wm@gmail.com>
 */
class PushReference
{
    const ZERO = "0000000000000000000000000000000000000000";

    /**
     * @var string
     */
    protected $reference;

    /**
     * @var string
     */
    protected $before;

    /**
     * @var string
     */
    protected $after;

    /**
     * @var boolean
     */
    protected $isForce;

    public function __construct(Repository $repository, $reference, $before, $after)
    {
        $this->repository = $repository;
        $this->reference  = $reference;
        $this->before     = $before;
        $this->after      = $after;
        $this->isForce    = $this->getForce();
    }

    /**
     * @return Repository
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * @return string
     */
    public function getReference()
    {
        return $this->reference;
    }

    /**
     * @return string
     */
    public function getBefore()
    {
        return $this->before;
    }

    /**
     * @return string
     */
    public function getAfter()
    {
        return $this->after;
    }

    /**
     * @return array
     */
    public function getLog($excludes = array())
    {
        return $this->repository->getLog(array_merge(
            array($this->getRevision()),
            array_map(function ($e) {
                return '^'.$e;
            }, $excludes)
        ));
    }

    public function getRevision()
    {
        if ($this->isDelete()) {
            throw new LogicException('No log on deletion');
        }

        if ($this->isCreate()) {
            return $this->getAfter();
        }

        return $this->getBefore().'..'.$this->getAfter();
    }

    /**
     * @return boolean
     */
    public function isCreate()
    {
        return $this->isZero($this->before);
    }

    /**
     * @return boolean
     */
    public function isDelete()
    {
        return $this->isZero($this->after);
    }

    /**
     * @return boolean
     */
    public function isForce()
    {
        return $this->isForce;
    }

    /**
     * @return boolean
     */
    public function isFastForward()
    {
        return !$this->isDelete() && !$this->isCreate() && !$this->isForce();
    }

    /**
     * @return boolean
     */
    protected function isZero($reference)
    {
        return self::ZERO === $reference;
    }

    /**
     * @return boolean
     */
    protected function getForce()
    {
        if ($this->isDelete() || $this->isCreate()) {
            return false;
        }

        $result = $this->repository->run('merge-base', array(
            $this->before,
            $this->after
        ));

        return $this->before !== trim($result);
    }
}
