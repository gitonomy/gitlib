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

use Gitonomy\Git\Exception\RuntimeException;

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
     * @var string
     */
    protected $gitDir;

    /**
     * @var string
     */
    protected $workingDir;

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
     * @throws InvalidArgumentException The folder does not exists
     */
    public function __construct($gitDir, $workingDir = null)
    {
        $gitDir = realpath($gitDir);
        if (null === $workingDir && is_dir($gitDir.'/.git')) {
            $workingDir  = $gitDir;
            $gitDir      = $gitDir.'/.git';
        }

        if (!is_dir($gitDir)) {
            throw new \InvalidArgumentException(sprintf('Directory "%s" does not exist', $gitDir));
        }

        if (null !== $workingDir && !is_dir($workingDir)) {
            $workingDir = realpath($workingDir);

            throw new \InvalidArgumentException(sprintf('Directory "%s" does not exist', $workingDir));
        }

        $this->gitDir     = $gitDir;
        $this->workingDir = $workingDir;
        $this->objects    = array();
    }

    public function isBare()
    {
        if (null === $this->isBare) {
            $this->isBare = trim($this->run('config', array('core.bare'))) == 'true';
        }

        return $this->isBare;
    }

    /**
     * @return Commit
     */
    public function getHeadCommit()
    {
        $head = $this->getHead();

        if ($head instanceof Reference) {
            $head = $head->getCommit();
        }

        return $head;
    }

    /**
     * @return Reference|Commit
     */
    public function getHead()
    {
        if ($this->isBare()) {
            throw new \LogicException("Can't get HEAD in a bare repository");
        }

        $file = $this->gitDir.'/HEAD';
        if (!file_exists($file)) {
            throw new \RuntimeException('Missing file HEAD');
        }

        $content = trim(file_get_contents($file));

        if (preg_match('/^ref: (.+)$/', $content, $vars)) {
            return $this->getReferences()->get($vars[1]);
        } elseif (preg_match('/^[0-9a-f]{40}$/', $content)) {
            return $this->getCommit($content);
        }

        throw new \RuntimeException(sprintf('Unexpected HEAD value : %s', $content));
    }

    /**
     * @return boolean
     */
    public function isHeadDetached()
    {
        return $this->getHead() instanceof Commit;
    }

    /**
     * @return boolean
     */
    public function isHeadAttached()
    {
        return !$this->isHeadDetached();
    }

    /**
     * Returns the path to the Git repository.
     *
     * @return string A directory path
     */
    public function getPath()
    {
        return $this->workingDir === null ? $this->gitDir : $this->workingDir;
    }

    /**
     * Returns the directory containing git files (git-dir).
     *
     * @return string
     */
    public function getGitDir()
    {
        return $this->gitDir;
    }

    /**
     * Returns the work-tree directory. This may be null if repository is
     * bare.
     *
     * @return string
     */
    public function getWorkingDir()
    {
        return $this->workingDir;
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
        $process = ProcessBuilder::create(array('du', '-skc', $this->gitDir))->getProcess();

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
        $argument = sprintf('%s \'%s\'', $command, $this->gitDir);

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

    /**
     * This command is a facility command. You can run any command
     * directly on git repository.
     *
     * @param string $command Git command to run (checkout, branch, tag)
     * @param array  $args    Arguments of git command
     *
     * @return string Output of a successful process
     *
     * @throws RuntimeException Error while executing git command
     */
    public function run($command, $args = array())
    {
        $command = $this->getProcess($command, $args);
        $command->run();
        if (!$command->isSuccessful()) {
            throw new RuntimeException($command);
        }

        return $command->getOutput();
    }

    /**
     * @return WorkingCopy
     */
    public function getWorkingCopy()
    {
        return new WorkingCopy($this);
    }

    /**
     * @see self::run
     */
    protected function getProcess($command, $args = array())
    {
        $base = array('git', '--git-dir', $this->gitDir, '--work-tree', $this->workingDir, $command);
        $builder = new ProcessBuilder(array_merge($base, $args));

        return $builder->getProcess();
    }
}
