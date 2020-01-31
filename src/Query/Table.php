<?php

namespace SilverStripe\Versioned\Query;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\State;
use SilverStripe\Versioned\Versioned;

class Table
{

    use Injectable;

    /*
     * Determine if the given versioned table is a part of the sub-tree of the current data object
     * This helps prevent rewriting of other tables that get joined in, in particular, many_many tables
     */
    public function isVersioned(string $table, string $class): bool
    {
        $schema = DataObject::getSchema();
        $tableClass = $schema->tableClass($table);

        // ensure we actually have a table
        if ($tableClass === null || $tableClass === '') {
            return false;
        }

        // Check that this class belongs to the same tree
        $baseClass = $schema->baseDataClass($class);

        if (!is_a($tableClass, $baseClass, true)) {
            return false;
        }

        // Check that this isn't a derived table
        // (e.g. _Live, or a many_many table)
        $mainTable = $schema->tableName($tableClass);

        if ($mainTable !== $table) {
            return false;
        }

        return true;
    }

    /*
     * Given a table and stage determine the table name.
     * Note: Stages this asset does not exist in will default to the draft table.
     */
    public function getStageTable(string $table, string $stage)
    {
        if (State::singleton()->hasStages() && $stage === Versioned::LIVE) {
            return $table . '_' . Versioned::LIVE;
        }

        return $table;
    }
}
