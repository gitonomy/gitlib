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
 * Git repository object.
 *
 * Main entry point for browsing a Git repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Repository
{
    /**
     * Path to the repository
     *
     * @var string
     */
    protected $path;

    /**
     * Cache containing all objets of the repository.
     *
     * Associative array, indexed by object hash
     *
     * @var array
     */
    protected $objects;

    /**
     * Constructor.
     *
     * @param string $path Path to the Git repository
     *
     * @throws InvalidArgumentException The folder does not exists
     */
    public function __construct($path)
    {
        $this->objects = array();

        if (!is_dir($path)) {
            throw new \InvalidArgumentException(sprintf('The folder "%s" does not exists', $path));
        }
        $this->path   = $path;
    }

    /**
     * Returns the path to the Git repository.
     *
     * @return string A directory path
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Instanciates a revision.
     *
     * @param string $name Name of the revision
     *
     * @return Gitonomy\Git\Revision
     */
    public function getRevision($name)
    {
        return new Revision($this, $name);
    }

    /**
     * Returns the reference list associated to the repository.
     *
     * @return Gitonomy\Git\ReferenceBag
     */
    public function getReferences()
    {
        return new ReferenceBag($this);
    }

    /**
     * Instanciates a commit object or fetches one from the cache.
     *
     * @param string $hash A commit hash, with a length of 40
     *
     * @return Gitonomy\Git\Commit
     */
    public function getCommit($hash)
    {
        if (! isset($this->objects[$hash])) {
            $this->objects[$hash] = new Commit($this, $hash);
        }

        return $this->objects[$hash];
    }

    public function getTree($hash)
    {
        if (! isset($this->objects[$hash])) {
            $this->objects[$hash] = new Tree($this, $hash);
        }

        return $this->objects[$hash];
    }

    public function getBlob($hash)
    {
        if (! isset($this->objects[$hash])) {
            $this->objects[$hash] = new Blob($this, $hash);
        }

        return $this->objects[$hash];
    }

    public function getLog($reference = null)
    {
        return new Log($this, $reference);
    }

    /**
     * Returns the size of repository, in kilobytes.
     *
     * @return int A sum, in kilobytes
     */
    public function getSize()
    {
        ob_start();
        system(sprintf('du -skc %s', escapeshellarg($this->path)), $return);
        $result = ob_get_clean();

        if (0 !== $return) {
            throw new \RuntimeException('Unable to compute filesize');
        }

        if (!preg_match('/(\d+)\s+total$/', $result, $vars)) {
            throw new \RuntimeException('Unable to parse repository size output');
        }

        return $vars[1];
    }

    /**
     * Executes a shell command on the repository, using PHP pipes.
     *
     * @param string $command The command to execute
     */
    public function shell($command, array $env = array())
    {
        $argument = sprintf('%s \'%s\'', $command, $this->path);

        $prefix = '';
        foreach ($env as $name => $value) {
            $prefix .= sprintf('export %s=%s;', escapeshellarg($name), escapeshellarg($value));
        }

        proc_open($prefix.'git shell -c '.escapeshellarg($argument), array(STDIN, STDOUT, STDERR), $pipes);
    }

    /**
     * Returns the hooks object.
     *
     * @return Gitonomy\Git\Hooks
     */
    public function getHooks()
    {
        return new Hooks($this);
    }
}
