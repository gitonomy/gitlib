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

use Gitonomy\Git\Exception\LogicException;

/**
 * Push reference contains a commit interval. This object aggregates methods
 * for this interval.
 *
 * @author Julien DIDIER <genzo.wm@gmail.com>
 */
final readonly class PushReference
{
    const string ZERO = '0000000000000000000000000000000000000000';

    private readonly bool $isForce;

    public function __construct(
        protected readonly Repository $repository,
        protected readonly string $reference,
        protected readonly string $before,
        protected readonly string $after,
    ) {
        $this->isForce = $this->getForce();
    }

    public function getRepository(): Repository
    {
        return $this->repository;
    }

    public function getReference(): string
    {
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
        return $this->repository->getLog(array_merge(
            [$this->getRevision()],
            array_map(function ($e) {
                return '^'.$e;
            }, $excludes)
        ));
    }

    public function getRevision(): string
    {
        if ($this->isDelete()) {
            throw new LogicException('No revision for deletion');
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
        return $this->isForce;
    }

    public function isFastForward(): bool
    {
        return !$this->isDelete() && !$this->isCreate() && !$this->isForce();
    }

    protected function isZero(string $reference): bool
    {
        return self::ZERO === $reference;
    }

    protected function getForce(): bool
    {
        if ($this->isDelete() || $this->isCreate()) {
            return false;
        }

        $result = $this->repository->run('merge-base', [
            $this->before,
            $this->after,
        ]);

        return $this->before !== trim($result);
    }
}
