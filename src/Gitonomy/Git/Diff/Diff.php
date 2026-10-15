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

namespace Gitonomy\Git\Diff;

use Gitonomy\Git\Parser\DiffParser;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Util\LegacyOverride;

/**
 * Representation of a diff.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class Diff
{
    /**
     * @var File[]
     */
    protected $files;

    /**
     * @var string
     */
    protected $rawDiff;

    /**
     * Constructs a new diff for a given revision.
     *
     * @param array  $files   The files
     * @param string $rawDiff The raw diff
     */
    public function __construct(array $files, $rawDiff)
    {
        $this->files = $files;
        $this->rawDiff = $rawDiff;
    }

    /**
     * Parses the output of ``git diff --raw --patch``.
     *
     * @param Repository|null $repository The repository to read the blobs of the files from
     *
     * @return Diff
     *
     * @psalm-variadic The arguments this method gets with func_get_arg(), commented out in its signature
     */
    public static function parse($rawDiff /* , ?Repository $repository = null */)
    {
        // A second argument, without breaking the classes overriding this method.
        $repository = 1 < \func_num_args() ? func_get_arg(1) : null;

        $parser = new DiffParser();
        $parser->parse($rawDiff);

        $diff = new self($parser->files, $rawDiff);
        if (null !== $repository) {
            $diff->attachRepository($repository);
        }

        return $diff;
    }

    /**
     * @deprecated since gitonomy/gitlib 1.7, the diffs gitlib returns have their repository already
     */
    public function setRepository(Repository $repository)
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, the diffs gitlib returns have their repository already.', __METHOD__);

        $this->attachRepository($repository);
    }

    /**
     * Get list of files modified in the diff's revision.
     *
     * @return File[] An array of Diff\File objects
     */
    public function getFiles()
    {
        return $this->files;
    }

    /**
     * Returns the raw diff.
     *
     * @return string The raw diff
     */
    public function getRawDiff()
    {
        return $this->rawDiff;
    }

    /**
     * Export a diff as array.
     *
     * @return array The array
     */
    public function toArray()
    {
        return [
            'rawDiff' => $this->rawDiff,
            'files'   => array_map(
                function (File $file) {
                    return $file->toArray();
                },
                $this->files
            ),
        ];
    }

    /**
     * Create a new instance of Diff from an array.
     *
     * @param array           $array      The array
     * @param Repository|null $repository The repository to read the blobs of the files from
     *
     * @return Diff The new instance
     *
     * @psalm-variadic The arguments this method gets with func_get_arg(), commented out in its signature
     */
    public static function fromArray(array $array /* , ?Repository $repository = null */)
    {
        // A second argument, without breaking the classes overriding this method.
        $repository = 1 < \func_num_args() ? func_get_arg(1) : null;

        return new static(
            array_map(
                function ($array) use ($repository) {
                    return File::fromArray($array, $repository);
                },
                $array['files']
            ),
            $array['rawDiff']
        );
    }

    private function attachRepository(Repository $repository): void
    {
        foreach ($this->files as $file) {
            // Like in 1.6, a subclass overriding File::setRepository() gets the repository.
            if (LegacyOverride::isOverridden($file, File::class, 'setRepository')) {
                $file->setRepository($repository);

                continue;
            }

            // Without the deprecation of File::setRepository().
            \Closure::bind(static function () use ($file, $repository) {
                $file->repository = $repository;
            }, null, File::class)();
        }
    }
}
