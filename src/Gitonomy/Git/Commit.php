<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Util\StringHelper;

/**
 * Representation of a Git commit.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Commit extends Revision
{
    /**
     * Hash of the commit.
     *
     * @var string
     */
    private $hash;

    /**
     * Short hash.
     *
     * @var string
     */
    private $shortHash;

    /**
     * A flag indicating if the commit is initialized.
     *
     * @var boolean
     */
    private $initialized = false;

    /**
     * Hash of the tree.
     *
     * @var string
     */
    private $treeHash;
    private $tree;

    /**
     * Hashes of the parent commits.
     *
     * @var array
     */
    private $parentHashes;

    /**
     * Author name.
     *
     * @var string
     */
    private $authorName;

    /**
     * Author email.
     *
     * @var string
     */
    private $authorEmail;

    /**
     * Date of authoring.
     *
     * @var DateTime
     */
    private $authorDate;

    /**
     * Committer name.
     *
     * @var string
     */
    private $committerName;

    /**
     * Committer email.
     *
     * @var string
     */
    private $committerEmail;

    /**
     * Date of commit.
     *
     * @var DateTime
     */
    private $committerDate;

    /**
     * Message of the commit.
     *
     * @var string
     */
    private $message;

    /**
     * Constructor.
     *
     * @param Gitonomy\Git\Repository $repository Repository of the commit
     *
     * @param string $hash Hash of the commit
     */
    public function __construct(Repository $repository, $hash)
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
            throw new ReferenceNotFoundException($hash);
        }

        $this->hash = $hash;

        parent::__construct($repository, $hash);
    }

    /**
     * Initializes the commit, which means read data about it and fill object.
     *
     * @throws ReferenceNotFoundException An error occurred during read of data.
     */
    private function initialize()
    {
        if (true === $this->initialized) {
            return;
        }

        $parser = new Parser\CommitParser();
        try {
            $result = $this->repository->run('cat-file', array('commit', $this->hash));
        } catch (ProcessException $e) {
            throw new ReferenceNotFoundException(sprintf('Can not find reference "%s"', $this->hash));
        }

        $parser->parse($result);

        $this->treeHash       = $parser->tree;
        $this->parentHashes   = $parser->parents;
        $this->authorName     = $parser->authorName;
        $this->authorEmail    = $parser->authorEmail;
        $this->authorDate     = $parser->authorDate;
        $this->committerName  = $parser->committerName;
        $this->committerEmail = $parser->committerEmail;
        $this->committerDate  = $parser->committerDate;
        $this->message        = $parser->message;

        $this->initialized = true;
    }

    /**
     * @return Diff
     */
    public function getDiff()
    {
        $args = array('-r', '-p', '-m', '-M', '--no-commit-id', '--full-index', $this->hash);

        $diff = Diff::parse($this->repository->run('diff-tree', $args));
        $diff->setRepository($this->repository);

        return $diff;
    }

    /**
     * Returns the commit hash.
     *
     * @return string A SHA1 hash
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * Returns the short commit hash.
     *
     * @return string A SHA1 hash
     */
    public function getShortHash()
    {
        if (null !== $this->shortHash) {
            return $this->shortHash;
        }

        $result = $this->repository->run('log', array('--abbrev-commit', '--format=%h', '-n', 1, $this->hash));

        return $this->shortHash = trim($result);
    }

    /**
     * Returns a fixed-with short hash.
     */
    public function getFixedShortHash($length = 6)
    {
        return StringHelper::substr($this->hash, 0, $length);
    }

    /**
     * Returns parent hashes.
     *
     * @return array An array of SHA1 hashes
     */
    public function getParentHashes()
    {
        $this->initialize();

        return $this->parentHashes;
    }

    /**
     * Returns the parent commits.
     *
     * @return array An array of Commit objects
     */
    public function getParents()
    {
        $this->initialize();

        $result = array();

        foreach ($this->parentHashes as $parentHash) {
            $result[] = $this->repository->getCommit($parentHash);
        }

        return $result;
    }

    /**
     * Returns the tree hash.
     *
     * @return string A SHA1 hash
     */
    public function getTreeHash()
    {
        $this->initialize();

        return $this->treeHash;
    }

    public function getTree()
    {
        $this->initialize();

        if (null === $this->tree) {
            $this->tree = $this->repository->getTree($this->treeHash);
        }

        return $this->tree;
    }

    /**
     * @return Commit
     */
    public function getLastModification($path = null)
    {
        if (0 === strpos($path, '/')) {
            $path = StringHelper::substr($path, 1);
        }

        if ($getWorkingDir = $this->repository->getWorkingDir()) {
            $path = $getWorkingDir.'/'.$path;
        }

        $result = $this->repository->run('log', array('--format=%H', '-n', 1, $this->hash, '--', $path));

        return $this->repository->getCommit(trim($result));
    }

    /**
     * Returns the first line of the commit, and the first 50 characters.
     *
     * Ported from https://github.com/fabpot/Twig-extensions/blob/d67bc7e69788795d7905b52d31188bbc1d390e01/lib/Twig/Extensions/Extension/Text.php#L52-L109
     *
     * @param integer $length
     * @param boolean $preserve
     * @param string  $separator
     *
     * @return string
     */
    public function getShortMessage($length = 50, $preserve = false, $separator = '...')
    {
        $this->initialize();

        $message = $this->getSubjectMessage();

        if (StringHelper::strlen($message) > $length) {
            if ($preserve && false !== ($breakpoint = StringHelper::strpos($message, ' ', $length))) {
                $length = $breakpoint;
            }

            return rtrim(StringHelper::substr($message, 0, $length)).$separator;
        }

        return $message;
    }

    /**
     * Resolves all references associated to this commit.
     *
     * @return array An array of references (Branch, Tag, Squash)
     */
    public function resolveReferences()
    {
        return $this->repository->getReferences()->resolve($this);
    }

    /**
     * Find branch containing the commit
     *
     * @param boolean $local  set true to try to locate a commit on local repository
     * @param boolean $remote set true to try to locate a commit on remote repository
     *
     * @return array An array of Reference\Branch
     */
    public function getIncludingBranches($local = true, $remote = true)
    {
        $arguments = array('--contains', $this->hash);

        if ($local && $remote) {
            $arguments[] = '-a';
        } elseif (!$local && $remote) {
            $arguments[] = '-r';
        } elseif (!$local && !$remote) {
            throw new InvalidArgumentException('You should a least set one argument to true');
        }

        try {
            $result = $this->repository->run('branch', $arguments);
        } catch (ProcessException $e) {
            return array();
        }

        if (!$result) {
            return array();
        }

        $branchesName = explode("\n", trim(str_replace('*', '', $result)));
        $branchesName = array_filter($branchesName, function($v) { return false === StringHelper::strpos($v, '->');});
        $branchesName = array_map('trim', $branchesName);

        $references = $this->repository->getReferences();

        $branches = array();
        foreach ($branchesName as $branchName) {
            if (false === $local) {
                $branches[] = $references->getRemoteBranch($branchName);
            } elseif (0 === StringHelper::strrpos($branchName, 'remotes/')) {
                $branches[] = $references->getRemoteBranch(str_replace('remotes/', '', $branchName));
            } else {
                $branches[] = $references->getBranch($branchName);
            }
        }

        return $branches;
    }

    /**
     * Returns the author name.
     *
     * @return string A name
     */
    public function getAuthorName()
    {
        $this->initialize();

        return $this->authorName;
    }

    /**
     * Returns the author email.
     *
     * @return string An email
     */
    public function getAuthorEmail()
    {
        $this->initialize();

        return $this->authorEmail;
    }

    /**
     * Returns the authoring date.
     *
     * @return DateTime A time object
     */
    public function getAuthorDate()
    {
        $this->initialize();

        return $this->authorDate;
    }

    /**
     * Returns the committer name.
     *
     * @return string A name
     */
    public function getCommitterName()
    {
        $this->initialize();

        return $this->committerName;
    }

    /**
     * Returns the comitter email.
     *
     * @return string An email
     */
    public function getCommitterEmail()
    {
        $this->initialize();

        return $this->committerEmail;
    }

    /**
     * Returns the authoring date.
     *
     * @return DateTime A time object
     */
    public function getCommitterDate()
    {
        $this->initialize();

        return $this->committerDate;
    }

    /**
     * Returns the message of the commit.
     *
     * @return string A commit message
     */
    public function getMessage()
    {
        $this->initialize();

        return $this->message;
    }

    /**
     * Returns the subject message (the first line)
     *
     * @return string The subject message
     */
    public function getSubjectMessage()
    {
        $message = $this->getMessage();

        $lines = explode("\n", $message);

        return reset($lines);
    }

    /**
     * Return the body message
     *
     * @return string The body message
     */
    public function getBodyMessage()
    {
        $message = $this->getMessage();

        $lines = explode("\n", $message);

        array_shift($lines);
        array_shift($lines);

        return implode("\n", $lines);
    }

    /**
     * @inheritdoc
     */
    public function getCommit()
    {
        return $this;
    }
}
