<?php

/**
 * @package Resolved
 * @copyright 2010-2015 Vanilla Forums, Inc
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 */

/**
 * Resolved Plugin
 *
 * This plugin adds the ability to mark discussions and "resolved", thereby
 * closing them for further comments unless re-opened by the original author.
 *
 * Changes
 *  1.0        Initial Release
 *  1.1
 *  1.2        Commenting, spacening
 *  1.2.1      Fix DateResolved issue
 *
 * @author Matt Lincoln Russell <lincoln@vanillaforums.com>
 * @since 1.0
 */
class ResolvedPlugin extends Gdn_Plugin {

    /**
     * Get a DiscussionModel.
     *
     * @staticvar DiscussionModel $model
     * @return DiscussionModel Returns a DiscussionModel
     */
    public static function discussionModel() {
        static $model = null;
        if (!($model instanceof DiscussionModel)) {
            $model = new DiscussionModel();
        }
        return $model;
    }

    /**
     * Resolves a discussion
     *
     * @param object $discussion
     * @param int $resolve
     * @return void
     */
    public function resolve(&$discussion, $resolve) {
        $resolution = [
            'Resolved' => $resolve,
            'DateResolved' => $resolve ? Gdn_Format::toDateTime() : null,
            'ResolvedUserID' => $resolve ? Gdn::session()->UserID : null
        ];

        $discussionID = val('DiscussionID', $discussion);
        self::discussionModel()->setField($discussionID, $resolution);
        svalr('Resolved', $discussion, $resolve);
    }

    /**
     * Allow staff to Resolve via checkbox under comment form.
     *
     * @return void
     */
    public function base_afterBodyField_handler($sender, $args) {
        if (checkPermission('Plugins.Resolved.Manage')) {
            echo '<span class="ResolvedCheckbox">' .
            $sender->Form->checkBox('Resolved', t('Resolved'), ['value' => '1']) . '</span>';
        }
    }

    /**
     * Allow staff to Resolve via discussion options.
     *
     * @return void
     */
    public function base_discussionOptions_handler($sender, $args) {
        $discussion = $args['Discussion'];
        $resolved = val('Resolved', $discussion);
        $newResolved = (int) !$resolved;
        if (checkPermission('Plugins.Resolved.Manage')) {
            $label = t($resolved ? 'Unresolve' : 'Resolve');
            $url = "/discussion/resolve?discussionid={$discussion->DiscussionID}&resolve={$newResolved}";
            // Deal with inconsistencies in how options are passed
            if (isset($sender->Options)) {
                $sender->Options .= wrap(anchor($label, $url, 'ResolveDiscussion Hijack'), 'li');
            } else {
                $args['DiscussionOptions']['ResolveDiscussion'] = [
                    'Label' => $label,
                    'Url' => $url,
                    'Class' => 'ResolveDiscussion Hijack'
                ];
            }
        }
    }

    /**
     * Show Unresolved meta tag.
     *
     * @return void
     */
    public function base_beforeDiscussionMeta_handler($sender, $args) {
        $resolved = val('Resolved', val('Discussion', $args));
        if (checkPermission('Plugins.Resolved.Manage') && !$resolved) {
            echo ' <span class="Tag Tag-Unresolved">' . t('Unresolved') . '</span> ';
        }
    }

    /**
     * Show [RESOLVED] in discussion title when viewing single.
     *
     * @return void
     */
    public function discussionController_beforeDiscussionOptions_handler($sender, $args) {
        $discussion = $sender->data('Discussion');
        if (checkPermission('Plugins.Resolved.Manage') && $discussion->Resolved) {
            $newName = '<span class="DiscussionResolved">[RESOLVED]</span> '.val('Name', $discussion);
            svalr('Name', $discussion, $newName);
            $sender->setData('Discussion', $discussion);
        }
    }

