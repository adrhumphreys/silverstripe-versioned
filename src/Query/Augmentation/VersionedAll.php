<?php

namespace SilverStripe\Versioned\Query\Augmentation;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Query\Table;

/*
 * If all versions are requested, ensure that records are sorted by this field
 */
class VersionedAll implements Augmentation
{

    use Injectable;

    public function augment(DataObject $dataObject, SQLSelect $query, DataQuery $dataQuery = null): void
    {
        // Query against _Versions table first
        Versioned::singleton()->augment($dataObject, $query);

        $baseTable = Table::singleton()->getBaseTable($dataObject);
        $query->addOrderBy("\"{$baseTable}_Versions\".\"Version\"");
    }
}
