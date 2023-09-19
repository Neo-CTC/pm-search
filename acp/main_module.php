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

		/** @var \crosstimecafe\pmsearch\controller\acp_controller $acp_controller */
		$acp_controller = $phpbb_container->get('crosstimecafe.pmsearch.controller.acp');

		// You are here → X
		$acp_controller->set_page_url($this->u_action);

		/** @var \phpbb\language\language $language */
		$language = $phpbb_container->get('language');
		$language->add_lang('acp/search');

		/** @var \phpbb\request\request $request */
		$request = $phpbb_container->get('request');
		$action  = $request->variable('action', '');
		$engine  = $request->variable('engine', '');


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
					// Invalid engine, stop here
					if (!$engine || !in_array($engine, ['sphinx', 'mysql','postgres']))
					{
						trigger_error($language->lang('ERROR'), E_USER_WARNING);
					}

					if (confirm_box(true))
					{
						$response = $acp_controller->maintenance($action, $engine);
						if ($request->is_ajax())
						{
							$json_response = new \phpbb\json_response;

							// Don't refresh on error
							if ($response['REFRESH_DATA']['time'] == 0)
							{
								unset($response['REFRESH_DATA']);
							}
							$json_response->send($response);
						}
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
					$acp_controller->display_options();
				break;

				// Display status page by default
				case 'status':
				default:
					$this->tpl_name   = 'acp_pmsearch_status';
					$this->page_title = $language->lang('ACP_PMSEARCH_TITLE') . ' - ' . $language->lang('ACP_PMSEARCH_MODE_STATUS');
					$acp_controller->display_status();
			}
		}
	}
}