    /**
     * Handle discussion option menu Resolve action.
     *
     * @throws Exception Throws an exception when the discussion is not found, or the request is not a POST
     * @return void
     */
    public function discussionController_resolve_create($sender, $args) {
        $sender->permission('Plugins.Resolved.Manage');
        $discussionID = $sender->Request->get('discussionid');
        $resolve = $sender->Request->get('resolve');

        if (!Gdn::session()->checkPermission('Garden.Moderation.Manage')) {
            $discussion = new DiscussionModel();
            $discussion = $discussion->getID($discussionID);
            if ($discussion && $discussion->InsertUserID !== GDN::session()->UserID) {
                throw new Exception(t('You do not have a permission to resolve that discussion.'));
            }
        }
        // Make sure we are posting back.
        if (!$sender->Request->isPostBack()) {
            throw permissionException('Javascript');
        }

        $discussion = $sender->DiscussionModel->getID($discussionID);

        if (!$discussion) {
            throw notFoundException('Discussion');
        }

        // Resolve the discussion.
        $this->resolve($discussion, $resolve);

        $sender->sendOptions($discussion);

        if (!$resolve) {
            require_once $sender->fetchViewLocation('helper_functions', 'Discussions');
            $sender->jsonTarget(".Section-DiscussionList #Discussion_{$discussionID} .Meta-Discussion", '<span class="Tag Tag-Unresolved" title="Unresolved">' . t('Unresolved') . '</span>', 'Prepend');
            $sender->jsonTarget(".Section-DiscussionList #Discussion_{$discussionID}", 'Unresolved', 'AddClass');
        } else {
            $sender->jsonTarget(".Section-DiscussionList #Discussion_{$discussionID} .Tag-Unresolved", null, 'Remove');
            $sender->jsonTarget(".Section-DiscussionList #Discussion_{$discussionID}", 'Unresolved', 'RemoveClass');
        }

        $sender->jsonTarget("#Discussion_{$discussionID}", null, 'Highlight');
        $sender->jsonTarget(".Discussion #Item_0", null, 'Highlight');

        $sender->render('Blank', 'Utility', 'Dashboard');
    }

    /**
     * Handle comment form Resolved checkbox & new user comments.
     *
     * @return void
     */
    public function commentModel_afterSaveComment_handler($sender, $args) {
        $discussionID = $args['FormPostValues']['DiscussionID'] ?? null;
        $resolved = $args['FormPostValues']['Resolved'] ?? null;
        $hasPermission = checkPermission("Plugins.Resolved.Manage") || Gdn::session()->checkRankedPermission("Garden.Moderation.Manage");
        if (!$hasPermission || !$discussionID || $resolved === null) {
            return;
        }
        $discussion = [
            'DiscussionID' => $discussionID
        ];
        $this->resolve($discussion, $resolved);
    }

    /**
     * Prior to saving a comment, check whether the discussion is resolved.
     *
     * @param CommentModel $sender The comment model.
     * @param BeforeSaveComment $args The event properties.
     */
    public function commentModel_beforeSaveComment_handler($sender, $args) {
        $discussionID = $args['FormPostValues']['DiscussionID'] ?? false;
        $hasPermission = checkPermission("Plugins.Resolved.Manage") || Gdn::session()->checkRankedPermission("Garden.Moderation.Manage");
        if (!$discussionID) {
            return;
        }
        $discussion = self::discussionModel()->getID($discussionID);
        $discussionResolved = $discussion->Resolved ?? 0;
        $isStarter = $discussion->InsertUserID === Gdn::session()->UserID ?? false;
        if ($discussionResolved && !$hasPermission && !$isStarter) {
            throw new Gdn_UserException(t('You cannot comment in a closed discussion.'));
        }
    }

    /**
     * Disallow comments in Resolved discussions by new participants.
     *
     * @param DiscussionController $sender
     */
    public function discussionController_beforeDiscussionRender_handler($sender, $args) {
        $discussion = $sender->data('Discussion');
        // Do not close blog comments.
        if ('page' == val('Type', $discussion)) {
            return;
        }
        $resolved = val('Resolved', $discussion);
        $isStarter = (val('InsertUserID', $discussion) == Gdn::session()->UserID);
        if (!checkPermission('Plugins.Resolved.Manage') && $resolved && !$isStarter) {
            // Pretend we're closed
            svalr('Closed', $discussion, 1);
            $sender->setData('Discussion', $discussion);
        }
    }

