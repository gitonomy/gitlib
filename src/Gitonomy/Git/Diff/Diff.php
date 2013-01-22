<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the GPL license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git\Diff;

use Gitonomy\Git\Parser\DiffParser;

/**
 * Representation of a diff.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Diff
{
    /**
     * @var array
     */
    protected $files;

    /**
     * Constructs a new diff for a given revision.
     *
     * @var Repository $repository
     * @var string     $revision   A string revision, passed to git diff command
     * @var boolean    $isTree     Indicates if revisions are commit-trees to compare
     */
    public function __construct(array $files)
    {
        $this->files = $files;
    }

    /**
     * @return Diff
     */
    static public function parse($rawDiff)
    {
        $parser = new DiffParser();
        $parser->parse($rawDiff);

        return new Diff($parser->files);
    }

    /**
     * @return array
     */
    public function getRevisions()
    {
        return $this->revisions;
    }

    /**
     * Get list of files modified in the diff's revision.
     *
     * @return array An array of Diff\File objects
     */
    public function getFiles()
    {
        return $this->files;
    }

    public function toArray()
    {
        return array_map(function (File $file) {
            return $file->toArray();
        }, $this->files);
    }

    public static function fromArray(array $array)
    {
        return new Diff(array_map(function ($array) {
            return File::fromArray($array);
        }, $array));
    }
}
