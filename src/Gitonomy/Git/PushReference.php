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
use Gitonomy\Git\Util\LegacyOverride;

/**
 * Push reference contains a commit interval. This object aggregates methods
 * for this interval.
 *
 * @author Julien DIDIER <genzo.wm@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class PushReference
{
    const ZERO = '0000000000000000000000000000000000000000';

    /**
     * @var Repository
     */
    protected $repository;
    /**
     * @var string
     */
    protected $reference;

    /**
     * @var string
     */
    protected $before;

    /**
     * @var string
     */
    protected $after;

    /**
     * @var bool
     */
    protected $isForce;

    public function __construct(Repository $repository, $reference, $before, $after)
    {
        $this->repository = $repository;
        $this->reference = $reference;
        $this->before = $before;
        $this->after = $after;
        $this->isForce = $this->getForce();
    }

    /**
     * @return Repository
     */
    public function getRepository()
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
     * @return string
     *
     * @deprecated since gitonomy/gitlib 1.7, use getFullname() instead
     */
    public function getReference()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getFullname()" instead.', __METHOD__);

        return $this->reference;
    }

    /**
     * @return string
     */
    public function getBefore()
    {
        return $this->before;
    }

    /**
     * @return string
     */
    public function getAfter()
    {
        return $this->after;
    }

    /**
     * @return Log
     */
    public function getLog($excludes = [])
    {
        return $this->repository->getLog(array_merge(
            // Like in 1.6, a subclass overriding getRevision() gives the range.
            [LegacyOverride::isOverridden($this, self::class, 'getRevision') ? $this->getRevision() : $this->getRange()],
            array_map(function ($e) {
                return '^'.$e;
            }, $excludes)
        ));
    }

    /**
     * @return string The pushed commits: "before..after", or "after" for a creation
     *
     * @throws LogicException The push deletes the reference
     */
    public function getRange(): string
    {
        if ($this->deletes()) {
            throw new LogicException('No revision for deletion');
        }

        if ($this->creates()) {
            return $this->getAfter();
        }

        return $this->getBefore().'..'.$this->getAfter();
    }

    /**
     * @return string
     *
     * @deprecated since gitonomy/gitlib 1.7, use getRange() instead
     */
    public function getRevision()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getRange()" instead.', __METHOD__);

        return $this->getRange();
    }

    /**
     * @return bool Whether the push creates the reference, like File::isCreation() for a file
     */
    public function isCreation(): bool
    {
        return $this->isZero($this->before);
    }

    /**
     * @return bool
     *
     * @deprecated since gitonomy/gitlib 1.7, use isCreation() instead
     */
    public function isCreate()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "isCreation()" instead.', __METHOD__);

        return $this->isCreation();
    }

    /**
     * @return bool Whether the push deletes the reference, like File::isDeletion() for a file
     */
    public function isDeletion(): bool
    {
        return $this->isZero($this->after);
    }

    /**
     * @return bool
     *
     * @deprecated since gitonomy/gitlib 1.7, use isDeletion() instead
     */
    public function isDelete()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "isDeletion()" instead.', __METHOD__);

        return $this->isDeletion();
    }

    /**
     * @return bool
     */
    public function isForce()
    {
        return $this->isForce;
    }

    /**
     * @return bool
     */
    public function isFastForward()
    {
        return !$this->deletes() && !$this->creates() && !$this->isForce();
    }

    /**
     * @return bool
     */
    protected function isZero($reference)
    {
        return self::ZERO === $reference;
    }

    /**
     * @return bool
     */
    protected function getForce()
    {
        if ($this->deletes() || $this->creates()) {
            return false;
        }

        $result = $this->repository->run('merge-base', [
            $this->before,
            $this->after,
        ]);

        return $this->before !== trim($result);
    }

    /**
     * Like in 1.6, a subclass overriding isCreate() tells whether the push creates the reference.
     */
    private function creates(): bool
    {
        return LegacyOverride::isOverridden($this, self::class, 'isCreate') ? $this->isCreate() : $this->isCreation();
    }

    /**
     * Like in 1.6, a subclass overriding isDelete() tells whether the push deletes the reference.
     */
    private function deletes(): bool
    {
        return LegacyOverride::isOverridden($this, self::class, 'isDelete') ? $this->isDelete() : $this->isDeletion();
    }
}
