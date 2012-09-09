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

class Diff
{
    protected $repository;
    protected $revision;
    protected $files;

    public function __construct(Repository $repository, $revision)
    {
        $this->repository = $repository;
        $this->revision   = $revision;
    }

    public function getRevision()
    {
        return $this->revision;
    }

    protected function initialize()
    {
        ob_start();
        system(sprintf(
            'cd %s && git diff-tree -r -p -m -M --no-commit-id %s',
            escapeshellarg($this->repository->getPath()),
            escapeshellarg($this->revision)
        ), $return);
        $result = ob_get_clean();

        if (0 !== $return) {
            throw new \RuntimeException('Error while getting diff');
        }

        $parser = new Parser\DiffParser();
        $parser->parse($result);
        $this->files = $parser->files;
    }

    public function getFiles()
    {
        $this->initialize();

        return $this->files;
    }
}
