<?php

/*
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final readonly class RevisionList implements \IteratorAggregate, \Countable
{
    /**
     * @var Revision[]
     */
    private array $revisions;

    /**
     * Constructs a revision list from a variety of types.
     *
     * @param string|Revision|array $revisions can be a string, a Revision, an array of strings or an array of Revision, Branch, Tag, Commit
     */
    public function __construct(Repository $repository, string|Revision|array $revisions)
    {
        if (\is_string($revisions)) {
            $revisions = [$repository->getRevision($revisions)];
        } elseif ($revisions instanceof Revision) {
            $revisions = [$revisions];
        }

        if (0 == \count($revisions)) {
            throw new \InvalidArgumentException('Empty revision list not allowed');
        }

        $normalizedRevisions = [];
        foreach ($revisions as $revision) {
            if (\is_string($revision)) {
                $revision = new Revision($repository, $revision);
            } elseif (!$revision instanceof Revision) {
                throw new \InvalidArgumentException(\sprintf('Expected a "Revision", got a "%s".', \is_object($revision) ? $revision::class : \gettype($revision)));
            }

            $normalizedRevisions[] = $revision;
        }

        $this->revisions = $normalizedRevisions;
    }

    /**
     * @return Revision[]
     */
    public function getAll(): array
    {
        return $this->revisions;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->revisions);
    }

    public function count(): int
    {
        return \count($this->revisions);
    }

    /**
     * @return string[]
     */
    public function getAsTextArray(): array
    {
        return array_map(static function (Revision $revision) {
            return $revision->getRevision();
        }, $this->revisions);
    }
}
