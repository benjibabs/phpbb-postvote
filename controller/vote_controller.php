<?php
/**
 * PostVote extension for phpBB.
 *
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace benjibabs\postvote\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class vote_controller
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

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\user */
	protected $user;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $php_ext;

	public function __construct(
		\phpbb\auth\auth $auth,
		\phpbb\cache\driver\driver_interface $cache,
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\language\language $language,
		\phpbb\request\request $request,
		\phpbb\user $user,
		string $phpbb_root_path,
		string $php_ext
	) {
		$this->auth            = $auth;
		$this->cache           = $cache;
		$this->config          = $config;
		$this->db              = $db;
		$this->language        = $language;
		$this->request         = $request;
		$this->user            = $user;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext         = $php_ext;
	}

	/**
	 * Handle POST /app.php/postvote/vote
	 *
	 * Expected POST fields:
	 *   post_id        int
	 *   vote           int  (+1 or -1)
	 *   creation_time  int  (CSRF)
	 *   form_token     string (CSRF)
	 */
	public function handle(): JsonResponse
	{
		// Extension must be enabled
		if (empty($this->config['postvote_enabled']))
		{
			return $this->error('POSTVOTE_DISABLED', 403);
		}

		// Must be logged in
		if ($this->user->data['user_id'] == ANONYMOUS)
		{
			return $this->error('POSTVOTE_LOGIN_REQUIRED', 401);
		}

		// CSRF validation
		if (!$this->check_csrf())
		{
			return $this->error('FORM_INVALID', 403);
		}

		// Parse and validate inputs
		$post_id    = $this->request->variable('post_id', 0);
		$vote_value = $this->request->variable('vote', 0);

		if ($post_id <= 0)
		{
			return $this->error('POSTVOTE_INVALID_POST', 400);
		}

		if (!in_array($vote_value, [1, -1], true))
		{
			return $this->error('POSTVOTE_INVALID_VOTE', 400);
		}

		// Permission checks
		if (!$this->auth->acl_get('u_postvote'))
		{
			return $this->error('POSTVOTE_NO_PERMISSION', 403);
		}

		if ($vote_value === -1 && !$this->auth->acl_get('u_postvote_down'))
		{
			return $this->error('POSTVOTE_NO_DOWNVOTE_PERMISSION', 403);
		}

		// Rate limit check
		if (!$this->check_rate_limit())
		{
			return $this->error('POSTVOTE_RATE_LIMITED', 429);
		}

		// Verify post exists and fetch poster_id
		$post_row = $this->get_post($post_id);
		if ($post_row === null)
		{
			return $this->error('POSTVOTE_POST_NOT_FOUND', 404);
		}

		// Users cannot vote on their own posts
		if ((int) $post_row['poster_id'] === (int) $this->user->data['user_id'])
		{
			return $this->error('POSTVOTE_CANNOT_VOTE_OWN', 403);
		}

		$user_id  = (int) $this->user->data['user_id'];
		$poster_id = (int) $post_row['poster_id'];

		// Check for existing vote
		$existing_vote = $this->get_existing_vote($post_id, $user_id);

		if ($existing_vote !== null)
		{
			if ($existing_vote === $vote_value)
			{
				// Same vote – toggle off (remove vote)
				$this->delete_vote($post_id, $user_id);
			}
			else
			{
				// Different vote – update
				$this->update_vote($post_id, $user_id, $vote_value);
				$this->increment_rate_counter();
			}
		}
		else
		{
			// New vote
			$this->insert_vote($post_id, $user_id, $vote_value);
			$this->increment_rate_counter();
		}

		// Recalculate cached totals in phpbb_posts
		$totals = $this->recalculate_post_totals($post_id);

		// Update post author reputation
		$reputation = $this->recalculate_user_reputation($poster_id);

		// Invalidate leaderboard caches
		$this->invalidate_leaderboard_cache();

		// Invalidate per-post cache
		$this->cache->destroy('_postvote_post_' . $post_id);

		return new JsonResponse([
			'success'          => true,
			'score'            => $totals['vote_score'],
			'upvotes'          => $totals['vote_up'],
			'downvotes'        => $totals['vote_down'],
			'user_vote'        => $this->get_existing_vote($post_id, $user_id) ?? 0,
			'user_reputation'  => $reputation,
		]);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate CSRF token sent with AJAX request.
	 * Reads creation_time and form_token from POST, mirrors check_form_key logic.
	 */
	protected function check_csrf(): bool
	{
		$creation_time = abs($this->request->variable('creation_time', 0));
		$form_token    = $this->request->variable('form_token', '');

		if (!$creation_time || !$form_token)
		{
			return false;
		}

		$timespan = ($this->config['form_token_lifetime'] == -1)
			? -1
			: max(30, (int) $this->config['form_token_lifetime']);

		$diff = time() - $creation_time;
		if (!$diff || ($timespan !== -1 && $diff > $timespan))
		{
			return false;
		}

		$token_sid = ($this->user->data['user_id'] == ANONYMOUS && !empty($this->config['form_token_sid_guests']))
			? $this->user->session_id
			: '';

		$expected = sha1($creation_time . $this->user->data['user_form_salt'] . 'postvote' . $token_sid);
		return hash_equals($expected, $form_token);
	}

	/**
	 * Rate limiting: allow at most postvote_rate_limit votes per postvote_rate_period seconds.
	 * Returns true if the action is allowed (not yet limited).
	 */
	protected function check_rate_limit(): bool
	{
		$limit  = (int) ($this->config['postvote_rate_limit']  ?? 10);
		$period = (int) ($this->config['postvote_rate_period'] ?? 60);
		$user_id = (int) $this->user->data['user_id'];

		$cache_key = 'postvote_rate_' . $user_id;
		$data      = $this->cache->get($cache_key);

		if ($data === false)
		{
			// No existing entry – allow
			return true;
		}

		return ((int) $data['count']) < $limit;
	}

	/**
	 * Increment the per-user rate limit counter after a successful vote action.
	 */
	protected function increment_rate_counter(): void
	{
		$period  = (int) ($this->config['postvote_rate_period'] ?? 60);
		$user_id = (int) $this->user->data['user_id'];

		$cache_key = 'postvote_rate_' . $user_id;
		$data      = $this->cache->get($cache_key);

		if ($data === false)
		{
			$data = ['count' => 0, 'reset_time' => time() + $period];
		}

		$data['count']++;
		$this->cache->put($cache_key, $data, $period);
	}

	/**
	 * Return the post row (post_id, poster_id) or null if not found.
	 */
	protected function get_post(int $post_id): ?array
	{
		global $table_prefix;

		$sql = 'SELECT post_id, poster_id
			FROM ' . $table_prefix . 'posts
			WHERE post_id = ' . $post_id;
		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result) ?: null;
		$this->db->sql_freeresult($result);
		return $row;
	}

	/**
	 * Return current user's vote value for this post, or null if none.
	 */
	protected function get_existing_vote(int $post_id, int $user_id): ?int
	{
		global $table_prefix;

		$sql = 'SELECT vote_value
			FROM ' . $table_prefix . 'post_votes
			WHERE post_id = ' . $post_id . '
				AND user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return ($row !== false) ? (int) $row['vote_value'] : null;
	}

	protected function insert_vote(int $post_id, int $user_id, int $vote_value): void
	{
		global $table_prefix;

		$sql = 'INSERT INTO ' . $table_prefix . 'post_votes
			(post_id, user_id, vote_value, vote_time)
			VALUES (' . $post_id . ', ' . $user_id . ', ' . $vote_value . ', ' . time() . ')';
		$this->db->sql_query($sql);
	}

	protected function update_vote(int $post_id, int $user_id, int $vote_value): void
	{
		global $table_prefix;

		$sql = 'UPDATE ' . $table_prefix . 'post_votes
			SET vote_value = ' . $vote_value . ', vote_time = ' . time() . '
			WHERE post_id = ' . $post_id . '
				AND user_id = ' . $user_id;
		$this->db->sql_query($sql);
	}

	protected function delete_vote(int $post_id, int $user_id): void
	{
		global $table_prefix;

		$sql = 'DELETE FROM ' . $table_prefix . 'post_votes
			WHERE post_id = ' . $post_id . '
				AND user_id = ' . $user_id;
		$this->db->sql_query($sql);
	}

	/**
	 * Recalculate and persist vote totals in phpbb_posts.
	 * Returns an array with vote_up, vote_down, vote_score.
	 */
	protected function recalculate_post_totals(int $post_id): array
	{
		global $table_prefix;

		// Count upvotes
		$sql = 'SELECT COUNT(*) AS cnt
			FROM ' . $table_prefix . 'post_votes
			WHERE post_id = ' . $post_id . ' AND vote_value = 1';
		$result  = $this->db->sql_query($sql);
		$vote_up = (int) $this->db->sql_fetchfield('cnt');
		$this->db->sql_freeresult($result);

		// Count downvotes
		$sql = 'SELECT COUNT(*) AS cnt
			FROM ' . $table_prefix . 'post_votes
			WHERE post_id = ' . $post_id . ' AND vote_value = -1';
		$result    = $this->db->sql_query($sql);
		$vote_down = (int) $this->db->sql_fetchfield('cnt');
		$this->db->sql_freeresult($result);

		$vote_score = $vote_up - $vote_down;

		$sql = 'UPDATE ' . $table_prefix . 'posts
			SET vote_up = ' . $vote_up . ',
				vote_down = ' . $vote_down . ',
				vote_score = ' . $vote_score . '
			WHERE post_id = ' . $post_id;
		$this->db->sql_query($sql);

		return [
			'vote_up'    => $vote_up,
			'vote_down'  => $vote_down,
			'vote_score' => $vote_score,
		];
	}

	/**
	 * Recalculate and persist user reputation = SUM(vote_score) across their posts.
	 */
	protected function recalculate_user_reputation(int $poster_id): int
	{
		global $table_prefix;

		$sql = 'SELECT COALESCE(SUM(vote_score), 0) AS rep
			FROM ' . $table_prefix . 'posts
			WHERE poster_id = ' . $poster_id;
		$result     = $this->db->sql_query($sql);
		$reputation = (int) $this->db->sql_fetchfield('rep');
		$this->db->sql_freeresult($result);

		$sql = 'UPDATE ' . $table_prefix . 'users
			SET reputation = ' . $reputation . '
			WHERE user_id = ' . $poster_id;
		$this->db->sql_query($sql);

		return $reputation;
	}

	protected function invalidate_leaderboard_cache(): void
	{
		$this->cache->destroy('_postvote_leaderboard_users');
		$this->cache->destroy('_postvote_leaderboard_posts');
	}

	/**
	 * Return a JSON error response.
	 */
	protected function error(string $lang_key, int $status = 400): JsonResponse
	{
		$this->language->add_lang('postvote', 'benjibabs/postvote');
		$message = $this->language->is_set($lang_key)
			? $this->language->lang($lang_key)
			: $lang_key;
		return new JsonResponse(['success' => false, 'error' => $message], $status);
	}
}
