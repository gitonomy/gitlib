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

namespace Gitonomy\Git\Tests;

use Gitonomy\Git\Util\WeakCache;
use PHPUnit\Framework\TestCase;

class WeakCacheTest extends TestCase
{
    public function testKeepsAnObjectWhileItIsUsed(): void
    {
        /** @var WeakCache<\stdClass> $cache */
        $cache = new WeakCache();
        $object = new \stdClass();
        $cache->set('used', $object);
        $cache->set('freed', new \stdClass());

        $this->assertSame($object, $cache->get('used'));
        $this->assertNull($cache->get('freed'));
        $this->assertNull($cache->get('unknown'));
    }

    public function testForgetsTheKeysOfTheFreedObjects(): void
    {
        /** @var WeakCache<\stdClass> $cache */
        $cache = new WeakCache();
        $object = new \stdClass();
        $cache->set('used', $object);
        for ($i = 0; $i < 10_000; ++$i) {
            $cache->set('freed'.$i, new \stdClass());
        }

        $references = new \ReflectionProperty(WeakCache::class, 'references')->getValue($cache);
        $this->assertIsArray($references);
        $this->assertLessThan(64, \count($references), 'Only the keys of the objects used, and the last ones');
        $this->assertSame($object, $cache->get('used'));
    }
}
