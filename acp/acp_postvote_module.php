<?php
/**
 * PostVote extension for phpBB.
 *
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace benjibabs\postvote\acp;

class acp_postvote_module
{
	/** @var string */
	public $page_title;

	/** @var string */
	public $tpl_name;

	/** @var string */
	public $u_action;

	public function main(string $id, string $mode): void
	{
		global $phpbb_container;

		/** @var \phpbb\config\config $config */
		$config = $phpbb_container->get('config');

		/** @var \phpbb\language\language $language */
		$language = $phpbb_container->get('language');

		/** @var \phpbb\request\request $request */
		$request = $phpbb_container->get('request');

		/** @var \phpbb\template\template $template */
		$template = $phpbb_container->get('template');

		// Load language file
		$language->add_lang('postvote', 'benjibabs/postvote');

		$this->tpl_name   = 'acp_postvote';
		$this->page_title = $language->lang('ACP_POSTVOTE_SETTINGS');

		if ($mode !== 'settings')
		{
			return;
		}

		$form_key = 'acp_postvote';
		add_form_key($form_key);

		$errors = [];
		$submit = $request->is_set_post('submit');

		// Validate CSRF on submit
		if ($submit && !check_form_key($form_key))
		{
			$errors[] = $language->lang('FORM_INVALID');
		}

		if ($submit && empty($errors))
		{
			$postvote_enabled      = $request->variable('postvote_enabled', 0);
			$postvote_allow_dv     = $request->variable('postvote_allow_downvote', 0);
			$postvote_rate_limit   = max(1, min(1000, $request->variable('postvote_rate_limit', 10)));
			$postvote_rate_period  = max(10, min(3600, $request->variable('postvote_rate_period', 60)));
			$postvote_cache_ttl    = max(0, min(86400, $request->variable('postvote_cache_ttl', 300)));

			$config->set('postvote_enabled',        $postvote_enabled);
			$config->set('postvote_allow_downvote', $postvote_allow_dv);
			$config->set('postvote_rate_limit',     $postvote_rate_limit);
			$config->set('postvote_rate_period',    $postvote_rate_period);
			$config->set('postvote_cache_ttl',      $postvote_cache_ttl);

			trigger_error($language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		$template->assign_vars([
			'S_ERROR'                    => !empty($errors),
			'ERROR_MSG'                  => implode('<br>', $errors),

			'POSTVOTE_ENABLED'           => (bool) $config['postvote_enabled'],
			'POSTVOTE_ALLOW_DOWNVOTE'    => (bool) $config['postvote_allow_downvote'],
			'POSTVOTE_RATE_LIMIT'        => (int) $config['postvote_rate_limit'],
			'POSTVOTE_RATE_PERIOD'       => (int) $config['postvote_rate_period'],
			'POSTVOTE_CACHE_TTL'         => (int) $config['postvote_cache_ttl'],

			'U_ACTION'                   => $this->u_action,
		]);
	}
}
