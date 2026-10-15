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

use Gitonomy\Git\Blob;
use Gitonomy\Git\Driver\Data\DiffFileData;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Repository;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @phpstan-import-type FileChangeArray from FileChange
 *
 * @phpstan-type FileArray array{old_name: string|null, new_name: string|null, old_mode: string|null, new_mode: string|null, old_index: string|null, new_index: string|null, is_binary: bool, is_copy: bool, changes: list<FileChangeArray>}
 */
final class File
{
    private readonly string $name;

    /**
     * @param string|null     $oldName    Null for a creation
     * @param string|null     $newName    Null for a deletion
     * @param string|null     $oldIndex   The hash of the old blob, null when there is none (a creation...)
     * @param string|null     $newIndex   The hash of the new blob, null when there is none (a deletion, a modified submodule...); the hash of a file of the working tree isn't in the repository
     * @param FileChange[]    $changes
     * @param Repository|null $repository The repository to read the blobs from
     *
     * @throws InvalidArgumentException Both names are null
     */
    public function __construct(
        private readonly ?string $oldName,
        private readonly ?string $newName,
        private readonly ?string $oldMode,
        private readonly ?string $newMode,
        private readonly ?string $oldIndex,
        private readonly ?string $newIndex,
        private readonly bool $isBinary,
        private readonly bool $isCopy = false,
        private array $changes = [],
        private ?Repository $repository = null,
    ) {
        $this->name = $newName ?? $oldName ?? throw new InvalidArgumentException('A file of a diff has an old name, a new name, or both.');
    }

    /**
     * @deprecated since gitonomy/gitlib 1.7, give the changes to the constructor instead
     */
    public function addChange(FileChange $change): void
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, give the changes to the constructor instead.', __METHOD__);

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
     * A rename can only occurs if it's a modification (not a creation or a deletion), and is not a copy.
     */
    public function isRename(): bool
    {
        return $this->isModification() && $this->oldName !== $this->newName && !$this->isCopy;
    }

    /**
     * Indicates if it's a copy.
     *
     * Unlike a rename, the source file of a copy still exists after the change.
     */
    public function isCopy(): bool
    {
        return $this->isCopy;
    }

    /**
     * Indicates if the file mode has changed.
     */
    public function isModeChange(): bool
    {
        return $this->isModification() && $this->oldMode !== $this->newMode;
    }

    /**
     * @deprecated since gitonomy/gitlib 1.7, use isModeChange() instead
     */
    public function isChangeMode(): bool
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "isModeChange()" instead.', __METHOD__);

        return $this->isModeChange();
    }

    /**
     * Indicates if this diff file is a deletion.
     */
    public function isDeletion(): bool
    {
        return null === $this->newName;
    }

    /**
     * @deprecated since gitonomy/gitlib 1.7, use isDeletion() instead
     */
    public function isDelete(): bool
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "isDeletion()" instead.', __METHOD__);

        return $this->isDeletion();
    }

    /**
     * @return int Number of added lines
     */
    public function getAdditions(): int
    {
        return array_sum(array_map(static fn (FileChange $change): int => $change->getAdditions(), $this->changes));
    }

    /**
     * @return int Number of deleted lines
     */
    public function getDeletions(): int
    {
        return array_sum(array_map(static fn (FileChange $change): int => $change->getDeletions(), $this->changes));
    }

    public function getOldName(): ?string
    {
        return $this->oldName;
    }

    public function getNewName(): ?string
    {
        return $this->newName;
    }

    /**
     * @return string The new name, or the old one for a deletion
     */
    public function getName(): string
    {
        return $this->name;
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

    /**
     * @return FileArray
     */
    public function toArray(): array
    {
        return [
            'old_name' => $this->oldName,
            'new_name' => $this->newName,
            'old_mode' => $this->oldMode,
            'new_mode' => $this->newMode,
            'old_index' => $this->oldIndex,
            'new_index' => $this->newIndex,
            'is_binary' => $this->isBinary,
            'is_copy' => $this->isCopy,
            'changes' => array_map(static fn (FileChange $change): array => $change->toArray(), array_values($this->changes)),
        ];
    }

    /**
     * @internal
     */
    public static function fromData(DiffFileData $data, ?Repository $repository): self
    {
        return new self($data->oldName, $data->newName, $data->oldMode, $data->newMode, $data->oldIndex, $data->newIndex, $data->isBinary, $data->isCopy, $data->changes, $repository);
    }

    /**
     * @param array{old_name: string|null, new_name: string|null, old_mode: string|null, new_mode: string|null, old_index: string|null, new_index: string|null, is_binary: bool, is_copy?: bool, changes: list<FileChangeArray>} $array Without "is_copy" from 1.x
     */
    public static function fromArray(array $array, ?Repository $repository = null): self
    {
        return new self(
            $array['old_name'],
            $array['new_name'],
            $array['old_mode'],
            $array['new_mode'],
            // 1.x used an empty string for a missing hash.
            '' === $array['old_index'] ? null : $array['old_index'],
            '' === $array['new_index'] ? null : $array['new_index'],
            $array['is_binary'],
            $array['is_copy'] ?? false,
            array_map(FileChange::fromArray(...), $array['changes']),
            $repository,
        );
    }

    /**
     * @deprecated since gitonomy/gitlib 1.7, a presentation concern: use substr($file->getNewIndex(), 0, 12) instead
     */
    public function getAnchor(): string
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "substr($file->getNewIndex(), 0, 12)" instead.', __METHOD__);

        return substr($this->newIndex ?? '', 0, 12);
    }

    public function getRepository(): ?Repository
    {
        return $this->repository;
    }

    /**
     * @deprecated since gitonomy/gitlib 1.7, the diffs gitlib returns have their repository already
     */
    public function setRepository(Repository $repository): void
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, the diffs gitlib returns have their repository already.', __METHOD__);

        $this->repository = $repository;
    }

    /**
     * @throws LogicException   The file has no repository, or is a creation
     * @throws RuntimeException The diff doesn't know the hash of the old blob
     */
    public function getOldBlob(): Blob
    {
        if ($this->isCreation()) {
            throw new LogicException(\sprintf('"%s" is a creation: it has no old blob.', $this->name));
        }

        return $this->blob($this->oldIndex, 'old');
    }

    /**
     * @throws LogicException   The file has no repository, or is a deletion
     * @throws RuntimeException The diff doesn't know the hash of the new blob, like for a modified submodule
     */
    public function getNewBlob(): Blob
    {
        if ($this->isDeletion()) {
            throw new LogicException(\sprintf('"%s" is a deletion: it has no new blob.', $this->name));
        }

        return $this->blob($this->newIndex, 'new');
    }

    private function blob(?string $hash, string $side): Blob
    {
        if (null === $this->repository) {
            throw new LogicException('A diff without repository can\'t read blobs: get it from a repository.');
        }

        if (null === $hash) {
            throw new RuntimeException(\sprintf('The diff doesn\'t know the hash of the %s blob of "%s".', $side, $this->name));
        }

        return $this->repository->getBlob($hash);
    }
}
