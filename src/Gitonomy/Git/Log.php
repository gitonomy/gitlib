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

class Log
{
    protected $repository;
    protected $revisions;
    protected $offset;
    protected $limit;

    public function __construct(Repository $repository, $revisions)
    {
        $this->repository = $repository;
        $this->revisions = $revisions;
    }

    public function getOffset()
    {
        return $this->offset;
    }

    public function setOffset($offset)
    {
        $this->offset = $offset;

        return $this;
    }

    public function getLimit()
    {
        return $this->limit;
    }

    public function setLimit($limit)
    {
        $this->limit = $limit;

        return $this;
    }

    public function getCommits()
    {
        ob_start();
        $cmd = sprintf(
            'cd %s && git log --format="format:%s" %s %s %s',
            escapeshellarg($this->repository->getPath()),
            '%H',
            null !== $this->offset ? '--skip='.((int) $this->offset) : '',
            null !== $this->limit ? '-n '.((int) $this->limit) : '',
            null !== $this->revisions ? escapeshellarg($this->revisions) : '--all'
        );
        system($cmd, $result);

        $output = ob_get_clean();

        $exp = explode("\n", $output);

        $result = array();
        foreach ($exp as $hash) {
            if ($hash == '') {
                continue;
            }
            $result[] = $this->repository->getCommit($hash);
        }

        return $result;
    }

    public function countCommits()
    {
        ob_start();
        system(sprintf(
            'cd %s && git rev-list %s',
            escapeshellarg($this->repository->getPath()),
            escapeshellarg($this->revisions)
        ), $result);
        $output = ob_get_clean();
        $exp = explode("\n", $output);

        return count($exp);
    }
}
