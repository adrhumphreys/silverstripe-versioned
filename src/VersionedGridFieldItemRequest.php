<?php

namespace SilverStripe\Versioned;

use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\View\ArrayData;

/**
 * Provides versioned dataobject support to {@see GridFieldDetailForm_ItemRequest}
 *
 * @property GridFieldDetailForm_ItemRequest $owner
 */
class VersionedGridFieldItemRequest extends GridFieldDetailForm_ItemRequest
{

    public function Breadcrumbs($unlinked = false)
    {
        $items = parent::Breadcrumbs($unlinked);
        $status = $this->getRecordStatus();
        if ($status) {
            // Generate badge
            $badge = DBField::create_field('HTMLFragment', sprintf(
                '<span class="badge version-status version-status--%s">%s</span>',
                $status['class'],
                $status['title']
            ));
            /** @var ArrayData $lastItem */
            $lastItem = $items->last();
            $lastItem->setField('Extra', $badge);
        }
        return $items;
    }

    protected function getFormActions()
    {
        // Check if record is versionable
        /** @var Versioned|RecursivePublishable|DataObject $record */
        $record = $this->getRecord();
        $ownerIsVersioned = $record && $record->hasExtension(Versioned::class);
        $ownerIsPublishable = $record && $record->hasExtension(RecursivePublishable::class);
        if (!$record || !($ownerIsVersioned || $ownerIsPublishable)) {
            return parent::getFormActions();
        }
        // Add extra actions prior to extensions so that these can be modified too
        $this->beforeExtending(
            'updateFormActions',
            function (FieldList $actions) use ($record, $ownerIsVersioned) {
                if ($ownerIsVersioned) {
                    $this->addVersionedButtons($record, $actions);
                } else {
                    $this->addUnversionedButtons($record, $actions);
                }
            }
        );

        return parent::getFormActions();
    }

    /**
     * Ensures that an unversioned object calls publishRecursive() to its ownees
     * @param array $data
     * @param Form $form
     */
    public function saveFormIntoRecord($data, $form)
    {
        $record = parent::saveFormIntoRecord($data, $form);
        $record->publishRecursive();
    }

    /**
     * Archive this versioned record
     *
     * @param array $data
     * @param Form $form
     * @return HTTPResponse
     */
    public function doArchive($data, $form)
    {
        /** @var Versioned|DataObject $record */
        $record = $this->getRecord();
        if (!$record->canArchive()) {
            return $this->httpError(403);
        }

        // Record name before it's deleted
        $title = $record->Title;
        $record->doArchive();

        $message = _t(
            __CLASS__ . '.Archived',
            'Archived {name} {title}',
            [
                'name' => $record->i18n_singular_name(),
                'title' => Convert::raw2xml($title)
            ]
        );
        $this->setFormMessage($form, $message);

        //when an item is deleted, redirect to the parent controller
        $controller = $this->getToplevelController();
        $controller->getRequest()->addHeader('X-Pjax', 'Content'); // Force a content refresh

        return $controller->redirect($this->getBackLink(), 302); //redirect back to admin section
    }

    /**
     * Publish this versioned record
     *
     * @param array $data
     * @param Form $form
     * @return HTTPResponse
     */
    public function doPublish($data, $form)
    {
        /** @var Versioned|RecursivePublishable|DataObject $record */
        $record = $this->getRecord();
        $isNewRecord = $record->ID == 0;

        // Check permission
        if (!$record->canPublish()) {
            return $this->httpError(403);
        }

        // Initial save and reload
        $record = $this->saveFormIntoRecord($data, $form);
        $record->publishRecursive();
        $editURL = $this->Link('edit');
        $xmlTitle = Convert::raw2xml($record->Title);
        $link = "<a href=\"{$editURL}\">{$xmlTitle}</a>";
        $message = _t(
            __CLASS__ . '.Published',
            'Published {name} {link}',
            [
                'name' => $record->i18n_singular_name(),
                'link' => $link
            ]
        );
        $this->setFormMessage($form, $message);

        return $this->redirectAfterSave($isNewRecord);
    }

