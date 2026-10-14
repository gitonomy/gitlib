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
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[RequiresPhpExtension('ffi')]
    public function testTheSystemConfigurationIsLeftOutWithGitConfigNoSystem(): void
    {
        if (!LibGit2Driver::isSupported()) {
            $this->markTestSkipped('libgit2 1.9 is not installed.');
        }

        // libgit2 is initialized once per process. 4 and 2 read the search
        // path (GIT_OPT_GET_SEARCH_PATH) of the system configuration
        // (GIT_CONFIG_LEVEL_SYSTEM), "/etc" on Linux.
        $code = <<<'PHP'
            require $argv[1];
            $ffi = Gitonomy\Git\Driver\Ffi\LibGit2::load(null);
            $path = $ffi->new('git_buf');
            $ffi->git_libgit2_opts(4, 2, FFI::addr($path));
            echo FFI::string($path->ptr, $path->size);
            $ffi->git_buf_dispose(FFI::addr($path));
            PHP;

        $process = new Process([\PHP_BINARY, '-r', $code, self::AUTOLOAD], env: ['GIT_CONFIG_NOSYSTEM' => '1']);
        $process->mustRun();
        $this->assertSame('', $process->getOutput());

        $process = new Process([\PHP_BINARY, '-r', $code, self::AUTOLOAD], env: ['GIT_CONFIG_NOSYSTEM' => false]);
        $process->mustRun();
        $this->assertNotSame('', $process->getOutput());
    }

    public function testReftableRepositoriesAreUnsupported(): void
    {
        if (!LibGit2Driver::isSupported()) {
            $this->markTestSkipped('libgit2 1.9 is not installed.');
        }
        if (version_compare(self::gitVersion(), '2.45.0', '<')) {
            $this->markTestSkipped('Reftable repositories require git 2.45.');
        }

        $dir = self::createTempDir();
        new Process([self::gitCommand(), 'init', '-q', '--ref-format=reftable', $dir])->mustRun();

        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('stores its references in the reftable format, which LibGit2Driver doesn\'t support; use CliDriver.');

        new Repository($dir, driver: new LibGit2Driver());
    }

    /**
     * @param \Closure(LibGit2Driver, string, string): void $clone
     */
    #[DataProvider('provideBundleClones')]
    public function testBundlesAreUnsupported(\Closure $clone, string $url): void
    {
        if (!LibGit2Driver::isSupported()) {
            $this->markTestSkipped('libgit2 1.9 is not installed.');
        }

        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage(\sprintf('"%s" is a bundle, which LibGit2Driver doesn\'t support; use CliDriver.', $url));

        $clone(new LibGit2Driver(), self::createTempDir().'/clone', $url);
    }

    /**
     * @return iterable<string, array{\Closure(LibGit2Driver, string, string): void, string}>
     */
    public static function provideBundleClones(): iterable
    {
        foreach (self::provideClones() as $name => [$clone]) {
            yield $name => [$clone, self::REPOSITORY_URL];
            yield $name.', file:// URL' => [$clone, 'file://'.self::REPOSITORY_URL];
        }
    }

    /**
     * is_file() hands a URL to the stream wrapper of its scheme: the one of
     * "ftp://" connects to the host, with the credentials of the URL.
     *
     * @param \Closure(LibGit2Driver, string, string): void $clone
     */
    #[DataProvider('provideClones')]
    public function testOnlyALocalPathIsLookedAtForABundle(\Closure $clone): void
    {
        if (!LibGit2Driver::isSupported()) {
            $this->markTestSkipped('libgit2 1.9 is not installed.');
        }

        $wrapper = new class {
            /**
             * @var list<string>
             */
            public static array $urls = [];

            public mixed $context;

            public function url_stat(string $path, int $flags): false
            {
                self::$urls[] = $path;

                return false;
            }
        };
        stream_wrapper_register('gitlib-test', $wrapper::class);

        try {
            $clone(new LibGit2Driver(), self::createTempDir().'/clone', 'gitlib-test://user:secret@example.com/repository.git');
            $this->fail('libgit2 knows no "gitlib-test" transport.');
        } catch (DriverException) {
        } finally {
            stream_wrapper_unregister('gitlib-test');
        }

        $this->assertSame([], $wrapper::$urls);
    }

    /**
     * @return iterable<string, array{\Closure(LibGit2Driver, string, string): void}>
     */
    public static function provideClones(): iterable
    {
        yield 'clone' => [static fn (LibGit2Driver $driver, string $path, string $url) => $driver->cloneFrom($url, $path, null)];
        yield 'bare clone' => [static fn (LibGit2Driver $driver, string $path, string $url) => $driver->cloneBareFrom($url, $path, null)];
        yield 'mirror' => [static fn (LibGit2Driver $driver, string $path, string $url) => $driver->mirrorFrom($url, $path)];
    }
}
