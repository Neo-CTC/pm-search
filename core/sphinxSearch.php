<?php

namespace crosstimecafe\pmsearch\core;

use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use Foolz\SphinxQL\SphinxQL;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;

class sphinxSearch implements pmsearch_base
{
	public $rows;
	public $total_found;
	public $error_msg;
	public $error_msg_full;
	/**
	 * @var $sphinxql SphinxQL
	 */
	public $sphinxql;
	protected $uid;
	protected $config;
	protected $language;
	protected $db;
	protected $sphinx_id;

	public function __construct(int $uid, config $config, language $lang, driver_interface $db)
	{
		$this->uid      = $uid;
		$this->config   = $config;
		$this->language = $lang;
		$this->db       = $db;

		$this->rows           = [];
		$this->total_found    = null;
		$this->error_msg      = '';
		$this->error_msg_full = '';

		// Fill in missing config
		if (!$this->config['fulltext_sphinx_id'])
		{
			$this->config->set('fulltext_sphinx_id', unique_id());
		}
		$this->sphinx_id = 'index_phpbb_' . $this->config['fulltext_sphinx_id'] . '_private_messages';

		$this->connect();
	}

	private function connect()
	{
		$conn = new Connection();
		$conn->setParams([
			'host'    => $this->config['pmsearch_host'],
			'port'    => $this->config['pmsearch_port'],
			'options' => [
				MYSQLI_OPT_CONNECT_TIMEOUT => 2,
			],
		]);
		$this->sphinxql = new SphinxQL($conn);
	}

	/**
	 * @inheritDoc
	 */
	public function ready()
	{
		// TODO: Implement ready() method.
	}

	/**
	 * @inheritDoc
	 */
	public function status()
	{
		// Set some defaults
		$template = [
			'SPHINX_STATUS'  => $this->language->lang('CONNECTION_FAILED'),
			'TOTAL_MESSAGES' => 'N/A',
			'INDEX_BYTES'    => 'N/A',
			'RAM_BYTES'      => 'N/A',
		];
		// Fetch version
		$version = $this->get_version();

		// No version? No connection.
		if (!$version)
		{
			return $template;
		}

		$template['SPHINX_VERSION'] = ($version[0] == 's' ? 'Sphinx ' : 'Manticore ') . implode('.', array_slice($version, 1));

		// Todo catch invalid index types, i.e. non rt tables
		$ready = $this->index_ready(false);
		if ($ready)
		{
			$template['SPHINX_STATUS'] = $this->language->lang('ACP_PMSEARCH_READY');

			// TODO get stats for Sphinx
			// Extra stats for Manticore
			if ($version[0] == 'm')
			{
				$this->sphinxql->query('SHOW INDEX ' . $this->sphinx_id . ' STATUS');
				$result = $this->query_execute(false);

				while ($row = $result->fetchAssoc())
				{
					switch ($row['Variable_name'])
					{
						// Todo get more variables
						case 'indexed_documents':
							$template['TOTAL_MESSAGES'] = $row['Value'];
							break;
						case 'disk_bytes':
							$template['INDEX_BYTES'] = round($row['Value'] / 1048576, 1) . ' MiB';
							break;
						case 'ram_bytes':
							$template['RAM_BYTES'] = round($row['Value'] / 1048576, 1) . ' MiB';
							break;
					}
				}
				$template['SPHINX_ID'] = $this->sphinx_id;
			}
		}
		// No index, but ready to be created
		else if ($version[0] == 'm' || ($version[0] == 's' && $version[1] == 3))
		{
			$template['SPHINX_STATUS'] = $this->language->lang('ACP_PMSEARCH_INDEX_EMPTY');
		}
		else
		{
			$template['SPHINX_STATUS'] = $this->language->lang('ACP_PMSEARCH_NO_INDEX_CREATE');
		}
		return $template;

		// Fetch index state

		// Template variables
		// SPHINX_STATUS

		// SHOW_CONFIG
		// DATA_ID
		// DATA_PATH
	}

