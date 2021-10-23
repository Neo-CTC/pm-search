<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2021, NeoDev
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\ucp;

/**
 * PM Search UCP module info.
 */
class main_info
{
	public function module()
	{
		return [
			'filename'	=> '\crosstimecafe\pmsearch\ucp\main_module',
			'title'		=> 'UCP_PMSEARCH_TITLE',
			'modes'		=> [
				'search'	=> [
					'title'	=> 'UCP_PMSEARCH',
					'auth'	=> 'ext_crosstimecafe/pmsearch',
					'cat'	=> ['UCP_PM'],
				],
			],
		];
	}
}
