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

namespace Gitonomy\Git\Driver\Ffi;

use FFI\CData;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\UnsupportedOperationException;

/**
 * Loads libgit2, and helps calling it.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class LibGit2
{
    /**
     * The only libgit2 version libgit2.h is written for: libgit2 changes its
     * struct layouts on every minor release, so loading any other version
     * would silently corrupt memory.
     */
    private const array SUPPORTED_VERSION = [1, 9];

    /**
     * The names to look libgit2 up with, when no path is given.
     */
    private const array LIBRARY_NAMES = [
        'libgit2.so.1.9',
        'libgit2.1.9.dylib',
        'libgit2.so',
        'libgit2.dylib',
        'git2.dll',
    ];

    /**
     * Loads libgit2 once per request (the whole process with the CLI) and per
     * library path, or takes the one preload() loaded.
     *
     * @throws UnsupportedOperationException The FFI extension or libgit2 1.9 is missing, or "ffi.enable" restricts FFI
     */
    public static function load(?string $libraryPath): \FFI
    {
        static $ffiByLibraryPath = [];

        return $ffiByLibraryPath[$libraryPath ?? ''] ??= self::doLoad($libraryPath);
    }

    /**
     * Loads and initializes libgit2 for the whole process, from the
     * opcache.preload script: PHP unloads a library at the end of every
     * request otherwise, and libgit2 initializes itself again (about 10 ms).
     *
     * @throws UnsupportedOperationException The FFI extension or libgit2 1.9 is missing
     */
    public static function preload(?string $libraryPath): void
    {
        self::assertFfiIsAvailable();
        $path = $libraryPath ?? self::locate();
        self::assertSupportedVersion($path);

        // FFI::load() reads the library to load from the header.
        $header = tempnam(sys_get_temp_dir(), 'gitlib-libgit2-');

        try {
            file_put_contents($header, \sprintf("#define FFI_SCOPE \"%s\"\n#define FFI_LIB \"%s\"\n", self::scope($libraryPath), $path).file_get_contents(__DIR__.'/libgit2.h'));
            $ffi = \FFI::load($header);
        } catch (\FFI\Exception $e) {
            throw new UnsupportedOperationException(\sprintf('Unable to preload libgit2 from "%s".', $path), 0, $e);
        } finally {
            unlink($header);
        }

        // FFI::load() only warns while preloading.
        if (null === $ffi) {
            throw new UnsupportedOperationException(\sprintf('Unable to preload libgit2 from "%s".', $path));
        }

        // Once for the forked processes (php-fpm workers...) too.
        self::initialize($ffi);
    }

    /**
     * @throws DriverException When $rc is a libgit2 error code
     */
    public static function check(\FFI $ffi, int $rc, string $context): void
    {
        if ($rc < 0) {
            throw new DriverException(\sprintf('%s: %s', $context, self::lastErrorMessage($ffi)));
        }
    }

    /**
     * A PHP string can't be stored in a `char *` field: copy it into a C
     * buffer, to keep alive as long as the field is in use.
     */
    public static function cString(\FFI $ffi, string $value): CData
    {
        $buffer = $ffi->new('char['.(\strlen($value) + 1).']');
        \FFI::memcpy($buffer, $value, \strlen($value));

        return $buffer;
    }

    public static function oidToHex(\FFI $ffi, CData $oid): string
    {
        $buffer = $ffi->new('char[41]');
        $ffi->git_oid_tostr($buffer, 41, $oid);

        return \FFI::string($buffer);
    }

    private static function doLoad(?string $libraryPath): \FFI
    {
        self::assertFfiIsAvailable();

        $ffi = self::preloaded($libraryPath) ?? self::define($libraryPath ?? self::locate());
        // Only counts one more user once libgit2 is initialized.
        self::initialize($ffi);

        return $ffi;
    }

    /**
     * @return \FFI|null What preload() loaded for $libraryPath, if it was called
     */
    private static function preloaded(?string $libraryPath): ?\FFI
    {
        try {
            return \FFI::scope(self::scope($libraryPath));
        } catch (\FFI\Exception) {
            return null;
        }
    }

    private static function define(string $libraryPath): \FFI
    {
        self::assertSupportedVersion($libraryPath);

        try {
            return \FFI::cdef(file_get_contents(__DIR__.'/libgit2.h'), $libraryPath);
        } catch (\FFI\Exception $e) {
            throw new UnsupportedOperationException(\sprintf('Unable to load libgit2 from "%s"; use CliDriver.', $libraryPath), 0, $e);
        }
    }

    private static function scope(?string $libraryPath): string
    {
        return 'gitonomy/gitlib:'.($libraryPath ?? '');
    }

    private static function assertFfiIsAvailable(): void
    {
        if (!\extension_loaded('ffi')) {
            throw new UnsupportedOperationException('LibGit2Driver requires the FFI extension; use CliDriver.');
        }

        try {
            \FFI::cdef();
        } catch (\FFI\Exception $e) {
            // ffi.enable defaults to "preload": outside the CLI, only preloaded code can use FFI.
            throw new UnsupportedOperationException('The "ffi.enable" setting restricts the FFI API: call LibGit2Driver::preload() from the opcache.preload script, set "ffi.enable" to "true", or use CliDriver.', 0, $e);
        }
    }

    private static function assertSupportedVersion(string $libraryPath): void
    {
        try {
            $ffi = \FFI::cdef('int git_libgit2_version(int *major, int *minor, int *rev);', $libraryPath);
        } catch (\FFI\Exception $e) {
            throw new UnsupportedOperationException(\sprintf('Unable to load libgit2 from "%s"; use CliDriver.', $libraryPath), 0, $e);
        }

        $major = $ffi->new('int');
        $minor = $ffi->new('int');
        $rev = $ffi->new('int');
        $ffi->git_libgit2_version(\FFI::addr($major), \FFI::addr($minor), \FFI::addr($rev));
        if (self::SUPPORTED_VERSION !== [$major->cdata, $minor->cdata]) {
            throw new UnsupportedOperationException(\sprintf('LibGit2Driver requires libgit2 %s, but "%s" is libgit2 %d.%d.%d; use CliDriver.', implode('.', self::SUPPORTED_VERSION), $libraryPath, $major->cdata, $minor->cdata, $rev->cdata));
        }
    }

    private static function initialize(\FFI $ffi): void
    {
        if ($ffi->git_libgit2_init() < 0) {
            throw new UnsupportedOperationException(\sprintf('Unable to initialize libgit2: %s', self::lastErrorMessage($ffi)));
        }
    }

    private static function locate(): string
    {
        foreach (self::LIBRARY_NAMES as $name) {
            try {
                \FFI::cdef('', $name);

                return $name;
            } catch (\FFI\Exception) {
                continue;
            }
        }

        throw new UnsupportedOperationException('LibGit2Driver requires libgit2 1.9, but none could be found: pass its path to LibGit2Driver, or use CliDriver.');
    }

    private static function lastErrorMessage(\FFI $ffi): string
    {
        $error = $ffi->git_error_last();
        if (\FFI::isNull($error)) {
            return 'unknown error.';
        }

        return rtrim(\FFI::string($error->message), '.').'.';
    }
}
