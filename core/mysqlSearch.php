<?php

namespace crosstimecafe\pmsearch\core;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;

class mysqlSearch implements pmsearch_base
{
	protected $uid;
	protected $config;
	protected $language;
	protected $db;

	protected $mysql_indexes;
	public $rows;
	public $total_found;
	public $error_msg;
	public $error_msg_full;

	public function __construct(int $uid, config $config, language $lang, driver_interface $db)
	{
		$this->uid      = $uid;
		$this->config   = $config;
		$this->language = $lang;
		$this->db       = $db;

		// List of MySQL indexes needed for searching
		$this->mysql_indexes = ['message_subject', 'message_text', 'message_content', 'to_address'];

		$this->rows           = [];
		$this->total_found    = null;
		$this->error_msg      = '';
		$this->error_msg_full = '';
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
		{
			$template = [];
			if (!$this->status_check())
			{
				$template['MYSQL_STATUS'] = $this->language->lang('ACP_PMSEARCH_PROCESSING');
			}
			else
			{
				// Fetch list of all indexes
				$i      = [];
				$result = $this->db->sql_query('SHOW INDEX FROM ' . PRIVMSGS_TABLE . ' WHERE Key_name LIKE "pmsearch_%"');
				$rows   = $this->db->sql_fetchrowset();
				if (count($rows) == 5)
				{
					$template['MYSQL_STATUS'] = $this->language->lang('ACP_PMSEARCH_READY');
				}
				else
				{
					$template['MYSQL_STATUS'] = $this->language->lang('ACP_PMSEARCH_NO_INDEX');
				}
			}
			return $template;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function create_index()
	{
		// Quick check to avoid altering the table while altering the table
		if (!$this->status_check())
		{
			trigger_error($this->language->lang('ACP_PMSEARCH_MYSQL_IN_PROGRESS'));
		}
		/* Oh okay, so...
		* Creating a fulltext index inside MySQL can take a significant amount of time. All the while, php is waiting until
		* either MySQL finishes or the script hits the max execution time. This is of course a bad thing and the end user
		 * thinks we've failed. So how to we overcome this conundrum? By ignoring phpBB's db interface and handling it ourselves.
		 * By setting a read timeout for MySQL queries we can force php to move on even if MySQL is still working in the
		 * background. The ALTER TABLE command doesn't care if we wait for or not.
		*/

		global $dbhost, $dbuser, $dbpasswd, $dbname, $dbport;
		$id = mysqli_init();

		// Limit time spent waiting for a query to finish executing
		mysqli_options($id, MYSQLI_OPT_READ_TIMEOUT, 5);

		// Copy port/socket handling from phpbb mysqli driver
		$port   = (!$dbport) ? null : $dbport;
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
				$port   = null;
			}
		}
		mysqli_real_connect($id, $dbhost, $dbuser, $dbpasswd, $dbname, $port, $socket);

		// Add the things
		$sql = "ALTER TABLE phpbb_privmsgs
	ADD FULLTEXT IF NOT EXISTS pmsearch_b (message_subject, message_text),
    ADD FULLTEXT IF NOT EXISTS pmsearch_s (message_subject),
    ADD FULLTEXT IF NOT EXISTS pmsearch_t (message_text),
    ADD FULLTEXT IF NOT EXISTS pmsearch_ta(to_address)";

		// This will either finish without incident, or if there is a lot of messages
		//  to index, the connection will time out.
		$result = @mysqli_query($id, $sql);

		if ($result === false)
		{
			$err_id = mysqli_errno($id);
			// Client error 2006 - CR_SERVER_GONE_ERROR
			// We are expecting this to happen
			if ($err_id == 2006)
			{
				trigger_error($this->language->lang('ACP_PMSEARCH_IN_PROGRESS'));
			}
			else
			{
				// Well, huh. That didn't work
				$msg = $this->language->lang('ACP_PMSEARCH_INDEX_CREATE_ERR_MYSQL') . '<br><pre>' .
					mysqli_errno($id) . '\n' . mysqli_error($id) . '</pre>';
				trigger_error($msg);
			}
		}
		trigger_error($this->language->lang('ACP_PMSEARCH_INDEX_DONE'));
	}

	/**
	 * @inheritDoc
	 */
	public function reindex()
	{
		// Todo deal with timeouts while waiting for the index to complete

		// Drop to rebuild
		$this->delete_index();
		$this->create_index();
	}

