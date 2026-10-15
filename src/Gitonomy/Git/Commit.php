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
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Util\StringHelper;

/**
 * Representation of a Git commit.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class Commit extends Revision
{
    /**
     * Associative array of commit data.
     *
     * @var array
     */
    private $data = [];

    /**
     * Constructor.
     *
     * @param Repository $repository Repository of the commit
     * @param string     $hash       Hash of the commit
     *
     * In gitlib 2.0, the constructor is internal, and takes a CommitData
     * instead of an array: get it from Repository::getCommit(). Passing data
     * is deprecated.
     */
    public function __construct(Repository $repository, $hash, array $data = [])
    {
        if ([] !== $data) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing data to "%s()" is deprecated, the constructor is internal in 2.0, and takes a "CommitData" instead of an array: get the commit from "%s::getCommit()".', __METHOD__, Repository::class);
        }

        if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
            throw new ReferenceNotFoundException($hash);
        }

        parent::__construct($repository, $hash);

        $this->setData($data);
    }

    /**
     * In gitlib 2.0, it is internal, and takes a CommitData instead of an array.
     */
    public function setData(array $data)
    {
        foreach ($data as $name => $value) {
            $this->data[$name] = $value;
        }
    }

    /**
     * @return Diff
     */
    public function getDiff()
    {
        $args = ['-r', '-p', '--raw', '-m', '-M', '--no-commit-id', '--full-index', $this->revision];

        return Diff::parse($this->repository->run('diff-tree', $args), $this->repository);
    }

    /**
     * Returns the commit hash.
     *
     * @return string A SHA1 hash
     */
    public function getHash()
    {
        return $this->revision;
    }

    /**
     * Returns the short commit hash.
     *
     * @return string A SHA1 hash
     */
    public function getShortHash()
    {
        return $this->getData('shortHash');
    }

    /**
     * Returns a fixed-with short hash.
     *
     * @return string Short hash
     *
     * @deprecated since gitonomy/gitlib 1.7, use substr($commit->getHash(), 0, $length) instead
     */
    public function getFixedShortHash($length = 6)
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "substr($commit->getHash(), 0, $length)" instead.', __METHOD__);

        return substr($this->revision, 0, $length);
    }

    /**
     * Returns parent hashes.
     *
     * @return string[] An array of SHA1 hashes
     */
    public function getParentHashes()
    {
        return $this->getData('parentHashes');
    }

    /**
     * Returns the parent commits.
     *
     * @return Commit[] An array of Commit objects
     */
    public function getParents()
    {
        $result = [];
        foreach ($this->getData('parentHashes') as $parentHash) {
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
        return $this->getData('treeHash');
    }

    /**
     * @return Tree
     */
    public function getTree()
    {
        return $this->getData('tree');
    }

    /**
     * @return Commit
     */
    public function getLastModification($path = null)
    {
        if (0 === strpos($path, '/')) {
            $path = substr($path, 1);
        }

        if ($getWorkingDir = $this->repository->getWorkingDir()) {
            $path = $getWorkingDir.'/'.$path;
        }

        $result = $this->repository->run('log', ['--format=%H', '-n', 1, $this->revision, '--', $path]);

        return $this->repository->getCommit(trim($result));
    }

    /**
     * Returns the first line of the commit, and the first 50 characters.
     *
     * Ported from https://github.com/fabpot/Twig-extensions/blob/d67bc7e69788795d7905b52d31188bbc1d390e01/lib/Twig/Extensions/Extension/Text.php#L52-L109
     *
     * @param int    $length
     * @param bool   $preserve
     * @param string $separator
     *
     * @return string
     *
     * @deprecated since gitonomy/gitlib 1.7, use symfony/string instead: u($commit->getSubjectMessage())->truncate($length, $separator, !$preserve)
     */
    public function getShortMessage($length = 50, $preserve = false, $separator = '...')
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use symfony/string instead: "u($commit->getSubjectMessage())->truncate($length, $separator, !$preserve)".', __METHOD__);

        $message = $this->getData('subjectMessage');
        // The encoding StringHelper::setEncoding() set, without loading this deprecated class.
        $encoding = class_exists(StringHelper::class, false) ? StringHelper::getEncoding() : 'utf-8';

        if (mb_strlen($message, $encoding) > $length) {
            if ($preserve && false !== ($breakpoint = mb_strpos($message, ' ', $length, $encoding))) {
                $length = $breakpoint;
            }

            return rtrim(mb_substr($message, 0, $length, $encoding)).$separator;
        }

        return $message;
    }

    /**
     * Resolves all references associated to this commit.
     *
     * @return Reference[] An array of references (Branch, Tag, Squash)
     */
    public function resolveReferences()
    {
        return $this->repository->getReferences()->resolve($this);
    }

    /**
     * Find branch containing the commit.
     *
     * Passing arguments, to keep local or remote branches only, is deprecated.
     *
     * @param bool $local  set true to try to locate a commit on local repository
     * @param bool $remote set true to try to locate a commit on remote repository
     *
     * @return Reference[]|Branch[] An array of Reference\Branch
     */
    public function getIncludingBranches($local = true, $remote = true)
    {
        if (func_num_args() > 0) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing arguments to "%s()" is deprecated, filter the branches it returns with "%s::isLocal()" or "isRemote()" instead.', __METHOD__, Branch::class);
        }

        $arguments = ['--contains', $this->revision];

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
            return [];
        }

        if (!$result) {
            return [];
        }

        $branchesName = explode("\n", trim(str_replace('*', '', $result)));
        $branchesName = array_filter($branchesName, function ($v) {
            return false === strpos($v, '->');
        });
        $branchesName = array_map('trim', $branchesName);

        $references = $this->repository->getReferences();

        $branches = [];
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
        return $this->getData('authorName');
    }

    /**
     * Returns the author email.
     *
     * @return string An email
     */
    public function getAuthorEmail()
    {
        return $this->getData('authorEmail');
    }

    /**
     * Returns the authoring date.
     *
     * @return \DateTime A time object
     */
    public function getAuthorDate()
    {
        return $this->getData('authorDate');
    }

    /**
     * Returns the committer name.
     *
     * @return string A name
     */
    public function getCommitterName()
    {
        return $this->getData('committerName');
    }

    /**
     * Returns the comitter email.
     *
     * @return string An email
     */
    public function getCommitterEmail()
    {
        return $this->getData('committerEmail');
    }

    /**
     * Returns the authoring date.
     *
     * @return \DateTime A time object
     */
    public function getCommitterDate()
    {
        return $this->getData('committerDate');
    }

    /**
     * Returns the message of the commit.
     *
     * @return string A commit message
     */
    public function getMessage()
    {
        return $this->getData('message');
    }

    /**
     * Returns the subject message (the first line).
     *
     * @return string The subject message
     */
    public function getSubjectMessage()
    {
        return $this->getData('subjectMessage');
    }

    /**
     * Return the body message.
     *
     * @return string The body message
     */
    public function getBodyMessage()
    {
        return $this->getData('bodyMessage');
    }

    /**
     * {@inheritdoc}
     */
    public function getCommit()
    {
        return $this;
    }

    private function getData($name)
    {
        if (isset($this->data[$name])) {
            return $this->data[$name];
        }

        if ($name === 'shortHash') {
            $this->data['shortHash'] = trim($this->repository->run('log', ['--abbrev-commit', '--format=%h', '-n', 1, $this->revision]));

            return $this->data['shortHash'];
        }

        if ($name === 'tree') {
            $this->data['tree'] = $this->repository->getTree($this->getData('treeHash'));

            return $this->data['tree'];
        }

        if ($name === 'subjectMessage') {
            $lines = explode("\n", $this->getData('message'));
            $this->data['subjectMessage'] = reset($lines);

            return $this->data['subjectMessage'];
        }

        if ($name === 'bodyMessage') {
            $message = $this->getData('message');

            $lines = explode("\n", $message);

            array_shift($lines);
            array_shift($lines);

            $this->data['bodyMessage'] = implode("\n", $lines);

            return $this->data['bodyMessage'];
        }

        $parser = new Parser\CommitParser();

        try {
            $result = $this->repository->run('cat-file', ['commit', $this->revision]);
        } catch (ProcessException $e) {
            throw new ReferenceNotFoundException(sprintf('Can not find reference "%s"', $this->revision));
        }

        $parser->parse($result);

        $this->data['treeHash'] = $parser->tree;
        $this->data['parentHashes'] = $parser->parents;
        $this->data['authorName'] = $parser->authorName;
        $this->data['authorEmail'] = $parser->authorEmail;
        $this->data['authorDate'] = $parser->authorDate;
        $this->data['committerName'] = $parser->committerName;
        $this->data['committerEmail'] = $parser->committerEmail;
        $this->data['committerDate'] = $parser->committerDate;
        $this->data['message'] = $parser->message;

        if (!isset($this->data[$name])) {
            throw new \InvalidArgumentException(sprintf('No data named "%s" in Commit.', $name));
        }

        return $this->data[$name];
    }
}
