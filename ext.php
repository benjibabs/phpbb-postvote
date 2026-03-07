<?php
/**
 * PostVote extension for phpBB.
 *
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace benjibabs\postvote;

class ext extends \phpbb\extension\base
{
	/**
	 * {@inheritDoc}
	 */
	public function is_enableable(): bool
	{
		$config = $this->container->get('config');
		return phpbb_version_compare($config['version'], '3.3.0', '>=');
	}
}
