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
use Gitonomy\Git\Util\LegacyOverride;
use Gitonomy\Git\Util\StringHelper;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class Log implements \Countable, \IteratorAggregate
{
    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @var null|RevisionList
     */
    protected $revisions;

    /**
     * @var array
     */
    protected $paths;

    /**
     * @var int
     */
    protected $offset;

    /**
     * @var int
     */
    protected $limit;

    /**
     * Instanciates a git log object.
     *
     * @param Repository                       $repository the repository where log occurs
     * @param RevisionList|Revision|array|null $revisions  a list of revisions or null if you want all history
     * @param array                            $paths      paths to filter on
     * @param int|null                         $offset     start list from a given position
     * @param int|null                         $limit      limit number of fetched elements
     */
    public function __construct(Repository $repository, $revisions = null, $paths = null, $offset = null, $limit = null)
    {
        if (null !== $revisions && !$revisions instanceof RevisionList) {
            $revisions = new RevisionList($repository, $revisions);
        }

        foreach (null === $revisions ? [] : $revisions->getAll() as $revision) {
            if ($revision instanceof Commit || $revision instanceof Reference) {
                continue;
            }

            $name = $revision->getRevision();
            if (str_starts_with($name, '-') && '--all' !== $name) {
                trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing "%s", an option of "git log", as revision to "%s()" is deprecated, it throws in 2.0: pass revisions, ranges, names to exclude or "--all".', $name, __METHOD__);
            }
        }

        if (null === $paths) {
            $paths = [];
        } elseif (is_string($paths)) {
            $paths = [$paths];
        } elseif (!is_array($paths)) {
            throw new \InvalidArgumentException(sprintf('Expected a string or an array, got a "%s".', is_object($paths) ? get_class($paths) : gettype($paths)));
        }

        if (null !== $offset && $offset < 0) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing a negative offset to "%s()" is deprecated, it throws in 2.0.', __METHOD__);
        }

        if (null !== $limit && $limit < 0) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing a negative limit to "%s()" is deprecated, it throws in 2.0: pass null for no limit.', __METHOD__);
        }

        $this->repository = $repository;
        $this->revisions = $revisions;
        $this->paths = $paths;
        $this->offset = $offset;
        $this->limit = $limit;
    }

    /**
     * @return Diff
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::getDiff() instead: it ignored the paths of the log
     */
    public function getDiff()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::getDiff()" instead.', __METHOD__, Repository::class);

        return $this->repository->getLegacyDiff($this->revisions);
    }

    /**
     * @return RevisionList
     */
    public function getRevisions()
    {
        return $this->revisions;
    }

    /**
     * @return array
     */
    public function getPaths()
    {
        return $this->paths;
    }

    /**
     * @return int
     */
    public function getOffset()
    {
        return $this->offset;
    }

    /**
     * @return self The log, starting from the commit $offset
     *
     * @throws InvalidArgumentException $offset is negative
     */
    public function withOffset(?int $offset): self
    {
        if (null !== $offset && $offset < 0) {
            throw new InvalidArgumentException(sprintf('The offset of a log can\'t be negative, %d given.', $offset));
        }

        $log = clone $this;
        $log->offset = $offset;

        return $log;
    }

    /**
     * @param int $offset
     *
     * @deprecated since gitonomy/gitlib 1.7, use withOffset() instead
     */
    public function setOffset($offset)
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "withOffset()" instead.', __METHOD__);

        $this->offset = $offset;

        return $this;
    }

    /**
     * @return int
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @return self The log, of $limit commits at most
     *
     * @throws InvalidArgumentException $limit is negative
     */
    public function withLimit(?int $limit): self
    {
        if (null !== $limit && $limit < 0) {
            throw new InvalidArgumentException(sprintf('The limit of a log can\'t be negative, %d given.', $limit));
        }

        $log = clone $this;
        $log->limit = $limit;

        return $log;
    }

    /**
     * @param int $limit
     *
     * @deprecated since gitonomy/gitlib 1.7, use withLimit() instead
     */
    public function setLimit($limit)
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "withLimit()" instead.', __METHOD__);

        $this->limit = $limit;

        return $this;
    }

    /**
     * @return Commit
     */
    public function getSingleCommit()
    {
        $limit = $this->limit;
        $this->limit = 1;
        $commits = $this->getCommits();
        // Like in 1.6, a subclass overriding setLimit() sets the limit back.
        if (LegacyOverride::isOverridden($this, self::class, 'setLimit')) {
            $this->setLimit($limit);
        } else {
            $this->limit = $limit;
        }

        if (count($commits) === 0) {
            throw new ReferenceNotFoundException('The log is empty');
        }

        return array_pop($commits);
    }

    /**
     * @return Commit[]
     */
    public function getCommits()
    {
        // The encoding StringHelper::setEncoding() set, without loading this deprecated class.
        $encoding = class_exists(StringHelper::class, false) ? StringHelper::getEncoding() : 'utf-8';
        $args = ['--encoding='.$encoding, '--format=raw'];

        if (null !== $this->offset) {
            $args[] = '--skip='.((int) $this->offset);
        }

        if (null !== $this->limit) {
            $args[] = '-n';
            $args[] = (int) $this->limit;
        }

        if (null !== $this->revisions) {
            $args = array_merge($args, $this->revisions->getAsTextArray());
        } else {
            $args[] = '--all';
        }

        $args[] = '--';

        $args = array_merge($args, $this->paths);

        try {
            $output = $this->repository->run('log', $args);
        } catch (ProcessException $e) {
            throw new ReferenceNotFoundException(sprintf('Can not find revision "%s"', implode(' ', $this->revisions->getAsTextArray())), null, $e);
        }

        $parser = new Parser\LogParser();
        $parser->parse($output);

        $result = [];
        foreach ($parser->log as $commitData) {
            $hash = $commitData['id'];
            unset($commitData['id']);

            $commit = $this->repository->getCommit($hash);
            $commit->setData($commitData);

            $result[] = $commit;
        }

        return $result;
    }

    /**
     * Counting a log whose offset or limit leaves commits out is deprecated:
     * this count includes them, where 2.0 counts the commits the log iterates.
     *
     * @see Countable
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        $count = $this->countCommits();

        $iterated = max(0, $count - (int) $this->offset);
        if (null !== $this->limit) {
            $iterated = min($iterated, (int) $this->limit);
        }

        if ($iterated !== $count) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Counting a log whose offset or limit leaves commits out with "%s()" is deprecated, it counts only the commits the log iterates in 2.0: use "countCommits()" to count them all.', __METHOD__);
        }

        return $count;
    }

    /**
     * @see IteratorAggregate
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new \ArrayIterator($this->getCommits());
    }

    /**
     * Count commits, without offset or limit.
     *
     * @return int
     */
    public function countCommits()
    {
        if (null !== $this->revisions && count($this->revisions)) {
            $output = $this->repository->run('rev-list', array_merge(['--count'], $this->revisions->getAsTextArray(), ['--'], $this->paths));
        } else {
            $output = $this->repository->run('rev-list', array_merge(['--count', '--all', '--'], $this->paths));
        }

        return (int) $output;
    }
}
