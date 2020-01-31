<?php

namespace SilverStripe\Versioned\State;

use SilverStripe\Control\Cookie;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Backend;
use SilverStripe\Versioned\ReadingMode;
use SilverStripe\Versioned\Versioned;

/**
 * Intent is to allow versioned to find the state that the site is in
 */
class Site
{

    use Injectable;

    /*
     * Determine if the current user is able to set the given site stage / archive
     */
    public function canChooseSiteStage(HTTPRequest $request): bool
    {
        // Request is allowed if stage isn't being modified
        if ((!$request->getVar('stage') || $request->getVar('stage') === Versioned::LIVE)
            && !$request->getVar('archiveDate')
        ) {
            return true;
        }

        // Request is allowed if unsecuredDraftSite is enabled
        if (!Backend::singleton()->getDraftSiteSecured()) {
            return true;
        }

        // Predict if choose_site_stage() will allow unsecured draft assignment by session
        if (Config::inst()->get(Versioned::class, 'use_session')
            && $request->getSession()->get('unsecuredDraftSite')) {
            return true;
        }

        // Check permissions with member ID in session.
        $member = Security::getCurrentUser();
        $permissions = Config::inst()->get(Versioned::class, 'non_live_permissions');

        return $member && Permission::checkMember($member, $permissions);
    }

    /*
     * Choose the stage the site is currently on.
     *
     * If $_GET['stage'] is set, then it will use that stage, and store it in
     * the session.
     *
     * if $_GET['archiveDate'] is set, it will use that date, and store it in
     * the session.
     *
     * If neither of these are set, it checks the session, otherwise the stage
     * is set to 'Live'.
     */
    public function chooseSiteStage(HTTPRequest $request): void
    {
        $mode = Backend::singleton()->getDefaultReadingMode();

        // Check any pre-existing session mode
        $useSession = Config::inst()
            ->get(Versioned::class, 'use_session');

        if ($useSession) {
            // Boot reading mode from session
            $mode = $request->getSession()->get('readingMode') ?: $mode;

            // Set draft site security if disabled for this session
            if ($request->getSession()->get('unsecuredDraftSite')) {
                Backend::singleton()->setDraftSiteSecured(false);
            }
        }

        $updateSession = false;
        // Verify if querystring contains valid reading mode
        $queryMode = ReadingMode::fromQueryString($request->getVars());

        if ($queryMode) {
            $mode = $queryMode;
            $updateSession = true;
        }

        // Save reading mode
        Backend::singleton()->setReadingMode($mode);

        // Set mode if session enabled
        if ($useSession && $updateSession) {
            $request->getSession()->set('readingMode', $mode);
        }

        if (!headers_sent() && !Director::is_cli()) {
            if (Backend::singleton()->getStage() === Versioned::LIVE) {
                // clear the cookie if it's set
                if (Cookie::get('bypassStaticCache')) {
                    Cookie::force_expiry('bypassStaticCache', null, null, false, true /* httponly */);
                }
            } else {
                // set the cookie if it's cleared
                if (!Cookie::get('bypassStaticCache')) {
                    Cookie::set('bypassStaticCache', '1', 0, null, null, false, true /* httponly */);
                }
            }
        }
    }
}
