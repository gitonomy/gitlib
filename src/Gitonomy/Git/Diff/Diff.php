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

namespace Gitonomy\Git\Diff;

use Gitonomy\Git\Parser\DiffParser;
use Gitonomy\Git\Repository;

/**
 * Representation of a diff.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final readonly class Diff
{
    /**
     * Constructs a new diff for a given revision.
     *
     * @param File[] $files The files
     */
    public function __construct(
        private array $files,
        private string $rawDiff,
    ) {
    }

    public static function parse(string $rawDiff): self
    {
        $parser = new DiffParser();
        $parser->parse($rawDiff);

        return new self($parser->files, $rawDiff);
    }

    public function setRepository(Repository $repository): void
    {
        foreach ($this->files as $file) {
            $file->setRepository($repository);
        }
    }

    /**
     * Get list of files modified in the diff's revision.
     *
     * @return File[] An array of Diff\File objects
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Returns the raw diff.
     */
    public function getRawDiff(): string
    {
        return $this->rawDiff;
    }

    /**
     * Export a diff as array.
     */
    public function toArray(): array
    {
        return [
            'rawDiff' => $this->rawDiff,
            'files' => array_map(
                static function (File $file) {
                    return $file->toArray();
                },
                $this->files
            ),
        ];
    }

    /**
     * Create a new instance of Diff from an array.
     */
    public static function fromArray(array $array): self
    {
        return new self(
            array_map(
                static function ($array) {
                    return File::fromArray($array);
                },
                $array['files']
            ),
            $array['rawDiff']
        );
    }
}
