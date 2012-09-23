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

use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;

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
     * Boolean indicating if repository is a bare repository
     *
     * @var boolean
     */
    protected $isBare;

    /**
     * Cache containing all objets of the repository.
     *
     * Associative array, indexed by object hash
     *
     * @var array
     */
    protected $objects;

    /**
     * Reference bag associated to this repository.
     *
     * @var ReferenceBag
     */
    protected $referenceBag;

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

    public function isBare()
    {
        if (null === $this->isBare) {
            $this->isBare = trim($this->run('config', array('core.bare'))) == 'true';
        }

        return $this->isBare;
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
     * @return Revision
     */
    public function getRevision($name)
    {
        return new Revision($this, $name);
    }

    /**
     * Returns the reference list associated to the repository.
     *
     * @return ReferenceBag
     */
    public function getReferences()
    {
        if (null === $this->referenceBag) {
            $this->referenceBag = new ReferenceBag($this);
        }

        return $this->referenceBag;
    }

    /**
     * Instanciates a commit object or fetches one from the cache.
     *
     * @param string $hash A commit hash, with a length of 40
     *
     * @return Commit
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

    public function getLog($reference = null, $offset = null, $limit = null)
    {
        return new Log($this, $reference, $offset, $limit);
    }

    /**
     * @return Diff
     */
    public function getDiff($revision)
    {
        return new Diff($this, $revision);
    }

    /**
     * Returns the size of repository, in kilobytes.
     *
     * @return int A sum, in kilobytes
     *
     * @throws RuntimeException An error occurred while computing size
     */
    public function getSize()
    {
        $process = ProcessBuilder::create(array('du', '-skc', $this->path))->getProcess();

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Unable to compute size: ', $process->getErrorOutput()));
        }

        if (!preg_match('/(\d+)\s+total$/', $process->getOutput(), $vars)) {
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
     * @return Hooks
     */
    public function getHooks()
    {
        return new Hooks($this);
    }

    public function getProcess($command, $args = array(), $returnBuilder = false)
    {
        $builder = new ProcessBuilder(array_merge(array('git', $command), $args));
        $builder->setWorkingDirectory($this->path);

        if ($returnBuilder) {
            return $builder;
        }

        return $builder->getProcess();
    }

    public function run($command, $args = array())
    {
        if (is_string($command)) {
            $command = $this->getProcess($command, $args);
        } elseif (!$command instanceof Process) {
            throw new \InvalidArgumentException(sprintf('Expected a "string" or a "Process", "%s" given', gettype(variant)));
        }

        $command->run();

        if (!$command->isSuccessful()) {
            throw new \RuntimeException('Error while running git command: '.$command->getErrorOutput());
        }

        return $command->getOutput();
    }
}
