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
 */
final class Commit extends Revision
{
    /**
     * Associative array of commit data.
     *
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param Repository $repository Repository of the commit
     * @param string     $hash       Hash of the commit
     */
    public function __construct(Repository $repository, string $hash, array $data = [])
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
            throw new ReferenceNotFoundException($hash);
        }

        parent::__construct($repository, $hash);

        $this->setData($data);
    }

    public function setData(array $data): void
    {
        foreach ($data as $name => $value) {
            $this->data[$name] = $value;
        }
    }

    public function getDiff(): Diff
    {
        $args = ['-r', '-p', '--raw', '-m', '-M', '--no-commit-id', '--full-index', $this->revision];

        $diff = Diff::parse($this->repository->run('diff-tree', $args));
        $diff->setRepository($this->repository);

        return $diff;
    }

    /**
     * Returns the commit hash.
     */
    public function getHash(): string
    {
        return $this->revision;
    }

    /**
     * Returns the short commit hash.
     */
    public function getShortHash(): string
    {
        return $this->getData('shortHash');
    }

    /**
     * Returns a fixed-with short hash.
     */
    public function getFixedShortHash(int $length = 6): string
    {
        return StringHelper::substr($this->revision, 0, $length);
    }

    /**
     * Returns parent hashes.
     *
     * @return string[] An array of SHA1 hashes
     */
    public function getParentHashes(): array
    {
        return $this->getData('parentHashes');
    }

    /**
     * Returns the parent commits.
     *
     * @return Commit[] An array of Commit objects
     */
    public function getParents(): array
    {
        $result = [];
        foreach ($this->getData('parentHashes') as $parentHash) {
            $result[] = $this->repository->getCommit($parentHash);
        }

        return $result;
    }

    /**
     * Returns the tree hash.
     */
    public function getTreeHash(): string
    {
        return $this->getData('treeHash');
    }

    public function getTree(): Tree
    {
        return $this->getData('tree');
    }

    public function getLastModification(?string $path = null): Commit
    {
        if (null !== $path && 0 === strpos($path, '/')) {
            $path = StringHelper::substr($path, 1);
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
     */
    public function getShortMessage(int $length = 50, bool $preserve = false, string $separator = '...'): string
    {
        $message = $this->getData('subjectMessage');

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
     * @return Reference[] An array of references (Branch, Tag, Squash)
     */
    public function resolveReferences(): array
    {
        return $this->repository->getReferences()->resolve($this);
    }

    /**
     * Find branch containing the commit.
     *
     * @param bool $local  set true to try to locate a commit on local repository
     * @param bool $remote set true to try to locate a commit on remote repository
     *
     * @return Reference[]|Branch[] An array of Reference\Branch
     */
    public function getIncludingBranches(bool $local = true, bool $remote = true): array
    {
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
            return false === StringHelper::strpos($v, '->');
        });
        $branchesName = array_map('trim', $branchesName);

        $references = $this->repository->getReferences();

        $branches = [];
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
     */
    public function getAuthorName(): string
    {
        return $this->getData('authorName');
    }

    /**
     * Returns the author email.
     */
    public function getAuthorEmail(): string
    {
        return $this->getData('authorEmail');
    }

    /**
     * Returns the authoring date.
     */
    public function getAuthorDate(): \DateTime
    {
        return $this->getData('authorDate');
    }

    /**
     * Returns the committer name.
     */
    public function getCommitterName(): string
    {
        return $this->getData('committerName');
    }

    /**
     * Returns the comitter email.
     */
    public function getCommitterEmail(): string
    {
        return $this->getData('committerEmail');
    }

    /**
     * Returns the authoring date.
     */
    public function getCommitterDate(): \DateTime
    {
        return $this->getData('committerDate');
    }

    /**
     * Returns the message of the commit.
     */
    public function getMessage(): string
    {
        return $this->getData('message');
    }

    /**
     * Returns the subject message (the first line).
     */
    public function getSubjectMessage(): string
    {
        return $this->getData('subjectMessage');
    }

    /**
     * Return the body message.
     */
    public function getBodyMessage(): string
    {
        return $this->getData('bodyMessage');
    }

    public function getCommit(): Commit
    {
        return $this;
    }

    private function getData(string $name): mixed
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
