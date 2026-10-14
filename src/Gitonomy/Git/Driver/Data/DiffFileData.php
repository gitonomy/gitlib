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

namespace Gitonomy\Git\Driver\Data;

use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Exception\InvalidArgumentException;

/**
 * A file of a diff, as computed by a Driver.
 *
 * Its changes are FileChange objects already: unlike a file, they need no
 * repository.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class DiffFileData
{
    /**
     * The new name, or the old one for a deletion.
     */
    public string $name;

    /**
     * @param string|null      $oldName  Null for a creation
     * @param string|null      $newName  Null for a deletion
     * @param string|null      $oldIndex The hash of the old blob, null when there is none
     * @param string|null      $newIndex The hash of the new blob, null when there is none, or for a file of the working tree
     * @param list<FileChange> $changes
     *
     * @throws InvalidArgumentException Both names are null
     */
    public function __construct(
        public ?string $oldName,
        public ?string $newName,
        public ?string $oldMode,
        public ?string $newMode,
        public ?string $oldIndex,
        public ?string $newIndex,
        public bool $isBinary,
        public bool $isCopy,
        public array $changes,
    ) {
        $this->name = $newName ?? $oldName ?? throw new InvalidArgumentException('A file of a diff has an old name, a new name, or both.');
    }
}
