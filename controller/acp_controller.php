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

use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use Foolz\SphinxQL\SphinxQL;
use mysqli;

const SphinxQL_ERR_CONNECTION = 1;
const SphinxQL_ERR_DATA       = 2;
const SphinxQL_ERR_PROGRAM    = 3;

class acp_controller
{
	protected $config;
	protected $language;
	protected $log;
	protected $request;
	protected $template;
	protected $user;
	protected $db;
	private   $sphinx_QL;
	private   $u_action;

	private $sphinxql_error_msg;
	private $sphinxql_error_num;
	private $sphinx_id;

	private $mysql_indexes;


	public function __construct(\phpbb\config\config $config, \phpbb\language\language $language, \phpbb\log\log $log, \phpbb\request\request $request, \phpbb\template\template $template, \phpbb\user $user, \phpbb\db\driver\driver_interface $db)
	{
		$this->config   = $config;
		$this->language = $language;
		$this->log      = $log;
		$this->request  = $request;
		$this->template = $template;
		$this->user     = $user;
		$this->db       = $db;

		// Prep connection to Sphinx
		$conn = new Connection();
		$conn->setParams([
			'host'    => $this->config['pmsearch_host'],
			'port'    => $this->config['pmsearch_port'],
			'options' => [
				MYSQLI_OPT_CONNECT_TIMEOUT => 2,
			],
		]);
		$this->sphinx_QL          = new SphinxQL($conn);
		$this->sphinxql_error_msg = '';
		$this->sphinxql_error_num = 0;

		// Create new sphinx id if needed
		if (!$this->config['fulltext_sphinx_id'])
			{
				$this->config->set('fulltext_sphinx_id', unique_id());
			}
		$this->sphinx_id = 'index_phpbb_' . $this->config['fulltext_sphinx_id'] . '_private_messages';

		// List of full-text MySQL indexes needed for searching
		$this->mysql_indexes = ['message_subject','message_text','message_content','to_address'];
	}

