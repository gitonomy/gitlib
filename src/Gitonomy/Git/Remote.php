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

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Remote
{
    protected $repository;
    protected $name;
    protected $urls;

    public function __construct(Repository $repository, $name, array $urls = array())
    {
        $this->repository = $repository;
        $this->name       = $name;
        $this->urls       = $urls;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getLocalReferences()
    {
        $name = $this->name;
        return array_filter((array) $this->repository->getReferences()->getAll(), function ($ref) use ($name) {
            return preg_match('#^refs/remotes/'.preg_quote($name).'/#', $ref->getFullname());
        });
    }
}
