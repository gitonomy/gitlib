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

use Gitonomy\Git\Exception\LogicException;

/**
 * Push reference contains a commit interval. This object aggregates methods
 * for this interval.
 *
 * @author Julien DIDIER <genzo.wm@gmail.com>
 */
final class PushReference
{
    public const string ZERO = '0000000000000000000000000000000000000000';

    private ?bool $isForce = null;

    /**
     * @param string $reference The full name of the pushed reference
     * @param string $before    Its commit before the push, ZERO for a creation
     * @param string $after     Its commit after the push, ZERO for a deletion
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly string $reference,
        private readonly string $before,
        private readonly string $after,
    ) {
    }

    public function getRepository(): Repository
    {
        return $this->repository;
    }

    /**
     * @return string The full name of the pushed reference
     */
    public function getFullname(): string
    {
        return $this->reference;
    }

    /**
     * @deprecated since gitonomy/gitlib 2.0, use getFullname() instead
     */
    public function getReference(): string
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, use "getFullname()" instead.', __METHOD__);

        return $this->reference;
    }

    public function getBefore(): string
    {
        return $this->before;
    }

    public function getAfter(): string
    {
        return $this->after;
    }

    /**
     * @param string[] $excludes
     */
    public function getLog(array $excludes = []): Log
    {
        return $this->repository->getLog([$this->getRange(), ...array_map(static fn (string $exclude): string => '^'.$exclude, $excludes)]);
    }

    /**
     * @deprecated since gitonomy/gitlib 2.0, use getRange() instead
     */
    public function getRevision(): string
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, use "getRange()" instead.', __METHOD__);

        return $this->getRange();
    }

    /**
     * @return string The pushed commits: "before..after", or "after" for a creation
     *
     * @throws LogicException The push deletes the reference
     */
    public function getRange(): string
    {
        if ($this->isDelete()) {
            throw new LogicException('A deletion pushes no commit.');
        }

        if ($this->isCreate()) {
            return $this->getAfter();
        }

        return $this->getBefore().'..'.$this->getAfter();
    }

    public function isCreate(): bool
    {
        return $this->isZero($this->before);
    }

    public function isDelete(): bool
    {
        return $this->isZero($this->after);
    }

    public function isForce(): bool
    {
        return $this->isForce ??= $this->getForce();
    }

    public function isFastForward(): bool
    {
        return !$this->isDelete() && !$this->isCreate() && !$this->isForce();
    }

    private function isZero(string $reference): bool
    {
        return self::ZERO === $reference;
    }

    private function getForce(): bool
    {
        if ($this->isDelete() || $this->isCreate()) {
            return false;
        }

        // Without a common history either, it is a force update.
        return !\in_array($this->before, $this->repository->getRepositoryDriver()->getMergeBases($this->before, $this->after), true);
    }
}