	/**
	 * Fetch Sphinx/Manticore version
	 *
	 * @return false|string[]
	 */
	private function get_version()
	{
		$this->error_msg      = 'ACP_PMSEARCH_ERR_UNKNOWN';
		$this->error_msg_full = '';

		$this->sphinxql->query("SHOW STATUS LIKE 'version'");
		$result = $this->query_execute(false);

		// Only Manticore returns a version from status
		// Count of 1 returned row if found, 0 otherwise
		if ($result && $result->count())
		{
			$row = $result->fetchAssoc();
			if (preg_match('/^([\d.]+)/', $row['Value'], $m))
			{
				return array_merge(['m'], explode('.', $m[1]));
			}
		}

		// No version? Must be Sphinx
		$this->sphinxql->query("SHOW VARIABLES LIKE 'version'");
		$result = $this->query_execute(false);

		if ($result && $result->count())
		{
			$row = $result->fetchAssoc();
			if (preg_match('/^([\d.]+)/', $row['Value'], $m))
			{
				return array_merge(['s'], explode('.', $m[1]));
			}
		}

		// Still no version? Might be an ancient version of Sphinx
		// $my = @new mysqli($this->config['pmsearch_host'], '', '', '', $this->config['pmsearch_port']);
		// if ($my)
		// {
		// 	$v = $my->get_server_info();
		// 	if (preg_match('/^([\d.]+)/', $v, $m))
		// 	{
		// 		return array_merge(['s'], explode('.', $m[1]));
		// 	}
		// }

		// Unknown version??
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function query($query)
	{
		// TODO: Implement query() method.
	}

	private function query_execute($handle_errors = true)
	{
		$result = false;
		try
		{
			$result = $this->sphinxql->execute();
		}
		catch (ConnectionException $e)
		{
			if ($handle_errors)
			{
				$this->error_handler('ACP_PMSEARCH_ERR_CONN', $e->getMessage());
			}
		}
		catch (DatabaseException $e)
		{
			if ($handle_errors)
			{
				$this->error_handler('ACP_PMSEARCH_ERR_DB', $e->getMessage());
			}
		}
		catch (SphinxQLException $e)
		{
			if ($handle_errors)
			{
				$this->error_handler('ACP_PMSEARCH_ERR_UNKNOWN', $e->getMessage());
			}
		}
		return $result;
	}

	private function error_handler($msg, $err_msg = '', $str_arg = '')
	{
		$error_message = $this->language->lang($msg, $str_arg);
		$error_message .= $err_msg ? '<br>' . $err_msg : '';
		trigger_error($error_message);
	}

	private function index_ready($handle_errors = true): bool
	{
		$this->sphinxql->query("SHOW TABLES LIKE '" . $this->sphinx_id . "'");
		$result = $this->query_execute($handle_errors);
		if ($result && $result->count())
		{
			// We can only work with RT tables
			$row = $result->fetchAssoc();
			if ($row['Type'] != 'rt')
			{
				$this->error_handler('ACP_PMSEARCH_UNSUPPORTED_INDEX', '', $row['Type']);
			}
			return true;
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function reindex()
	{
		// Does our index exist?
		$this->delete_index();
		$this->create_index();

		// Todo replace the group concat with to_uid and to_gid from the private message table
		// Todo find a better logic for folder searching, maybe?

		// Todo document how the query works

		// TODO How portable is this sql statement?
		// Returned columns must match the column names of the index
		$query  = "SELECT
						p.msg_id as id,
						p.author_id as author_id,
						GROUP_CONCAT(t.user_id SEPARATOR ' ') as user_id,
						p.message_time,
						p.message_subject,
						p.message_text,
						GROUP_CONCAT( CONCAT(t.user_id,'_',t.folder_id) SEPARATOR ' ') as folder_id
						FROM " . PRIVMSGS_TABLE . ' p
						JOIN ' . PRIVMSGS_TO_TABLE . ' t ON p.msg_id = t.msg_id
						WHERE t.pm_deleted = 0
						GROUP BY p.msg_id
						ORDER BY p.msg_id ASC';
		$offset = 0;
		$limit  = 500;
		$result = $this->db->sql_query_limit($query, $limit);

		// Todo what if indexing takes too long and the script exceeds execution time?
		// Shove all returned rows into an array for processing
		// Rows must be an associative array
		while ($rows = $this->db->sql_fetchrowset($result))
		{
			// Set query mode to insert
			$this->sphinxql->insert()->into($this->sphinx_id)
			;

			// Load rows into query
			foreach ($rows as $row)
			{
				// MySQL returns user_id as a string of ids; explode user_id into an array of integers
				$row['user_id'] = array_map('intval', explode(' ', $row['user_id']));

				// Add row to insert query
				// Row must be an associative array with the column names as keys
				$this->sphinxql->set($row);
			}
			$this->query_execute();

			// Next 500 rows
			$offset += $limit;
			$this->db->sql_freeresult($result);
			$result = $this->db->sql_query_limit($query, $limit, $offset);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function create_index()
	{
		// Get Sphinx version. There's a big difference between Sphinx and Manticore
		$version = $this->get_version();
		if (!$version)
		{
			$this->error_handler('CONNECTION_FAILED');
		}

		// Manticore 3.0+
		if ($version[0] == 'm')
		{
			// Add `-` to the list of characters to index, allow wildcards, strip xml tags
			$index_settings = "charset_table = 'non_cjk, U+002D' min_prefix_len = '3' min_infix_len = '3' html_strip = '1'";
			$this->sphinxql->query('CREATE TABLE ' . $this->sphinx_id . "(author_id integer,user_id multi,message_time timestamp,message_subject text indexed,message_text text indexed,folder_id text indexed) " . $index_settings);
		}

		//Sphinx 3.x
		else if ($version[0] == 's' && $version[1] == 3)
		{
			$this->sphinxql->query('CREATE TABLE ' . $this->sphinx_id . '(author_id integer,user_id multi,message_time bigint,message_subject field ,message_text field,folder_id field)');
		}
		else
		{
			$this->error_handler('ACP_PMSEARCH_UNKNOWN_VERSION', '');
		}
		$this->query_execute();
	}

	/**
	 * @inheritDoc
	 */
	public function delete_index()
	{
		$this->sphinxql->query('DROP TABLE IF EXISTS ' . $this->sphinx_id);
		$this->query_execute();
	}

	/**
	 * @inheritDoc
	 */
	public function update_entry($id)
	{
		// TODO: Implement update_entry() method.
	}

	/**
	 * @inheritDoc
	 */
	public function search(array $indexes, string $keywords, array $from, array $to, array $folders, string $order, string $direction, int $offset)
	{
		if (!$this->index_ready(false))
		{
			return false;
		}
		$search = $this->sphinxql;

		/*
		 *
		 * SphinxQL is SQL
		 *
		 */

		// Columns
		$search->select('id');

		// Table
		$search->from($this->sphinx_id);

		// Where, all following where functions are combined using AND
		$search->where('user_id', $this->uid);

		// Search for terms while also allowing control characters
		if ($keywords)
		{
			$search->match($indexes, $keywords, true);
		}

		// Search for messages sent from these authors
		if ($from)
		{
			$search->where('author_id', 'IN', $from);
		}

		// Search for messages sent to these recipients
		if ($to)
		{
			$search->where('author_id', '=', $this->uid);
			$search->where('user_id', 'IN', $to);
		}

		if ($folders)
		{
			// Tack on user id to each folder to limit it to current user.
			foreach ($folders as &$f)
			{
				// Because everyone has the same folder ids for Inbox, Sent, and Outbox, we add `<user id>_`
				// to the start of all folders. This allows us to run a full text match for matching folders.
				// Todo this could probably be replaced with a better logic/sql statement?? Try MVA big numbers

				// Yes the `"` are required
				$f = '"' . $this->uid . '_' . $f . '"';
			}
			unset($f);

			// Combine folder array into a string with an `OR` search function
			$search->match('folder_id', implode('|', $folders), true);
		}

		$search->orderBy($order, $direction);
		$search->limit($offset, $this->config['posts_per_page']);

		try
		{
			// Fetch matches
			$result     = $this->sphinxql->execute();
			$this->rows = $result->fetchAllAssoc();

			// Fetch total matches
			$this->sphinxql->query("SHOW META LIKE 'total_found'");
			$result            = $this->sphinxql->execute();
			$meta_data         = $result->fetchAllNum();
			$this->total_found = $meta_data[0][1];
		}
		catch (ConnectionException $e)
		{
			// Can't connect
			$this->error_msg      = 'UCP_PMSEARCH_ERR_CONN';
			$this->error_msg_full = $e->getMessage();
			// Todo better error handling
		}
		catch (DatabaseException $e)
		{
			// Bad sql or missing table or some other problem
			$this->error_msg      = 'UCP_PMSEARCH_ERR_DB';
			$this->error_msg_full = $e->getMessage();
		}
		catch (SphinxQLException $e)
		{
			// Unknown error
			$this->error_msg      = 'UCP_PMSEARCH_ERR_UNKNOWN';
			$this->error_msg_full = $e->getMessage();
		}

		// Reset connection for future usage
		$this->sphinxql->reset();

		if (!$this->error_msg)
		{
			return true;
		}
		else
		{
			return false;
		}
	}
}
