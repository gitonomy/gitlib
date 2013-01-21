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
class Log implements \Countable, \IteratorAggregate
{
    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @var array
     */
    protected $revisions;

    /**
     * @var array
     */
    protected $paths;

    /**
     * @var integer
     */
    protected $offset;

    /**
     * @var integer
     */
    protected $limit;

    public function __construct(Repository $repository, $revisions, $paths, $offset = null, $limit = null)
    {
        $this->repository = $repository;
        $this->revisions  = (array) $revisions;
        $this->paths      = (array) $paths;
        $this->offset     = $offset;
        $this->limit      = $limit;
    }

    /**
     * @return Diff
     */
    public function getDiff()
    {
        return new Diff($this->repository, $this->revisions, false);
    }

    /**
     * @return array
     */
    public function getRevisions()
    {
        return $this->revisions;
    }

    /**
     * @return array
     */
    public function getPaths()
    {
        return $this->paths;
    }

    /**
     * @return int
     */
    public function getOffset()
    {
        return $this->offset;
    }

    /**
     * @param int $offset
     */
    public function setOffset($offset)
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * @return int
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @param int $limit
     */
    public function setLimit($limit)
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * @return array
     */
    public function getCommits()
    {
        $offset    = null !== $this->offset ? '--skip='.((int) $this->offset) : '';
        $limit     = null !== $this->limit ? '-n '.((int) $this->limit) : '';
        $revisions = null !== $this->revisions ? $this->revisions : '--all';

        $args = array('--encoding=UTF-8', '--format=format:%H');

        if (null !== $this->offset) {
            $args[] = '--skip='.((int) $this->offset);
        }

        if (null !== $this->limit) {
            $args[] = '-n';
            $args[] = (int) $this->limit;
        }

        if (count($this->revisions)) {
            $args = array_merge($args, $this->revisions);
        } else {
            $args[] = '--all';
        }

        $args[] = '--';

        $args = array_merge($args, $this->paths);

        $exp = explode("\n", $this->repository->run('log', $args));

        $result = array();
        foreach ($exp as $hash) {
            if ($hash == '') {
                continue;
            }
            $result[] = $this->repository->getCommit($hash);
        }

        return $result;
    }

    /**
     * @see Countable
     */
    public function count()
    {
        return $this->countCommits();
    }

    /**
     * @see IteratorAggregate
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->getCommits());
    }

    /**
     * Count commits, without offset or limit.
     *
     * @return int
     */
    public function countCommits()
    {
        if (count($this->revisions)) {
            $output = $this->repository->run('rev-list', array_merge($this->revisions, array('--'), $this->paths));
        } else {
            $output = $this->repository->run('rev-list', array_merge(array('--all', '--'), $this->paths));
        }

        return count(explode("\n", $output)) - 1;
    }
}
