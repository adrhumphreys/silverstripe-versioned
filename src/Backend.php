<?php

namespace SilverStripe\Versioned;

use InvalidArgumentException;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * This is instance of versioned that you can over write to change how the functions it calls are handled
 */
class Backend
{

    use Injectable;

    /**
     * @var string
     */
    private $readingMode = '';

    /**
     * @var string
     */
    private $defaultReadingMode = '';

    /**
     * @var bool|null
     */
    private $isDraftSiteSecured = null;

    /**
     * A cache used by getVersionNumberByStage().
     * Clear through {@link flushCache()}.
     * version (int)0 means not on this stage.
     *
     * @var array
     */
    protected $cacheVersionNumber = [];

    /**
     * Reset static configuration variables to their default values.
     */
    public function reset()
    {
        $this->setReadingMode('');
        Controller::curr()->getRequest()->getSession()->clear('readingMode');
    }

    /*
     * Set the current reading mode.
     */
    public function setReadingMode(string $readingMode): void
    {
        $this->readingMode = $readingMode;
    }

    /*
     * Get the current reading mode.
     */
    public function getReadingMode(): string
    {
        return $this->readingMode;
    }

    /**
     * Set the reading stage.
     *
     * @param string $stage
     * @throws InvalidArgumentException
     */
    public function setStage(string $stage): void
    {
        ReadingMode::validateStage($stage);
        $this->setReadingMode('Stage.' . $stage);
    }

    /*
     * Get the current reading stage.
     */
    public function getStage(): ?string
    {
        $parts = explode('.', $this->getReadingMode());

        if ($parts[0] == 'Stage') {
            return $parts[1];
        }

        return null;
    }

    /*
     * Set the reading archive date.
     */
    public function setReadingArchivedDate(string $newReadingArchivedDate, string $stage = Versioned::DRAFT): void
    {
        ReadingMode::validateStage($stage);
        $this->setReadingMode('Archive.' . $newReadingArchivedDate . '.' . $stage);
    }

    /*
     * Get the current archive date.
     */
    public function getCurrentArchivedDate(): ?string
    {
        $parts = explode('.', $this->getReadingMode());

        if ($parts[0] == 'Archive') {
            return $parts[1];
        }

        return null;
    }

    /*
     * Get the current archive stage.
     */
    public function getCurrentArchivedStage(): string
    {
        $parts = explode('.', $this->getReadingMode());

        if (sizeof($parts) === 3 && $parts[0] == 'Archive') {
            return $parts[2];
        }

        return Versioned::DRAFT;
    }

    /*
     * Replace default mode.
     * An non-default mode should be specified via querystring arguments.
     */
    public function setDefaultReadingMode(string $mode): void
    {
        $this->defaultReadingMode = $mode;
    }

    /*
     * Get default reading mode
     */
    public function getDefaultReadingMode(): string
    {
        return $this->defaultReadingMode ?: Versioned::DEFAULT_MODE;
    }

    /*
     * Set if the draft site should be secured or not
     */
    public function setDraftSiteSecured(bool $secured): void
    {
        $this->isDraftSiteSecured = $secured;
    }

    /*
     * Check if draft site should be secured.
     * Can be turned off if draft site unauthenticated
     */
    public function getDraftSiteSecured(): bool
    {
        if ($this->isDraftSiteSecured !== null) {
            return (bool) $this->isDraftSiteSecured;
        }

        return (bool) Config::inst()->get(Versioned::class, 'draft_site_secured');
    }

    /**
     * Get a singleton instance of a class in the given stage.
     *
     * @param string $class The name of the class.
     * @param string $stage The name of the stage.
     * @param string $filter A filter to be inserted into the WHERE clause.
     * @param boolean $cache Use caching.
     * @param string $sort A sort expression to be inserted into the ORDER BY clause.
     * @return DataObject
     */
    public function getOneByStage(
        string $class,
        string $stage,
        string $filter = '',
        bool $cache = true,
        string $sort = ''
    ): DataObject {
        return State::withVersionedMode(static function () use ($class, $stage, $filter, $cache, $sort) {
            Backend::singleton()->setStage($stage);
            return DataObject::get_one($class, $filter, $cache, $sort);
        });
    }

    /*
     * Gets the current version number of a specific record, returns null if no version exists
     * Be aware, this uses a hardcoded SQL query for performance
     */
    public function getVersionNumberByStage(string $class, string $stage, int $id, bool $cache = true): ?int
    {
        ReadingMode::validateStage($stage);
        $baseClass = DataObject::getSchema()->baseDataClass($class);
        $stageTable = DataObject::getSchema()->tableName($baseClass);

        if ($stage === Versioned::LIVE) {
            $stageTable .= "_{$stage}";
        }

        // cached call
        if ($cache && Cache::isCachedVersionNumber($baseClass, $stage, $id)) {
            return Cache::getCachedVersionNumber($baseClass, $stage, $id);
        }

        // get version as performance-optimized SQL query (gets called for each record in the site tree)
        $version = DB::prepared_query(
            "SELECT \"Version\" FROM \"$stageTable\" WHERE \"ID\" = ?",
            [$id]
        )->value();

        if ($cache) {
            Cache::setCacheVersionedNumber($baseClass, $stage, $id, $version ?: 0);
        }

        return $version ?: null;
    }

