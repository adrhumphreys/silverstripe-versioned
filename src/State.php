<?php

namespace SilverStripe\Versioned;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * The intention here is to provide methods to find the state of data objects
 * Calling this should be done through State::singleton() or Injector::inst()->get(State::class);
 */
class State
{

    use Injectable;

    /*
     * Returns whether the current record is the latest one.
     *
     * @see Backend::getLatestVersion()
     */
    public function isLatestVersion(DataObject $dataObject): bool
    {
        if (!$dataObject->isInDB()) {
            return false;
        }

        /** @var Versioned|DataObject $version */
        $latestVersionedObject = Backend::singleton()->getLatestVersion(get_class($dataObject), $dataObject->ID);

        return ($latestVersionedObject->Version == $dataObject->Version);
    }

    /*
     * Returns whether the current record's version is the current live/published version
     */
    public function isLiveVersion(DataObject $dataObject): bool
    {
        $id = $dataObject->ID ?: $dataObject->OldID;

        if (!$id || !State::singleton()->isPublished($dataObject)) {
            return false;
        }

        $liveVersionNumber = Backend::singleton()
            ->getVersionNumberByStage($dataObject, VERSIONED::LIVE, $id);

        return $liveVersionNumber == $dataObject->Version;
    }

    /*
     * Check if this record exists on live
     */
    public function isPublished(DataObject $dataObject): bool
    {
        $id = $dataObject->ID ?: $dataObject->OldID;

        if (!$id) {
            return false;
        }

        // Non-staged objects are considered "published" if saved
        // @TODO: hasStages
        if (!$this->hasStages()) {
            return true;
        }

        $liveVersion = Backend::singleton()
            ->getVersionNumberByStage(get_class($dataObject), Versioned::LIVE, $id);

        return (bool) $liveVersion;
    }

    /*
     * Returns whether the current record's version is the current draft/modified version
     */
    public function isLatestDraftVersion(DataObject $dataObject): bool
    {
        $id = $dataObject->ID ?: $dataObject->OldID;

        // @TODO: onDraft
        if (!$id || !State::singleton()->isOnDraft($dataObject)) {
            return false;
        }

        $draftVersionNumber = Backend::singleton()
            ->getVersionNumberByStage($dataObject, Versioned::DRAFT, $id);

        return $draftVersionNumber == $dataObject->Version;
    }

    /*
     * Check if page doesn't exist on any stage, but used to be
     */
    public function isArchived(DataObject $dataObject): bool
    {
        $id = $dataObject->ID ?: $dataObject->OldID;

        if (!$id) {
            return false;
        }

        $published = State::singleton()->isPublished($dataObject);

        return !State::singleton()->isOnDraft($dataObject) && !$published;
    }

    /*
     * Check if this record exists on the draft stage
     */
    public function isOnDraft(DataObject $dataObject): bool
    {
        $id = $dataObject->ID ?: $dataObject->OldID;

        if (!$id) {
            return false;
        }

        //TODO: hasstages
        if (!State::singleton()->hasStages($dataObject)) {
            return true;
        }

        $draftVersion = Backend::singleton()
            ->getVersionNumberByStage(get_class($dataObject), Versioned::DRAFT, $id);

        return (bool) $draftVersion;
    }

    /*
     * Compares current draft with live version, and returns true if no draft version of this page exists  but the page
     * is still published (eg, after triggering "Delete from draft site" in the CMS).
     */
    public function isOnLiveOnly(DataObject $dataObject): bool
    {
        return State::singleton()->isPublished($dataObject) && !State::singleton()->isOnDraft($dataObject);
    }

    /*
     * Compares current draft with live version, and returns true if no live version exists, meaning the page was never
     * published.
     */
    public function isOnDraftOnly(DataObject $dataObject): bool
    {
        return State::singleton()->isOnDraft($dataObject) && !State::singleton()->isPublished($dataObject);
    }

    /*
     * Compares current draft with live version, and returns true if these versions differ, meaning there have been
     * unpublished changes to the draft site.
     */
    public function isModifiedOnDraft(DataObject $dataObject): bool
    {
        // TODO: stagesDiffer
        return State::singleton()->isOnDraft($dataObject) && $this->stagesDiffer();
    }

    /*
     * Returns an array of possible stages.
     */
    public function getVersionedStages(DataObject $dataObject): array
    {
        // TODO: hasStages
        if ($dataObject->hasStages()) {
            return [Versioned::DRAFT, Versioned::LIVE];
        }

        return [Versioned::DRAFT];
    }

    /**
     * Get author of this record.
     * Note: Only works on records selected via Versions()
     *
     * @param DataObject|Versioned $dataObject
     * @return Member|null
     */
    public function getAuthor(DataObject $dataObject): ?Member
    {
        if (!$dataObject->AuthorID) {
            return null;
        }

        return Member::get_by_id($dataObject->AuthorID);
    }

    /**
     * Get publisher of this record.
     * Note: Only works on records selected via Versions()
     *
     * @param DataObject|Versioned $dataObject
     * @return Member|null
     */
    public function getPublisher(DataObject $dataObject): ?Member
    {
        if (!$dataObject->PublisherID) {
            return null;
        }

        return Member::get_by_id($dataObject->PublisherID);
    }
}
