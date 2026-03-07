<?php
/**
 * PostVote extension for phpBB.
 *
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace benjibabs\postvote\migrations;

class v1_initial_schema extends \phpbb\db\migration\migration
{
	public static function depends_on(): array
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	/**
	 * Already installed when vote_score column exists in phpbb_posts.
	 */
	public function effectively_installed(): bool
	{
		return $this->db_tools->sql_column_exists($this->table_prefix . 'posts', 'vote_score');
	}

	/**
	 * Create/update schema.
	 * Handles the case where phpbb_post_votes already exists from a previous extension.
	 */
	public function update_schema(): array
	{
		$schema = [];

		// Only create the votes table if it does not already exist
		if (!$this->db_tools->sql_table_exists($this->table_prefix . 'post_votes'))
		{
			$schema['add_tables'] = [
				$this->table_prefix . 'post_votes' => [
					'COLUMNS' => [
						'vote_id'    => ['UINT', null, 'auto_increment'],
						'post_id'    => ['UINT', 0],
						'user_id'    => ['UINT', 0],
						'vote_value' => ['TINT:1', 0],
						'vote_time'  => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'vote_id',
					'KEYS' => [
						'pv_post_user' => ['UNIQUE', ['post_id', 'user_id']],
						'pv_post_id'   => ['INDEX',  ['post_id']],
						'pv_user_id'   => ['INDEX',  ['user_id']],
					],
				],
			];
		}

		// Always add the vote aggregate columns to posts and users (guard via effectively_installed)
		$schema['add_columns'] = [
			$this->table_prefix . 'posts' => [
				'vote_score' => ['INT:11', 0],
				'vote_up'    => ['UINT', 0],
				'vote_down'  => ['UINT', 0],
			],
			$this->table_prefix . 'users' => [
				'reputation' => ['INT:11', 0],
			],
		];

		return $schema;
	}

	/**
	 * Drop table and columns on revert.
	 */
	public function revert_schema(): array
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'post_votes',
			],
			'drop_columns' => [
				$this->table_prefix . 'posts' => ['vote_score', 'vote_up', 'vote_down'],
				$this->table_prefix . 'users' => ['reputation'],
			],
		];
	}

	/**
	 * Install config values, permissions, and ACP module.
	 */
	public function update_data(): array
	{
		return [
			// Config
			['config.add', ['postvote_enabled', 1]],
			['config.add', ['postvote_rate_limit', 10]],
			['config.add', ['postvote_rate_period', 60]],
			['config.add', ['postvote_allow_downvote', 1]],
			['config.add', ['postvote_reputation_per_up', 1]],
			['config.add', ['postvote_reputation_per_down', -1]],
			['config.add', ['postvote_cache_ttl', 300]],

			// Permissions
			['permission.add', ['u_postvote', true]],
			['permission.add', ['u_postvote_down', true]],

			// Set default role permissions
			['permission.permission_set', ['ROLE_USER_STANDARD', 'u_postvote', 'role', true]],
			['permission.permission_set', ['ROLE_USER_STANDARD', 'u_postvote_down', 'role', true]],
			['permission.permission_set', ['ROLE_USER_NEW_MEMBER', 'u_postvote', 'role', true]],

			// ACP module
			['module.add', [
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_POSTVOTE',
			]],
			['module.add', [
				'acp',
				'ACP_POSTVOTE',
				[
					'module_basename' => '\benjibabs\postvote\acp\acp_postvote_module',
					'modes'           => ['settings'],
				],
			]],
		];
	}

	/**
	 * Remove config values, permissions, and ACP module on revert.
	 */
	public function revert_data(): array
	{
		return [
			['config.remove', ['postvote_enabled']],
			['config.remove', ['postvote_rate_limit']],
			['config.remove', ['postvote_rate_period']],
			['config.remove', ['postvote_allow_downvote']],
			['config.remove', ['postvote_reputation_per_up']],
			['config.remove', ['postvote_reputation_per_down']],
			['config.remove', ['postvote_cache_ttl']],
			['permission.remove', ['u_postvote']],
			['permission.remove', ['u_postvote_down']],
			['module.remove', [
				'acp',
				'ACP_POSTVOTE',
				[
					'module_basename' => '\benjibabs\postvote\acp\acp_postvote_module',
					'modes'           => ['settings'],
				],
			]],
		];
	}
}
