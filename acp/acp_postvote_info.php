<?php
/**
 * PostVote extension for phpBB.
 *
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace benjibabs\postvote\acp;

class acp_postvote_info
{
	public function module(): array
	{
		return [
			'filename' => '\benjibabs\postvote\acp\acp_postvote_module',
			'title'    => 'ACP_POSTVOTE',
			'modes'    => [
				'settings' => [
					'title' => 'ACP_POSTVOTE_SETTINGS',
					'auth'  => 'ext_benjibabs/postvote && acl_a_board',
					'cat'   => ['ACP_CAT_DOT_MODS'],
				],
			],
		];
	}
}
