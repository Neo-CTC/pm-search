<?php

namespace crosstimecafe\pmsearch\core;

use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use Foolz\SphinxQL\SphinxQL;
use mysqli;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;


class sphinxSearch implements pmsearch_base
{
	public $error_msg;
	public $error_msg_full;
	public $message_ids;
	public $total_found;

	private $config;
	private $db;
	private $index_table;
	/** @var $sphinxql SphinxQL */
	private $sphinxql;

	/**
	 * @param \phpbb\config\config              $config
	 * @param \phpbb\db\driver\driver_interface $db
	 */
	public function __construct(config $config, driver_interface $db)
	{
		$this->config = $config;
		$this->db     = $db;

		$this->message_ids    = [];
		$this->total_found    = null;
		$this->error_msg      = '';
		$this->error_msg_full = '';

		// Fill in missing config
		if (!$this->config['fulltext_sphinx_id'])
		{
			$this->config->set('fulltext_sphinx_id', unique_id());
		}
		// $this->index_table = 'index_phpbb_' . $this->config['fulltext_sphinx_id'] . '_private_messages';
		$this->index_table = 'phpbb_pmsearch';

		$this->connect();
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
			return false;
		}

		// Manticore 3.0+
		if ($version[0] == 'm')
		{
			// Add `-` to the list of characters to index, allow wildcards, strip xml tags
			$index_settings = "charset_table = 'non_cjk, U+002D' min_prefix_len = '3' min_infix_len = '3' html_strip = '1'";
			$this->sphinxql->query('CREATE TABLE ' . $this->index_table . "(author_id integer,user_id multi,message_time timestamp,message_subject text indexed,message_text text indexed,folder_id text indexed) " . $index_settings);
		}

