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

namespace Gitonomy\Git\Util;

/**
 * Objects by key, as long as the application uses them.
 *
 * The keys of the objects freed since are removed once the cache has grown
 * twice as big: a long-running process reading millions of commits keeps
 * only the ones it uses.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @template T of object
 *
 * @internal
 */
final class WeakCache
{
    private const int MIN_SWEEP_SIZE = 64;

    /**
     * @var array<string, \WeakReference<T>>
     */
    private array $references = [];

    private int $sweepSize = self::MIN_SWEEP_SIZE;

    /**
     * @return T|null
     */
    public function get(string $key): ?object
    {
        return ($this->references[$key] ?? null)?->get();
    }

    /**
     * @param T $object
     */
    public function set(string $key, object $object): void
    {
        $this->references[$key] = \WeakReference::create($object);

        if (\count($this->references) < $this->sweepSize) {
            return;
        }

        $this->references = array_filter($this->references, static fn (\WeakReference $reference): bool => null !== $reference->get());
        // Each sweep at least halves the cache, or waits for it to double: constant time on average.
        $this->sweepSize = max(self::MIN_SWEEP_SIZE, 2 * \count($this->references));
    }
}
