<?php

namespace SilverStripe\Versioned\Query\Augmentation;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Query\Table;
use SilverStripe\Versioned\State;
use SilverStripe\Versioned\Versioned;

/*
 * Reading a specific stage, but only return items that aren't in any other stage
 */
class StageUnique implements Augmentation
{

    use Injectable;

    public function augment(DataObject $dataObject, SQLSelect $query, DataQuery $dataQuery): void
    {
        if (!State::singleton()->hasStages()) {
            return;
        }

        // Set stage first
        Stage::singleton()->augment($dataObject, $query, $dataQuery);

        // Now exclude any ID from any other stage.
        $stage = $dataQuery->getQueryParam('Versioned.stage');
        $excludingStage = $stage === Versioned::DRAFT
            ? Versioned::LIVE
            : Versioned::DRAFT;

        // Note that we double rename to avoid the regular stage rename
        // renaming all subquery references to be Versioned.stage
        $tempName = 'ExclusionarySource_' . $excludingStage;
        $excludingTable = Table::singleton()->getBaseTable($dataObject, $excludingStage);
        $baseTable = Table::singleton()->getBaseTable($dataObject, $stage);
        $query->addWhere("\"{$baseTable}\".\"ID\" NOT IN (SELECT \"ID\" FROM \"{$tempName}\")");
        $query->renameTable($tempName, $excludingTable);
    }
}
