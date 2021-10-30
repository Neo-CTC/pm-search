<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2020, NeoDev
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\controller;

use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use Foolz\SphinxQL\SphinxQL;

class acp_controller
{
	protected $config;
	protected $language;
	protected $log;
	protected $request;
	protected $template;
	protected $user;
	protected $db;
	private $indexer;
	private $u_action;

	public function __construct(\phpbb\config\config $config, \phpbb\language\language $language, \phpbb\log\log $log, \phpbb\request\request $request, \phpbb\template\template $template, \phpbb\user $user, \phpbb\db\driver\driver_interface $db)
	{
		$this->config	= $config;
		$this->language	= $language;
		$this->log		= $log;
		$this->request	= $request;
		$this->template	= $template;
		$this->user		= $user;
		$this->db		= $db;
		$this->indexer = new SphinxQL(new Connection());
	}

	public function display_options()
	{
		// Todo error checks: missing table, searchd offline, etc
		$this->indexer->query('SHOW INDEX pm STATUS');
		$result = $this->search_execute();

		$this->template->assign_var('INDEX_VERSION', 'Sphinx');
		$this->template->assign_var('TOTAL_MESSAGES', 'N/A');
		$this->template->assign_var('INDEX_BYTES', 'N/A');
		$this->template->assign_var('RAM_BYTES', 'N/A');
		while($row = $result->fetchAssoc())
		{
			switch($row['Variable_name'])
			{
				case 'indexed_documents':
					$this->template->assign_var('TOTAL_MESSAGES', $row['Value']);
					break;
				case 'disk_bytes':
					$this->template->assign_var('INDEX_BYTES', round($row['Value']/1048576, 1). ' MiB');
					break;
				case 'ram_bytes':
					$this->template->assign_var('RAM_BYTES', round($row['Value']/1048576, 1). ' MiB');
					break;
			}
		}

		$this->indexer->query('SHOW STATUS');
		$result = $this->search_execute();
		while($row = $result->fetchAssoc())
		{
			switch ($row['Counter'])
			{
				case 'version':
					$v = $row['Value'];
					$v = 'Manticore ' .substr($v, 0, strpos($v, ' '));
					$this->template->assign_var('INDEX_VERSION', $v);
			}
		}

		$this->template->assign_vars([
			'U_ACTION'	=> $this->u_action,
		]);

	}

	public function reindex()
	{
		// Todo reload page once finished
		$time = microtime(true);


		$this->indexer->query("SHOW STATUS LIKE 'version'");
		$result = $this->search_execute();
		if($result->count())
		{
			// Manticore
			$this->indexer->query("SHOW TABLES LIKE 'pm'");
			$result = $this->search_execute();
			if ($result->count())
			{
				$this->indexer->query('DROP TABLE pm');
				$this->search_execute($this->language->lang('ACP_PMSEARCH_INDEX_ERR_DROP'));
			}

			$this->indexer->query('CREATE TABLE pm(author_id integer,user_id multi,message_time timestamp,message_subject text ,message_text text ,folder_id text indexed)');
			$this->search_execute($this->language->lang('ACP_PMSEARCH_INDEX_ERR_CREATE'));
		}
		else
		{
			// Sphinx
			$this->indexer->query("SHOW TABLES LIKE 'pm'");
			$result = $this->search_execute();
			if (!$result->count())
			{
				trigger_error('Private message table missing',E_USER_WARNING);
			}
			$this->indexer->select('id')->from('pm')->limit(500);
			$result = $this->search_execute();
			while($rows = $result->fetchAllNum())
			{
				$id_arr = [];
				foreach ($rows as $row)
				{
					$id_arr[] = (int)$row[0];
				}
				$this->indexer->delete()->from('pm')->where('id','in',$id_arr);
				$this->search_execute();
				$this->indexer->select('id')->from('pm')->limit(500);
				$result = $this->search_execute();
			}
		}

		$offset = 0;
		$query = "SELECT
		 	p.msg_id as id,
		 	p.author_id author_id,
		 	GROUP_CONCAT(t.user_id SEPARATOR ' ') user_id,
		 	p.message_time,
		 	p.message_subject,
		 	p.message_text,
		 	GROUP_CONCAT( CONCAT(t.user_id,'_',t.folder_id) SEPARATOR ' ') folder_id
			FROM ".PRIVMSGS_TABLE. ' p
			JOIN ' .PRIVMSGS_TO_TABLE. ' t ON p.msg_id = t.msg_id
			WHERE t.pm_deleted = 0
			GROUP BY p.msg_id
			ORDER BY p.msg_id ASC';
		$result = $this->db->sql_query_limit($query,500);
		while($rows = $this->db->sql_fetchrowset($result))
		{
			$this->indexer->insert()->into('pm');
			foreach ($rows as $row)
			{
				$row['user_id'] = array_map('intval', explode(' ', $row['user_id']));
				$this->indexer->set($row);
			}
			$this->search_execute('Error inserting rows');
			$offset += 500;
			$result = $this->db->sql_query_limit($query,500,$offset);
		}

		$this->indexer->query('OPTIMIZE INDEX pm');
		$this->search_execute();

		$message = $this->language->lang('ACP_PMSEARCH_DONE') . '<br />';
		$message .= 'Index time: '.round(microtime(true)-$time,1).' seconds<br /> Peek memory usage: '.round(memory_get_peak_usage()/1048576,2).'MiB';

		trigger_error($message,E_USER_NOTICE);
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}
	private function search_execute($err_message = '')
	{
		$result = false;
		try
		{
			$result = $this->indexer->execute();
		}
		catch (ConnectionException $e)
		{
			trigger_error($this->language->lang('ACP_PMSEARCH_CONNECTION_ERROR'),E_USER_WARNING);
		}
		catch (DatabaseException $e)
		{
			$err_message? trigger_error($err_message,E_USER_WARNING) : trigger_error($e->getMessage(),E_USER_WARNING);
		}
		catch (SphinxQLException $e)
		{
			trigger_error($e->getMessage(),E_USER_ERROR);
		}
		return $result;
	}
}