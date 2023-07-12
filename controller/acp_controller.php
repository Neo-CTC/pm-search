<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2020, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\controller;

use crosstimecafe\pmsearch\core\mysqlSearch;
use crosstimecafe\pmsearch\core\sphinxSearch;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;
use phpbb\log\log;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;

class acp_controller
{
	protected $config;
	protected $language;
	protected $log;
	protected $request;
	protected $template;
	protected $user;
	protected $db;
	private $u_action;

	public function __construct(config $config, language $language, log $log, request $request, template $template, user $user, driver_interface $db)
	{
		$this->config   = $config;
		$this->language = $language;
		$this->log      = $log;
		$this->request  = $request;
		$this->template = $template;
		$this->user     = $user;
		$this->db       = $db;
	}

	public function display_status()
	{
		// Todo check for incompatible index versions
		$sphinx = new sphinxSearch(0, $this->config, $this->language, $this->db);
		$this->template->assign_vars($sphinx->status());

		if ($this->db->get_sql_layer() == 'mysqli')
		{
			$mysql = new mysqlSearch(0, $this->config, $this->language, $this->db);
			$this->template->assign_vars($mysql->status());
		}
		else
		{
			$this->template->assign_var('MYSQL_STATUS', $this->language->lang('FULLTEXT_MYSQL_INCOMPATIBLE_DATABASE'));
			$this->template->assign_var('MYSQL_SKIP', 1);
		}

		switch ($this->config['pmsearch_engine'])
		{
			case 'sphinx':
				$this->template->assign_var('SPHINX_ACTIVE', 1);
				break;
			case 'mysql':
				$this->template->assign_var('MYSQL_ACTIVE', 1);
				break;
		}
	}

	public function display_options()
	{
		/*
		 *
		 * Set template variables
		 *
		 */


		add_form_key('crosstimecafe_pmsearch_acp_settings');

		$this->template->assign_vars([
			'U_ACTION' => $this->u_action,

			'enabled'     => $this->config['pmsearch_enable'],
			'search_type' => $this->config['pmsearch_engine'],
			'host'        => $this->config['pmsearch_host'],
			'port'        => $this->config['pmsearch_port'],
		]);
	}

	public function save_settings()
	{
		/*
		 *
		 * Validate form
		 *
		 */


		if (!check_form_key('crosstimecafe_pmsearch_acp_settings'))
		{
			trigger_error($this->language->lang('FORM_INVALID'));
		}


		/*
		 *
		 * Collect input
		 *
		 */


		$type    = $this->request->variable('search_type', 'sphinx');
		$enabled = $this->request->variable('enable_search', 0);
		$host    = $this->request->variable('hostname', '127.0.0.1');
		$port    = $this->request->variable('port', 9306);


		/*
		 *
		 * Validate input
		 *
		 */

		// Todo: validate host, maybe
		$port = ($port > 0 && $port <= 65535) ? $port : 9306;


		/*
		 *
		 * Save settings
		 *
		 */


		$enabled ? $this->config->set('pmsearch_enable', 1) : $this->config->set('pmsearch_enable', 0);

		if ($type == 'sphinx')
		{
			$this->config->set('pmsearch_engine', 'sphinx');
			$this->config->set('pmsearch_host', $host);
			$this->config->set('pmsearch_port', $port);
		}
		else
		{
			$this->config->set('pmsearch_engine', 'mysql');
		}

		trigger_error($this->language->lang('CONFIG_UPDATED'), E_USER_NOTICE);
	}

	public function maintenance($action, $engine)
	{
		switch ($engine)
		{
			case 'sphinx':
				$backend = new sphinxSearch(0, $this->config, $this->language, $this->db);
				break;
			case 'mysql':
				$backend = new mysqlSearch(0, $this->config, $this->language, $this->db);
				break;
			default:
				break;
		}

		switch ($action)
		{
			case 'delete':
				$backend->delete_index();
				trigger_error($this->language->lang('ACP_PMSEARCH_DROP_DONE'));
				break;

			case 'create':
				// Collect the starting time, indexing takes a long time
				$time = microtime(true);

				$backend->reindex();

				// Set the message to send
				$message = $this->language->lang('ACP_PMSEARCH_INDEX_DONE');
				$message .= '<br />' . $this->language->lang('ACP_PMSEARCH_INDEX_STATS', round(microtime(true) - $time, 1), round(memory_get_peak_usage() / 1048576, 2));
				trigger_error($message);
				break;
			default:
				break;
		}
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}
	
	private function get_backend($engine)
	{
		
	}

}
