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
 * Representation of a diff.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Diff
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
    protected $files;

    /**
     * @var boolean
     */
    protected $isTree;

    /**
     * Constructs a new diff for a given revision.
     *
     * @var Repository $repository
     * @var string     $revision   A string revision, passed to git diff command
     * @var boolean    $isTree     Indicates if revisions are commit-trees to compare
     */
    public function __construct(Repository $repository, $revisions, $isTree = true)
    {
        $this->repository = $repository;
        $this->revisions  = (array) $revisions;
        $this->isTree     = $isTree;
    }

    /**
     * @return array
     */
    public function getRevisions()
    {
        return $this->revisions;
    }

    protected function initialize()
    {
        $args = array('-r', '-p', '-m', '-M', '--no-commit-id', '--full-index');
        $args = array_merge($args, $this->revisions);
        $result = $this->repository->run($this->isTree ? 'diff-tree' : 'diff', $args);

        $parser = new Parser\DiffParser();
        $parser->setRepository($this->repository);
        $parser->parse($result);

        $this->files = $parser->files;
    }

    /**
     * Get list of files modified in the diff's revision.
     *
     * @return array An array of Diff\File objects
     */
    public function getFiles()
    {
        $this->initialize();

        return $this->files;
    }
}
