<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Displays a post, and all the posts below it.
 * If no post is given, displays all posts in a discussion
 *
 * @package   mod_forumx
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @copyright 2020 onwards MOFET
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$d             = required_param('d', PARAM_INT);          // Discussion ID.
$parent        = optional_param('parent', 0, PARAM_INT);  // If set, then display this post and all children.
$mode          = optional_param('mode', 0, PARAM_INT);    // If set, changes the layout of the thread.
$move          = optional_param('move', 0, PARAM_INT);    // If set, moves this discussion to another forum.
$mark          = optional_param('mark', '', PARAM_ALPHA); // Used for tracking read posts if user initiated.
$postid        = optional_param('postid', 0, PARAM_INT);  // Used for tracking read posts if user initiated.
$active_post   = optional_param('p', 0, PARAM_INT);       // Post to display open.
$reply_to_post = optional_param('r', 0, PARAM_INT);       // Post to reply to.

if ($reply_to_post && $reply_to_post != $active_post) {
	$active_post = $reply_to_post; // The reply value takes priority.
}
$url = new moodle_url('/mod/forumx/discuss.php', array('d'=>$d));
if ($parent !== 0) {
    $url->param('parent', $parent);
}
$PAGE->set_url($url);

$discussion = $DB->get_record('forumx_discussions', array('id' => $d), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
$forum = $DB->get_record('forumx', array('id' => $discussion->forumx), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('forumx', $forum->id, $course->id, false, MUST_EXIST);

require_course_login($course, true, $cm);

// Move this down fix for MDL-6926.
require_once($CFG->dirroot.'/mod/forumx/lib.php');

$modcontext = context_module::instance($cm->id);
require_capability('mod/forumx:viewdiscussion', $modcontext, null, true, 'noviewdiscussionspermission', 'forumx');

if (!empty($CFG->enablerssfeeds) && !empty($CFG->forumx_enablerssfeeds) && $forum->rsstype && $forum->rssarticles) {
    require_once("$CFG->libdir/rsslib.php");

    $rsstitle = format_string($course->shortname, true, array('context' => context_course::instance($course->id))).': '.format_string($forum->name);
    rss_add_http_header($modcontext, 'mod_forumx', $forum, $rsstitle);
}

// Move discussion if requested.
if ($move > 0 && confirm_sesskey()) {
    $return = $CFG->wwwroot.'/mod/forumx/discuss.php?d='.$discussion->id;

    if (!$forumto = $DB->get_record('forumx', array('id' => $move))) {
        print_error('cannotmovetonotexist', 'forumx', $return);
    }

    require_capability('mod/forumx:movediscussions', $modcontext);

    if ($forum->type == 'single') {
        print_error('cannotmovefromsingleforum', 'forumx', $return);
    }

    if (!$forumto = $DB->get_record('forumx', array('id' => $move))) {
        print_error('cannotmovetonotexist', 'forumx', $return);
    }

    if ($forumto->type == 'single') {
        print_error('cannotmovetosingleforum', 'forumx', $return);
    }

    // Get target forum cm and check it is visible to current user.
    $modinfo = get_fast_modinfo($course);
    $forums = $modinfo->get_instances_of('forumx');
    if (!array_key_exists($forumto->id, $forums)) {
        print_error('cannotmovetonotfound', 'forumx', $return);
    }
    $cmto = $forums[$forumto->id];
    if (!$cmto->uservisible) {
        print_error('cannotmovenotvisible', 'forumx', $return);
    }

    $destinationctx = context_module::instance($cmto->id);
    require_capability('mod/forumx:startdiscussion', $destinationctx);

    if (!forumx_move_attachments($discussion, $forum->id, $forumto->id)) {
        echo $OUTPUT->notification("Errors occurred while moving attachment directories - check your file permissions");
    }
    // For each subscribed user in this forum and discussion, copy over per-discussion subscriptions if required.
    $discussiongroup = $discussion->groupid == -1 ? 0 : $discussion->groupid;
    $potentialsubscribers = \mod_forumx\subscriptions::fetch_subscribed_users(
        $forum,
        $discussiongroup,
        $modcontext,
        'u.id',
        true
    );

    // Pre-seed the subscribed_discussion caches.
    // Firstly for the forum being moved to.
    \mod_forumx\subscriptions::fill_subscription_cache($forumto->id);
    // And also for the discussion being moved.
    \mod_forumx\subscriptions::fill_subscription_cache($forum->id);
    $subscriptionchanges = array();
    $subscriptiontime = time();
    foreach ($potentialsubscribers as $subuser) {
        $userid = $subuser->id;
        $targetsubscription = \mod_forumx\subscriptions::is_subscribed($userid, $forumto, null, $cmto);
        $discussionsubscribed = \mod_forumx\subscriptions::is_subscribed($userid, $forum, $discussion->id);
        $forumsubscribed = \mod_forumx\subscriptions::is_subscribed($userid, $forum);

        if ($forumsubscribed && !$discussionsubscribed && $targetsubscription) {
            // The user has opted out of this discussion and the move would cause them to receive notifications again.
            // Ensure they are unsubscribed from the discussion still.
            $subscriptionchanges[$userid] = \mod_forumx\subscriptions::forumx_DISCUSSION_UNSUBSCRIBED;
        } else if (!$forumsubscribed && $discussionsubscribed && !$targetsubscription) {
            // The user has opted into this discussion and would otherwise not receive the subscription after the move.
            // Ensure they are subscribed to the discussion still.
            $subscriptionchanges[$userid] = $subscriptiontime;
        }
    }

    $DB->set_field('forumx_discussions', 'forumx', $forumto->id, array('id' => $discussion->id));
    $DB->set_field('forumx_read', 'forumxid', $forumto->id, array('discussionid' => $discussion->id));

    // Delete the existing per-discussion subscriptions and replace them with the newly calculated ones.
    $DB->delete_records('forumx_discussion_subs', array('discussion' => $discussion->id));
    $newdiscussion = clone $discussion;
    $newdiscussion->forumx = $forumto->id;
    foreach ($subscriptionchanges as $userid => $preference) {
        if ($preference != \mod_forumx\subscriptions::forumx_DISCUSSION_UNSUBSCRIBED) {
            // Users must have viewdiscussion to a discussion.
            if (has_capability('mod/forumx:viewdiscussion', $destinationctx, $userid)) {
                \mod_forumx\subscriptions::subscribe_user_to_discussion($userid, $newdiscussion, $destinationctx);
            }
        } else {
            \mod_forumx\subscriptions::unsubscribe_user_from_discussion($userid, $newdiscussion, $destinationctx);
        }
    }

    $params = array(
        'context' => $destinationctx,
        'objectid' => $discussion->id,
        'other' => array(
            'fromforumid' => $forum->id,
            'toforumid' => $forumto->id,
        )
    );
    $event = \mod_forumx\event\discussion_moved::create($params);
    $event->add_record_snapshot('forumx_discussions', $discussion);
    $event->add_record_snapshot('forumx', $forum);
    $event->add_record_snapshot('forumx', $forumto);
    $event->trigger();

    // Delete the RSS files for the 2 forums to force regeneration of the feeds.
    require_once($CFG->dirroot.'/mod/forumx/rsslib.php');
    forumx_rss_delete_file($forum);
    forumx_rss_delete_file($forumto);

    redirect($return.'&move=-1&sesskey='.sesskey());
}

// Trigger discussion viewed event.
forumx_discussion_view($modcontext, $forum, $discussion);

if ($mode) {
	$mode = forumx_normalize_layout_mode($mode, false);
    set_user_preference('forumx_displaymode', $mode);
}

$displaymode = get_user_preferences('forumx_displaymode', $CFG->forumx_displaymode);

if ($parent) {
    // If flat AND parent, then force nested display this time.
    if ($displaymode == forumx_MODE_FLATOLDEST || 
    	$displaymode == forumx_MODE_FLATNEWEST ||
    	$displaymode == forumx_MODE_ONLY_DISCUSSION) {
        $displaymode = forumx_MODE_NESTED;
    }
} else {
    $parent = $discussion->firstpost;
}

if (!$post = forumx_get_post_full($parent)) {
    print_error("notexists", 'forumx', "$CFG->wwwroot/mod/forumx/view.php?f=$forum->id");
}

if (!forumx_user_can_see_post($forum, $discussion, $post, null, $cm)) {
    print_error('noviewdiscussionspermission', 'forumx', "$CFG->wwwroot/mod/forumx/view.php?id=$forum->id");
}

if ($mark == 'read' || $mark == 'unread') {
    if ($CFG->forumx_usermarksread && forumx_tp_can_track_forums($forum) && forumx_tp_is_tracked($forum)) {
        if ($mark == 'read') {
            forumx_tp_add_read_record($USER->id, $postid);
        } else {
            // Unread.
            forumx_tp_delete_read_records($USER->id, $postid);
        }
    }
}

$forumnode = $PAGE->navigation->find($cm->id, navigation_node::TYPE_ACTIVITY);
if (empty($forumnode)) {
    $forumnode = $PAGE->navbar;
} else {
    $forumnode->make_active();
}
$node = $forumnode->add(format_string($discussion->name), new moodle_url('/mod/forumx/discuss.php', array('d'=>$discussion->id)));
$node->display = false;
if ($node && $post->id != $discussion->firstpost) {
    $node->add(format_string($post->subject), $PAGE->url);
}

$PAGE->set_title("$course->shortname: ".format_string($discussion->name));
$PAGE->set_heading($course->fullname);
$renderer = $PAGE->get_renderer('mod_forumx');

forumx_add_desktop_styles();
$js_params = array(
		'forum' => $forum->id,
		'forumtype' => $forum->type
);
if ($reply_to_post) {
	$js_params['reply'] = $reply_to_post;
}
$PAGE->requires->js_call_amd('mod_forumx/forumx', 'init', array($js_params));
$PAGE->requires->strings_for_js(array(
	        'flagpost', 'unflagpost', 'recommendpost', 'unrecommendpost', 'clicktosubscribe', 'clicktounsubscribe',
    		'opendiscussionthread', 'closediscussionthread', 'discussionreply', 'discussionreplies', 
    		'cancel', 'delete', 'deletesure', 'deletesureplural', 'copylink:close', 'copylink:copied', 
    		'copylink:copy', 'copylink:failed', 'copylink:notsupported', 'copylink:title',
    		'subscribeforum:no', 'subscribeforum:nolabel', 'subscribeforum:yes', 'subscribeforum:yeslabel',
    		'tracking:no', 'tracking:nolabel', 'tracking:yes', 'tracking:yeslabel', 
    		'lockdiscussion', 'unlockdiscussion', 'forwardtitle', 'forwardsent',
    		'subscribediscussion:no', 'subscribediscussion:nolabel', 
    		'subscribediscussion:yes', 'subscribediscussion:yeslabel', 'copylink:gotolink',
    		'pgl:loadbutton', 'pgl:buttonclose', 'pgl:buttonnext', 'pgl:buttonprev',
    		'linktopostfield', 'forwarderror:empty', 'forwarderror:invalidemail', 'confirmdiscardcontentlock',
    		'enabled', 'disabled', 'postingfailed'
    	)
	    , 'forumx');

echo $OUTPUT->header();
echo forumx_print_top_panel($course, '');

echo $OUTPUT->heading(format_string($forum->name), 2);
echo $OUTPUT->heading(format_string($discussion->name), 3, 'discussionname');

// is_guest should be used here as this also checks whether the user is a guest in the current course.
// Guests and visitors cannot subscribe - only enrolled users.
if ((!is_guest($modcontext, $USER) && isloggedin()) && has_capability('mod/forumx:viewdiscussion', $modcontext)) {
    // Discussion subscription.
}
echo forumx_print_elements_for_js();

/// Check to see if groups are being used in this forum
/// If so, make sure the current person is allowed to see this discussion
/// Also, if we know they should be able to reply, then explicitly set $canreply for performance reasons

$canreply = forumx_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
if (!$canreply && $forum->type !== 'news') {
    if (isguestuser() || !isloggedin()) {
        $canreply = true;
    }
    if (!is_enrolled($modcontext) && !is_viewing($modcontext)) {
        // allow guests and not-logged-in to see the link - they are prompted to log in after clicking the link
        // normal users with temporary guest access see this link too, they are asked to enrol instead
        $canreply = enrol_selfenrol_available($course->id);
    }
}

// Output the links to neighbour discussions.
$neighbours = forumx_get_discussion_neighbours($cm, $discussion, $forum);
$neighbourlinks = $renderer->neighbouring_discussion_navigation($neighbours['prev'], $neighbours['next']);
echo $neighbourlinks;

// Print the controls across the top.
echo '<div class="discussioncontrols clearfix">';

if (!empty($CFG->enableportfolios) && has_capability('mod/forumx:exportdiscussion', $modcontext)) {
    require_once($CFG->libdir.'/portfoliolib.php');
    $button = new portfolio_add_button();
    $button->set_callback_options('forumx_portfolio_caller', array('discussionid' => $discussion->id), 'mod_forumx');
    $button = $button->to_html(PORTFOLIO_ADD_FULL_FORM, get_string('exportdiscussion', 'mod_forumx'));
    $buttonextraclass = '';
    if (empty($button)) {
        // No portfolio plugin available.
        $button = '&nbsp;';
        $buttonextraclass = ' noavailable';
    }
    echo html_writer::tag('div', $button, array('class' => 'discussioncontrol exporttoportfolio'.$buttonextraclass));
} else {
    echo html_writer::tag('div', '&nbsp;', array('class'=>'discussioncontrol nullcontrol'));
}
echo '</div>';
echo '<div class="clearfix forummode_container">';
echo '<div class="selector_wrapper_container float_end">';
forumx_print_mode_form($forum->id, $displaymode, true);
echo '</div>';
echo '</div>';

if (!empty($forum->blockafter) && !empty($forum->blockperiod)) {
    $a = new stdClass();
    $a->blockafter  = $forum->blockafter;
    $a->blockperiod = get_string('secondstotime'.$forum->blockperiod);
    echo $OUTPUT->notification(get_string('thisforumisthrottled', 'forumx', $a));
}

if ($forum->type == 'qanda' && !has_capability('mod/forumx:viewqandawithoutposting', $modcontext) &&
            !forumx_user_has_posted($forum->id, $discussion->id, $USER->id)) {
    echo $OUTPUT->notification(get_string('qandanotify', 'forumx'));
}

if ($move == -1 && confirm_sesskey()) {
    echo $OUTPUT->notification(get_string('discussionmoved', 'forumx', format_string($forum->name, true)), 'notifysuccess');
}

$discussion->flagged	 = \mod_forumx\post_actions::is_discussion_flagged($discussion->id, $USER->id);
$discussion->recommended = \mod_forumx\post_actions::is_discussion_recommended($discussion->id);

$unread = forumx_get_discussions_unread($cm, $discussion->id);
if (isset($unread[$discussion->id])) {
	$discussion->unread = $unread[$discussion->id];
}
$canrate = has_capability('mod/forumx:rate', $modcontext);

$display_type = $displaymode == forumx_MODE_THREADED ? forumx_DISPLAY_OPEN : null;
if ($canreply) {
	echo forumx_print_quick_reply_dialog(true);
}
echo forumx_print_quick_forward_dialog();

$discussion->discussion = $discussion->id;
echo '<ul class="discussionslist">';
forumx_print_discussion($course, $cm, $forum, $discussion, $post, $displaymode, $canreply, $canrate, $display_type, true, $active_post);
echo '</ul>';

echo $neighbourlinks;

echo $OUTPUT->footer();
