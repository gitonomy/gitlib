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
 * Representation of a Git commit.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Commit
{
    /**
     * The repository associated to the commit.
     *
     * @var Gitonomy\Git\Repository
     */
    private $repository;

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
        $this->repository = $repository;
        $this->hash = $hash;
    }

    /**
     * Initializes the commit, which means read data about it and fill object.
     *
     * @throws RuntimeException An error occurred during read of data.
     */
    private function initialize()
    {
        if (true === $this->initialized) {
            return;
        }

        $parser = new Parser\CommitParser();
        $result = $this->repository->run('cat-file', array('commit', $this->hash));
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

    public function getDiff()
    {
        return new Diff($this->repository, array($this->hash));
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
        return substr($this->hash, 0, $length);
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
    public function getLastModification($path, $lastHash = null)
    {
        if (preg_match('#^/#', $path)) {
            $path = substr($path, 1);
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

        if (function_exists('mb_substr')) {
            if (mb_strlen($message) > $length) {
                if ($preserve) {
                    if (false !== ($breakpoint = mb_strpos($message, ' ', $length))) {
                        $length = $breakpoint;
                    }
                }

                return rtrim(mb_substr($message, 0, $length)) . $separator;
            }

            return $message;
        } else {
            if (strlen($message) > $length) {
                if ($preserve) {
                    if (false !== ($breakpoint = strpos($message, ' ', $length))) {
                        $length = $breakpoint;
                    }
                }

                return rtrim(substr($message, 0, $length)) . $separator;
            }

            return $message;
        }
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
            throw new \InvalidArgumentException('You should a least set one argument to true');
        }

        try {
            $result = $this->repository->run('branch', $arguments);
        } catch (\Exception $e) {
            return array();
        }

        if (!$result) {
            return array();
        }

        $branchesName = explode("\n", trim(str_replace('*', '', $result)));
        $branchesName = array_filter($branchesName, function($v) { return false === strpos($v, '->');});
        $branchesName = array_map('trim', $branchesName);

        $references = $this->repository->getReferences();

        $branches = array();
        foreach ($branchesName as $branchName) {
            if (false === $local) {
                $branches[] = $references->getRemoteBranch($branchName);
            } elseif (0 === strrpos($branchName, 'remotes/')) {
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
}
