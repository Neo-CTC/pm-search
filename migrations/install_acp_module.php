<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2021, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\migrations;

use phpbb\db\migration\migration;

class install_acp_module extends migration
{
	public function effectively_installed()
	{
		$sql       = 'SELECT module_id
			FROM ' . $this->table_prefix . "modules
			WHERE module_class = 'ucp'
				AND module_langname = 'ACP_PMSEARCH_TITLE'";
		$result    = $this->db->sql_query($sql);
		$module_id = $this->db->sql_fetchfield('module_id');
		$this->db->sql_freeresult($result);

		return $module_id !== false;
	}

	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v320\v320'];
	}

	public function update_data()
	{
		// Todo: Delete indexes on removal
		return [
			['module.add', [
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_PMSEARCH_TITLE',
			]],
			['module.add', [
				'acp',
				'ACP_PMSEARCH_TITLE',
				[
					'module_basename' => '\crosstimecafe\pmsearch\acp\main_module',
					'modes'           => ['settings','status'],
				],
			]],
			['config.add', ['pmsearch_enable', 0]],
			['config.add', ['pmsearch_sphinx_ready', 0]],
			['config.add', ['pmsearch_engine', 'sphinx']],
			['config.add', ['pmsearch_host', '127.0.0.1']],
			['config.add', ['pmsearch_port', '9306']],
		];
	}
}
