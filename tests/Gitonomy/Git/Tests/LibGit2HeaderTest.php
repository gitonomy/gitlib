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

use PHPUnit\Framework\TestCase;

/**
 * PHPStan can't analyse FFI calls: this catches a libgit2 function called
 * without being declared in libgit2.h (which only fails at runtime), and
 * declarations left unused (each one must exist in the loaded libgit2).
 */
class LibGit2HeaderTest extends TestCase
{
    private const string FFI_DIR = __DIR__.'/../../../../src/Gitonomy/Git/Driver/Ffi';

    public function testCalledFunctionsAreTheDeclaredOnes(): void
    {
        preg_match_all('/->(git_\w+)\(/', implode('', array_map(file_get_contents(...), glob(self::FFI_DIR.'/*.php') ?: [])), $called);
        preg_match_all('/^[\w ]+?\**\b(git_\w+)\(/m', (string) file_get_contents(self::FFI_DIR.'/libgit2.h'), $declared);

        $called = array_values(array_unique($called[1]));
        $declared = array_values(array_unique($declared[1]));
        sort($called);
        sort($declared);

        $this->assertSame($declared, $called);
    }
}
