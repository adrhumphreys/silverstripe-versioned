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
        return ReadingState::withVersionedMode(static function () use ($class, $stage, $filter, $cache, $sort) {
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
     * @return DataList The objects matching the filter
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

        ReadingState::withVersionedMode(function () use ($stage, $dataObject) {
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

        return ReadingState::withVersionedMode(function () use ($stage, $forceInsert, $dataObject) {
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

    /*
     * Return the latest version of the given record.
     */
    public function getLatestVersion(string $class, int $id): ?DataObject
    {
        $baseClass = DataObject::getSchema()->baseDataClass($class);
        $list = DataList::create($baseClass)
            ->setDataQueryParam([
                "Versioned.mode" => 'latest_version_single',
                "Versioned.id" => $id
            ]);

        return $list->first();
    }

    /**
     * Return the equivalent of a DataList::create() call, querying the latest
     * version of each record stored in the (class)_Versions tables.
     *
     * In particular, this will query deleted records as well as active ones.
     *
     * @param string $class The class of objects to be returned.
     * @param string|array $filter A filter to be inserted into the WHERE clause.
     * Supports parameterised queries. See SQLSelect::addWhere() for syntax examples.
     * @param string|array $sort A sort expression to be inserted into the ORDER
     * BY clause.  If omitted, DataObject::$default_sort will be used.

     * @return DataList The objects matching the filter
     */
    public function getIncludingDeleted(string $class, $filter, $sort): DataList
    {
        return DataList::create($class)
            ->where($filter)
            ->sort($sort)
            ->setDataQueryParam("Versioned.mode", "latest_versions");
    }

    /*
     * Return the specific version of the given id.
     *
     * Caution: The record is retrieved as a DataObject, but saving back
     * modifications via write() will create a new version, rather than
     * modifying the existing one.
     */
    public function getVersion(string $class, int $id, int $version): ?DataObject
    {
        $baseClass = DataObject::getSchema()->baseDataClass($class);
        $list = DataList::create($baseClass)
            ->setDataQueryParam([
                "Versioned.mode" => 'version',
                "Versioned.version" => $version
            ]);

        return $list->byID($id);
    }

    /*
     * Return a list of all versions for a given id.
     */
    public function getAllVersions(string $class, int $id): DataList
    {
        return DataList::create($class)
            ->filter('ID', $id)
            ->setDataQueryParam('Versioned.mode', 'all_versions');
    }
}
