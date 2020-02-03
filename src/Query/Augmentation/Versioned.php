<?php

namespace SilverStripe\Versioned\Query\Augmentation;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Query\Table;
use SilverStripe\Versioned\Versioned as VersionedExtension;

class Versioned implements Augmentation
{

    use Injectable;

    public function augment(DataObject $dataObject, SQLSelect $query, DataQuery $dataQuery = null): void
    {
        $baseTable = Table::singleton()->getBaseTable($dataObject);

        foreach ($query->getFrom() as $alias => $join) {
            if (!Table::singleton()->isVersioned($alias, get_class($dataObject))) {
                continue;
            }

            if ($alias != $baseTable) {
                // Make sure join includes version as well
                $query->setJoinFilter(
                    $alias,
                    "\"{$alias}_Versions\".\"RecordID\" = \"{$baseTable}_Versions\".\"RecordID\""
                    . " AND \"{$alias}_Versions\".\"Version\" = \"{$baseTable}_Versions\".\"Version\""
                );
            }

            // Rewrite all usages of `Table` to `Table_Versions`
            $query->renameTable($alias, $alias . '_Versions');
            // However, add an alias back to the base table in case this must later be joined.
            // See ApplyVersionFilters for example which joins _Versions back onto draft table.
            $query->renameTable($alias . '_Draft', $alias);
        }

        // Add all <basetable>_Versions columns
        foreach (Config::inst()->get(VersionedExtension::class, 'db_for_versions_table') as $name => $type) {
            $query->selectField(sprintf('"%s_Versions"."%s"', $baseTable, $name), $name);
        }

        // Alias the record ID as the row ID, and ensure ID filters are aliased correctly
        $query->selectField("\"{$baseTable}_Versions\".\"RecordID\"", "ID");
        $query->replaceText("\"{$baseTable}_Versions\".\"ID\"", "\"{$baseTable}_Versions\".\"RecordID\"");

        // However, if doing count, undo rewrite of "ID" column
        $query->replaceText(
            "count(DISTINCT \"{$baseTable}_Versions\".\"RecordID\")",
            "count(DISTINCT \"{$baseTable}_Versions\".\"ID\")"
        );

        // Filter deleted versions, which are all unqueryable
        $query->addWhere(["\"{$baseTable}_Versions\".\"WasDeleted\"" => 0]);
    }
}
