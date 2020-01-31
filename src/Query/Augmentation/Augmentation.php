<?php

namespace SilverStripe\Versioned\Query\Augmentation;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;

interface Augmentation
{
    public function augment(DataObject $dataObject, SQLSelect $query, DataQuery $dataQuery): void;
}
