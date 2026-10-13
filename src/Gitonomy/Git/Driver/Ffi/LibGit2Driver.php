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
use Gitonomy\Git\Driver\RepositoryPaths;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\Util\LeadingDash;
use Gitonomy\Git\Util\NulByte;

/**
 * Runs libgit2 1.9 in-process, through PHP's FFI extension.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @experimental
 */
final readonly class LibGit2Driver implements DriverInterface
{
    private const int GIT_CHECKOUT_FORCE = 1 << 1;
    private const int GIT_CHECKOUT_NONE = 1 << 30;
    private const int GIT_DIRECTION_FETCH = 0;
    private const int GIT_PROXY_AUTO = 1;
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

    public function open(string $dir, ?string $workingDir): LibGit2RepositoryDriver
    {
        $paths = RepositoryPaths::resolve($dir, $workingDir);

        return new LibGit2RepositoryDriver($this->ffi, $paths->gitDir, $paths->workingDir);
    }

    public function init(string $path): void
    {
        NulByte::assertNone($path);

        $this->ffi->git_repository_free($this->initRepository($path, false));
    }

    public function initBare(string $path): void
    {
        NulByte::assertNone($path);

        $this->ffi->git_repository_free($this->initRepository($path, true));
    }

    public function cloneFrom(string $url, string $path, ?string $branch): void
    {
        NulByte::assertNone($url, $path, $branch ?? '');
        // Like CliDriver, where git would read it as an option.
        LeadingDash::assertNone($url);

        // git_clone() only checks a branch out, where git also checks a tag out, HEAD detached.
        $tag = null !== $branch && $this->isTagOnly($url, $branch) ? $branch : null;

        $options = $this->ffi->new('git_clone_options');
        LibGit2::check($this->ffi, $this->ffi->git_clone_options_init(\FFI::addr($options), 1), 'Unable to initialize clone options');
        // Like git, through the proxy that http.proxy or the environment configures.
        $options->fetch_opts->proxy_opts->type = self::GIT_PROXY_AUTO;

        // The buffer must stay alive until git_clone() returns.
        $branchBuffer = null;
        if (null !== $tag) {
            $options->checkout_opts->checkout_strategy = self::GIT_CHECKOUT_NONE;
        } elseif (null !== $branch) {
            $branchBuffer = LibGit2::cString($this->ffi, $branch);
            $options->checkout_branch = $this->ffi->cast('const char *', $branchBuffer);
        }

        $existing = is_dir($path);
        $repository = $this->ffi->new('git_repository*');
        LibGit2::check($this->ffi, $this->ffi->git_clone(\FFI::addr($repository), $url, $path, \FFI::addr($options)), \sprintf('Unable to clone "%s"', $url));

        try {
            try {
                if (null !== $tag) {
                    $this->checkOutTag($repository, $tag);
                }
            } finally {
                $this->ffi->git_repository_free($repository);
            }
        } catch (DriverException $e) {
            // Like git, which removes what it created when a clone fails.
            self::remove($path, $existing);

            throw $e;
        }

        self::removeFetchHead($path.'/.git');
    }

    public function cloneBareFrom(string $url, string $path, ?string $branch): void
    {
        NulByte::assertNone($url, $path, $branch ?? '');
        LeadingDash::assertNone($url);

        // Like git, only the branches (and the tags).
        $this->fetchInto($path, $url, '+refs/heads/*:refs/heads/*', $branch, ['remote.origin.url' => $url]);
    }

    public function mirrorFrom(string $url, string $path): void
    {
        NulByte::assertNone($url, $path);
        LeadingDash::assertNone($url);

        $this->fetchInto($path, $url, '+refs/*:refs/*', null, [
            'remote.origin.url' => $url,
            'remote.origin.fetch' => '+refs/*:refs/*',
            'remote.origin.mirror' => 'true',
            'remote.origin.tagopt' => '--no-tags',
        ]);
    }

    public function isValidRepository(string $url): bool
    {
        NulByte::assertNone($url);
        LeadingDash::assertNone($url);

        return null !== $this->listRemoteReferences($url);
    }

    public function isValidRepositoryAndBranch(string $url, string $branch): bool
    {
        NulByte::assertNone($url, $branch);
        LeadingDash::assertNone($url);

        return \in_array('refs/heads/'.$branch, $this->listRemoteReferences($url) ?? [], true);
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
        // Like git, which refuses to clone into a directory with files.
        $existing = is_dir($path);
        if ($existing && [] !== array_diff(scandir($path) ?: [], ['.', '..'])) {
            throw new DriverException(\sprintf('Unable to clone "%s": "%s" exists and is not an empty directory.', $url, $path));
        }

        $repository = $this->initRepository($path, true);

        try {
            try {
                $this->fetch($repository, $url, $refspec, $branch);
                $this->setConfig($repository, $config);
            } finally {
                $this->ffi->git_repository_free($repository);
            }
        } catch (DriverException $e) {
            // Like git, which removes what it created when a clone fails.
            self::remove($path, $existing);

            throw $e;
        }

        self::removeFetchHead($path);
    }

    /**
     * libgit2 writes an empty FETCH_HEAD, even when told not to, where a clone with git writes none.
     */
    private static function removeFetchHead(string $gitDir): void
    {
        if (is_file($gitDir.'/FETCH_HEAD')) {
            unlink($gitDir.'/FETCH_HEAD');
        }
    }

    /**
     * @return bool Whether $url has a tag named $name, and no branch of this name
     */
    private function isTagOnly(string $url, string $name): bool
    {
        $references = $this->listRemoteReferences($url) ?? [];

        return !\in_array('refs/heads/'.$name, $references, true) && \in_array('refs/tags/'.$name, $references, true);
    }

    /**
     * Checks the commit of the tag $tag out, HEAD detached, and removes the
     * local branch git_clone() created: git creates none.
     */
    private function checkOutTag(CData $repository, string $tag): void
    {
        $head = $this->ffi->new('git_reference*');
        LibGit2::check($this->ffi, $this->ffi->git_reference_lookup(\FFI::addr($head), $repository, 'HEAD'), 'Unable to read HEAD');

        try {
            $branch = $this->ffi->git_reference_type($head) === $this->ffi->GIT_REFERENCE_SYMBOLIC ? $this->ffi->git_reference_symbolic_target($head) : null;
        } finally {
            $this->ffi->git_reference_free($head);
        }

        $commit = $this->ffi->new('git_object*');
        LibGit2::check($this->ffi, $this->ffi->git_revparse_single(\FFI::addr($commit), $repository, 'refs/tags/'.$tag.'^{commit}'), \sprintf('Unable to resolve the tag "%s"', $tag));

        try {
            $options = $this->ffi->new('git_checkout_options');
            LibGit2::check($this->ffi, $this->ffi->git_checkout_options_init(\FFI::addr($options), 1), 'Unable to initialize checkout options');
            // The clone wrote no file.
            $options->checkout_strategy = self::GIT_CHECKOUT_FORCE;
            LibGit2::check($this->ffi, $this->ffi->git_checkout_tree($repository, $commit, \FFI::addr($options)), \sprintf('Unable to checkout the tag "%s"', $tag));
            LibGit2::check($this->ffi, $this->ffi->git_repository_set_head_detached($repository, $this->ffi->git_object_id($commit)), 'Unable to detach HEAD');
        } finally {
            $this->ffi->git_object_free($commit);
        }

        if (null === $branch) {
            return;
        }

        $reference = $this->ffi->new('git_reference*');
        $rc = $this->ffi->git_reference_lookup(\FFI::addr($reference), $repository, $branch);
        // An empty remote has no default branch.
        if (LibGit2::GIT_ENOTFOUND === $rc) {
            return;
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to look up "%s"', $branch));

        try {
            LibGit2::check($this->ffi, $this->ffi->git_branch_delete($reference), \sprintf('Unable to delete "%s"', $branch));
        } finally {
            $this->ffi->git_reference_free($reference);
        }
    }

    /**
     * Removes $path, or only its content when $keep.
     */
    private static function remove(string $path, bool $keep): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if (is_dir($file) && !is_link($file)) {
                rmdir($file);
            } else {
                unlink($file);
            }
        }

        if (!$keep) {
            rmdir($path);
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
            $options->proxy_opts->type = self::GIT_PROXY_AUTO;
            // A clone writes no FETCH_HEAD.
            $options->update_fetchhead = 0;

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
        if (LibGit2::GIT_ENOTFOUND === $rc && null !== $branch) {
            // Like git, HEAD detached at the commit of a tag of this name.
            $this->detachHeadAtTag($repository, $branch, $url);

            return;
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to look up "%s"', $head));
        $this->ffi->git_reference_free($reference);

        LibGit2::check($this->ffi, $this->ffi->git_repository_set_head($repository, $head), 'Unable to update HEAD');
    }

    /**
     * @throws DriverException $repository has no tag named $tag
     */
    private function detachHeadAtTag(CData $repository, string $tag, string $url): void
    {
        $commit = $this->ffi->new('git_object*');
        $rc = $this->ffi->git_revparse_single(\FFI::addr($commit), $repository, 'refs/tags/'.$tag.'^{commit}');
        if (LibGit2::GIT_ENOTFOUND === $rc) {
            throw new DriverException(\sprintf('Remote branch "%s" not found in "%s".', $tag, $url));
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to resolve the tag "%s"', $tag));

        try {
            LibGit2::check($this->ffi, $this->ffi->git_repository_set_head_detached($repository, $this->ffi->git_object_id($commit)), 'Unable to detach HEAD');
        } finally {
            $this->ffi->git_object_free($commit);
        }
    }

    /**
     * @return string|null The full name of the branch HEAD points to on the remote, or null for an empty repository
     */
    private function defaultBranch(CData $remote): ?string
    {
        $buffer = $this->ffi->new('git_buf');
        $rc = $this->ffi->git_remote_default_branch(\FFI::addr($buffer), $remote);
        if (LibGit2::GIT_ENOTFOUND === $rc) {
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
            $proxy = $this->ffi->new('git_proxy_options');
            $proxy->version = 1;
            $proxy->type = self::GIT_PROXY_AUTO;
            if ($this->ffi->git_remote_connect($remote, self::GIT_DIRECTION_FETCH, null, \FFI::addr($proxy), null) < 0) {
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
