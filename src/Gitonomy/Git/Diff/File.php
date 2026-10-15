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

use Gitonomy\Git\Repository;
use Gitonomy\Git\Util\LegacyOverride;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class File
{
    /**
     * @var string
     */
    protected $oldName;

    /**
     * @var string
     */
    protected $newName;

    /**
     * @var string
     */
    protected $oldMode;

    /**
     * @var string
     */
    protected $newMode;

    /**
     * @var string
     */
    protected $oldIndex;

    /**
     * @var string
     */
    protected $newIndex;

    /**
     * @var bool
     */
    protected $isBinary;

    /**
     * @var bool
     */
    private $isCopy;

    /**
     * @var FileChange[] An array of FileChange objects
     */
    protected $changes;

    /**
     * @var Repository
     */
    protected $repository;

    /**
     * Instanciates a new File object.
     *
     * @param FileChange[]    $changes
     * @param Repository|null $repository The repository to read the blobs from
     */
    public function __construct($oldName, $newName, $oldMode, $newMode, $oldIndex, $newIndex, $isBinary, $isCopy = false, array $changes = [], ?Repository $repository = null)
    {
        $this->oldName = $oldName;
        $this->newName = $newName;
        $this->oldMode = $oldMode;
        $this->newMode = $newMode;
        $this->oldIndex = $oldIndex;
        $this->newIndex = $newIndex;
        $this->isBinary = $isBinary;
        $this->isCopy = $isCopy;
        $this->changes = $changes;
        $this->repository = $repository;
    }

    /**
     * @deprecated since gitonomy/gitlib 1.7, give the changes to the constructor instead
     */
    public function addChange(FileChange $change)
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, give the changes to the constructor instead.', __METHOD__);

        $this->changes[] = $change;
    }

    /**
     * Indicates if this diff file is a creation.
     *
     * @return bool
     */
    public function isCreation()
    {
        return null === $this->oldName;
    }

    /**
     * Indicates if this diff file is a modification.
     *
     * @return bool
     */
    public function isModification()
    {
        return null !== $this->oldName && null !== $this->newName;
    }

    /**
     * Indicates if it's a rename.
     *
     * A rename can only occurs if it's a modification (not a creation or a deletion), and is not a copy.
     *
     * @return bool
     */
    public function isRename()
    {
        return $this->isModification() && $this->oldName !== $this->newName && !$this->isCopy;
    }

    /**
     * Indicates if it's a copy.
     *
     * Unlike a rename, the source file of a copy still exists after the change.
     * The diffs gitlib reads have no copies: git detects them with -C only.
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
     * Indicates if the file mode has changed.
     *
     * @deprecated since gitonomy/gitlib 1.7, use isModeChange() instead
     */
    public function isChangeMode()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "isModeChange()" instead.', __METHOD__);

        return $this->isModeChange();
    }

    /**
     * Indicates if this diff file is a deletion.
     *
     * @return bool
     */
    public function isDeletion()
    {
        return null === $this->newName;
    }

    /**
     * Indicates if this diff file is a deletion.
     *
     * @return bool
     *
     * @deprecated since gitonomy/gitlib 1.7, use isDeletion() instead
     */
    public function isDelete()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "isDeletion()" instead.', __METHOD__);

        return $this->isDeletion();
    }

    /**
     * @return int Number of added lines
     */
    public function getAdditions()
    {
        $result = 0;
        foreach ($this->changes as $change) {
            // Like in 1.6, a subclass overriding FileChange::getCount() counts the lines.
            $result += LegacyOverride::isOverridden($change, FileChange::class, 'getCount') ? $change->getCount(DiffLineType::Addition->value) : $change->getAdditions();
        }

        return $result;
    }

    /**
     * @return int Number of deleted lines
     */
    public function getDeletions()
    {
        $result = 0;
        foreach ($this->changes as $change) {
            // Like in 1.6, a subclass overriding FileChange::getCount() counts the lines.
            $result += LegacyOverride::isOverridden($change, FileChange::class, 'getCount') ? $change->getCount(DiffLineType::Deletion->value) : $change->getDeletions();
        }

        return $result;
    }

    public function getOldName()
    {
        return $this->oldName;
    }

    public function getNewName()
    {
        return $this->newName;
    }

    public function getName()
    {
        if (null === $this->newName) {
            return $this->oldName;
        }

        return $this->newName;
    }

    public function getOldMode()
    {
        return $this->oldMode;
    }

    public function getNewMode()
    {
        return $this->newMode;
    }

    public function getOldIndex()
    {
        return $this->oldIndex;
    }

    public function getNewIndex()
    {
        return $this->newIndex;
    }

    public function isBinary()
    {
        return $this->isBinary;
    }

    /**
     * @return FileChange[]
     */
    public function getChanges()
    {
        return $this->changes;
    }

    public function toArray()
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

    /**
     * @param Repository|null $repository The repository to read the blobs from
     *
     * @return File
     *
     * @psalm-variadic The arguments this method gets with func_get_arg(), commented out in its signature
     */
    public static function fromArray(array $array /* , ?Repository $repository = null */)
    {
        // A second argument, without breaking the classes overriding this method.
        $repository = 1 < \func_num_args() ? func_get_arg(1) : null;

        $changes = array_map(function (array $change) {
            return FileChange::fromArray($change);
        }, $array['changes']);

        return new self($array['old_name'], $array['new_name'], $array['old_mode'], $array['new_mode'], $array['old_index'], $array['new_index'], $array['is_binary'], $array['is_copy'] ?? false, $changes, $repository);
    }

    /**
     * @deprecated since gitonomy/gitlib 1.7, a presentation concern: use substr($file->getNewIndex(), 0, 12) instead
     */
    public function getAnchor()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "substr($file->getNewIndex(), 0, 12)" instead.', __METHOD__);

        return substr($this->newIndex, 0, 12);
    }

    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * @deprecated since gitonomy/gitlib 1.7, the diffs gitlib returns have their repository already
     */
    public function setRepository(Repository $repository)
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, the diffs gitlib returns have their repository already.', __METHOD__);

        $this->repository = $repository;
    }

    public function getOldBlob()
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

    public function getNewBlob()
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