    public function prePopulateVersionNumberCache(string $class, string $stage, array $idList): void
    {
        ReadingMode::validateStage($stage);

        if (!Config::inst()->get(Versioned::class, 'prepopulate_versionnumber_cache')) {
            return;
        }

        /** @var Versioned|DataObject $singleton */
        $singleton = DataObject::singleton($class);
        $baseClass = $singleton->baseClass();
        $baseTable = $singleton->baseTable();
        $stageTable = $singleton->stageTable($baseTable, $stage);

        $filter = "";
        $parameters = [];

        if ($idList) {
            // Validate the ID list
            foreach ($idList as $id) {
                if (!is_numeric($id)) {
                    user_error(
                        "Bad ID passed to Versioned::prepopulate_versionnumber_cache() in \$idList: " . $id,
                        E_USER_ERROR
                    );
                }
            }
            $filter = 'WHERE "ID" IN (' . DB::placeholders($idList) . ')';
            $parameters = $idList;

            // If we are caching IDs for _all_ records then we can mark this cache as "complete" and in the case of a cache-miss
            // no subsequent call is necessary
        } else {
            Cache::markVersionNumberCacheComplete($baseClass, $stage);
        }

        $versions = DB::prepared_query("SELECT \"ID\", \"Version\" FROM \"$stageTable\" $filter", $parameters)->map();

        foreach ($versions as $id => $version) {
            Cache::setCacheVersionedNumber($baseClass, $stage, $id, $version ?: 0);
        }
    }

    /**
     * Get a set of class instances by the given stage.
     *
     * @param string $class The class of objects to be returned.
     * @param string $stage The name of the stage.
     * @param string|array $filter A filter to be inserted into the WHERE clause.
     * Supports parameterised queries. See SQLSelect::addWhere() for syntax examples.
     * @param string|array $sort A sort expression to be inserted into the ORDER
     * BY clause.  If omitted, DataObject::$default_sort will be used.
     * @param string $join Deprecated 3.0 Join clause. Use leftJoin($table, $joinClause) instead.
     * @param string|array $limit A limit expression to be inserted into the LIMIT clause.
     *
     * @return DataList The objects matching the filter, in the class specified by $containerClass
     */
    public function getByStage(
        string $class,
        string $stage,
        $filter = '',
        $sort = '',
        $join = '',
        $limit = null
    ): DataList {
        ReadingMode::validateStage($stage);
        $result = DataObject::get($class, $filter, $sort, $join, $limit);

        return $result->setDataQueryParam([
            'Versioned.mode' => 'stage',
            'Versioned.stage' => $stage
        ]);
    }

    /*
     * Delete a record from a given stage
     */
    public function deleteFromStage(string $stage, DataObject $dataObject): void
    {
        ReadingMode::validateStage($stage);

        State::withVersionedMode(function () use ($stage, $dataObject) {
            Backend::singleton()->setStage($stage);
            $clone = clone $dataObject;
            $clone->delete();
        });

        // Fix the version number cache (in case you go delete from stage and then check ExistsOnLive)
        $baseClass = $dataObject->baseClass();
        Cache::setCacheVersionedNumber($baseClass, $stage, $dataObject->ID, 0);
    }

    /*
     * Write the given record to the given stage.
     * Note: If writing to live, this will write to stage as well.
     */
    public function writeToStage(string $stage, DataObject $dataObject, bool $forceInsert = false): int
    {
        ReadingMode::validateStage($stage);

        return State::withVersionedMode(function () use ($stage, $forceInsert, $dataObject) {
            $oldParams = $dataObject->getSourceQueryParams();

            try {
                // Lazy load and reset version in current stage prior to resetting write stage
                $dataObject->forceChange();
                $dataObject->Version = null;

                // Migrate stage prior to write
                Backend::singleton()->setStage($stage);
                $dataObject->setSourceQueryParam('Versioned.mode', 'stage');
                $dataObject->setSourceQueryParam('Versioned.stage', $stage);

                // Write
                $dataObject->invokeWithExtensions('onBeforeWriteToStage', $toStage, $forceInsert);

                return $dataObject->write(false, $forceInsert);
            } finally {
                // Revert global state
                $dataObject->invokeWithExtensions('onAfterWriteToStage', $toStage, $forceInsert);
                $dataObject->setSourceQueryParams($oldParams);
            }
        });
    }

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
    public function rollbackRecursive($version, DataObject $dataObject): DataObject
    {
        $dataObject->invokeWithExtensions('onBeforeRollbackRecursive', $version);
        $this->rollbackSingle($version, $dataObject);

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
     * Rollback draft to a given version
     *
     * @param int|string|null $version Version ID or Versioned::LIVE to rollback from live.
     * Null to rollback current owner object.
     * @param DataObject|Versioned $dataObject The object to be rolled back
     */
    public function rollbackSingle($version, DataObject $dataObject)
    {
        // Validate $version and safely cast
        if (isset($version) && !is_numeric($version) && $version !== self::LIVE) {
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
