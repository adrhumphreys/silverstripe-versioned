<?php

namespace SilverStripe\Versioned;

use InvalidArgumentException;
use LogicException;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Cookie;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Resettable;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Query\Actions;
use SilverStripe\Versioned\Query\Augmentation\Archive;
use SilverStripe\Versioned\Query\Augmentation\AugmentationExtension;
use SilverStripe\Versioned\Query\Augmentation\Stage;
use SilverStripe\Versioned\Query\Augmentation\StageUnique;
use SilverStripe\Versioned\Query\Augmentation\Versioned as VersionedAugmentation;
use SilverStripe\Versioned\Query\Augmentation\VersionedAll;
use SilverStripe\Versioned\Query\Augmentation\VersionedLatest;
use SilverStripe\Versioned\Query\Augmentation\VersionedLatestSingle;
use SilverStripe\Versioned\Query\Augmentation\VersionedVersion;
use SilverStripe\Versioned\Query\Helper;
use SilverStripe\Versioned\Query\Table;
use SilverStripe\Versioned\State\Site;
use SilverStripe\View\TemplateGlobalProvider;

/**
 * The Versioned extension allows your DataObjects to have several versions,
 * allowing you to rollback changes and view history. An example of this is
 * the pages used in the CMS.
 *
 * Note: This extension relies on the object also having the {@see Ownership} extension applied.
 *
 * @property int $Version
 * @property DataObject|RecursivePublishable|Versioned $owner
 * @property int $AuthorID
 * @property int $PublisherID
 * @mixin RecursivePublishable
 */
class Versioned extends DataExtension implements Resettable
{
    /**
     * The default reading mode
     */
    const DEFAULT_MODE = 'Stage.Live';

    /**
     * Constructor arg to specify that staging is active on this record.
     * 'Staging' implies that 'Versioning' is also enabled.
     */
    const STAGEDVERSIONED = 'StagedVersioned';

    /**
     * Constructor arg to specify that versioning only is active on this record.
     */
    const VERSIONED = 'Versioned';

    /**
     * The Public stage.
     */
    const LIVE = 'Live';

    /**
     * The draft (default) stage
     */
    const DRAFT = 'Stage';

    /**
     * Field used to hold the migrating version
     */
    const MIGRATING_VERSION = 'MigratingVersion';

    /**
     * Field used to hold flag indicating the next write should be without a new version
     */
    const NEXT_WRITE_WITHOUT_VERSIONED = 'NextWriteWithoutVersioned';

    /**
     * Prevents delete() from creating a _Versions record (in case this must be deferred)
     * Best used with suppressDeleteVersion()
     */
    const DELETE_WRITES_VERSION_DISABLED = 'DeleteWritesVersionDisabled';

    /**
     * Ensure versioned page doesn't attempt to virtualise these non-db fields
     *
     * @config
     * @var array
     */
    private static $non_virtual_fields = [
        self::MIGRATING_VERSION,
        self::NEXT_WRITE_WITHOUT_VERSIONED,
        self::DELETE_WRITES_VERSION_DISABLED,
    ];

    /**
     * Additional database columns for the new
     * "_Versions" table. Used in {@link augmentDatabase()}
     * and all Versioned calls extending or creating
     * SELECT statements.
     *
     * @var array $db_for_versions_table
     */
    private static $db_for_versions_table = [
        "RecordID" => "Int",
        "Version" => "Int",
        "WasPublished" => "Boolean",
        "WasDeleted" => "Boolean",
        "WasDraft" => "Boolean",
        "AuthorID" => "Int",
        "PublisherID" => "Int"
    ];

    /**
     * Ensure versioned records cast extra fields properly
     *
     * @config
     * @var array
     */
    private static $casting = [
        "RecordID" => "Int",
        "WasPublished" => "Boolean",
        "WasDeleted" => "Boolean",
        "WasDraft" => "Boolean",
        "AuthorID" => "Int",
        "PublisherID" => "Int"
    ];

    /**
     * @var array
     * @config
     */
    private static $db = [
        'Version' => 'Int'
    ];

    /**
     * Used to enable or disable the prepopulation of the version number cache.
     * Defaults to true.
     *
     * @config
     * @var boolean
     */
    private static $prepopulate_versionnumber_cache = true;

    /**
     * Indicates whether augmentSQL operations should add subselects as WHERE conditions instead of INNER JOIN
     * intersections. Performance of the INNER JOIN scales on the size of _Versions tables where as the condition scales
     * on the number of records being returned from the base query.
     *
     * @config
     * @var bool
     */
    private static $use_conditions_over_inner_joins = false;

    /**
     * Additional database indexes for the new
     * "_Versions" table. Used in {@link augmentDatabase()}.
     *
     * @var array $indexes_for_versions_table
     */
    private static $indexes_for_versions_table = [
        'RecordID_Version' => [
            'type' => 'index',
            'columns' => ['RecordID', 'Version'],
        ],
        'RecordID' => [
            'type' => 'index',
            'columns' => ['RecordID'],
        ],
        'Version' => [
            'type' => 'index',
            'columns' => ['Version'],
        ],
        'AuthorID' => [
            'type' => 'index',
            'columns' => ['AuthorID'],
        ],
        'PublisherID' => [
            'type' => 'index',
            'columns' => ['PublisherID'],
        ],
    ];


    /**
     * An array of DataObject extensions that may require versioning for extra tables
     * The array value is a set of suffixes to form these table names, assuming a preceding '_'.
     * E.g. if Extension1 creates a new table 'Class_suffix1'
     * and Extension2 the tables 'Class_suffix2' and 'Class_suffix3':
     *
     *  $versionableExtensions = array(
     *      'Extension1' => 'suffix1',
     *      'Extension2' => array('suffix2', 'suffix3'),
     *  );
     *
     * This can also be manipulated by updating the current loaded config
     *
     * SiteTree:
     *   versionableExtensions:
     *     - Extension1:
     *       - suffix1
     *       - suffix2
     *     - Extension2:
     *       - suffix1
     *       - suffix2
     *
     * or programatically:
     *
     *  Config::modify()->merge($this->owner->class, 'versionableExtensions',
     *  array('Extension1' => 'suffix1', 'Extension2' => array('suffix2', 'suffix3')));
     *
     *
     * Your extension must implement VersionableExtension interface in order to
     * apply custom tables for versioned.
     *
     * @config
     * @var array
     */
    private static $versionableExtensions = [];

    /**
     * Permissions necessary to view records outside of the live stage (e.g. archive / draft stage).
     *
     * @config
     * @var array
     */
    private static $non_live_permissions = ['CMS_ACCESS_LeftAndMain', 'CMS_ACCESS_CMSMain', 'VIEW_DRAFT_CONTENT'];

    /**
     * Use PHP's session storage for the "reading mode" and "unsecuredDraftSite",
     * instead of explicitly relying on the "stage" query parameter.
     * This is considered bad practice, since it can cause draft content
     * to leak under live URLs to unauthorised users, depending on HTTP cache settings.
     *
     * @config
     * @var bool
     */
    private static $use_session = false;

    /**
     * Reset static configuration variables to their default values.
     * @deprecated reset is now handled in the backend rather than here
     */
    public static function reset()
    {
        Backend::singleton()->reset();
    }

