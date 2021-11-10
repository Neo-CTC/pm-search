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

class main_module
{
	public $page_title;
	public $tpl_name;
	public $u_action;

	public function main($id, $mode)
	{


		/*
		 *
		 * Setup variables
		 *
		 */


		global $phpbb_container;
		$acp_controller = $phpbb_container->get('crosstimecafe.pmsearch.controller.acp');
		$language       = $phpbb_container->get('language');
		$request        = $phpbb_container->get('request');

		$action = $request->variable('action', '');
		$engine = $request->variable('engine', '');

		$language->add_lang('acp/search');


		/*
		 *
		 * Perform an action or display a mode
		 *
		 */


		if ($action)
		{
			switch ($action)
			{
				// Save search settings
				case 'settings':
					$acp_controller->save_settings();
					break;

				// Start search reindex
				case 'delete':
				case 'create':
					if (!$engine)
					{
						trigger_error($language->lang('ACP_PMSEARCH_PROGRAM_ERROR'), E_USER_WARNING);
					}
					if (confirm_box(true))
					{
						$acp_controller->reindex();
					}
					else
					{
						$fields = build_hidden_fields(['action' => $action, 'engine' => $engine]);
						confirm_box(false, $language->lang('CONFIRM_OPERATION'), $fields);
					}
					break;
			}
		}
		else
		{
			switch ($mode)
			{
				// Display settings page
				case 'settings':
					$this->tpl_name   = 'acp_pmsearch_body';
					$this->page_title = $language->lang('ACP_PMSEARCH_TITLE') . ' - ' . $language->lang('ACP_PMSEARCH_MODE_SETTINGS');
					$acp_controller->set_page_url($this->u_action);
					$acp_controller->display_options();
					break;

				// Display status page by default
				case 'status':
				default:
					$this->tpl_name   = 'acp_pmsearch_status';
					$this->page_title = $language->lang('ACP_PMSEARCH_TITLE') . ' - ' . $language->lang('ACP_PMSEARCH_MODE_STATUS');
					$acp_controller->set_page_url($this->u_action);
					$acp_controller->display_status();
			}
		}
	}
}