	/**
	 * @inheritDoc
	 */
	public function delete_index()
	{
		// Quick check to avoid altering the table while altering the table
		$this->status_check();

		$sql = 'ALTER TABLE ' . PRIVMSGS_TABLE . ' DROP INDEX IF EXISTS pmsearch_b, DROP INDEX IF EXISTS pmsearch_s, DROP INDEX IF EXISTS pmsearch_t, DROP INDEX IF EXISTS pmsearch_ta';
		$this->db->sql_query($sql,0);
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
		// Make suer we only search messages to the user
		$where = ['t.user_id = ' . $this->uid];

		// Convert indexes to string
		$columns = implode(',', $indexes);

		// Prep keywords for matching
		if ($keywords)
		{
			// Add search operator to each keyword
			$match = [];
			foreach (explode(' ', $keywords) as $v)
			{
				// Todo test escaping with search operators, e.g. "Foo bar"
				// Strip unsupported operators
				$v = str_replace(['~', '@', '(', ')', '<', '>', '\\', '/'], '', $v);

				// After all that striping do we even have any keywords left?
				if (strlen($v) == 0)
				{
					$this->error_msg = 'UCP_PMSEARCH_ERR_QUERY';
					return false;
				}

				// Find unmatched double quotes
				if (substr_count($v, '"') % 2 == 1)
				{
					$this->error_msg = 'UCP_PMSEARCH_ERR_QUERY';
					return false;
				}

				// Let phpBB handle any escaping
				$v = $this->db->sql_escape($v);

				// Add "must include word" search operator (+) to each keyword
				// Unless the "must not include word" operator (-) is found
				$match[] = (substr($v, 0, 1) != '-') ? '+' . $v : $v;
			}
			// Boolean mode enables text operators
			$where[] = 'MATCH(' . $columns . ") AGAINST('" . implode(' ', $match) . "' IN BOOLEAN MODE)";
		}

		// Search for messages sent from these authors
		if ($from)
		{
			$where[] = 'p.author_id in (' . implode(',', $from) . ')';
		}

		// Search for messages sent to these recipients
		if ($to)
		{
			$where[] = 'p.author_id = ' . $this->uid;
			$where[] = 'MATCH(p.to_address) AGAINST("u_' . implode(' ', $to) . '")';
		}


		if ($folders)
		{
			// TODO I don't think this is right. Test and fix
			$where[] = 't.folder_id in (' . implode(',', $folders) . ')';
		}

		// Combine the where
		$where = implode(' AND ', $where);

		$sql = 'SELECT DISTINCT p.msg_id id 
				FROM ' . PRIVMSGS_TABLE . ' p 
				JOIN ' . PRIVMSGS_TO_TABLE . ' t ON p.msg_id = t.msg_id
				WHERE ' . $where . '
				ORDER BY ' . $order . ' ' . $direction . '
				LIMIT ' . $offset . ',' . $this->config['posts_per_page'];

		// Get matching message ids
		$result     = $this->db->sql_query($sql);
		$this->rows = $this->db->sql_fetchrowset($result);

		// Count all the distinct message id's to find total count of rows
		$sql               = 'SELECT COUNT(DISTINCT p.msg_id) as total_count
                FROM ' . PRIVMSGS_TABLE . ' p 
				JOIN ' . PRIVMSGS_TO_TABLE . ' t ON p.msg_id = t.msg_id
				WHERE ' . $where;
		$result            = $this->db->sql_query($sql);
		$this->total_found = $this->db->sql_fetchrow($result)['total_count'];

		// TODO SQL error catching

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function query($query)
	{
		// TODO: Implement query() method.
	}

	private function status_check()
	{
		// Must use MySQL to use MySQL
		if ($this->db->get_sql_layer() != 'mysqli')
		{
			trigger_error($this->language->lang('FULLTEXT_MYSQL_INCOMPATIBLE_DATABASE'));
		}

		// TODO add a where statement
		// Stop if any table changes are currently in progress
		$result = $this->db->sql_query('SHOW PROCESSLIST');
		while ($row = $this->db->sql_fetchrow($result))
		{
			// The Info column contains the SQL query of the process
			if (strpos($row['Info'], 'ALTER TABLE ' . PRIVMSGS_TABLE) !== false)
			{
				// Don't continue if MySQL is still processing the indexes
				return false;
			}
		}
		return true;
	}
}