    /**
     * Amend freshly created DataQuery objects with versioned-specific
     * information.
     *
     * @param SQLSelect $query
     * @param DataQuery $dataQuery
     */
    public function augmentDataQueryCreation(SQLSelect &$query, DataQuery &$dataQuery)
    {
        // Convert reading mode to dataquery params and assign
        $args = ReadingMode::toDataQueryParams(Versioned::get_reading_mode());
        if ($args) {
            foreach ($args as $key => $value) {
                $dataQuery->setQueryParam($key, $value);
            }
        }
    }

    /**
     * Construct a new Versioned object.
     *
     * @var string $mode One of "StagedVersioned" or "Versioned".
     */
    public function __construct($mode = self::STAGEDVERSIONED)
    {
        // Handle deprecated behaviour
        if ($mode === 'Stage' && func_num_args() === 1) {
            Deprecation::notice("5.0", "Versioned now takes a mode as a single parameter");
            $mode = static::VERSIONED;
        } elseif (is_array($mode) || func_num_args() > 1) {
            Deprecation::notice("5.0", "Versioned now takes a mode as a single parameter");
            $mode = func_num_args() > 1 || count($mode) > 1
                ? static::STAGEDVERSIONED
                : static::VERSIONED;
        }

        State::singleton()->setMode($mode);
    }

    /**
     * Get this record at a specific version
     *
     * @param int|string|null $from Version or stage to get at. Null mean returns self object
     * @return Versioned|DataObject
     * @deprecated use Backend::singleton()->getAtVersion($from, $this->owner)
     */
    public function getAtVersion($from)
    {
        return Backend::singleton()->getAtVersion($from, $this->owner);
    }

    /**
     * Get modified date for the given version
     *
     * @deprecated 4.2..5.0 Use getLastEditedAndStageForVersion instead
     * @param int $version
     * @return string
     */
    protected function getLastEditedForVersion($version)
    {
        Deprecation::notice('5.0', 'Use getLastEditedAndStageForVersion instead');
        $result = Backend::singleton()->getLastEditedAndStageForVersion($version, $this->owner);

        if ($result) {
            return reset($result);
        }

        return null;
    }

    /**
     * Get modified date and stage for the given version
     *
     * @param int $version
     * @return array A list containing 0 => LastEdited, 1 => Stage
     * @deprecated use Backend::singleton()->getLastEditedAndStageForVersion($version, $dataObject)
     */
    protected function getLastEditedAndStageForVersion($version)
    {
        return Backend::singleton()->getLastEditedAndStageForVersion($version, $this->owner);
    }

    /**
     * Updates query parameters of relations attached to versioned dataobjects
     *
     * @param array $params
     */
    public function updateInheritableQueryParams(&$params)
    {
        // Skip if versioned isn't set
        if (!isset($params['Versioned.mode'])) {
            return;
        }

        // Adjust query based on original selection criterea
        switch ($params['Versioned.mode']) {
            case 'all_versions':
            {
                // Versioned.mode === all_versions doesn't inherit very well, so default to stage
                $params['Versioned.mode'] = 'stage';
                $params['Versioned.stage'] = static::DRAFT;
                break;
            }
            case 'version':
            {
                // If we selected this object from a specific version, we need
                // to find the date this version was published, and ensure
                // inherited queries select from that date.
                $version = $params['Versioned.version'];
                $dateAndStage = Backend::singleton()
                    ->getLastEditedAndStageForVersion($version, $this->owner);

                // Filter related objects at the same date as this version
                unset($params['Versioned.version']);
                if ($dateAndStage) {
                    list($date, $stage) = $dateAndStage;
                    $params['Versioned.mode'] = 'archive';
                    $params['Versioned.date'] = $date;
                    $params['Versioned.stage'] = $stage;
                } else {
                    // Fallback to default
                    $params['Versioned.mode'] = 'stage';
                    $params['Versioned.stage'] = static::DRAFT;
                }
                break;
            }
        }
    }

    /**
     * Augment the the SQLSelect that is created by the DataQuery
     *
     * See {@see augmentLazyLoadFields} for lazy-loading applied prior to this.
     *
     * @param SQLSelect $query
     * @param DataQuery|null $dataQuery
     * @throws InvalidArgumentException
     * @deprecated use Base::singleton()->augmentSQL($dataObject, $query, $dataQuery);
     */
    public function augmentSQL(SQLSelect $query, DataQuery $dataQuery = null)
    {
        // Ideally in a newer release we can change use the extension as an extension
        AugmentationExtension::singleton()->augmentSQL($this->owner, $query, $dataQuery);
    }

    /**
     * Reading a specific stage (Stage or Live)
     *
     * @param SQLSelect $query
     * @param DataQuery $dataQuery
     * @deprecated use Stage::singleton()->augment($this->owner, $query, $dataQuery)
     */
    protected function augmentSQLStage(SQLSelect $query, DataQuery $dataQuery)
    {
        Stage::singleton()->augment($this->owner, $query, $dataQuery);
    }

    /**
     * Reading a specific stage, but only return items that aren't in any other stage
     *
     * @param SQLSelect $query
     * @param DataQuery $dataQuery
     * @deprecated use StageUnique::singleton()->augment($this->owner, $query, $dataQuery)
     */
    protected function augmentSQLStageUnique(SQLSelect $query, DataQuery $dataQuery)
    {
        StageUnique::singleton()->augment($this->owner, $query, $dataQuery);
    }

    /**
     * Augment SQL to select from `_Versions` table instead.
     *
     * @param SQLSelect $query
     * @deprecated use VersionedAugmentation::singleton()->augment($this->owner, $query, null);
     */
    protected function augmentSQLVersioned(SQLSelect $query)
    {
        VersionedAugmentation::singleton()->augment($this->owner, $query, null);
    }

    /**
     * Prepare a sub-select for determining latest versions of records on the base table. This is used as either an
     * inner join or sub-select on the base query
     *
     * @param SQLSelect $baseQuery
     * @param DataQuery $dataQuery
     * @return SQLSelect
     * @deprecated use Helper::singleton()->prepareMaxVersionSubSelect($this->owner, $baseQuery, $dataQuery);
     */
    protected function prepareMaxVersionSubSelect(SQLSelect $baseQuery, DataQuery $dataQuery)
    {
        return Helper::singleton()->prepareMaxVersionSubSelect($this->owner, $baseQuery, $dataQuery);
    }

    /**
     * Indicates if a subquery filtering versioned records should apply as a condition instead of an inner join
     *
     * @param SQLSelect $baseQuery
     * @return bool
     * @deprecated use Helper::singleton()->shouldApplySubSelectAsCondition($dataobject, $baseQuery)
     */
    protected function shouldApplySubSelectAsCondition(SQLSelect $baseQuery)
    {
        return Helper::singleton()->shouldApplySubSelectAsCondition($this->owner, $baseQuery);
    }

    /**
     * Filter the versioned history by a specific date and archive stage
     *
     * @param SQLSelect $query
     * @param DataQuery $dataQuery
     * @deprecated use Archive::singleton()->augment($this->owner, $query, $dataQuery)
     */
    protected function augmentSQLVersionedArchive(SQLSelect $query, DataQuery $dataQuery)
    {
        Archive::singleton()->augment($this->owner, $query, $dataQuery);
    }

