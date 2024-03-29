<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2021, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\ucp;


use phpbb\json_response;

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
	 * @param string $id   The module ID
	 * @param string $mode The module mode (for example: manage or settings)
	 *
	 * @throws \Exception
	 */
	public function main($id, $mode)
	{
		// Fetch the controller
		/** @var \phpbb\request\request $request */
		global $phpbb_container, $request;

		/** @var \crosstimecafe\pmsearch\controller\ucp_controller $ucp_controller */
		$ucp_controller = $phpbb_container->get('crosstimecafe.pmsearch.controller.ucp');

		// Load language settings
		/** @var \phpbb\language\language $language */
		$language = $phpbb_container->get('language');
		$language->add_lang('search');
		$this->page_title = $language->lang('UCP_PMSEARCH_TITLE');

		// Load controller settings
		$ucp_controller->set_page_url($this->u_action);

		// Select controller function
		if ($mode == 'search')
		{
			// Collect input variables
			$keywords = $request->variable('keywords', '', true);
			$from     = $request->variable('from', '', true);
			$sent     = $request->variable('sent', '', true);

			$action = $request->variable('action', '');

			// Select function
			if ($keywords || $from || $sent)
			{
				$this->tpl_name = 'ucp_pmsearch_results';
				$ucp_controller->display_messages();
			}

			else if ($action)
			{
				$response = $ucp_controller->pm_actions();
				if ($request->is_ajax())
				{
					$json_response = new json_response;
					$json_response->send($response);
				}
				// TODO: handle non javascript actions
			}
			else
			{
				$this->tpl_name = 'ucp_pmsearch_option';
				$ucp_controller->display_options();
			}
		}
	}
}
