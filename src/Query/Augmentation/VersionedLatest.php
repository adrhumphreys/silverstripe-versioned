<?php

namespace SilverStripe\Versioned\Query\Augmentation;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Query\Helper;
use SilverStripe\Versioned\Query\Table;

/*
 * Return latest version instances, regardless of whether they are on a particular stage.
 * This provides "show all, including deleted" functionality.
 *
 * Note: latest_version ignores deleted versions, and will select the latest non-deleted
 * version.
 */
class VersionedLatest implements Augmentation
{

    use Injectable;

    public function augment(DataObject $dataObject, SQLSelect $query, DataQuery $dataQuery): void
    {
        // Query against _Versions table first
        Versioned::singleton()->augment($dataObject, $query);

        // Join and select only latest version
        $baseTable = Table::singleton()->getBaseTable($dataObject);
        $subSelect = Helper::singleton()->prepareMaxVersionSubSelect($dataObject, $query, $dataQuery);

        $subSelect->addWhere("\"{$baseTable}_Versions_Latest\".\"WasDeleted\" = 0");

        if (Helper::singleton()->shouldApplySubSelectAsCondition($dataObject, $query)) {
            $subSelect->addWhere(
                "\"{$baseTable}_Versions_Latest\".\"RecordID\" = \"{$baseTable}_Versions\".\"RecordID\""
            );

            $query->addWhere([
                "\"{$baseTable}_Versions\".\"Version\" = ({$subSelect->sql($params)})" => $params,
            ]);

            return;
        }

        $subSelect->addSelect("\"{$baseTable}_Versions_Latest\".\"RecordID\"");
        $subSelect->addGroupBy("\"{$baseTable}_Versions_Latest\".\"RecordID\"");

        // Join on latest version filtered by date
        $query->addInnerJoin(
            '(' . $subSelect->sql($params) . ')',
            <<<SQL
            "{$baseTable}_Versions_Latest"."RecordID" = "{$baseTable}_Versions"."RecordID"
            AND "{$baseTable}_Versions_Latest"."LatestVersion" = "{$baseTable}_Versions"."Version"
SQL
            ,
            "{$baseTable}_Versions_Latest",
            20,
            $params
        );
    }
}