    /**
     * Add 'Unresolved' discussions filter to menu.
     *
     * @return void
     */
    public function base_afterDiscussionFilters_handler($sender) {
        if (checkPermission('Plugins.Resolved.Manage')) {
            $unresolved .= t('Unresolved').filterCountString(self::countUnresolved());
            echo '<li class="Unresolved">'.anchor(sprite('SpUnresolved').' '.$unresolved, '/discussions/unresolved') . '</li>';
        }
    }

    /**
     * Count the number of unresolved discussions.
     *
     * @return integer Returns the number of unresolved discussions
     */
    public static function countUnresolved() {
        $numUnresolved = Gdn::sql()
            ->select('count(DISTINCT d.DiscussionID)', '', 'NumUnresolved')
            ->from('Discussion d')
            ->where('d.Resolved', 0)
            ->beginWhereGroup()
            ->whereNotIn('d.Type', ['page', 'Report', 'poll', 'SimplePage'])
            ->orWhere('d.Type is null')
            ->endWhereGroup()
            ->get()
            ->firstRow()
            ->NumUnresolved;

        return $numUnresolved;
    }

    /**
     * Discussions filter: Unresolved.
     *
     * @return void
     */
    public function discussionsController_unresolved_create($sender, $args) {
        $sender->permission('Plugins.Resolved.Manage');
        $page = val(0, $args, 0);

        // Determine offset from $Page
        list($page, $limit) = offsetLimit($page, c('Vanilla.Discussions.PerPage', 30));

        // Validate $Page
        if (!is_numeric($page) || $page < 0) {
            $page = 0;
        }

        $discussionModel = new DiscussionModel();
        $wheres = ['d.Resolved' => '0'];

        // Hack in our wheregroup.
        Gdn::sql()->beginWhereGroup()
            ->whereNotIn('d.Type', ['page', 'Report', 'poll', 'SimplePage'])
            ->orWhere('d.Type is null')
            ->endWhereGroup();

        $sender->DiscussionData = $discussionModel->get($page, $limit, $wheres);
        $sender->setData('Discussions', $sender->DiscussionData);
        $countDiscussions = $discussionModel->getCount($wheres);
        $sender->setData('CountDiscussions', $countDiscussions);
        $sender->Category = false;

        $sender->setJson('Loading', $page . ' to ' . $limit);

        // Build a pager
        $pagerFactory = new Gdn_PagerFactory();
        $sender->EventArguments['PagerType'] = 'Pager';
        $sender->fireEvent('BeforeBuildBookmarkedPager');
        $sender->Pager = $pagerFactory->getPager($sender->EventArguments['PagerType'], $sender);
        $sender->Pager->ClientID = 'Pager';
        $sender->Pager->configure(
            $page, $limit, $countDiscussions, 'discussions/unresolved/%1$s'
        );

        if (!$sender->data('_PagerUrl')) {
            $sender->setData('_PagerUrl', 'discussions/unresolved/{Page}');
        }
        $sender->setData('_Page', $page);
        $sender->setData('_Limit', $limit);
        $sender->fireEvent('AfterBuildBookmarkedPager');

        // Deliver JSON data if necessary
        if ($sender->deliveryType() != DELIVERY_TYPE_ALL) {
            $sender->setJson('LessRow', $sender->Pager->toString('less'));
            $sender->setJson('MoreRow', $sender->Pager->toString('more'));
            $sender->View = 'discussions';
        }

        // Add modules
        $sender->addModule('DiscussionFilterModule');
        $sender->addModule('NewDiscussionModule');
        $sender->addModule('CategoriesModule');

        // Render default view
        $sender->setData('Title', t('Unresolved'));
        $sender->setData('Breadcrumbs', [['Name' => t('Unresolved'), 'Url' => '/discussions/unresolved']]);
        $sender->render('index');
    }

    /**
     * Plugin setup method.
     *
     * @return void
     */
    public function setup() {
        $this->structure();
    }

    /**
     * Plugin structure method.
     *
     * Add 'Resolved' columns to the Discussion table.
     *
     * @return void
     */
    public function structure() {
        Gdn::structure()
            ->table('Discussion')
            ->column('Resolved', 'int', '0')
            ->column('DateResolved', 'datetime', true)
            ->column('ResolvedUserID', 'int', true)
            ->set();
    }

}
