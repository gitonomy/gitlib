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

use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\Process\Process;

/**
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
class LibGit2DriverTest extends AbstractTestCase
{
    private const string AUTOLOAD = __DIR__.'/../../../../vendor/autoload.php';

    #[RequiresPhpExtension('ffi')]
    public function testRestrictedFfiApiIsReported(): void
    {
        // The setting only applies at startup: it needs a new PHP process.
        $code = <<<'PHP'
            require $argv[1];
            try {
                new Gitonomy\Git\Driver\Ffi\LibGit2Driver();
            } catch (Gitonomy\Git\Exception\UnsupportedOperationException $e) {
                echo $e->getMessage();
            }
            PHP;

        $process = new Process([\PHP_BINARY, '-d', 'ffi.enable=0', '-r', $code, self::AUTOLOAD]);
        $process->mustRun();

        $this->assertSame('The "ffi.enable" setting restricts the FFI API: call LibGit2Driver::preload() from the opcache.preload script, set "ffi.enable" to "true", or use CliDriver.', $process->getOutput());
    }

    #[RequiresPhpExtension('ffi')]
    #[RequiresPhpExtension('Zend OPcache')]
    public function testPreloadKeepsLibgit2LoadedForTheProcess(): void
    {
        if (!LibGit2Driver::isSupported()) {
            $this->markTestSkipped('libgit2 1.9 is not installed.');
        }

        $preload = self::createTempDir().'/preload.php';
        file_put_contents($preload, \sprintf('<?php require %s; %s::preload();', var_export(self::AUTOLOAD, true), LibGit2Driver::class));

        // FFI::scope() throws unless preload() registered the scope of libgit2.
        $code = <<<'PHP'
            require $argv[1];
            FFI::scope('gitonomy/gitlib:');
            echo new Gitonomy\Git\Repository($argv[2], driver: new Gitonomy\Git\Driver\Ffi\LibGit2Driver())->getHeadCommit()->getHash();
            PHP;

        $repository = self::createFoobarRepository();
        $process = new Process([\PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.preload='.$preload, '-r', $code, self::AUTOLOAD, $repository->getGitDir()]);
        $process->mustRun();

        $this->assertSame($repository->getHeadCommit()->getHash(), $process->getOutput());
    }
}
