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
use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Psr\Log\LoggerInterface;

/**
 * Runs libgit2 1.9 in-process, through PHP's FFI extension.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @experimental
 */
final readonly class LibGit2Driver implements DriverInterface
{
    private const int GIT_ENOTFOUND = -3;
    private const int GIT_REMOTE_DOWNLOAD_TAGS_ALL = 3;

    private \FFI $ffi;

    /**
     * @param string|null $libraryPath The libgit2 shared library, looked up under its usual names when null
     *
     * @throws UnsupportedOperationException The FFI extension or libgit2 1.9 is missing, or "ffi.enable" restricts FFI
     */
    public function __construct(?string $libraryPath = null)
    {
        $this->ffi = LibGit2::load($libraryPath);
    }

    /**
     * To call from the opcache.preload script, with php-fpm for instance:
     * libgit2 then stays loaded and initialized for the whole process,
     * instead of every request loading it again (about 10 ms), and the driver
     * may call FFI whatever "ffi.enable" is.
     *
     * @param string|null $libraryPath The libgit2 shared library, as given to the constructor
     *
     * @throws UnsupportedOperationException The FFI extension or libgit2 1.9 is missing
     */
    public static function preload(?string $libraryPath = null): void
    {
        LibGit2::preload($libraryPath);

        // Classes declared while preloading may call FFI, even when
        // "ffi.enable" is "preload", its default.
        class_exists(LibGit2RepositoryDriver::class);
        class_exists(PathFilter::class);
    }

    public static function isSupported(): bool
    {
        try {
            LibGit2::load(null);

            return true;
        } catch (UnsupportedOperationException) {
            return false;
        }
    }

    /**
     * LibGit2Driver runs no command, so it logs nothing.
     */
    public function open(string $gitDir, ?string $workingDir, ?LoggerInterface $logger): LibGit2RepositoryDriver
    {
        return new LibGit2RepositoryDriver($this->ffi, $gitDir, $workingDir);
    }

    public function init(string $path): void
    {
        $this->ffi->git_repository_free($this->initRepository($path, false));
    }

    public function initBare(string $path): void
    {
        $this->ffi->git_repository_free($this->initRepository($path, true));
    }

    public function cloneFrom(string $url, string $path, ?string $branch): void
    {
        $options = $this->ffi->new('git_clone_options');
        LibGit2::check($this->ffi, $this->ffi->git_clone_options_init(\FFI::addr($options), 1), 'Unable to initialize clone options');

        // The buffer must stay alive until git_clone() returns.
        $branchBuffer = null;
        if (null !== $branch) {
            $branchBuffer = LibGit2::cString($this->ffi, $branch);
            $options->checkout_branch = $this->ffi->cast('const char *', $branchBuffer);
        }

        $repository = $this->ffi->new('git_repository*');
        LibGit2::check($this->ffi, $this->ffi->git_clone(\FFI::addr($repository), $url, $path, \FFI::addr($options)), \sprintf('Unable to clone "%s"', $url));
        $this->ffi->git_repository_free($repository);
    }

    public function cloneBareFrom(string $url, string $path, ?string $branch): void
    {
        // Like git, only the branches (and the tags).
        $this->fetchInto($path, $url, '+refs/heads/*:refs/heads/*', $branch, ['remote.origin.url' => $url]);
    }

    public function mirrorFrom(string $url, string $path): void
    {
        $this->fetchInto($path, $url, '+refs/*:refs/*', null, [
            'remote.origin.url' => $url,
            'remote.origin.fetch' => '+refs/*:refs/*',
            'remote.origin.mirror' => 'true',
        ]);
    }

    public function isValidRepository(string $url): bool
    {
        return null !== $this->listRemoteReferences($url);
    }

    public function isValidRepositoryAndBranch(string $url, string $branchName): bool
    {
        foreach ($this->listRemoteReferences($url) ?? [] as $fullname) {
            // Same matching as `git ls-remote --heads <url> <pattern>`:
            // only branches, matched on whole trailing path components.
            if (str_starts_with($fullname, 'refs/heads/') && ($fullname === 'refs/heads/'.$branchName || str_ends_with($fullname, '/'.$branchName))) {
                return true;
            }
        }

        return false;
    }

    private function initRepository(string $path, bool $bare): CData
    {
        $repository = $this->ffi->new('git_repository*');
        LibGit2::check($this->ffi, $this->ffi->git_repository_init(\FFI::addr($repository), $path, (int) $bare), \sprintf('Unable to initialize a repository in "%s"', $path));

        return $repository;
    }

    /**
     * Initializes a bare repository in $path, and fetches $refspec from $url
     * into it.
     *
     * @param array<string, string> $config
     */
    private function fetchInto(string $path, string $url, string $refspec, ?string $branch, array $config): void
    {
        $repository = $this->initRepository($path, true);

        try {
            $this->fetch($repository, $url, $refspec, $branch);
            $this->setConfig($repository, $config);
        } finally {
            $this->ffi->git_repository_free($repository);
        }
    }

    /**
     * Fetches $refspec from $url, and points HEAD to $branch, or to the
     * default branch of $url.
     */
    private function fetch(CData $repository, string $url, string $refspec, ?string $branch): void
    {
        $remote = $this->ffi->new('git_remote*');
        LibGit2::check($this->ffi, $this->ffi->git_remote_create_anonymous(\FFI::addr($remote), $repository, $url), \sprintf('Unable to create a remote for "%s"', $url));

        try {
            $options = $this->ffi->new('git_fetch_options');
            LibGit2::check($this->ffi, $this->ffi->git_fetch_options_init(\FFI::addr($options), 1), 'Unable to initialize fetch options');
            $options->download_tags = self::GIT_REMOTE_DOWNLOAD_TAGS_ALL;

            $refspecBuffer = LibGit2::cString($this->ffi, $refspec);
            $refspecs = $this->ffi->new('char*[1]');
            $refspecs[0] = $this->ffi->cast('char *', $refspecBuffer);
            $refspecArray = $this->ffi->new('git_strarray');
            $refspecArray->strings = $this->ffi->cast('char **', $refspecs);
            $refspecArray->count = 1;

            LibGit2::check($this->ffi, $this->ffi->git_remote_fetch($remote, \FFI::addr($refspecArray), \FFI::addr($options), null), \sprintf('Unable to fetch "%s"', $url));

            $head = null !== $branch ? 'refs/heads/'.$branch : $this->defaultBranch($remote);
        } finally {
            $this->ffi->git_remote_free($remote);
        }

        if (null === $head) {
            return;
        }

        $reference = $this->ffi->new('git_reference*');
        $rc = $this->ffi->git_reference_lookup(\FFI::addr($reference), $repository, $head);
        if (self::GIT_ENOTFOUND === $rc) {
            throw new DriverException(\sprintf('Remote branch "%s" not found in "%s".', $branch ?? $head, $url));
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to look up "%s"', $head));
        $this->ffi->git_reference_free($reference);

        LibGit2::check($this->ffi, $this->ffi->git_repository_set_head($repository, $head), 'Unable to update HEAD');
    }

    /**
     * @return string|null The full name of the branch HEAD points to on the remote, or null for an empty repository
     */
    private function defaultBranch(CData $remote): ?string
    {
        $buffer = $this->ffi->new('git_buf');
        $rc = $this->ffi->git_remote_default_branch(\FFI::addr($buffer), $remote);
        if (self::GIT_ENOTFOUND === $rc) {
            return null;
        }
        LibGit2::check($this->ffi, $rc, 'Unable to find the default branch of the remote');

        try {
            return \FFI::string($buffer->ptr, $buffer->size);
        } finally {
            $this->ffi->git_buf_dispose(\FFI::addr($buffer));
        }
    }

    /**
     * @param array<string, string> $config
     */
    private function setConfig(CData $repository, array $config): void
    {
        $handle = $this->ffi->new('git_config*');
        LibGit2::check($this->ffi, $this->ffi->git_repository_config(\FFI::addr($handle), $repository), 'Unable to open the repository configuration');

        try {
            foreach ($config as $name => $value) {
                LibGit2::check($this->ffi, $this->ffi->git_config_set_string($handle, $name, $value), \sprintf('Unable to set "%s"', $name));
            }
        } finally {
            $this->ffi->git_config_free($handle);
        }
    }

    /**
     * @return string[]|null The full names of the references of $url, or null when it can't be reached
     */
    private function listRemoteReferences(string $url): ?array
    {
        $remote = $this->ffi->new('git_remote*');
        if ($this->ffi->git_remote_create_detached(\FFI::addr($remote), $url) < 0) {
            return null;
        }

        try {
            if ($this->ffi->git_remote_connect($remote, 0, null, null, null) < 0) { // GIT_DIRECTION_FETCH
                return null;
            }

            try {
                $heads = $this->ffi->new('git_remote_head**');
                $count = $this->ffi->new('size_t');
                LibGit2::check($this->ffi, $this->ffi->git_remote_ls(\FFI::addr($heads), \FFI::addr($count), $remote), \sprintf('Unable to list the references of "%s"', $url));

                $references = [];
                for ($i = 0; $i < $count->cdata; ++$i) {
                    $references[] = \FFI::string($heads[$i]->name);
                }

                return $references;
            } finally {
                $this->ffi->git_remote_disconnect($remote);
            }
        } finally {
            $this->ffi->git_remote_free($remote);
        }
    }
}
