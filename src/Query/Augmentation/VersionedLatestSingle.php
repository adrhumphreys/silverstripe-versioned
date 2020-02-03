<?php

namespace SilverStripe\Versioned\Query\Augmentation;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Query\Table;

/*
 * Return latest version instance, regardless of whether it is on a particular stage.
 * This is similar to augmentSQLVersionedLatest() below, except it only returns a single value
 * selected by Versioned.id
 */
class VersionedLatestSingle implements Augmentation
{

    use Injectable;

    public function augment(DataObject $dataObject, SQLSelect $query, DataQuery $dataQuery): void
    {
        $id = $dataQuery->getQueryParam('Versioned.id');

        if (!$id) {
            throw new InvalidArgumentException("Invalid id");
        }

        // Query against _Versions table first
        Versioned::singleton()->augment($dataObject, $query);

        $baseTable = Table::singleton()->getBaseTable($dataObject);

        $query->addWhere(["\"$baseTable\".\"RecordID\"" => $id]);
        $query->setOrderBy("Version DESC");
        $query->setLimit(1);
    }
}
