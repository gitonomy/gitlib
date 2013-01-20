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
     * Short message of the commit.
     *
     * @var string
     */
    private $shortMessage;

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
     * Returns the first line of the commit, and the first 80 characters.
     *
     * @return string
     */
    public function getShortMessage($limit = 50)
    {
        $this->initialize();

        if (null !== $this->shortMessage) {
            return $this->shortMessage;
        }

        $pos    = mb_strpos($this->message, "\n");
        $length = mb_strlen($this->message);

        if (false === $pos) {
            if ($length < $limit) {
                $shortMessage = $this->message;
            } else {
                $shortMessage = mb_substr($this->message, 0, $limit).'...';
            }
        } else {
            if ($pos < $limit) {
                $shortMessage = mb_substr($this->message, 0, $pos);
            } else {
                $shortMessage = mb_substr($this->message, 0, $limit).'...';
            }
        }

        return $this->shortMessage = $shortMessage;
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

    public function toJson()
    {
        $this->initialize();

        return json_encode(array(
            'authorDate'     => $this->authorDate,
            'authorEmail'    => $this->authorEmail,
            'authorName'     => $this->authorName,
            'committerDate'  => $this->committerDate,
            'committerEmail' => $this->committerEmail,
            'committerName'  => $this->committerName,
            'hash'           => $this->hash,
            'message'        => $this->message,
        ));
    }

    public function fromJson($json)
    {
        $datas = json_decode($json, true);

        $this->initialized = true;

        $this->authorDate     = new \DateTime($datas['authorDate']['date'], new \DateTimeZone($datas['authorDate']['timezone']));
        $this->authorEmail    = $datas['authorEmail'];
        $this->authorName     = $datas['authorName'];
        $this->committerDate  = new \DateTime($datas['committerDate']['date'], new \DateTimeZone($datas['committerDate']['timezone']));
        $this->committerEmail = $datas['committerEmail'];
        $this->committerName  = $datas['committerName'];
        $this->hash           = $datas['hash'];
        $this->message        = $datas['message'];
    }

    public static function createInstanceFrom($json)
    {
        $datas = json_decode($json, true);

        $commit = new static(new FakeRepository, $datas['hash']);

        $commit->fromJson($json);

        return $commit;
    }
}

class FakeRepository extends Repository
{
    public function __construct() {}

    public function addListener($eventName, $listener, $priority = 0) { $this->warn(); }
    public function cloneTo($path, $bare = true) { $this->warn(); }
    public function getBlame($revision, $file, $lineRange = null) { $this->warn(); }
    public function getBlob($hash) { $this->warn(); }
    public function getCommit($hash) { $this->warn(); }
    public function getDiff($revision) { $this->warn(); }
    public function getGitDir() { $this->warn(); }
    public function getHead() { $this->warn(); }
    public function getHeadCommit() { $this->warn(); }
    public function getHooks() { $this->warn(); }
    public function getLog($revisions = null, $paths = null, $offset = null, $limit = null) { $this->warn(); }
    public function getPath() { $this->warn(); }
    public function getReferences() { $this->warn(); }
    public function getRevision($name) { $this->warn(); }
    public function getSize() { $this->warn(); }
    public function getTree($hash) { $this->warn(); }
    public function getWorkingCopy() { $this->warn(); }
    public function getWorkingDir() { $this->warn(); }
    public function isBare() { $this->warn(); }
    public function isHeadAttached() { $this->warn(); }
    public function isHeadDetached() { $this->warn(); }
    public function run($command, $args = array()) { $this->warn(); }
    public function shell($command, array $env = array()) { $this->warn(); }

    private function warn()
    {
        throw new \Exception('You can use repository when load a commit from json');
    }
}
