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

class main_module
{
	public $page_title;
	public $tpl_name;
	public $u_action;

	/**
	 * Main ACP module
	 *
	 * @param int    $id   The module ID
	 * @param string $mode The module mode (for example: manage or settings)
	 * @throws \Exception
	 */
	public function main($id, $mode)
	{
		global $phpbb_container;

		$acp_controller = $phpbb_container->get('crosstimecafe.pmsearch.controller.acp');
		$language = $phpbb_container->get('language');

		$request = $phpbb_container->get('request');
		$action = $request->variable('action', '');
		switch($action)
		{
			case 'settings':
				trigger_error('Not yet ready at this time');
				break;
			case 'reindex':
				if(confirm_box(true))
				{
					$acp_controller->reindex();
				}
				else
				{
					$fields = build_hidden_fields(['action' => 'reindex']);
					confirm_box(false,$language->lang('CONFIRM_OPERATION'),$fields);
				}
			break;
			default:
				$this->tpl_name = 'acp_pmsearch_body';
				$this->page_title = $language->lang('ACP_PMSEARCH_TITLE');
				$acp_controller->set_page_url($this->u_action);
				$acp_controller->display_options();
			break;
		}
	}
}
