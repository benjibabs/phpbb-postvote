<?php
/**
 * PostVote extension for phpBB.
 *
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace benjibabs\postvote\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\cache\driver\driver_interface */
	protected $cache;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\path_helper */
	protected $path_helper;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\routing\helper */
	protected $routing_helper;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $php_ext;

	/** Collected vote data keyed by post_id for the current page */
	protected array $vote_data = [];

	/** Whether vote data has been preloaded for this page */
	protected bool $vote_data_loaded = false;

	public function __construct(
		\phpbb\auth\auth $auth,
		\phpbb\cache\driver\driver_interface $cache,
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\language\language $language,
		\phpbb\path_helper $path_helper,
		\phpbb\request\request $request,
		\phpbb\routing\helper $routing_helper,
		\phpbb\template\template $template,
		\phpbb\user $user,
		string $phpbb_root_path,
		string $php_ext
	) {
		$this->auth            = $auth;
		$this->cache           = $cache;
		$this->config          = $config;
		$this->db              = $db;
		$this->language        = $language;
		$this->path_helper     = $path_helper;
		$this->request         = $request;
		$this->routing_helper  = $routing_helper;
		$this->template        = $template;
		$this->user            = $user;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext         = $php_ext;
	}

	public static function getSubscribedEvents(): array
	{
		return [
			'core.page_header'                        => 'inject_assets',
			'core.viewtopic_modify_post_list_sql'     => 'modify_post_list_sql',
			'core.viewtopic_post_rowset_data'         => 'add_vote_cols_to_rowset',
			'core.viewtopic_modify_post_data'         => 'preload_vote_data',
			'core.viewtopic_modify_post_row'          => 'inject_post_vote_vars',
			'core.permissions'                        => 'register_permissions',
		];
	}

	/**
	 * Carry vote columns from the raw SQL row into the rowset so they survive the whitelist copy.
	 * Fires once per post during the rowset-building phase.
	 *
	 * @param \phpbb\event\data $event
	 */
	public function add_vote_cols_to_rowset(\phpbb\event\data $event): void
	{
		if (empty($this->config['postvote_enabled']))
		{
			return;
		}

		$rowset_data = $event['rowset_data'];
		$row         = $event['row'];

		$rowset_data['vote_score'] = (int) ($row['vote_score'] ?? 0);
		$rowset_data['vote_up']    = (int) ($row['vote_up']    ?? 0);
		$rowset_data['vote_down']  = (int) ($row['vote_down']  ?? 0);
		// Also carry poster_id for the inject step (rename to avoid collision with user_id)
		$rowset_data['post_poster_id'] = (int) ($row['poster_id'] ?? $row['user_id'] ?? 0);

		$event['rowset_data'] = $rowset_data;
	}

	/**
	 * Inject CSS/JS and global template vars on every page load.
	 */
	public function inject_assets(): void
	{
		if (empty($this->config['postvote_enabled']))
		{
			return;
		}

		// Load extension language strings so lang() works in Twig templates
		$this->language->add_lang('postvote', 'benjibabs/postvote');

		// Build CSRF token for AJAX use
		$now       = time();
		$token_sid = ($this->user->data['user_id'] == ANONYMOUS && !empty($this->config['form_token_sid_guests']))
			? $this->user->session_id
			: '';
		$token = sha1($now . $this->user->data['user_form_salt'] . 'postvote' . $token_sid);

		// Score sort is the default; date sort is opt-in via ?vpsort=date
		$vpsort = $this->request->variable('vpsort', '');

		$this->template->assign_vars([
			'POSTVOTE_ENABLED'         => true,
			'POSTVOTE_CREATION_TIME'   => $now,
			'POSTVOTE_TOKEN'           => $token,
			'POSTVOTE_VOTE_URL'        => $this->routing_helper->route('benjibabs_postvote_vote', [], false),
			'POSTVOTE_LEADERBOARD_URL' => $this->routing_helper->route('benjibabs_postvote_leaderboard', [], false),
			'POSTVOTE_SORT_ACTIVE'     => ($vpsort !== 'date'),
			'S_USER_LOGGED_IN'         => ($this->user->data['user_id'] != ANONYMOUS),
			'U_CAN_VOTE'               => $this->auth->acl_get('u_postvote'),
			'U_CAN_DOWNVOTE'           => $this->auth->acl_get('u_postvote_down'),
		]);
	}

	/**
	 * Optionally reorder the post list SQL to sort by vote score when ?vpsort=score is set.
	 * Uses core.viewtopic_modify_post_list_sql which exposes the raw SQL string.
	 *
	 * @param \phpbb\event\data $event
	 */
	public function modify_post_list_sql(\phpbb\event\data $event): void
	{
		if (empty($this->config['postvote_enabled']))
		{
			return;
		}

		// Default sort is by vote score. Only skip if the user explicitly requests date order.
		$vpsort = $this->request->variable('vpsort', '');
		if ($vpsort === 'date')
		{
			return;
		}

		// Replace the ORDER BY clause with vote_score DESC.
		// p.vote_score is already a column on phpbb_posts added by our migration.
		$sql = $event['sql'];
		$pos = stripos($sql, 'ORDER BY');
		if ($pos !== false)
		{
			$sql = substr($sql, 0, $pos) . 'ORDER BY p.vote_score DESC, p.post_time ASC';
		}

		$event['sql'] = $sql;
	}

	/**
	 * After the rowset is built, preload all vote data for posts on this page in one query.
	 * This avoids N+1 queries in the post row loop.
	 *
	 * @param \phpbb\event\data $event
	 */
	public function preload_vote_data(\phpbb\event\data $event): void
	{
		if (empty($this->config['postvote_enabled']) || $this->vote_data_loaded)
		{
			return;
		}

		$rowset    = $event['rowset'];
		$post_ids  = array_keys($rowset);

		if (empty($post_ids))
		{
			$this->vote_data_loaded = true;
			return;
		}

		$user_id = (int) $this->user->data['user_id'];

		// Fetch current user's votes for posts on this page (single query)
		$this->vote_data = [];
		if ($user_id != ANONYMOUS)
		{
			$sql = 'SELECT post_id, vote_value
				FROM ' . $this->get_votes_table() . '
				WHERE ' . $this->db->sql_in_set('post_id', $post_ids) . '
					AND user_id = ' . $user_id;
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$this->vote_data[(int) $row['post_id']] = (int) $row['vote_value'];
			}
			$this->db->sql_freeresult($result);
		}

		$this->vote_data_loaded = true;
	}

	/**
	 * Inject vote-related template vars into each post row.
	 *
	 * @param \phpbb\event\data $event
	 */
	public function inject_post_vote_vars(\phpbb\event\data $event): void
	{
		if (empty($this->config['postvote_enabled']))
		{
			return;
		}

		$row      = $event['row'];
		$post_row = $event['post_row'];
		$post_id  = (int) $row['post_id'];

		$vote_score = (int) ($row['vote_score'] ?? 0);
		$vote_up    = (int) ($row['vote_up']    ?? 0);
		$vote_down  = (int) ($row['vote_down']  ?? 0);

		// Current user's vote on this post (0 = none, 1 = up, -1 = down)
		$user_vote  = $this->vote_data[$post_id] ?? 0;

		$post_row['POST_VOTE_SCORE']    = $vote_score;
		$post_row['POST_VOTE_UP']       = $vote_up;
		$post_row['POST_VOTE_DOWN']     = $vote_down;
		$post_row['POST_VOTE_USER']     = $user_vote;        // -1, 0, or 1
		$post_row['POST_VOTED_UP']      = ($user_vote === 1);
		$post_row['POST_VOTED_DOWN']    = ($user_vote === -1);
		$post_row['POST_ID_FOR_VOTE']   = $post_id;

		$event['post_row'] = $post_row;
	}

	/**
	 * Register u_postvote and u_postvote_down permissions.
	 *
	 * @param \phpbb\event\data $event
	 */
	public function register_permissions(\phpbb\event\data $event): void
	{
		$permissions = $event['permissions'];

		$permissions['u_postvote'] = [
			'lang' => 'ACL_U_POSTVOTE',
			'cat'  => 'post',
		];
		$permissions['u_postvote_down'] = [
			'lang' => 'ACL_U_POSTVOTE_DOWN',
			'cat'  => 'post',
		];

		$event['permissions'] = $permissions;
	}

	/**
	 * Helper: return the fully-qualified votes table name.
	 */
	protected function get_votes_table(): string
	{
		global $table_prefix;
		return $table_prefix . 'post_votes';
	}
}
