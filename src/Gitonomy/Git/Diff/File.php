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

use Gitonomy\Git\Blob;
use Gitonomy\Git\Repository;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class File
{
    /**
     * @var FileChange[]
     */
    private array $changes = [];

    private ?Repository $repository = null;

    /**
     * Instanciates a new File object.
     */
    public function __construct(
        private readonly ?string $oldName,
        private readonly ?string $newName,
        private readonly ?string $oldMode,
        private readonly ?string $newMode,
        private readonly ?string $oldIndex,
        private readonly ?string $newIndex,
        private readonly bool $isBinary,
    ) {
    }

    public function addChange(FileChange $change): void
    {
        $this->changes[] = $change;
    }

    /**
     * Indicates if this diff file is a creation.
     */
    public function isCreation(): bool
    {
        return null === $this->oldName;
    }

    /**
     * Indicates if this diff file is a modification.
     */
    public function isModification(): bool
    {
        return null !== $this->oldName && null !== $this->newName;
    }

    /**
     * Indicates if it's a rename.
     *
     * A rename can only occurs if it's a modification (not a creation or a deletion).
     */
    public function isRename(): bool
    {
        return $this->isModification() && $this->oldName !== $this->newName;
    }

    /**
     * Indicates if the file mode has changed.
     */
    public function isChangeMode(): bool
    {
        return $this->isModification() && $this->oldMode !== $this->newMode;
    }

    /**
     * Indicates if this diff file is a deletion.
     */
    public function isDeletion(): bool
    {
        return null === $this->newName;
    }

    /**
     * Indicates if this diff file is a deletion.
     */
    public function isDelete(): bool
    {
        return null === $this->newName;
    }

    /**
     * @return int Number of added lines
     */
    public function getAdditions(): int
    {
        $result = 0;
        foreach ($this->changes as $change) {
            $result += $change->getCount(FileChange::LINE_ADD);
        }

        return $result;
    }

    /**
     * @return int Number of deleted lines
     */
    public function getDeletions(): int
    {
        $result = 0;
        foreach ($this->changes as $change) {
            $result += $change->getCount(FileChange::LINE_REMOVE);
        }

        return $result;
    }

    public function getOldName(): ?string
    {
        return $this->oldName;
    }

    public function getNewName(): ?string
    {
        return $this->newName;
    }

    public function getName(): ?string
    {
        if (null === $this->newName) {
            return $this->oldName;
        }

        return $this->newName;
    }

    public function getOldMode(): ?string
    {
        return $this->oldMode;
    }

    public function getNewMode(): ?string
    {
        return $this->newMode;
    }

    public function getOldIndex(): ?string
    {
        return $this->oldIndex;
    }

    public function getNewIndex(): ?string
    {
        return $this->newIndex;
    }

    public function isBinary(): bool
    {
        return $this->isBinary;
    }

    /**
     * @return FileChange[]
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function toArray(): array
    {
        return [
            'old_name'  => $this->oldName,
            'new_name'  => $this->newName,
            'old_mode'  => $this->oldMode,
            'new_mode'  => $this->newMode,
            'old_index' => $this->oldIndex,
            'new_index' => $this->newIndex,
            'is_binary' => $this->isBinary,
            'changes'   => array_map(function (FileChange $change) {
                return $change->toArray();
            }, $this->changes),
        ];
    }

    public static function fromArray(array $array): self
    {
        $file = new self($array['old_name'], $array['new_name'], $array['old_mode'], $array['new_mode'], $array['old_index'], $array['new_index'], $array['is_binary']);

        foreach ($array['changes'] as $change) {
            $file->addChange(FileChange::fromArray($change));
        }

        return $file;
    }

    public function getAnchor(): string
    {
        return substr($this->newIndex ?? '', 0, 12);
    }

    public function getRepository(): ?Repository
    {
        return $this->repository;
    }

    public function setRepository(Repository $repository): void
    {
        $this->repository = $repository;
    }

    public function getOldBlob(): Blob
    {
        if (null === $this->repository) {
            throw new \RuntimeException('Repository is missing to return Blob object.');
        }

        if ($this->isCreation()) {
            throw new \LogicException('Can\'t return old Blob on a creation');
        }

        if ($this->oldIndex === '') {
            throw new \RuntimeException('Index is missing to return Blob object.');
        }

        return $this->repository->getBlob($this->oldIndex);
    }

    public function getNewBlob(): Blob
    {
        if (null === $this->repository) {
            throw new \RuntimeException('Repository is missing to return Blob object.');
        }

        if ($this->isDeletion()) {
            throw new \LogicException('Can\'t return new Blob on a deletion');
        }

        if ($this->newIndex === '') {
            throw new \RuntimeException('Index is missing to return Blob object.');
        }

        return $this->repository->getBlob($this->newIndex);
    }
}
