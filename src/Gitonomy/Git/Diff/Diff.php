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

use Gitonomy\Git\Driver\Data\DiffData;
use Gitonomy\Git\Driver\Data\DiffFileData;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Parser\DiffParser;
use Gitonomy\Git\Repository;

/**
 * Representation of a diff.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @phpstan-import-type FileArray from File
 */
final readonly class Diff
{
    /**
     * @param File[] $files
     */
    public function __construct(
        private array $files,
        private string $rawDiff,
    ) {
    }

    /**
     * Parses the output of ``git diff --raw --patch``, ``-z`` or not.
     *
     * @param Repository|null $repository The repository to read the blobs of the files from
     *
     * @throws InvalidArgumentException The diff has no raw information
     * @throws RuntimeException         The diff holds what the parser doesn't know, like the log of a submodule
     */
    public static function parse(string $rawDiff, ?Repository $repository = null): self
    {
        $parser = new DiffParser();
        $parser->parse($rawDiff);

        return new self(array_map(static fn (DiffFileData $file): File => File::fromData($file, $repository), $parser->files), $rawDiff);
    }

    /**
     * @internal
     */
    public static function fromData(DiffData $data, Repository $repository): self
    {
        return new self(array_map(static fn (DiffFileData $file): File => File::fromData($file, $repository), $data->files), $data->rawDiff);
    }

    /**
     * @deprecated since gitonomy/gitlib 2.0, the diffs gitlib returns have their repository already
     */
    public function setRepository(Repository $repository): void
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, the diffs gitlib returns have their repository already.', __METHOD__);

        foreach ($this->files as $file) {
            // Without one more deprecation for each file.
            \Closure::bind(static fn () => $file->repository = $repository, null, File::class)();
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
     *
     * @return array{rawDiff: string, files: list<FileArray>}
     */
    public function toArray(): array
    {
        return [
            'rawDiff' => $this->rawDiff,
            'files' => array_map(static fn (File $file): array => $file->toArray(), array_values($this->files)),
        ];
    }

    /**
     * Create a new instance of Diff from an array.
     *
     * @param array{rawDiff: string, files: list<FileArray>} $array
     */
    public static function fromArray(array $array, ?Repository $repository = null): self
    {
        return new self(array_map(static fn (array $file): File => File::fromArray($file, $repository), $array['files']), $array['rawDiff']);
    }
}
