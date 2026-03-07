<?php
/**
 * PostVote extension for phpBB.
 *
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	// General
	'POSTVOTE_UPVOTE'                   => 'Upvote',
	'POSTVOTE_DOWNVOTE'                 => 'Downvote',
	'POSTVOTE_SCORE'                    => 'Score',
	'POSTVOTE_VOTES'                    => 'Votes',
	'POSTVOTE_LEADERBOARD'              => 'PostVote Leaderboard',
	'POSTVOTE_LEADERBOARD_TOP_USERS'    => 'Top Users by Reputation',
	'POSTVOTE_LEADERBOARD_TOP_POSTS'    => 'Top Posts by Score',
	'POSTVOTE_SORT_LABEL'               => 'Sort',
	'POSTVOTE_SORT_BY_SCORE'            => 'Top Voted',
	'POSTVOTE_SORT_BY_DATE'             => 'Date',
	'POSTVOTE_REPUTATION'               => 'Reputation',
	'POSTVOTE_NO_VOTES_YET'             => 'No votes yet.',

	// Errors returned by the AJAX endpoint
	'POSTVOTE_DISABLED'                 => 'PostVote is currently disabled.',
	'POSTVOTE_LOGIN_REQUIRED'           => 'You must be logged in to vote.',
	'POSTVOTE_NO_PERMISSION'            => 'You do not have permission to vote.',
	'POSTVOTE_NO_DOWNVOTE_PERMISSION'   => 'You do not have permission to downvote.',
	'POSTVOTE_RATE_LIMITED'             => 'You are voting too fast. Please wait a moment.',
	'POSTVOTE_INVALID_POST'             => 'Invalid post.',
	'POSTVOTE_INVALID_VOTE'             => 'Invalid vote value.',
	'POSTVOTE_POST_NOT_FOUND'           => 'Post not found.',
	'POSTVOTE_CANNOT_VOTE_OWN'          => 'You cannot vote on your own posts.',

	// ACP settings
	'ACP_POSTVOTE'                      => 'PostVote',
	'ACP_POSTVOTE_SETTINGS'             => 'PostVote Settings',
	'POSTVOTE_ENABLE'                   => 'Enable PostVote',
	'POSTVOTE_ENABLE_EXPLAIN'           => 'Enable or disable the PostVote extension across the board.',
	'POSTVOTE_ALLOW_DOWNVOTE'           => 'Allow downvoting',
	'POSTVOTE_ALLOW_DOWNVOTE_EXPLAIN'   => 'When disabled, users with the downvote permission can still cast negative votes but new installations start with downvoting enabled.',
	'POSTVOTE_RATE_LIMIT'               => 'Votes per period',
	'POSTVOTE_RATE_LIMIT_EXPLAIN'       => 'Maximum number of votes a user may cast within the rate-limit period.',
	'POSTVOTE_RATE_PERIOD'              => 'Rate-limit period (seconds)',
	'POSTVOTE_RATE_PERIOD_EXPLAIN'      => 'Length of the rate-limit window in seconds (e.g. 60 for one minute).',
	'POSTVOTE_CACHE_TTL'                => 'Leaderboard cache TTL (seconds)',
	'POSTVOTE_CACHE_TTL_EXPLAIN'        => 'How long to cache leaderboard data in seconds. Set 0 to disable caching.',

	// Permissions (used in core.permissions event)
	'ACL_U_POSTVOTE'                    => 'Can upvote posts',
	'ACL_U_POSTVOTE_DOWN'               => 'Can downvote posts',
]);