    /**
     * Delete this record from the live site
     *
     * @param array $data
     * @param Form $form
     * @return HTTPResponse
     */
    public function doUnpublish($data, $form)
    {
        /** @var Versioned|DataObject $record */
        $record = $this->getRecord();
        if (!$record->canUnpublish()) {
            return $this->httpError(403);
        }

        // Record name before it's deleted
        $title = $record->Title;
        $record->doUnpublish();

        $message = _t(
            __CLASS__ . '.Unpublished',
            'Unpublished {name} {title}',
            [
                'name' => $record->i18n_singular_name(),
                'title' => Convert::raw2xml($title)
            ]
        );
        $this->setFormMessage($form, $message);

        // Redirect back to edit
        return $this->redirectAfterSave(false);
    }

    /**
     * @param Form $form
     * @param string $message
     */
    protected function setFormMessage($form, $message)
    {
        $form->sessionMessage($message, 'good', ValidationResult::CAST_HTML);
        $controller = $this->getToplevelController();
        if ($controller->hasMethod('getEditForm')) {
            /** @var Form $backForm */
            $backForm = $controller->getEditForm();
            $backForm->sessionMessage($message, 'good', ValidationResult::CAST_HTML);
        }
    }

    /**
     * Return list of class / title to add on the end of record status in breadcrumbs
     *
     * @return array|null
     */
    protected function getRecordStatus()
    {
        /** @var DataObject|Versioned $record */
        $record = $this->record;

        // No status if un-versioned
        if (!$this->record->hasExtension(Versioned::class)) {
            return null;
        }

        if ($record->isOnDraftOnly()) {
            return [
                'class' => 'addedtodraft',
                'title' => _t(__CLASS__ . '.DRAFT', 'Draft')
            ];
        }

        if ($record->isModifiedOnDraft()) {
            return [
                'class' => 'modified',
                'title' => _t(__CLASS__ . '.MODIFIED', 'Modified')
            ];
        }

        return null;
    }

    protected function addVersionedButtons(DataObject $record, FieldList $actions)
    {
        // Save & Publish action
        /* @var DataObject|Versioned|RecursivePublishable $record */
        if ($record->canPublish()) {
            // "publish", as with "save", it supports an alternate state to show when action is needed.
            $publish = FormAction::create(
                'doPublish',
                _t(__CLASS__ . '.BUTTONPUBLISH', 'Publish')
            )
                ->setUseButtonTag(true)
                ->addExtraClass('btn btn-primary font-icon-rocket');

            // Insert after save
            if ($actions->fieldByName('action_doSave')) {
                $actions->insertAfter('action_doSave', $publish);
            } else {
                $actions->push($publish);
            }
        }

        // Unpublish action
        $isPublished = $record->isPublished();
        if ($isPublished && $record->canUnpublish()) {
            $actions->push(
                FormAction::create(
                    'doUnpublish',
                    _t(__CLASS__ . '.BUTTONUNPUBLISH', 'Unpublish')
                )
                    ->setUseButtonTag(true)
                    ->setDescription(_t(
                        __CLASS__ . '.BUTTONUNPUBLISHDESC',
                        'Remove this record from the published site'
                    ))
                    ->addExtraClass('btn-secondary')
            );
        }

        // Archive action
        if ($record->canArchive()) {
            // Replace "delete" action
            $actions->removeByName('action_doDelete');

            // "archive"
            $actions->push(
                FormAction::create('doArchive', _t(__CLASS__ . '.ARCHIVE', 'Archive'))
                    ->setDescription(_t(
                        __CLASS__ . '.BUTTONARCHIVEDESC',
                        'Unpublish and send to archive'
                    ))
                    ->addExtraClass('delete btn-secondary')
            );
        }
    }

    protected function addUnversionedButtons(DataObject $record, FieldList $actions)
    {
        if (!$record->canEdit()) {
            return;
        }

        /* @var FormAction $action */
        $action = $actions->fieldByName('action_doSave');

        if (!$action) {
            return;
        }

        $warn = !empty($record->config()->get('owns'));
        $action->setTitle(_t(
            __CLASS__ . '.BUTTONAPPLYCHANGES',
            'Apply changes'
        ))->addExtraClass('btn-primary font-icon-save');

        if ($warn) {
            $actions->push(LiteralField::create(
                'warning',
                '<span class="btn actions-warning font-icon-info-circled">'
                    ._t(
                        __CLASS__ . '.WARNINGAPPLYCHANGES',
                        'Draft/modified items will be published'
                    )
                .'</span>'
            ));
        }
    }
}
