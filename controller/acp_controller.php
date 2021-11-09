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
use mysqli;

//use mysqli;

const SphinxQL_ERR_CONNECTION = 1;
const SphinxQL_ERR_Data = 2;
const SphinxQL_ERR_PROGRAM = 3;

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

	private $sphinxql_error_msg;
	private $sphinxql_error_num;

	public function __construct(\phpbb\config\config $config, \phpbb\language\language $language, \phpbb\log\log $log, \phpbb\request\request $request, \phpbb\template\template $template, \phpbb\user $user, \phpbb\db\driver\driver_interface $db)
	{
		$this->config	= $config;
		$this->language	= $language;
		$this->log		= $log;
		$this->request	= $request;
		$this->template	= $template;
		$this->user		= $user;
		$this->db		= $db;

		//$this->functions = $phpbb_container->get('anavaro.pmsearch.search.helper')

		$conn = new Connection();
		$conn->setParams([
			'host'	=> $this->config['pmsearch_host'],
			'port'	=> $this->config['pmsearch_port'],
			'options' => [
				// Todo get error message for timeouts
				MYSQLI_OPT_CONNECT_TIMEOUT => 2,
			]
		]);
		$this->indexer = new SphinxQL($conn);
		$this->sphinxql_error_msg = '';
		$this->sphinxql_error_num = 0;
	}

	public function display_status()
	{


		/*
		 *
		 * Get Sphinx status
		 *
		 */


		// Todo adjust table name to match phpbb
		// Todo show create button if index is empty
		$index_exists = (bool) $this->get_sphinx_indexes('pm');

		if ($index_exists)
		{
			$version = $this->get_sphinx_version();
			$version = ($version[0] < 4) ? 'Sphinx ' . implode('.', $version) : 'Manticore ' . implode('.', $version);
			$this->template->assign_var('SPHINX_VERSION', $version);

			$this->indexer->query('SHOW INDEX pm STATUS');
			$result = $this->search_execute();
			if ($result)
			{
				$this->template->assign_var('SPHINX_STATUS', $this->language->lang('ACP_PMSEARCH_READY'));
				while ($row = $result->fetchAssoc())
				{
					switch ($row['Variable_name'])
					{
						// Todo get more variables
						case 'indexed_documents':
							$this->template->assign_var('TOTAL_MESSAGES', $row['Value']);
							break;
						case 'disk_bytes':
							$this->template->assign_var('INDEX_BYTES', round($row['Value'] / 1048576, 1) . ' MiB');
							break;
						case 'ram_bytes':
							$this->template->assign_var('RAM_BYTES', round($row['Value'] / 1048576, 1) . ' MiB');
							break;
					}
				}
			}
			else
			{
				switch ($this->sphinxql_error_num)
				{
					case SphinxQL_ERR_CONNECTION:
						$this->template->assign_var('SPHINX_STATUS', $this->language->lang('ACP_PMSEARCH_CONNECTION_ERROR'));
						break;
					case SphinxQL_ERR_Data:
						$this->template->assign_var('SPHINX_STATUS', $this->language->lang('ACP_PMSEARCH_NO_INDEX'));
						break;
					case SphinxQL_ERR_PROGRAM:
						$this->template->assign_var('SPHINX_STATUS', $this->sphinxql_error_msg);
						break;
				}
			}
		}
		else
		{
			$this->template->assign_var('SPHINX_STATUS', $this->language->lang('ACP_PMSEARCH_NO_INDEX'));
		}


		/*
		 *
		 * Get MySQL status
		 *
		 */


		$result = $this->db->sql_query('SHOW INDEX FROM '. PRIVMSGS_TABLE . ' WHERE key_name = "message_text"');
		if ($this->db->sql_fetchrow($result))
		{
			$this->template->assign_var('MYSQL_STATUS', $this->language->lang('ACP_PMSEARCH_READY'));
		}
		else
		{
			$this->template->assign_var('MYSQL_STATUS', $this->language->lang('ACP_PMSEARCH_NO_INDEX'));
		}


		/*
		 *
		 * Set various template variables
		 *
		 */


		$this->config['pmsearch_engine'] == 'sphinx'? $this->template->assign_var('SPHINX_ACTIVE', 1) : $this->template->assign_var('MYSQL_ACTIVE', 1);
	}

	public function display_options()
	{
		/*
		 *
		 * Set template variables
		 *
		 */


		$this->template->assign_vars([
			'U_ACTION'	=> $this->u_action,

			'enabled'		=> $this->config['pmsearch_enable'],
			'search_type'	=> $this->config['pmsearch_engine'],
			'host'			=> $this->config['pmsearch_host'],
			'port'			=> $this->config['pmsearch_port'],
		]);
	}

	public function save_settings()
	{
		/*
		 *
		 * Collect input
		 *
		 */


		$type = $this->request->variable('search_type','sphinx');
		$enabled = $this->request->variable('enable_search', 0);
		$host = $this->request->variable('hostname', '127.0.0.1');
		$port = $this->request->variable('port',9036);


		/*
		 *
		 * Filter input
		 *
		 */

		// Todo: validate host, maybe
		$port = ($port > 0 && $port <= 65535) ? $port : 9036;


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

		trigger_error($this->language->lang('CONFIG_UPDATED'),E_USER_NOTICE);
	}

	public function reindex()
	{
		// Todo reload page once finished


		/*
		 *
		 * Collect input
		 *
		 */


		$action = $this->request->variable('action', '');
		$engine = $this->request->variable('engine','');
		$time = microtime(true);

		if ($engine == 'sphinx')
		{


			/*
			 *
			 * Collect Sphinx index status
			 *
			 */


			// Get Sphinx version. There's a big difference between Sphinx and Manticore
			$version = $this->get_sphinx_version();
			if ($this->sphinxql_error_num == SphinxQL_ERR_CONNECTION)
			{
				// Can't connect to Sphinx
				trigger_error($this->language->lang('ACP_PMSEARCH_CONNECTION_ERROR'));
			}
			elseif ($version === false)
			{
				// Couldn't find the version number or another major error
				trigger_error($this->language->lang('ACP_PMSEARCH_PROGRAM_ERROR'),E_USER_ERROR);
			}

			// Does our index exist? Again, another big difference between Sphinx and Manticore
			$index_exists = (bool) $this->get_sphinx_indexes('pm');
			$message = '';


			/*
			 *
			 * Prep for indexing
			 *
			 */


			// Creating an index always starts with deleting any currently existing data
			if ($action == 'create' || $action == 'delete')
			{


				/*
				 *
				 * Delete from Sphinx
				 *
				 */


				if ($version[0] < 4 && $version[0] >= 2)
				{
					if ($index_exists)
					{
						// Sphinx can't delete the index, but it can delete the entries
						$this->indexer->query('DELETE FROM pm WHERE id != 0');
						$this->search_execute();
					}
				}


				/*
				 *
				 * Delete for Manticore
				 *
				 */


				elseif ($version[0] = 4)
				{
					// Clear the index
					if ($index_exists)
					{
						$this->indexer->query('DROP TABLE pm');
						$this->search_execute();
					}
				}
				else
				{
					// Unknown Sphinx/Manticore version
					trigger_error($this->language->lang('ACP_PMSEARCH_UNKNOWN_VERSION'), E_USER_WARNING);
				}

				// Set the message to send
				$message = $this->language->lang('ACP_PMSEARCH_DROP_DONE');
			}

			if ($action == 'create')
			{
				if ($version[0] < 4 && $version[0] >= 2)
				{


					/*
					 *
					 * Create index for Sphinx
					 *
					 */


					if (!$index_exists)
					{
						// Sphinx can't create a new index by itself
						trigger_error($this->language->lang('ACP_PMSEARCH_MISSING_TABLE'), E_USER_WARNING);
					}
				}
				elseif ($version[0] = 4)
				{


					/*
					 *
					 * Create index for Manticore
					 *
					 */


					$this->indexer->query('CREATE TABLE pm(author_id integer,user_id multi,message_time timestamp,message_subject text ,message_text text ,folder_id text indexed)');
					$this->search_execute();
				}


				/*
				 *
				 * Begin indexing
				 *
				 */


				// Todo replace the group concat with to_uid and to_gid from the private message table
				// Todo find a better logic for folder searching, maybe?
				// Todo try sql transactions
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
						// MySQL returns user_id as a string of ids; explode user_id into an array of integers
						$row['user_id'] = array_map('intval', explode(' ', $row['user_id']));
						$this->indexer->set($row);
					}
					if ($this->search_execute() === false)
					{
						// We got ourselves an error, most likely we ran out of RAM or disk space, or maybe
						// connection was lost.
						switch ($this->sphinxql_error_num)
						{
							case SphinxQL_ERR_CONNECTION:
								trigger_error($this->language->lang('ACP_PMSEARCH_CONNECTION_ERROR'),E_USER_WARNING);
								break;
							case SphinxQL_ERR_PROGRAM:
							default:
								// Todo send to log
								trigger_error($this->language->lang('ACP_PMSEARCH_PROGRAM_ERROR').'<br />'.$this->sphinxql_error_msg, E_USER_WARNING);
								break;
						}
					}
					$offset += 500;
					$result = $this->db->sql_query_limit($query,500,$offset);
				}

				// Not sure if this is needed, but it can't hurt
				$this->indexer->query('OPTIMIZE INDEX pm');
				$this->search_execute();

				// Set the message to send
				$message = $this->language->lang('ACP_PMSEARCH_INDEX_DONE');
			}


			/*
			 *
			 * Finial steps
			 *
			 */


			$message .= '<br />' . $this->language->lang('ACP_PMSEARCH_INDEX_STATS',round(microtime(true)-$time,1),round(memory_get_peak_usage()/1048576,2));
			trigger_error($message,E_USER_NOTICE);
		}
		elseif ($engine == 'mysql')
		{
			if ($action == 'delete')
			{
				// Alter table drop index
			}
			elseif ($action == 'create')
			{
				// Alter table add full text index
			}
		}
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	private function search_execute()
	{
		$this->sphinxql_error_num = 0;
		$this->sphinxql_error_msg = '';

		try
		{
			$result = $this->indexer->execute();
		}
		catch (ConnectionException $e)
		{
			// Todo update code to handle return codes
			$this->sphinxql_error_num = SphinxQL_ERR_CONNECTION;
			$this->sphinxql_error_msg = $e->getMessage();
			return false;
		}
		catch (DatabaseException $e)
		{
			$this->sphinxql_error_num = SphinxQL_ERR_Data;
			$this->sphinxql_error_msg = $e->getMessage();
			return false;
		}
		catch (SphinxQLException $e)
		{
			$this->sphinxql_error_num = SphinxQL_ERR_PROGRAM;
			$this->sphinxql_error_msg = $e->getMessage();
			return false;
		}
		return $result;
	}

	private function get_sphinx_version()
	{
		$this->indexer->query("SHOW STATUS LIKE 'version'");
		$result = $this->search_execute();
		if ($result === false)
		{
			return false;
		}

		if($result->count())
		{
			// Only Manticore 4+ returns a version
			$row = $result->fetchAssoc();
			return (preg_match('/^([\d.]+)/',$row['Value'],$m)) ? explode('.', $m) : false;
		}
		else
		{
			// More work is needed to find the version for Sphinx 2+
			$my = new mysqli($this->config['pmsearch_host'],'','','',$this->config['pmsearch_port']);
			$v = $my->get_server_info();
			return (preg_match('/^([\d.]+)/',$v,$m)) ? explode('.', $m[1]) : false;
		}
	}

	private function get_sphinx_indexes($index = false)
	{
		$index ? $this->indexer->query("SHOW TABLES LIKE '$index'") : $this->indexer->query('SHOW TABLES');
		$result = $this->search_execute();
		if($result)
		{
			$list = [];
			while($row = $result->fetchAssoc())
			{
				$list[] = $row['Value'];
			}
			return  $list;
		}
		else
		{
			trigger_error(print_r('Error?',true));
			return false;
		}
	}
}