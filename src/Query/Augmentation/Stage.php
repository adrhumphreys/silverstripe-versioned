<?php

namespace SilverStripe\Versioned\Query\Augmentation;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Query\Table;
use SilverStripe\Versioned\ReadingMode;
use SilverStripe\Versioned\State;
use SilverStripe\Versioned\Versioned;

class Stage implements Augmentation
{
    public function augment(DataObject $dataObject, SQLSelect $query, DataQuery $dataQuery): void
    {
        if (!State::singleton()->hasStages()) {
            return;
        }

        $stage = $dataQuery->getQueryParam('Versioned.stage');
        ReadingMode::validateStage($stage);

        if ($stage === Versioned::DRAFT) {
            return;
        }

        // Rewrite all tables to select from the live version
        foreach ($query->getFrom() as $table => $dummy) {
            if (!Table::singleton()->isVersioned($table, get_class($dataObject))) {
                continue;
            }

            $stageTable = Table::singleton()->getStageTable($table, $stage);
            $query->renameTable($table, $stageTable);
        }
    }
}