    /**
     * Return latest version instance, regardless of whether it is on a particular stage.
     * This is similar to augmentSQLVersionedLatest() below, except it only returns a single value
     * selected by Versioned.id
     *
     * @param SQLSelect $query
     * @param DataQuery $dataQuery
     * @deprecated use VersionedLatestSingle::singleton()->augment($this->owner, $query, $dataQuery);
     */
    protected function augmentSQLVersionedLatestSingle(SQLSelect $query, DataQuery $dataQuery)
    {
       VersionedLatestSingle::singleton()->augment($this->owner, $query, $dataQuery);
    }

    /**
     * Return latest version instances, regardless of whether they are on a particular stage.
     * This provides "show all, including deleted" functionality.
     *
     * Note: latest_version ignores deleted versions, and will select the latest non-deleted
     * version.
     *
     * @param SQLSelect $query
     * @param DataQuery $dataQuery
     * @deprecated use VersionedLatest::singleton()->augment($dataObject, $query, $dataQuery);
     */
    protected function augmentSQLVersionedLatest(SQLSelect $query, DataQuery $dataQuery)
    {
        VersionedLatest::singleton()->augment($this->owner, $query, $dataQuery);
    }

    /**
     * If selecting a specific version, filter it here
     *
     * @param SQLSelect $query
     * @param DataQuery $dataQuery
     * @deprecated use VersionedVersion::singleton()->augment($this->owner, $query, $dataQuery);
     */
    protected function augmentSQLVersionedVersion(SQLSelect $query, DataQuery $dataQuery)
    {
        VersionedVersion::singleton()->augment($this->owner, $query, $dataQuery);
    }

    /**
     * If all versions are requested, ensure that records are sorted by this field
     *
     * @param SQLSelect $query
     * @deprecated use VersionedAll::singleton()->augment($dataObject, $query)
     */
    protected function augmentSQLVersionedAll(SQLSelect $query)
    {
        VersionedAll::singleton()->augment($this->owner, $query);
    }

    /**
     * Determine if the given versioned table is a part of the sub-tree of the current dataobject
     * This helps prevent rewriting of other tables that get joined in, in particular, many_many tables
     *
     * @param string $table
     * @return bool True if this table should be versioned
     * @deprecated use Table::singleton()->isTableVersioned($table, get_class($this->owner))
     */
    protected function isTableVersioned($table)
    {
        return Table::singleton()->isVersioned($table, get_class($this->owner));
    }

    /**
     * For lazy loaded fields requiring extra sql manipulation, ie versioning.
     *
     * @param SQLSelect $query
     * @param DataQuery $dataQuery
     * @param DataObject $dataObject
     * @deprecated use Base::singleton()->augmentLoadLazyFields( $query, $dataQuery, $dataObject);
     */
    public function augmentLoadLazyFields(SQLSelect &$query, DataQuery &$dataQuery = null, $dataObject)
    {
        // Ideally in a newer release we can change use the extension as an extension
        AugmentationExtension::singleton()->augmentLoadLazyFields( $query, $dataQuery, $dataObject);
    }

    /**
     * @deprecated this will be moved to the AugmentationExtension
     */
    public function augmentDatabase()
    {
        // Stop gap for the extension not yet being an extension
        $augment = AugmentationExtension::singleton();
        $augment->setTempObject($this->owner);
        $augment->augmentDatabase();
    }

    /**
     * Cleanup orphaned records in the _Versions table
     *
     * @param string $baseTable base table to use as authoritative source of records
     * @param string $childTable Sub-table to clean orphans from
     * @deprecated this will be moved to the AugmentationExtension
     */
    protected function cleanupVersionedOrphans($baseTable, $childTable)
    {
        $augment = AugmentationExtension::singleton();
        $augment->setTempObject($this->owner);
        $augment->cleanupVersionedOrphans($baseTable, $childTable);
    }

    /**
     * Generates a ($table)_version DB manipulation and injects it into the current $manipulation
     *
     * @param array $manipulation Source manipulation data
     * @param string $class Class
     * @param string $table Table Table for this class
     * @param int $recordID ID of record to version
     * @param array|string $stages Stage or array of affected stages
     * @param bool $isDelete Set to true of version is created from a deletion
     * @deprecated this will be moved to the AugmentationExtension
     */
    protected function augmentWriteVersioned(&$manipulation, $class, $table, $recordID, $stages, $isDelete = false)
    {
        AugmentationExtension::singleton()
            ->augmentWriteVersioned($manipulation, $class, $table, $recordID, $stages, $isDelete);
    }

    /**
     * Rewrite the given manipulation to update the selected (non-default) stage
     *
     * @param array $manipulation Source manipulation data
     * @param string $table Name of table
     * @param int $recordID ID of record to version
     * @deprecated this will be moved to the AugmentationExtension
     */
    protected function augmentWriteStaged(&$manipulation, $table, $recordID)
    {
        AugmentationExtension::singleton()->augmentWriteStaged($manipulation, $table, $recordID);
    }

    /**
     * Adds a WasDeleted=1 version entry for this record, and records any stages
     * the deletion applies to
     *
     * @param string[]|string $stages Stage or array of affected stages
     * @deprecated this will be moved to the AugmentationExtension
     */
    protected function createDeletedVersion($stages = [])
    {
        AugmentationExtension::singleton()->createDeletedVersion($stages);
    }

    /**
     * @param array $manipulation
     * @deprecated this will be moved to the AugmentationExtension
     */
    public function augmentWrite(&$manipulation)
    {
        AugmentationExtension::singleton()->augmentWrite($manipulation);
    }

    /**
     * Perform a write without affecting the version table.
     *
     * @return int The ID of the record
     */
    public function writeWithoutVersion()
    {
        $this->setNextWriteWithoutVersion(true);

        return $this->owner->write();
    }

    /**
     *
     */
    public function onAfterWrite()
    {
        $this->setNextWriteWithoutVersion(false);
    }

    /**
     * Check if next write is without version
     *
     * @return bool
     */
    public function getNextWriteWithoutVersion()
    {
        return $this->owner->getField(self::NEXT_WRITE_WITHOUT_VERSIONED);
    }

    /**
     * Set if next write should be without version or not
     *
     * @param bool $flag
     * @return DataObject owner
     */
    public function setNextWriteWithoutVersion($flag)
    {
        return $this->owner->setField(self::NEXT_WRITE_WITHOUT_VERSIONED, $flag);
    }

    /**
     * Check if delete() should write _Version rows or not
     *
     * @return bool
     */
    public function getDeleteWritesVersion()
    {
        return !$this->owner->getField(self::DELETE_WRITES_VERSION_DISABLED);
    }

    /**
     * Set if delete() should write _Version rows
     *
     * @param bool $flag
     * @return DataObject owner
     */
    public function setDeleteWritesVersion($flag)
    {
        return $this->owner->setField(self::DELETE_WRITES_VERSION_DISABLED, !$flag);
    }

