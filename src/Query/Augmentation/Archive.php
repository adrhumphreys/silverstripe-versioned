<?php

namespace SilverStripe\Versioned\Query\Augmentation;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Query\Helper;
use SilverStripe\Versioned\Query\Table;
use SilverStripe\Versioned\ReadingMode;
use SilverStripe\Versioned\State;
use SilverStripe\Versioned\Versioned as VersionedExtension;

/*
 * Filter the versioned history by a specific date and archive stage
 */
class Archive implements Augmentation
{

    use Injectable;

    public function augment(DataObject $dataObject, SQLSelect $query, ?DataQuery $dataQuery): void
    {
        $baseTable = Table::singleton()->getBaseTable($dataObject);

        $date = $dataQuery->getQueryParam('Versioned.date');

        if (!$date) {
            throw new InvalidArgumentException("Invalid archive date");
        }

        // Query against _Versions table first
        Versioned::singleton()->augment($dataObject, $query, $dataQuery);

        // Validate stage
        $stage = $dataQuery->getQueryParam('Versioned.stage');
        ReadingMode::validateStage($stage);

        $subSelect = Helper::singleton()->prepareMaxVersionSubSelect($dataObject, $query, $dataQuery);

        $subSelect->addWhere(["\"{$baseTable}_Versions_Latest\".\"LastEdited\" <= ?" => $date]);

        // Filter on appropriate stage column in addition to date
        if (State::singleton()->hasStages()) {
            $stageColumn = $stage === VersionedExtension::LIVE
                ? 'WasPublished'
                : 'WasDraft';
            $subSelect->addWhere("\"{$baseTable}_Versions_Latest\".\"{$stageColumn}\" = 1");
        }

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
