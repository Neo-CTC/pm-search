<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2020, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\acp;

class main_info
{
	public function module()
	{
		return [
			'filename' => '\crosstimecafe\pmsearch\acp\main_module',
			'title'    => 'ACP_PMSEARCH_TITLE',
			'modes'    => [
				'status'   => [
					'title' => 'ACP_PMSEARCH_MODE_STATUS',
					'auth'  => 'ext_crosstimecafe/pmsearch && acl_a_board', // acl_a_board: Can alter board settings/check for updates, basically any admin
					'cat'   => ['ACP_PMSEARCH_TITLE'],
				],
				'settings' => [
					'title' => 'ACP_PMSEARCH_MODE_SETTINGS',
					'auth'  => 'ext_crosstimecafe/pmsearch && acl_a_board', // acl_a_board: Can alter board settings/check for updates, basically any admin
					'cat'   => ['ACP_PMSEARCH_TITLE'],
				],
			],
		];
	}
}