		//Sphinx 3.x
		else if ($version[0] == 's' && $version[1] == 3)
		{
			$this->sphinxql->query('CREATE TABLE ' . $this->index_table . '(author_id integer,user_id multi,message_time bigint,message_subject field ,message_text field,folder_id field)');
		}
		else
		{
			// Old Sphinx version, user needs to create the index table
			if (!$this->index_ready())
			{
				$this->error_msg = 'ACP_PMSEARCH_NO_INDEX_CREATE';
			}
		}
		return $this->query_execute();
	}

	/**
	 * @inheritDoc
	 */
	public function delete_entry($ids, $uid, $folder)
	{
		// TODO: Don't run if not ready

		// Undelivered messages, purge them
		if ($folder === PRIVMSGS_OUTBOX)
		{
			$this->sphinxql
				->delete()
				->from($this->index_table)
				->where('id', 'IN', $ids);
			$this->query_execute();
		}


		else
		{
			$reindex = false;

			// Build array of ids to delete
			// If message id is found, delete id from deleted ids
			$delete_ids = array_flip($ids);

			// Fetch messages without the current user
			$sql =
				"SELECT
					p.msg_id as id,
					p.author_id as author_id,
					GROUP_CONCAT(t.user_id SEPARATOR ' ') as user_id,
					p.message_time,
					p.message_subject,
					p.message_text,
					GROUP_CONCAT( CONCAT(t.user_id,'_',t.folder_id) SEPARATOR ' ') as folder_id
				FROM " . PRIVMSGS_TABLE . " p
				JOIN " . PRIVMSGS_TO_TABLE . " t ON p.msg_id = t.msg_id
				WHERE 
					t.pm_deleted = 0 AND
					" . $this->db->sql_in_set('p.msg_id', $ids) . " AND
					t.user_id != " . $uid . "
				GROUP BY p.msg_id";

			$result = $this->db->sql_query($sql);

			$this->sphinxql
				->replace()
				->into($this->index_table);
			while ($row = $this->db->sql_fetchrow($result))
			{
				// Message not deleted for everyone
				unset($delete_ids[$row['id']]);

				// User ids to array of integers
				$row['user_id'] = array_map('intval', explode(' ', $row['user_id']));
				$this->sphinxql->set($row);
				$reindex = true;
			}

			// Found stuff to update
			if ($reindex)
			{
				$this->query_execute();
			}

			if ($delete_ids)
			{
				$this->sphinxql
					->delete()
					->from($this->index_table)
					->where('id', 'IN', array_keys($delete_ids));
				$this->query_execute();
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function delete_index()
	{
		$version = $this->get_version();
		if (!$version)
		{
			return false;
		}

		if ($version[0] == 'm' || $version[1] == 3)
		{
			$this->sphinxql->query('DROP TABLE IF EXISTS ' . $this->index_table);
		}
		else
		{
			if (!$this->index_ready())
			{
				$this->error_msg = 'ACP_PMSEARCH_NO_INDEX_CREATE';
				return false;
			}
			// Sphinx 2.x, we can't drop the table, but we can delete all the data in it
			$this->sphinxql->query('TRUNCATE RTINDEX ' . $this->index_table);
		}

		$this->config->set('pmsearch_sphinx_ready', 0);
		$this->config->set('pmsearch_sphinx_position', 0);

		return $this->query_execute();
	}

	/**
	 * Check if searching is ready for use
	 *
	 * @inheritDoc
	 */
	public function ready(): bool
	{
		return $this->config['pmsearch_sphinx_ready'] == 2 && $this->index_ready();
	}

	/**
	 * @inheritDoc
	 */
	public function reindex(): bool
	{
		// Don't drop the index if in the middle of reindexing
		if ($this->config['pmsearch_sphinx_ready'] != 1)
		{
			if (!$this->delete_index())
			{
				return false;
			}
			if (!$this->create_index())
			{
				return false;
			}
			$this->config->set('pmsearch_sphinx_ready', 1);
		}

		// Start the clock
		$max_time   = ini_get('max_execution_time');
		$max_time   = $max_time > 0 ? $max_time : 30;
		$start_time = time();

		// Todo disable pm updates while indexing
		// Todo replace the group concat with to_uid and to_gid from the private message table
		// Todo find a better logic for folder searching, maybe?

		// Todo document how the query works

		// TODO How portable is this sql statement?
		// Join the message table with the recipient table
		// Group: all users that have the message
		// Group: folder location for each user
		// Ignore deleted messages
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
		$offset = $this->config['pmsearch_sphinx_position'];
		$limit  = 500;
		$result = $this->db->sql_query_limit($query, $limit);

		// Shove all returned rows into an array for processing
		// Rows must be an associative array
		while ($rows = $this->db->sql_fetchrowset($result))
		{
			// Set query mode to insert
			$this->sphinxql->replace()->into($this->index_table);

			// Load rows into query
			foreach ($rows as $row)
			{
				// MySQL returns user_id as a string of ids
				// Explode user_id, then convert into an integers
				$row['user_id'] = array_map('intval', explode(' ', $row['user_id']));

				// Add row to insert query
				// Row must be an associative array with the column names as keys
				$this->sphinxql->set($row);
			}
			$qe_result = $this->query_execute();
			if (!$qe_result)
			{
				return false;
			}

			// Next 500 rows
			$offset += $limit;

			// Save indexing position
			$this->config->set('pmsearch_sphinx_position', $offset);

			// Watch the clock, don't want the script to timeout
			$run_time = time() - $start_time;

			// Todo maybe move to cron task?
			// 5 seconds left on the clock, stop here
			if ($max_time - $run_time < 5)
			{
				$this->error_msg = 'INCOMPLETE';

				// Get total number of messages
				$result               = $this->db->sql_query('SELECT COUNT(*) as total FROM ' . PRIVMSGS_TABLE);
				$this->error_msg_full = $this->db->sql_fetchrow($result)['total'];
				return false;
			}

			$this->db->sql_freeresult($result);
			$result = $this->db->sql_query_limit($query, $limit, $offset);
		}

		// Done and ready
		$this->config->set('pmsearch_sphinx_ready', 2);
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function search(int $uid, string $indexes, string $keywords, array $from, array $to, array $folders, string $order, string $direction, int $offset)
	{
		if (!$this->index_ready())
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
		$search->from($this->index_table);

		// Where, all following where functions are combined using AND
		$search->where('user_id', $uid);

		// Search for terms while also allowing control characters
		if ($keywords)
		{
			// Select columns to match
			switch ($indexes)
			{
				case 't':
					$columns = 'message_text';
				break;
				case 's':
					$columns = 'message_subject';
				break;
				case 'b':
				default:
					$columns = ['message_text', 'message_subject'];
			}
			$search->match($columns, $keywords, true);
		}

		// Search for messages sent from these authors
		if ($from)
		{
			$search->where('author_id', 'IN', $from);
		}

		// Search for messages sent to these recipients
		if ($to)
		{
			$search->where('author_id', '=', $uid);
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
				$f = '"' . $uid . '_' . $f . '"';
			}
			unset($f);

			// Combine folder array into a string with an `OR` search function
			$search->match('folder_id', implode('|', $folders), true);
		}

		$search->orderBy($order, $direction);
		$search->limit($offset, $this->config['posts_per_page']);

		// Fetch matches
		$result = $this->query_execute();
		if (!$result)
		{
			return false;
		}

		while ($row = $result->fetchAssoc())
		{
			$this->message_ids[] = $row['id'];
		}

		// Fetch total matches
		$this->sphinxql->query("SHOW META LIKE 'total_found'");
		$result = $this->query_execute();
		if (!$result)
		{
			return false;
		}

		$meta_data         = $result->fetchAssoc();
		$this->total_found = $meta_data['Value'];

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function status(): array
	{
		// Todo show config variables

		// Set some defaults
		$template = [
			'SPHINX_STATUS'  => 'CONNECTION_FAILED',
			'SPHINX_ID'      => 'N/A',
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

		$ready = $this->index_ready();
		if ($ready)
		{
			$template['SPHINX_STATUS'] = 'ACP_PMSEARCH_READY';

			$this->sphinxql->query('SHOW INDEX ' . $this->index_table . ' STATUS');
			$result = $this->query_execute();
			if ($result)
			{
				$template['SPHINX_ID'] = $this->index_table;
				while ($row = $result->fetchAssoc())
				{
					switch ($row['Variable_name'])
					{
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
			}
		}
		// No index, but ready to be created
		else if ($version[0] == 'm' || ($version[0] == 's' && $version[1] == 3))
		{
			$template['SPHINX_STATUS'] = 'ACP_PMSEARCH_INDEX_EMPTY';
		}
		else
		{
			$template['SPHINX_STATUS'] = 'ACP_PMSEARCH_NO_INDEX_CREATE';
		}
		return $template;
	}

	/**
	 * @inheritDoc
	 */
	public function update_entry($id)
	{
		// TODO: Don't run if not ready

		$this->sphinxql
			->replace()
			->into($this->index_table);

		$sql = "SELECT
						p.msg_id as id,
						p.author_id as author_id,
						GROUP_CONCAT(t.user_id SEPARATOR ' ') as user_id,
						p.message_time,
						p.message_subject,
						p.message_text,
						GROUP_CONCAT( CONCAT(t.user_id,'_',t.folder_id) SEPARATOR ' ') as folder_id
						FROM " . PRIVMSGS_TABLE . ' p
						JOIN ' . PRIVMSGS_TO_TABLE . ' t ON p.msg_id = t.msg_id
						WHERE t.pm_deleted = 0 AND ' . $this->db->sql_in_set('p.msg_id', $id) . '
						GROUP BY p.msg_id';

		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			// User ids to array of integers
			$row['user_id'] = array_map('intval', explode(' ', $row['user_id']));
			$this->sphinxql->set($row);
		}

		return $this->query_execute();
	}

	/**
	 * Prepare SphinxQL connection
	 *
	 * @return void
	 */
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
	 * Fetch Sphinx/Manticore version
	 *
	 * @return false|string[]
	 */
	private function get_version()
	{
		$this->error_msg      = 'ACP_PMSEARCH_ERR_UNKNOWN';
		$this->error_msg_full = '';

		$this->sphinxql->query("SHOW STATUS LIKE 'version'");
		$result = $this->query_execute();

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
		$result = $this->query_execute();

		if ($result && $result->count())
		{
			$row = $result->fetchAssoc();
			if (preg_match('/^([\d.]+)/', $row['Value'], $m))
			{
				return array_merge(['s'], explode('.', $m[1]));
			}
		}

		// Still no version? Might be an ancient version of Sphinx
		$my = @new mysqli($this->config['pmsearch_host'], '', '', '', $this->config['pmsearch_port']);
		if (!$my->connect_errno)
		{
			$v = $my->get_server_info();
			if (preg_match('/^([\d.]+)/', $v, $m))
			{
				return array_merge(['s'], explode('.', $m[1]));
			}
		}

		// Unknown version??
		return false;
	}

	/**
	 * Check if the index is ready for usage
	 *
	 * @return bool
	 */
	private function index_ready(): bool
	{
		$this->sphinxql->query("SHOW TABLES LIKE '" . $this->index_table . "'");
		$result = $this->query_execute();
		if (!$result)
		{
			return false;
		}

		if ($result->count())
		{
			// We can only work with RT tables
			$row = $result->fetchAssoc();
			if ($row['Type'] == 'rt')
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Execute a prepared SphinxQL query
	 *
	 * @return false|\Foolz\SphinxQL\Drivers\ResultSetInterface
	 */
	private function query_execute()
	{
		// Flush error messages
		$this->error_msg      = '';
		$this->error_msg_full = '';

		try
		{
			$result = $this->sphinxql->execute();
		}
		catch (ConnectionException $e)
		{
			// Can't Connect
			$this->error_msg      = 'ACP_PMSEARCH_ERR_CONN';
			$this->error_msg_full = $e->getMessage();
			return false;
		}
		catch (DatabaseException $e)
		{
			// Bad sql or missing table or some other problem
			$this->error_msg      = 'ACP_PMSEARCH_ERR_DB';
			$this->error_msg_full = $e->getMessage();
			return false;
		}
		catch (SphinxQLException $e)
		{
			// Unknown error
			$this->error_msg      = 'ACP_PMSEARCH_ERR_UNKNOWN';
			$this->error_msg_full = $e->getMessage();
			return false;
		}
		return $result;
	}
}
