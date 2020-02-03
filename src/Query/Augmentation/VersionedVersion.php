<?php

namespace SilverStripe\Versioned\Query\Augmentation;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Query\Table;

/*
 * If selecting a specific version, filter it here
 */
class VersionedVersion implements Augmentation
{

    use Injectable;

    public function augment(DataObject $dataObject, SQLSelect $query, DataQuery $dataQuery): void
    {
        $version = $dataQuery->getQueryParam('Versioned.version');

        if (!$version) {
            throw new InvalidArgumentException("Invalid version");
        }

        // Query against _Versions table first
        Versioned::singleton()->augment($dataObject, $query);

        // Add filter on version field
        $baseTable = Table::singleton()->getBaseTable($dataObject);
        $query->addWhere([
            "\"{$baseTable}_Versions\".\"Version\"" => $version,
        ]);
    }
}
