<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2020, NeoDev
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\acp;

/**
 * PM Search ACP module info.
 */
class main_info
{
	public function module()
	{
		return [
			'filename'	=> '\crosstimecafe\pmsearch\acp\main_module',
			'title'		=> 'ACP_PMSEARCH_TITLE',
			'modes'		=> [
				'settings'	=> [
					'title'	=> 'ACP_PMSEARCH',
					'auth'	=> 'ext_crosstimecafe/pmsearch && acl_a_board',
					'cat'	=> ['ACP_PMSEARCH_TITLE'],
				],
			],
		];
	}
}
