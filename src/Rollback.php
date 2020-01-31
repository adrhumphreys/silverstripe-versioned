<?php

namespace SilverStripe\Versioned;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * The intent of this class is to perform rollbacks, you're able to inject over this class
 * to override the default rollback functionality
 */
class Rollback
{

    use Injectable;

    /**
     * Recursively rollback draft to the given version. This will also rollback any owned objects
     * at that point in time to the same date. Objects which didn't exist (or weren't attached)
     * to the record at the target point in time will be "unlinked", which dis-associates
     * the record without requiring a hard deletion.
     *
     * @param int|string|null $version Version ID or Versioned::LIVE to rollback from live.
     * Pass in null to rollback to the current object
     * @param DataObject|Versioned $dataObject The object to be rolled back
     * @return DataObject|Versioned The object rolled back
     */
    public function recursive($version, DataObject $dataObject): DataObject
    {
        $dataObject->invokeWithExtensions('onBeforeRollbackRecursive', $version);
        Rollback::singleton()->single($version, $dataObject);

        // Rollback relations on this item (works on unversioned records too)
        $rolledBackOwner = $dataObject->getAtVersion($version);
        if ($rolledBackOwner) {
            $rolledBackOwner->rollbackRelations($version);
        }

        // Unlink any objects disowned as a result of this action
        // I.e. objects which aren't owned anymore by this record, but are by the old draft record
        $rolledBackOwner->unlinkDisownedObjects($rolledBackOwner, Versioned::DRAFT);
        $rolledBackOwner->invokeWithExtensions('onAfterRollbackRecursive', $version);

        // Get rolled back version on draft
        return $dataObject->getAtVersion(Versioned::DRAFT);
    }

    /**
     * Rollback draft to a given version and data object
     *
     * @param int|string|null $version Version ID or Versioned::LIVE to rollback from live.
     * Null to rollback current owner object.
     * @param DataObject|Versioned $dataObject The object to be rolled back
     */
    public function single($version, DataObject $dataObject)
    {
        // Validate $version and safely cast
        if (isset($version) && !is_numeric($version) && $version !== Versioned::LIVE) {
            throw new InvalidArgumentException("Invalid rollback source version $version");
        }

        if (isset($version) && is_numeric($version)) {
            $version = (int) $version;
        }

        // Copy version between stage
        $dataObject->invokeWithExtensions('onBeforeRollbackSingle', $version);
        $dataObject->copyVersionToStage($version, Versioned::DRAFT);
        $dataObject->invokeWithExtensions('onAfterRollbackSingle', $version);
    }
}