	public function display_status()
	{
		// Todo check for incompatible index versions


		/*
		 *
		 * Get version
		 *
		 */


		// Start by trying to get the version
		$version = $this->get_sphinx_version();

		// If we have a version we are connected, time for more stats
		if ($version)
		{
			// Sphinx: 0; Manticore: 1
			$this->template->assign_var('SPHINX_VERSION', ($version[3] == 0 ? 'Sphinx ' : 'Manticore ') . implode('.', array_slice($version, 0, 3)));


			/*
			 *
			 * Get index status
			 *
			 */


			$index_exists = (bool) $this->get_sphinx_indexes($this->sphinx_id);
			if ($index_exists)
			{


				/*
				 *
				 * Normal index status
				 *
				 */


				$this->sphinx_QL->query('SHOW INDEX ' . $this->sphinx_id . ' STATUS');
				$result = $this->search_execute();
				if ($result)
				{
					$total = 0;
					while ($row = $result->fetchAssoc())
					{
						switch ($row['Variable_name'])
						{
							// Todo get more variables
							case 'indexed_documents':
								$this->template->assign_var('TOTAL_MESSAGES', $row['Value']);
								$total = (int) $row['Value'];
								break;
							case 'disk_bytes':
								$this->template->assign_var('INDEX_BYTES', round($row['Value'] / 1048576, 1) . ' MiB');
								break;
							case 'ram_bytes':
								$this->template->assign_var('RAM_BYTES', round($row['Value'] / 1048576, 1) . ' MiB');
								break;
						}
					}

					$this->template->assign_var('SPHINX_STATUS', $total ? $this->language->lang('ACP_PMSEARCH_READY') : $this->language->lang('ACP_PMSEARCH_INDEX_EMPTY'));
				}
				else
				{
					switch ($this->sphinxql_error_num)
					{
						case SphinxQL_ERR_CONNECTION:
							$this->template->assign_var('SPHINX_STATUS', $this->language->lang('ACP_PMSEARCH_CONNECTION_ERROR'));
							break;
						case SphinxQL_ERR_DATA:
							$this->template->assign_var('SPHINX_STATUS', $this->language->lang('ACP_PMSEARCH_NO_INDEX_CREATE'));
							break;
						case SphinxQL_ERR_PROGRAM:
							$this->template->assign_var('SPHINX_STATUS', $this->sphinxql_error_msg);
							break;
					}
				}
			}
			elseif ($version[3] == 1 || $version[0] >= 3)
			{


				/*
				 *
				 * Manticore or Sphinx 3.x with missing index
				 *
				 */


				// A missing index is no big deal for Manticore as it can create a new index by itself
				// The same is true for the newest Sphinx version.
				// Therefore, zeros for everything
				$this->template->assign_var('SPHINX_STATUS', $this->language->lang('ACP_PMSEARCH_INDEX_EMPTY'));
				$this->template->assign_var('TOTAL_MESSAGES', 0);
				$this->template->assign_var('INDEX_BYTES', '0 MiB');
				$this->template->assign_var('RAM_BYTES', '0 MiB');
			}
			elseif ($version[0] == 2 && $version[3] == 0)
			{


				/*
				 *
				 * Sphinx 2.x with missing index
				 *
				 */


				// Sphinx 2.x can not create the index by itself, complain to user
				$this->template->assign_vars([
					'SPHINX_STATUS' => $this->language->lang('ACP_PMSEARCH_NO_INDEX_CREATE'),
					'SHOW_CONFIG'   => 1,
					'DATA_ID'       => $this->sphinx_id,
					'DATA_PATH'     => $this->config['fulltext_sphinx_data_path'] . $this->sphinx_id,
				]);
			}
			else
			{
				switch ($this->sphinxql_error_num)
				{
					case SphinxQL_ERR_CONNECTION:
						$this->template->assign_var('SPHINX_STATUS', $this->language->lang('ACP_PMSEARCH_CONNECTION_ERROR'));
						break;
					case SphinxQL_ERR_DATA:
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
			$this->template->assign_var('SPHINX_STATUS', $this->language->lang('ACP_PMSEARCH_CONNECTION_ERROR'));
		}


		/*
		 *
		 * Get MySQL status
		 *
		 */

		if ($this->db->get_sql_layer() == 'mysqli')
		{

			// Fetch list of all indexes
			$indexes = $this->get_mysql_indexes();

			// Todo we can probably do better than a simple count of returned indexes
			$i = count($indexes);
			if ($i == count($this->mysql_indexes))
			{
				$this->template->assign_var('MYSQL_STATUS', $this->language->lang('ACP_PMSEARCH_READY'));
			}
			elseif ($i > 0)
			{
				$this->template->assign_var('MYSQL_STATUS', $this->language->lang('ACP_PMSEARCH_INCOMPLETE'));
			}
			else
			{
				$this->template->assign_var('MYSQL_STATUS', $this->language->lang('ACP_PMSEARCH_NO_INDEX'));
			}
		}
		else
		{
			$this->template->assign_var('MYSQL_STATUS', $this->language->lang('FULLTEXT_MYSQL_INCOMPATIBLE_DATABASE'));
			$this->template->assign_var('MYSQL_SKIP', 1);
		}


		/*
		 *
		 * Set various template variables
		 *
		 */


		$this->config['pmsearch_engine'] == 'sphinx' ? $this->template->assign_var('SPHINX_ACTIVE', 1) : $this->template->assign_var('MYSQL_ACTIVE', 1);
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

	public function reindex()
	{
		// Todo reload page once finished


		/*
		 *
		 * Collect input
		 *
		 */


		$action = $this->request->variable('action', '');
		$engine = $this->request->variable('engine', '');

		if (!in_array($action, ['create', 'delete']) || !in_array($engine, ['sphinx', 'mysql']))
		{
			// Unknown command, we stop here
			return;
		}

		// Collect the starting time, indexing takes a long time
		$time = microtime(true);


		/*
		 *
		 * Sphinx index
		 *
		 */


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
				trigger_error($this->language->lang('ACP_PMSEARCH_PROGRAM_ERROR'), E_USER_ERROR);
			}

			// Does our index exist? Another big difference between Sphinx and Manticore
			$index_exists = (bool) $this->get_sphinx_indexes($this->sphinx_id);
			if ($version[0] == 2 && $index_exists == false)
			{
				// Sphinx 2.x can't do anything unless the index exists
				trigger_error($this->language->lang('ACP_PMSEARCH_NO_INDEX_CREATE'), E_USER_WARNING);
			}


			/*
			 *
			 * Delete index
			 *
			 */


			// Creating/reindexing an index always starts with deleting any currently existing data
			if ($version[0] == 2)
			{
				// Sphinx can't delete the index, but it can delete the entries
				$this->sphinx_QL->query('DELETE FROM ' . $this->sphinx_id . ' WHERE id != 0');
				$this->search_execute();
			}
			// Can't delete an index if it doesn't exist
			elseif ($index_exists)
			{
				// Dropping the index from orbit
				$this->sphinx_QL->query('DROP TABLE ' . $this->sphinx_id);
				$this->search_execute();
			}

			// Stop here if we are not also creating the index
			if ($action == 'delete')
			{
				$message = $this->language->lang('ACP_PMSEARCH_DROP_DONE');
				$message .= '<br />' . $this->language->lang('ACP_PMSEARCH_INDEX_STATS', round(microtime(true) - $time, 1), round(memory_get_peak_usage() / 1048576, 2));
				trigger_error($message);
			}


			/*
			 *
			 * Create index
			 *
			 */


			if ($version[3] == 0 && $version[0] > 2)
			{
				// Create index for Sphinx 3.x
				$this->sphinx_QL->query('CREATE TABLE ' . $this->sphinx_id . '(author_id integer,user_id multi,message_time bigint,message_subject field ,message_text field,folder_id field)');
			}
			elseif ($version[3] == 1)
			{
				// Create index for Manticore
				$this->sphinx_QL->query('CREATE TABLE ' . $this->sphinx_id . '(author_id integer,user_id multi,message_time timestamp,message_subject text indexed,message_text text indexed,folder_id text indexed)');
			}
			$this->search_execute();


			/*
			 *
			 * Begin indexing
			 *
			 */


			// Todo replace the group concat with to_uid and to_gid from the private message table
			// Todo find a better logic for folder searching, maybe?
			// Todo try sql transactions

			// Todo document how the query works
			// Returned columns must match the column names of the index
			$query = "SELECT
						p.msg_id as id,
						p.author_id author_id,
						GROUP_CONCAT(t.user_id SEPARATOR ' ') user_id,
						p.message_time,
						p.message_subject,
						p.message_text,
						GROUP_CONCAT( CONCAT(t.user_id,'_',t.folder_id) SEPARATOR ' ') folder_id
						FROM " . PRIVMSGS_TABLE . ' p
						JOIN ' . PRIVMSGS_TO_TABLE . ' t ON p.msg_id = t.msg_id
						WHERE t.pm_deleted = 0
						GROUP BY p.msg_id
						ORDER BY p.msg_id ASC';

			// It is far quicker to fetch 500 rows and index all of them in one query than it is to handle them one at a time
			$offset = 0;
			$limit  = 500;
			$result = $this->db->sql_query_limit($query, $limit);

			// Todo what if indexing takes too long and the script exceeds execution time?

			// Shove all returned rows into an array for processing
			// Rows must be an associative array
			while ($rows = $this->db->sql_fetchrowset($result))
			{
				// Set query to insert
				$this->sphinx_QL->insert()->into($this->sphinx_id)
				;

				// Minor row processing before inserting into query
				foreach ($rows as $row)
				{
					// MySQL returns user_id as a string of ids; explode user_id into an array of integers
					$row['user_id'] = array_map('intval', explode(' ', $row['user_id']));

					// Add row to insert query
					// Row must be an associative array with column names as key
					$this->sphinx_QL->set($row);
				}
				if ($this->search_execute() === false)
				{
					// We got ourselves an error, most likely we ran out of RAM or disk space, or maybe
					// connection was lost.
					switch ($this->sphinxql_error_num)
					{
						case SphinxQL_ERR_CONNECTION:
							trigger_error($this->language->lang('ACP_PMSEARCH_CONNECTION_ERROR'), E_USER_WARNING);
							break;
						case SphinxQL_ERR_PROGRAM:
						default:
							// Todo send to log
							trigger_error($this->language->lang('ACP_PMSEARCH_PROGRAM_ERROR') . '<br />' . $this->sphinxql_error_msg, E_USER_WARNING);
							break;
					}
				}
				$offset += $limit;
				$result = $this->db->sql_query_limit($query, $limit, $offset);
			}

			// Set the message to send
			$message = $this->language->lang('ACP_PMSEARCH_INDEX_DONE');
			$message .= '<br />' . $this->language->lang('ACP_PMSEARCH_INDEX_STATS', round(microtime(true) - $time, 1), round(memory_get_peak_usage() / 1048576, 2));
			trigger_error($message);
		}


		/*
		 *
		 * MySQL index
		 *
		 */


		elseif ($engine == 'mysql')
		{
			// Todo deal with timeouts while waiting for the index to complete

			// Must use MySQL to use MySQL
			if ($this->db->get_sql_layer() != 'mysqli')
			{
				trigger_error($this->language->lang('FULLTEXT_MYSQL_INCOMPATIBLE_DATABASE'));
			}


			/*
			 *
			 * Collect index status
			 *
			 */


			// Stop if any table changes are currently in progress
			$result = $this->db->sql_query('SHOW PROCESSLIST');
			while ($row = $this->db->sql_fetchrow($result))
			{
				// The Info column contains the SQL query of the process
				if (strpos($row['Info'],'ALTER TABLE ' . PRIVMSGS_TABLE) !== false)
				{
					// Don't continue if MySQL is still processing the indexes
					trigger_error($this->language->lang('ACP_PMSEARCH_PROCESSING'));
				}
			}

			// Fetch list of all indexes
			$indexes = $this->get_mysql_indexes();

			if ($action == 'delete')
			{


				/*
				 *
				 * Drop MySQL indexes
				 *
				 */


				foreach($indexes as $v)
				{
					$this->db->sql_query('ALTER TABLE ' . PRIVMSGS_TABLE . ' DROP INDEX  ' . $v);
				}
				trigger_error($this->language->lang('ACP_PMSEARCH_DROP_DONE'));

			}
			elseif ($action == 'create')
			{
				// Todo disable search while modifying tables.

				// Creating a fulltext index inside MySQL can take a significant amount of time. All the while, php is waiting until
				// either MySQL finishes or the script hits the max execution time. Should php timeout, the user will get a message
				// about a gateway timeout or some other vague error. This may cause the user to believe that the indexing has failed.
				// We can do better by setting a MySQL client timeout. This is causes php to stop waiting while MySQL does its thing.


				/*
				 *
				 * Setup custom MySQL connection with timeout
				 *
				 */


				global $dbhost, $dbuser, $dbpasswd, $dbname, $dbport;
				$id = mysqli_init();

				// Limit time spent waiting for MySQL
				mysqli_options($id, MYSQLI_OPT_READ_TIMEOUT, 10);

				// Copy port/socket handling from phpbb mysqli driver
				$port = (!$dbport) ? null : $dbport;
				$socket = null;
				if ($port)
				{
					if (is_numeric($port))
					{
						$port = (int) $port;
					}
					else
					{
						$socket = $port;
						$port = null;
					}
				}

				mysqli_real_connect($id, $dbhost, $dbuser, $dbpasswd, $dbname, $port, $socket, MYSQLI_CLIENT_FOUND_ROWS);


				/*
				 *
				 * Collation Checks
				 *
				 */


				// Fetch column collation for selected columns
				$result = $this->db->sql_query('SHOW FULL COLUMNS FROM ' . PRIVMSGS_TABLE . ' WHERE Field IN ("message_text","message_subject") AND Collation = "utf8mb4_unicode_ci"');
				foreach($this->db->sql_fetchrowset($result) as $row)
				{
					// Change column from utf8-bin to utf8mb4-unicode-ci
					// Todo replace silence operator?
					$result = @mysqli_query($id, 'ALTER TABLE ' . PRIVMSGS_TABLE . ' MODIFY '. $row['Field'] . ' ' . $row['Type'] . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
					if ($result === false)
					{
						$err_id = mysqli_errno($id);
						// Client timeout
						if ($err_id ==  2006)
						{
							trigger_error($this->language->lang('ACP_PMSEARCH_IN_PROGRESS'));
						}
					}
				}


				/*
				 *
				 * Create Indexes
				 *
				 */


				foreach($this->mysql_indexes as $v)
				{
					if(!in_array($v, $indexes))
					{
						$i = $v . ' (' . (($v != 'message_content') ? $v : 'message_text,message_subject') . ')';
						$result = @mysqli_query($id,'ALTER TABLE ' . PRIVMSGS_TABLE . ' ADD FULLTEXT '. $i);
						if ($result === false)
						{
							$err_id = mysqli_errno($id);
							// Client timeout
							if ($err_id ==  2006)
							{
								trigger_error($this->language->lang('ACP_PMSEARCH_IN_PROGRESS'));
							}
						}
					}
				}

				trigger_error($this->language->lang('ACP_PMSEARCH_INDEX_DONE'));
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
			$result = $this->sphinx_QL->execute();
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
			$this->sphinxql_error_num = SphinxQL_ERR_DATA;
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
		// Try to fetch the version variable
		$this->sphinx_QL->query("SHOW STATUS LIKE 'version'");
		$result = $this->search_execute();

		// We couldn't connect or some other error
		if ($result === false)
		{
			return false;
		}

		// Only Manticore returns a version from status
		if ($result->count())
		{
			$row = $result->fetchAssoc();
			if (preg_match('/^([\d.]+)/', $row['Value'], $m))
			{
				$v   = explode('.', $m[1]);
				$v[] = 1;
				return $v;
			}
		}

		// No version? Must be Sphinx
		$this->sphinx_QL->query("SHOW VARIABLES LIKE 'version'");
		$result = $this->search_execute();

		if ($result->count())
		{
			$row = $result->fetchAssoc();
			if (preg_match('/^([\d.]+)/', $row['Value'], $m))
			{
				$v   = explode('.', $m[1]);
				$v[] = 0;
				return $v;
			}
		}

		// Still no version? Must be an ancient version of Sphinx
		$my = new mysqli($this->config['pmsearch_host'], '', '', '', $this->config['pmsearch_port']);
		$v  = $my->get_server_info();
		if (preg_match('/^([\d.]+)/', $v, $m))
		{
			$v   = explode('.', $m[1]);
			$v[] = 0;
			return $v;
		}

		// Unknown version??
		return false;
	}

	private function get_sphinx_indexes($index = false)
	{
		$index ? $this->sphinx_QL->query("SHOW TABLES LIKE '$index'") : $this->sphinx_QL->query('SHOW TABLES');
		$result = $this->search_execute();
		if ($result->count())
		{
			$list = [];
			while ($row = $result->fetchAssoc())
			{
				$list[] = $row['Index'];
			}
			return $list;
		}
		else
		{
			return false;
		}
	}

	private function get_mysql_indexes()
	{
		// Fetch list of all indexes
		$i = [];
		$result = $this->db->sql_query('SHOW INDEX FROM ' . PRIVMSGS_TABLE);
		while  ($row = $this->db->sql_fetchrow($result))
		{
			if (in_array($row['Key_name'],$this->mysql_indexes))
			{
				// Avoid entering duplicate indexes, the message_content index is reported twice,
				if(!in_array($row['Key_name'],$i))
				{
					$i[] = $row['Key_name'];
				}
			}
		}
		return $i;
	}
}