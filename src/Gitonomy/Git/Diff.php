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
     * @var string
     */
    protected $revision;

    /**
     * @var array
     */
    protected $files;

    /**
     * Constructs a new diff for a given revision.
     *
     * @var Repository $repository
     * @var string     $revision   A string revision, passed to git diff command
     */
    public function __construct(Repository $repository, $revision)
    {
        $this->repository = $repository;
        $this->revision   = $revision;
    }

    /**
     * @return string
     */
    public function getRevision()
    {
        return $this->revision;
    }

    protected function initialize()
    {
        $process = $this->repository->getProcess('diff-tree', array('-r', '-p', '-m', '-M', '--no-commit-id', $this->revision));

        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Error while getting diff: '.$process->getErrorOutput());
        }

        $parser = new Parser\DiffParser();
        $parser->parse($process->getOutput());

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
