<?php
/**
 * PostVote extension for phpBB.
 *
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace benjibabs\postvote\controller;

use Symfony\Component\HttpFoundation\Response;

class leaderboard_controller
{
	/** @var \phpbb\cache\driver\driver_interface */
	protected $cache;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $php_ext;

	public function __construct(
		\phpbb\cache\driver\driver_interface $cache,
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\language\language $language,
		\phpbb\template\template $template,
		\phpbb\user $user,
		string $phpbb_root_path,
		string $php_ext
	) {
		$this->cache           = $cache;
		$this->config          = $config;
		$this->db              = $db;
		$this->language        = $language;
		$this->template        = $template;
		$this->user            = $user;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext         = $php_ext;
	}

	/**
	 * Handle GET /app.php/postvote/leaderboard
	 */
	public function handle(): Response
	{
		if (empty($this->config['postvote_enabled']))
		{
			return new Response('PostVote is disabled.', 403);
		}

		// Load language
		$this->language->add_lang('postvote', 'benjibabs/postvote');

		$top_users = $this->get_top_users();
		$top_posts = $this->get_top_posts();

		// Assign top users
		foreach ($top_users as $user_row)
		{
			$this->template->assign_block_vars('top_users', [
				'USER_ID'        => (int) $user_row['user_id'],
				'USERNAME'       => $user_row['username'],
				'REPUTATION'     => (int) $user_row['reputation'],
				'U_PROFILE'      => append_sid($this->phpbb_root_path . 'memberlist.' . $this->php_ext, 'mode=viewprofile&u=' . (int) $user_row['user_id']),
			]);
		}

		// Assign top posts
		foreach ($top_posts as $post_row)
		{
			$this->template->assign_block_vars('top_posts', [
				'POST_ID'        => (int) $post_row['post_id'],
				'POST_SUBJECT'   => $post_row['post_subject'],
				'VOTE_SCORE'     => (int) $post_row['vote_score'],
				'VOTE_UP'        => (int) $post_row['vote_up'],
				'VOTE_DOWN'      => (int) $post_row['vote_down'],
				'POSTER_NAME'    => $post_row['username'],
				'U_VIEW_POST'    => append_sid($this->phpbb_root_path . 'viewtopic.' . $this->php_ext, 'p=' . (int) $post_row['post_id']) . '#p' . (int) $post_row['post_id'],
			]);
		}

		$this->template->assign_vars([
			'POSTVOTE_LEADERBOARD_TITLE' => $this->language->lang('POSTVOTE_LEADERBOARD'),
		]);

		// Use phpBB page rendering
		page_header($this->language->lang('POSTVOTE_LEADERBOARD'));
		$this->template->set_filenames(['body' => '@benjibabs_postvote/postvote_leaderboard.html']);
		page_footer();

		// page_footer() calls exit(), so we never reach here in practice.
		// Return empty response to satisfy type hint.
		return new Response('');
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	protected function get_top_users(): array
	{
		$cache_key = '_postvote_leaderboard_users';
		$cached    = $this->cache->get($cache_key);

		if ($cached !== false)
		{
			return $cached;
		}

		global $table_prefix;

		$sql = 'SELECT user_id, username, reputation
			FROM ' . $table_prefix . 'users
			WHERE user_type NOT IN (1, 2)
				AND reputation <> 0
			ORDER BY reputation DESC
			LIMIT 25';
		$result = $this->db->sql_query($sql);
		$rows   = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);

		$ttl = (int) ($this->config['postvote_cache_ttl'] ?? 300);
		$this->cache->put($cache_key, $rows, $ttl);

		return $rows;
	}

	protected function get_top_posts(): array
	{
		$cache_key = '_postvote_leaderboard_posts';
		$cached    = $this->cache->get($cache_key);

		if ($cached !== false)
		{
			return $cached;
		}

		global $table_prefix;

		$sql = 'SELECT p.post_id, p.post_subject, p.vote_score, p.vote_up, p.vote_down, u.username
			FROM ' . $table_prefix . 'posts p
			INNER JOIN ' . $table_prefix . 'users u ON u.user_id = p.poster_id
			WHERE p.post_visibility = 1
				AND p.vote_score <> 0
			ORDER BY p.vote_score DESC
			LIMIT 25';
		$result = $this->db->sql_query($sql);
		$rows   = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);

		$ttl = (int) ($this->config['postvote_cache_ttl'] ?? 300);
		$this->cache->put($cache_key, $rows, $ttl);

		return $rows;
	}
}
