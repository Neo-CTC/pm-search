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
 * PM Search UCP module.
 */
class main_module
{
	public $page_title;
	public $tpl_name;
	public $u_action;

	/**
	 * Main UCP module
	 *
	 * @param int    $id   The module ID
	 * @param string $mode The module mode (for example: manage or settings)
	 * @throws \Exception
	 */
	public function main($id, $mode)
	{
		/** @var \phpbb\request\request $request */
		global $phpbb_container, $request;

		/** @var \crosstimecafe\pmsearch\controller\ucp_controller $ucp_controller */
		$ucp_controller = $phpbb_container->get('crosstimecafe.pmsearch.controller.ucp');

		/** @var \phpbb\language\language $language */
		$language = $phpbb_container->get('language');
		$language->add_lang('search');

		// Set the page title for our UCP page
		$this->page_title = $language->lang('UCP_PMSEARCH_TITLE');

		// Make the $u_action url available in our UCP controller
		$ucp_controller->set_page_url($this->u_action);

		// Load the display options handle in our UCP controller
		if($mode == 'search')
		{
			// Todo validate and verify form

			// Todo I should probably use $mode for this
			if($request->variable('author', '') || $request->variable('keywords',''))
			{
				$this->tpl_name = 'ucp_pmsearch_results';
				$ucp_controller->display_messages();
			}
			else
			{
				$this->tpl_name = 'ucp_pmsearch_option';
				$ucp_controller->display_options();
			}
		}

	}
}