    /**
     * Helper method to safely suppress delete callback
     *
     * @param callable $callback
     * @return mixed Result of $callback()
     */
    protected function suppressDeletedVersion($callback)
    {
        $original = $this->getDeleteWritesVersion();
        try {
            $this->setDeleteWritesVersion(false);
            return $callback();
        } finally {
            $this->setDeleteWritesVersion($original);
        }
    }

    /**
     * If a write was skipped, then we need to ensure that we don't leave a
     * migrateVersion() value lying around for the next write.
     */
    public function onAfterSkippedWrite()
    {
        $this->setMigratingVersion(null);
    }

    /**
     * This function should return true if the current user can publish this record.
     * It can be overloaded to customise the security model for an application.
     *
     * Denies permission if any of the following conditions is true:
     * - canPublish() on any extension returns false
     * - canEdit() returns false
     *
     * @param Member $member
     * @return bool True if the current user can publish this record.
     */
    public function canPublish($member = null)
    {
        // Skip if invoked by extendedCan()
        if (func_num_args() > 4) {
            return null;
        }

        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, "ADMIN")) {
            return true;
        }

        // Standard mechanism for accepting permission changes from extensions
        $owner = $this->owner;
        $extended = $owner->extendedCan('canPublish', $member);
        if ($extended !== null) {
            return $extended;
        }

        // Default to relying on edit permission
        return $owner->canEdit($member);
    }

    /**
     * Check if the current user can delete this record from live
     *
     * @param null $member
     * @return mixed
     */
    public function canUnpublish($member = null)
    {
        // Skip if invoked by extendedCan()
        if (func_num_args() > 4) {
            return null;
        }

        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, "ADMIN")) {
            return true;
        }

        // Standard mechanism for accepting permission changes from extensions
        $owner = $this->owner;
        $extended = $owner->extendedCan('canUnpublish', $member);
        if ($extended !== null) {
            return $extended;
        }

        // Default to relying on canPublish
        return $owner->canPublish($member);
    }

    /**
     * Check if the current user is allowed to archive this record.
     * If extended, ensure that both canDelete and canUnpublish are extended also
     *
     * @param Member $member
     * @return bool
     */
    public function canArchive($member = null)
    {
        // Skip if invoked by extendedCan()
        if (func_num_args() > 4) {
            return null;
        }

        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // Standard mechanism for accepting permission changes from extensions
        $owner = $this->owner;
        $extended = $owner->extendedCan('canArchive', $member);
        if ($extended !== null) {
            return $extended;
        }

        // Admin permissions allow
        if (Permission::checkMember($member, "ADMIN")) {
            return true;
        }

        // Check if this record can be deleted from stage
        if (!$owner->canDelete($member)) {
            return false;
        }

        // Check if we can delete from live
        if (!$owner->canUnpublish($member)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the user can revert this record to live
     *
     * @param Member $member
     * @return bool
     */
    public function canRevertToLive($member = null)
    {
        $owner = $this->owner;

        // Skip if invoked by extendedCan()
        if (func_num_args() > 4) {
            return null;
        }

        // Can't revert if not on live
        if (!$owner->isPublished()) {
            return false;
        }

        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, "ADMIN")) {
            return true;
        }

        // Standard mechanism for accepting permission changes from extensions
        $extended = $owner->extendedCan('canRevertToLive', $member);
        if ($extended !== null) {
            return $extended;
        }

        // Default to canEdit
        return $owner->canEdit($member);
    }

    /**
     * Check if the user can restore this record to draft
     *
     * @param Member $member
     * @return bool
     */
    public function canRestoreToDraft($member = null)
    {
        $owner = $this->owner;

        // Skip if invoked by extendedCan()
        if (func_num_args() > 4) {
            return null;
        }

        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, "ADMIN")) {
            return true;
        }

        // Standard mechanism for accepting permission changes from extensions
        $extended = $owner->extendedCan('canRestoreToDraft', $member);
        if ($extended !== null) {
            return $extended;
        }

        // Default to canEdit
        return $owner->canEdit($member);
    }

    /**
     * Extend permissions to include additional security for objects that are not published to live.
     *
     * @param Member $member
     * @return bool|null
     */
    public function canView($member = null)
    {
        // Invoke default version-gnostic canView
        if ($this->owner->canViewVersioned($member) === false) {
            return false;
        }
        return null;
    }

    /**
     * Determine if there are any additional restrictions on this object for the given reading version.
     *
     * Override this in a subclass to customise any additional effect that Versioned applies to canView.
     *
     * This is expected to be called by canView, and thus is only responsible for denying access if
     * the default canView would otherwise ALLOW access. Thus it should not be called in isolation
     * as an authoritative permission check.
     *
     * This has the following extension points:
     *  - canViewDraft is invoked if Mode = stage and Stage = stage
     *  - canViewArchived is invoked if Mode = archive
     *
     * @param Member $member
     * @return bool False is returned if the current viewing mode denies visibility
     */
    public function canViewVersioned($member = null)
    {
        // Bypass when live stage
        $owner = $this->owner;

        // Bypass if site is unsecured
        if (!self::get_draft_site_secured()) {
            return true;
        }

        // Get reading mode from source query (or current mode)
        $readingParams = $owner->getSourceQueryParams()
            // Guess record mode from current reading mode instead
            ?: ReadingMode::toDataQueryParams(static::get_reading_mode());

        // If this is the live record we can view it
        if (isset($readingParams["Versioned.mode"])
            && $readingParams["Versioned.mode"] === 'stage'
            && $readingParams["Versioned.stage"] === static::LIVE
        ) {
            return true;
        }

        // Bypass if record doesn't have a live stage
        if (!$this->hasStages()) {
            return true;
        }

        // If we weren't definitely loaded from live, and we can't view non-live content, we need to
        // check to make sure this version is the live version and so can be viewed
        $latestVersion = Versioned::get_versionnumber_by_stage(get_class($owner), static::LIVE, $owner->ID);
        if ($latestVersion == $owner->Version) {
            // Even if this is loaded from a non-live stage, this is the live version
            return true;
        }

        // If stages are synchronised treat this as the live stage
        if (!$this->stagesDiffer()) {
            return true;
        }

        // Extend versioned behaviour
        $extended = $owner->extendedCan('canViewNonLive', $member);
        if ($extended !== null) {
            return (bool)$extended;
        }

        // Fall back to default permission check
        $permissions = Config::inst()->get(get_class($owner), 'non_live_permissions');
        $check = Permission::checkMember($member, $permissions);
        return (bool)$check;
    }

    /**
     * Determines canView permissions for the latest version of this object on a specific stage.
     * Usually the stage is read from {@link Versioned::current_stage()}.
     *
     * This method should be invoked by user code to check if a record is visible in the given stage.
     *
     * This method should not be called via ->extend('canViewStage'), but rather should be
     * overridden in the extended class.
     *
     * @param string $stage
     * @param Member $member
     * @return bool
     */
    public function canViewStage($stage = self::LIVE, $member = null)
    {
        return ReadingMode::withVersionedMode(function () use ($stage, $member) {
            Versioned::set_stage($stage);

            $owner = $this->owner;
            $versionFromStage = DataObject::get(get_class($owner))->byID($owner->ID);

            return $versionFromStage ? $versionFromStage->canView($member) : false;
        });
    }

    /**
     * Determine if a class is supporting the Versioned extensions (e.g.
     * $table_Versions does exists).
     *
     * @param string $class Class name
     * @return boolean
     */
    public function canBeVersioned($class)
    {
        return ClassInfo::exists($class)
            && is_subclass_of($class, DataObject::class)
            && DataObject::getSchema()->classHasTable($class);
    }

    /**
     * Check if a certain table has the 'Version' field.
     *
     * @param string $table Table name
     *
     * @return boolean Returns false if the field isn't in the table, true otherwise
     */
    public function hasVersionField($table)
    {
        // Base table has version field
        $class = DataObject::getSchema()->tableClass($table);
        return $class === DataObject::getSchema()->baseDataClass($class);
    }

    /**
     * @param string $table
     *
     * @return string
     */
    public function extendWithSuffix($table)
    {
        $owner = $this->owner;
        $versionableExtensions = (array)$owner->config()->get('versionableExtensions');

        if (count($versionableExtensions)) {
            foreach ($versionableExtensions as $versionableExtension => $suffixes) {
                if ($owner->hasExtension($versionableExtension)) {
                    /** @var VersionableExtension|Extension $ext */
                    $ext = $owner->getExtensionInstance($versionableExtension);
                    try {
                        $ext->setOwner($owner);
                        $table = $ext->extendWithSuffix($table);
                    } finally {
                        $ext->clearOwner();
                    }
                }
            }
        }

        return $table;
    }

    /**
     * Determines if the current draft version is the same as live or rather, that there are no outstanding draft changes
     *
     * @return bool
     */
    public function latestPublished()
    {
        $id = $this->owner->ID ?: $this->owner->OldID;
        if (!$id) {
            return false;
        }
        if (!$this->hasStages()) {
            return true;
        }
        $draftVersion = static::get_versionnumber_by_stage($this->owner, Versioned::DRAFT, $id);
        $liveVersion = static::get_versionnumber_by_stage($this->owner, Versioned::LIVE, $id);
        return $draftVersion === $liveVersion;
    }

    /**
     * @deprecated 4.0..5.0
     */
    public function doPublish()
    {
        Deprecation::notice('5.0', 'Use publishRecursive instead');
        return $this->owner->publishRecursive();
    }

    /**
     * Publishes this object to Live, but doesn't publish owned objects.
     *
     * User code should call {@see canPublish()} prior to invoking this method.
     *
     * @return bool True if publish was successful
     */
    public function publishSingle()
    {
        $owner = $this->owner;
        // get the last published version
        $original = null;
        if ($this->isPublished()) {
            $original = self::get_by_stage($owner->baseClass(), self::LIVE)
                ->byID($owner->ID);
        }

        // Publish it
        $owner->invokeWithExtensions('onBeforePublish', $original);
        $owner->writeToStage(static::LIVE);
        $owner->invokeWithExtensions('onAfterPublish', $original);
        return true;
    }

    /**
     * Removes the record from both live and stage
     *
     * User code should call {@see canArchive()} prior to invoking this method.
     *
     * @return bool Success
     */
    public function doArchive()
    {
        $owner = $this->owner;
        $owner->invokeWithExtensions('onBeforeArchive', $this);
        $owner->deleteFromChangeSets();
        // Unpublish without creating deleted version
        $this->suppressDeletedVersion(function () use ($owner) {
            $owner->doUnpublish();
            $owner->deleteFromStage(static::DRAFT);
        });
        // Create deleted version in both stages
        $this->createDeletedVersion([
            static::LIVE,
            static::DRAFT,
        ]);
        $owner->invokeWithExtensions('onAfterArchive', $this);
        return true;
    }

    /**
     * Removes this record from the live site
     *
     * User code should call {@see canUnpublish()} prior to invoking this method.
     *
     * @return bool Flag whether the unpublish was successful
     */
    public function doUnpublish()
    {
        $owner = $this->owner;
        // Skip if this record isn't saved
        if (!$owner->isInDB()) {
            return false;
        }

        // Skip if this record isn't on live
        if (!$owner->isPublished()) {
            return false;
        }

        $owner->invokeWithExtensions('onBeforeUnpublish');

        // Modify in isolated mode
        ReadingMode::withVersionedMode(function () use ($owner) {
            static::set_stage(static::LIVE);

            // This way our ID won't be unset
            $clone = clone $owner;
            $clone->delete();
        });

        $owner->invokeWithExtensions('onAfterUnpublish');
        return true;
    }

    public function onAfterDelete()
    {
        // Create deleted record for current stage
        $this->createDeletedVersion(static::get_stage());
    }

    /**
     * Determine if this object is published, and has any published owners.
     * If this is true, a warning should be shown before this is published.
     *
     * Note: This method returns false if the object itself is unpublished,
     * since owners are only considered on the same stage as the record itself.
     *
     * @return bool
     */
    public function hasPublishedOwners()
    {
        if (!$this->isPublished()) {
            return false;
        }
        // Count live owners
        /** @var Versioned|RecursivePublishable|DataObject $liveRecord */
        $liveRecord = static::get_by_stage(get_class($this->owner), Versioned::LIVE)->byID($this->owner->ID);
        return $liveRecord->findOwners(false)->count() > 0;
    }

    /**
     * Revert the draft changes: replace the draft content with the content on live
     *
     * User code should call {@see canRevertToLive()} prior to invoking this method.
     *
     * @return bool True if the revert was successful
     */
    public function doRevertToLive()
    {
        $owner = $this->owner;
        $owner->invokeWithExtensions('onBeforeRevertToLive');
        $owner->rollbackRecursive(static::LIVE);
        $owner->invokeWithExtensions('onAfterRevertToLive');
        return true;
    }

    /**
     * @deprecated 1.2..2.0 This extension method is redundant and will be removed
     */
    public function onAfterRevertToLive()
    {
    }

    /**
     * @deprecated 4.0..5.0
     */
    public function publish($fromStage, $toStage, $createNewVersion = true)
    {
        Deprecation::notice('5.0', 'Use copyVersionToStage instead');
        $this->owner->copyVersionToStage($fromStage, $toStage, true);
    }

    /**
     * Move a database record from one stage to the other.
     *
     * @param int|string|null $fromStage Place to copy from.  Can be either a stage name or a version number.
     * Null copies current object to stage
     * @param string $toStage Place to copy to.  Must be a stage name.
     * @param bool $createNewVersion [DEPRECATED] This parameter is ignored, as copying to stage should always
     * create a new version.
     */
    public function copyVersionToStage($fromStage, $toStage, $createNewVersion = true)
    {
        // Disallow $createNewVersion = false
        if (!$createNewVersion) {
            Deprecation::notice('5.0', 'copyVersionToStage no longer allows $createNewVersion to be false');
            $createNewVersion = true;
        }
        $owner = $this->owner;
        $owner->invokeWithExtensions('onBeforeVersionedPublish', $fromStage, $toStage, $createNewVersion);

        // Get at specific version
        $from = $this->getAtVersion($fromStage);
        if (!$from) {
            $baseClass = $owner->baseClass();
            throw new InvalidArgumentException("Can't find {$baseClass}#{$owner->ID} in stage {$fromStage}");
        }

        $from->writeToStage($toStage);
        $owner->invokeWithExtensions('onAfterVersionedPublish', $fromStage, $toStage, $createNewVersion);
    }

    /**
     * Get version migrated to
     *
     * @return int|null
     */
    public function getMigratingVersion()
    {
        return $this->owner->getField(self::MIGRATING_VERSION);
    }

    /**
     * @deprecated 4.0...5.0
     * @param string $version The version.
     */
    public function migrateVersion($version)
    {
        Deprecation::notice('5.0', 'use setMigratingVersion instead');
        $this->setMigratingVersion($version);
    }

    /**
     * Set the migrating version.
     *
     * @param string $version The version.
     * @return DataObject Owner
     */
    public function setMigratingVersion($version)
    {
        return $this->owner->setField(self::MIGRATING_VERSION, $version);
    }

    /**
     * Compare two stages to see if they're different.
     *
     * Only checks the version numbers, not the actual content.
     *
     * @return bool
     * @deprecated use State::singleton()->stagesDiffer($this->owner)
     */
    public function stagesDiffer()
    {
        if (func_num_args() > 0) {
            Deprecation::notice('5.0', 'Versioned only has two stages and stagesDiffer no longer requires parameters');
        }

        return State::singleton()->stagesDiffer($this->owner);
    }

    /**
     * @param string $filter
     * @param string $sort
     * @param string $limit
     * @param string $join Deprecated, use leftJoin($table, $joinClause) instead
     * @param string $having
     * @return ArrayList
     */
    public function Versions($filter = "", $sort = "", $limit = "", $join = "", $having = "")
    {
        return $this->allVersions($filter, $sort, $limit, $join, $having);
    }

    /**
     * NOTE: Versions() will be replaced with this method in SilverStripe 5.0
     *
     * @internal
     * @deprecated 1.5.0 Will be removed in 2.0.0, use Versions() instead
     * @return DataList
     */
    public function VersionsList()
    {
        $id = $this->owner->ID ?: $this->owner->OldID;
        $class = get_class($this->owner);
        return Versioned::get_all_versions($class, $id);
    }

    /**
     * Return a list of all the versions available.
     *
     * @deprecated 1.5.0 Will be removed in 2.0.0, please use Versions() instead
     * @param  string $filter
     * @param  string $sort
     * @param  string $limit
     * @param  string $join @deprecated use leftJoin($table, $joinClause) instead
     * @param  string $having @deprecated
     * @return ArrayList
     */
    public function allVersions($filter = "", $sort = "", $limit = "", $join = "", $having = "")
    {
        // Make sure the table names are not postfixed (e.g. _Live)
        $oldMode = static::get_reading_mode();
        static::set_stage(static::DRAFT);

        $owner = $this->owner;
        $list = DataObject::get(DataObject::getSchema()->baseDataClass($owner), $filter, $sort, $join, $limit);
        if ($having) {
            // @todo - This method doesn't exist on DataList
            $list->having($having);
        }

        $query = $list->dataQuery()->query();

        $baseTable = null;
        foreach ($query->getFrom() as $table => $tableJoin) {
            if (is_string($tableJoin) && $tableJoin[0] == '"') {
                $baseTable = str_replace('"', '', $tableJoin);
            } elseif (is_string($tableJoin) && substr($tableJoin, 0, 5) != 'INNER') {
                $query->setFrom([
                    $table => "LEFT JOIN \"$table\" ON \"$table\".\"RecordID\"=\"{$baseTable}_Versions\".\"RecordID\""
                        . " AND \"$table\".\"Version\" = \"{$baseTable}_Versions\".\"Version\""
                ]);
            }
            $query->renameTable($table, $table . '_Versions');
        }

        // Add all <basetable>_Versions columns
        foreach (Config::inst()->get(static::class, 'db_for_versions_table') as $name => $type) {
            $query->selectField(sprintf('"%s_Versions"."%s"', $baseTable, $name), $name);
        }

        $query->addWhere([
            "\"{$baseTable}_Versions\".\"RecordID\" = ?" => $owner->ID
        ]);
        $query->setOrderBy(($sort) ? $sort
            : "\"{$baseTable}_Versions\".\"LastEdited\" DESC, \"{$baseTable}_Versions\".\"Version\" DESC");

        $records = $query->execute();
        $versions = new ArrayList();

        foreach ($records as $record) {
            $versions->push(new Versioned_Version($record));
        }

        Versioned::set_reading_mode($oldMode);
        return $versions;
    }

    /**
     * Compare two version, and return the diff between them.
     *
     * @param string $from The version to compare from.
     * @param string $to The version to compare to.
     *
     * @return DataObject
     */
    public function compareVersions($from, $to)
    {
        $owner = $this->owner;
        $fromRecord = Versioned::get_version(get_class($owner), $owner->ID, $from);
        $toRecord = Versioned::get_version(get_class($owner), $owner->ID, $to);

        $diff = new DataDifferencer($fromRecord, $toRecord);

        return $diff->diffedData();
    }

    /**
     * Return the base table - the class that directly extends DataObject.
     *
     * Protected so it doesn't conflict with DataObject::baseTable()
     *
     * @param string $stage
     * @return string
     * @deprecated use Table::singleton()->getBaseTable($this->owner, (string) $stage);
     */
    protected function baseTable($stage = null)
    {
        return Table::singleton()->getBaseTable($this->owner, (string) $stage);
    }

    /**
     * Given a table and stage determine the table name.
     *
     * Note: Stages this asset does not exist in will default to the draft table.
     *
     * @param string $table Main table
     * @param string $stage
     * @return string Staged table name
     * @deprecated use Table::singleton()->getStageTable($table, $stage)
     */
    public function stageTable($table, $stage)
    {
        return Table::singleton()->getStageTable($table, $stage);
    }

    //-----------------------------------------------------------------------------------------------//


    /**
     * Determine if the current user is able to set the given site stage / archive
     *
     * @param HTTPRequest $request
     * @return bool
     * @deprecated use Site::singleton()->canChooseSiteStage($request)
     */
    public static function can_choose_site_stage($request)
    {
        return Site::singleton()->canChooseSiteStage($request);
    }

    /**
     * Choose the stage the site is currently on.
     *
     * If $_GET['stage'] is set, then it will use that stage, and store it in
     * the session.
     *
     * if $_GET['archiveDate'] is set, it will use that date, and store it in
     * the session.
     *
     * If neither of these are set, it checks the session, otherwise the stage
     * is set to 'Live'.
     * @param HTTPRequest $request
     *
     * @deprecated use Site::chooseSiteStage($request)
     */
    public static function choose_site_stage(HTTPRequest $request)
    {
        Site::singleton()->chooseSiteStage($request);
    }

    /**
     * Set the current reading mode.
     *
     * @param string $mode
     * @deprecated use Backend::singleton()->setReadingMode((string) $mode);
     */
    public static function set_reading_mode($mode)
    {
        Backend::singleton()->setReadingMode((string) $mode);
    }

    /**
     * Get the current reading mode.
     *
     * @return string
     * @deprecated use Backend::singleton()->getReadingMode();
     */
    public static function get_reading_mode()
    {
        return Backend::singleton()->getReadingMode();
    }

    /**
     * Get the current reading stage.
     *
     * @return string
     * @deprecated use Backend::singleton()->getStage();
     */
    public static function get_stage()
    {
        return Backend::singleton()->getStage();
    }

    /**
     * Get the current archive date.
     *
     * @return string
     * @deprecated use Backend::singleton()->getCurrentArchivedDate()
     */
    public static function current_archived_date()
    {
        return Backend::singleton()->getCurrentArchivedDate();
    }

    /**
     * Get the current archive stage.
     *
     * @return string
     * @deprecated use Backend::singleton()->getCurrentArchivedStage()
     */
    public static function current_archived_stage()
    {
        return Backend::singleton()->getCurrentArchivedStage();
    }

    /**
     * Set the reading stage.
     *
     * @param string $stage New reading stage.
     * @throws InvalidArgumentException
     * @deprecated Backend::singleton()->setStage($stage)
     */
    public static function set_stage($stage)
    {
        Backend::singleton()->setStage((string) $stage);
    }

    /**
     * Replace default mode.
     * An non-default mode should be specified via querystring arguments.
     *
     * @param string $mode
     * @deprecated use Backend::singleton()->setDefaultReadingMode($mode);
     */
    public static function set_default_reading_mode($mode)
    {
        Backend::singleton()->setDefaultReadingMode((string) $mode);
    }

    /**
     * Get default reading mode
     *
     * @return string
     * @deprecated use Backend::singleton()->getDefaultReadingMode()
     */
    public static function get_default_reading_mode()
    {
        return Backend::singleton()->getDefaultReadingMode();
    }

    /**
     * Check if draft site should be secured.
     * Can be turned off if draft site unauthenticated
     *
     * @return bool
     * @deprecated use Backend::singleton()->getDraftSiteSecured()
     */
    public static function get_draft_site_secured()
    {
        return Backend::singleton()->getDraftSiteSecured();
    }

    /**
     * Set if the draft site should be secured or not
     *
     * @param bool $secured
     * @deprecated use Backend::singleton()->setDraftSiteSecured($secured)
     */
    public static function set_draft_site_secured($secured)
    {
        Backend::singleton()->setDraftSiteSecured((bool) $secured);
    }

    /**
     * Set the reading archive date.
     *
     * @param string $date New reading archived date.
     * @param string $stage Set stage
     * @deprecated use Backend::singleton()->setReadingArchivedDate($date, $stage);
     */
    public static function reading_archived_date($date, $stage = self::DRAFT)
    {
        Backend::singleton()->setReadingArchivedDate((string) $date, (string) $stage);
    }

    /**
     * Get a singleton instance of a class in the given stage.
     *
     * @param string $class The name of the class.
     * @param string $stage The name of the stage.
     * @param string $filter A filter to be inserted into the WHERE clause.
     * @param boolean $cache Use caching.
     * @param string $sort A sort expression to be inserted into the ORDER BY clause.
     *
     * @return DataObject
     *
     * @deprecated use Backend::singleton()->getOneByStage($class, $stage, $filter, $cache, $sort);
     */
    public static function get_one_by_stage($class, $stage, $filter = '', $cache = true, $sort = '')
    {
        return Backend::singleton()->getOneByStage((string) $class, (string) $stage, (string) $filter, (bool) $cache, (string) $sort);
    }

    /**
     * Gets the current version number of a specific record.
     *
     * @param string $class Class to search
     * @param string $stage Stage name
     * @param int $id ID of the record
     * @param bool $cache Set to true to turn on cache
     * @return int|null Return the version number, or null if not on this stage
     * @deprecated use Backend::singleton()->getVersionNumberByStage($class, $stage, $id, $cache);
     */
    public static function get_versionnumber_by_stage($class, $stage, $id, $cache = true)
    {
        return Backend::singleton()->getVersionNumberByStage((string) $class, (string) $stage, (int) $id, (bool) $cache);
    }

    /**
     * Hook into {@link Hierarchy::prepopulateTreeDataCache}.
     *
     * @param DataList|array $recordList The list of records to prepopulate caches for. Null for all records.
     * @param array $options Deprecated, this is here for legacy purposes, it is not used
     */
    public function onPrepopulateTreeDataCache($recordList = null, array $options = [])
    {
        // If a datalist is passed through them we assume that we're using the ID column
        if ($recordList instanceof DataList) {
            $recordList = $recordList->column('ID');
        }

        // Handle the case in which a user has passed through something other than an array or datalist
        if (!is_array($recordList)) {
            $recordList = [];
        }

        $class = $this->owner->baseClass();
        Backend::singleton()->prePopulateVersionNumberCache($class, Versioned::DRAFT, $recordList);
        Backend::singleton()->prePopulateVersionNumberCache($class, Versioned::LIVE, $recordList);
    }

    /**
     * Pre-populate the cache for Versioned::get_versionnumber_by_stage() for
     * a list of record IDs, for more efficient database querying.  If $idList
     * is null, then every record will be pre-cached.
     *
     * @param string $class
     * @param string $stage
     * @param array $idList
     * @deprecated use Backend::singleton()->prePopulateVersionNumberCache((string) $class, (string) $stage, (array) $idList)
     */
    public static function prepopulate_versionnumber_cache($class, $stage, $idList = null)
    {
        Backend::singleton()->prePopulateVersionNumberCache((string) $class, (string) $stage, (array) $idList);
    }

    /**
     * Get a set of class instances by the given stage.
     *
     * @param string $class The name of the class.
     * @param string $stage The name of the stage.
     * @param string $filter A filter to be inserted into the WHERE clause.
     * @param string $sort A sort expression to be inserted into the ORDER BY clause.
     * @param string $join Deprecated, use leftJoin($table, $joinClause) instead
     * @param int $limit A limit on the number of records returned from the database.
     * @param string $containerClass The container class for the result set (default is DataList)
     *
     * @return DataList A modified DataList designated to the specified stage
     * @deprecated use Backend::singleton()->getByStage($class, $stage, $filter, $sort, $join, $limit)
     */
    public static function get_by_stage(
        $class,
        $stage,
        $filter = '',
        $sort = '',
        $join = '',
        $limit = null,
        $containerClass = DataList::class
    ) {
        return Backend::singleton()->getByStage($class, $stage, $filter, $sort, $join, $limit);
    }

    /**
     * Delete this record from the given stage
     *
     * @param string $stage
     */
    public function deleteFromStage($stage)
    {
        Backend::singleton()->deleteFromStage($stage, $this->owner);
    }

    /**
     * Write the given record to the given stage.
     * Note: If writing to live, this will write to stage as well.
     *
     * @param string $stage
     * @param boolean $forceInsert
     * @return int The ID of the record
     */
    public function writeToStage($stage, $forceInsert = false)
    {
        return Backend::singleton()->writeToStage($stage, $this->owner, $forceInsert);
    }

    /**
     * Roll the draft version of this record to match the published record.
     * Caution: Doesn't overwrite the object properties with the rolled back version.
     *
     * {@see doRevertToLive()} to reollback to live
     *
     * @deprecated 4.2..5.0 Use rollbackRecursive() instead
     * @param int $version Version number
     */
    public function doRollbackTo($version)
    {
        Deprecation::notice('5.0', 'Use rollbackRecursive() instead');
        $owner = $this->owner;
        $owner->extend('onBeforeRollback', $version);
        $owner->rollbackRecursive($version);
        $owner->extend('onAfterRollback', $version);
    }

    /**
     * @deprecated 1.2..2.0 This extension method is redundant and will be removed
     */
    public function onAfterRollback()
    {
    }

    /**
     * Recursively rollback draft to the given version. This will also rollback any owned objects
     * at that point in time to the same date. Objects which didn't exist (or weren't attached)
     * to the record at the target point in time will be "unlinked", which dis-associates
     * the record without requiring a hard deletion.
     *
     * @param int|string|null $version Version ID or Versioned::LIVE to rollback from live.
     * Pass in null to rollback to the current object
     * @return DataObject|Versioned The object rolled back
     */
    public function rollbackRecursive($version = null)
    {
        return Rollback::singleton()->recursive($version, $this->owner);
    }

    /**
     * Rollback draft to a given version
     *
     * @param int|string|null $version Version ID or Versioned::LIVE to rollback from live.
     * Null to rollback current owner object.
     */
    public function rollbackSingle($version)
    {
        Rollback::singleton()->single($version, $this->owner);
    }

    /**
     * Return the latest version of the given record.
     *
     * @param string $class
     * @param int $id
     * @return DataObject
     * @deprecated use Backend::singleton()->getLatestVersion($class, $id)
     */
    public static function get_latest_version($class, $id)
    {
        return Backend::singleton()->getLatestVersion((string) $class, (int) $id);
    }

    /**
     * Returns whether the current record is the latest one.
     *
     * @return boolean
     */
    public function isLatestVersion()
    {
        return State::singleton()->isLatestVersion($this->owner);
    }

    /**
     * Returns whether the current record's version is the current live/published version
     *
     * @return bool
     */
    public function isLiveVersion()
    {
        return State::singleton()->isLiveVersion($this->owner);
    }

    /**
     * Returns whether the current record's version is the current draft/modified version
     *
     * @return bool
     */
    public function isLatestDraftVersion()
    {
        return State::singleton()->isLatestDraftVersion($this->owner);
    }

    /**
     * Check if this record exists on live
     *
     * @return bool
     */
    public function isPublished()
    {
        return State::singleton()->isPublished($this->owner);
    }

    /**
     * Check if page doesn't exist on any stage, but used to be
     *
     * @return bool
     */
    public function isArchived()
    {
        return State::singleton()->isArchived($this->owner);
    }

    /**
     * Check if this record exists on the draft stage
     *
     * @return bool
     */
    public function isOnDraft()
    {
        return State::singleton()->isOnDraft($this->owner);
    }

    /**
     * Compares current draft with live version, and returns true if no draft version of this page exists  but the page
     * is still published (eg, after triggering "Delete from draft site" in the CMS).
     *
     * @return bool
     */
    public function isOnLiveOnly()
    {
        return State::singleton()->isOnLiveOnly($this->owner);
    }

    /**
     * Compares current draft with live version, and returns true if no live version exists, meaning the page was never
     * published.
     *
     * @return bool
     */
    public function isOnDraftOnly()
    {
        return State::singleton()->isOnDraftOnly($this->owner);
    }

    /**
     * Compares current draft with live version, and returns true if these versions differ, meaning there have been
     * unpublished changes to the draft site.
     *
     * @return bool
     */
    public function isModifiedOnDraft()
    {
        return State::singleton()->isModifiedOnDraft($this->owner);
    }

    /**
     * Return the equivalent of a DataList::create() call, querying the latest
     * version of each record stored in the (class)_Versions tables.
     *
     * In particular, this will query deleted records as well as active ones.
     *
     * @param string $class
     * @param string $filter
     * @param string $sort
     * @return DataList
     *
     * @deprecated use Backend::singleton()->getIncludingDeleted($class, $filter, $sort)
     */
    public static function get_including_deleted($class, $filter = "", $sort = "")
    {
        return Backend::singleton()->getIncludingDeleted($class, $filter, $sort);
    }

    /**
     * Return the specific version of the given id.
     *
     * Caution: The record is retrieved as a DataObject, but saving back
     * modifications via write() will create a new version, rather than
     * modifying the existing one.
     *
     * @param string $class
     * @param int $id
     * @param int $version
     *
     * @return DataObject
     * @deprecated use Backend::singleton()->getVersion($class, $id, $version);
     */
    public static function get_version($class, $id, $version)
    {
        return Backend::singleton()->getVersion((string) $class, (int) $id, (int) $version);
    }

    /**
     * Return a list of all versions for a given id.
     *
     * @param string $class
     * @param int $id
     *
     * @return DataList
     * @deprecated use Backend::singleton()->getAllVersions((string) $class, (int) $id);
     */
    public static function get_all_versions($class, $id)
    {
        return Backend::singleton()->getAllVersions((string) $class, (int) $id);
    }

    /**
     * @param array $labels
     */
    public function updateFieldLabels(&$labels)
    {
        $labels['Versions'] = _t(__CLASS__ . '.has_many_Versions', 'Versions', 'Past Versions of this record');
    }

    /**
     * @param FieldList $fields
     */
    public function updateCMSFields(FieldList $fields)
    {
        // remove the version field from the CMS as this should be left
        // entirely up to the extension (not the cms user).
        $fields->removeByName('Version');
    }

    /**
     * Ensure version ID is reset to 0 on duplicate
     *
     * @param DataObject $source Record this was duplicated from
     * @param bool $doWrite
     */
    public function onBeforeDuplicate($source, $doWrite)
    {
        $this->owner->Version = 0;
    }

    /**
     * @deprecated this doesn't do anything anymore @see Cache::reset
     */
    public function flushCache(): void
    {
        // TODO: Remove this?
    }

    /**
     * Return a piece of text to keep DataObject cache keys appropriately specific.
     *
     * @return string
     */
    public function cacheKeyComponent()
    {
        $readingMode = Backend::singleton()->getReadingMode();

        return 'versionedmode-' . $readingMode;
    }

    /**
     * Returns an array of possible stages the data object can be in
     *
     * @return array
     */
    public function getVersionedStages()
    {
        return State::singleton()->getVersionedStages($this->owner);
    }

    /**
     * Check if this object has stages
     *
     * @return bool True if this object is staged
     * @deprecated use return State::singleton()->hasStages();
     */
    public function hasStages()
    {
        return State::singleton()->hasStages();
    }

    /**
     * Invoke a callback which may modify reading mode, but ensures this mode is restored
     * after completion, without modifying global state.
     *
     * The desired reading mode should be set by the callback directly
     *
     * @param callable $callback
     * @return mixed Result of $callback
     * @deprecated use ReadingState::withVersionedMode($callback)
     */
    public static function withVersionedMode($callback)
    {
        return ReadingState::withVersionedMode($callback);
    }

    /**
     * Get author of this record.
     * Note: Only works on records selected via Versions()
     *
     * @return Member|null
     */
    public function Author()
    {
        return State::singleton()->getAuthor($this->owner);
    }

    /**
     * Get publisher of this record.
     * Note: Only works on records selected via Versions()
     *
     * @return Member|null
     */
    public function Publisher()
    {
        return State::singleton()->getPublisher($this->owner);
    }
}
