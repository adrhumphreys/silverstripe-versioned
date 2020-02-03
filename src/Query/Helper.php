<?php

namespace SilverStripe\Versioned\Query;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;

class Helper
{

    use Injectable;

    /*
     * Prepare a sub-select for determining latest versions of records on the base table. This is used as either an
     * inner join or sub-select on the base query
     */
    public function prepareMaxVersionSubSelect(DataObject $dataObject, SQLSelect $baseQuery, DataQuery $dataQuery): SQLSelect
    {
        $baseTable = Table::singleton()->getBaseTable($dataObject);

        // Create a sub-select that we determine latest versions
        $subSelect = SQLSelect::create(
            ['LatestVersion' => "MAX(\"{$baseTable}_Versions_Latest\".\"Version\")"],
            [$baseTable . '_Versions_Latest' => "\"{$baseTable}_Versions\""]
        );

        $subSelect->renameTable($baseTable, "{$baseTable}_Versions");

        // Determine the base table of the existing query
        $baseFrom = $baseQuery->getFrom();
        $baseTable = trim(reset($baseFrom), '"');

        // And then the name of the base table in the new query
        $newFrom = $subSelect->getFrom();
        $newTable = trim(key($newFrom), '"');

        // Parse "where" conditions to find those appropriate to be "promoted" into an inner join
        // We can ONLY promote a filter on the primary key of the base table. Any other conditions will make the
        // version returned incorrect, as we are filtering out version that may be the latest (and correct) version
        foreach ($baseQuery->getWhere() as $condition) {
            $conditionClause = key($condition);
            // Pull out the table and field for this condition. We'll skip anything we can't parse
            if (preg_match('/^"([^"]+)"\."([^"]+)"/', $conditionClause, $matches) !== 1) {
                continue;
            }

            $table = $matches[1];
            $field = $matches[2];

            if ($table !== $baseTable || $field !== 'RecordID') {
                continue;
            }

            // Rename conditions on the base table to the new alias
            $conditionClause = preg_replace(
                '/^"([^"]+)"\./',
                "\"{$newTable}\".",
                $conditionClause
            );

            $subSelect->addWhere([$conditionClause => reset($condition)]);
        }

        $shouldApplySubSelectAsCondition = Helper::singleton()->shouldApplySubSelectAsCondition($dataObject, $baseQuery);

        $dataObject->extend(
            'augmentMaxVersionSubSelect',
            $subSelect,
            $dataQuery,
            $shouldApplySubSelectAsCondition
        );

        return $subSelect;
    }

    /*
     * Indicates if a subquery filtering versioned records should apply as a condition instead of an inner join
     */
    public function shouldApplySubSelectAsCondition(DataObject $dataObject, SQLSelect $baseQuery): bool
    {
        $baseTable = Table::singleton()->getBaseTable($dataObject);

        $shouldApply =
            $baseQuery->getLimit() === 1 || Config::inst()->get(static::class, 'use_conditions_over_inner_joins');

        $dataObject->extend('updateApplyVersionedFiltersAsConditions', $shouldApply, $baseQuery, $baseTable);

        return $shouldApply;
    }
}
