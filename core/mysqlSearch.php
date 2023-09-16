<?php

namespace crosstimecafe\pmsearch\core;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;

class mysqlSearch implements pmsearch_base
{
	public $error_msg;
	public $error_msg_full;
	public $message_ids;
	public $total_found;

	private $config;
	private $db;

	public function __construct(config $config, driver_interface $db)
	{
		$this->config = $config;
		$this->db     = $db;

		$this->message_ids    = [];
		$this->total_found    = null;
		$this->error_msg      = '';
		$this->error_msg_full = '';
	}

	/**
	 * @inheritDoc
	 */
	public function create_index()
	{
		// Quick check to avoid altering the table while altering the table
		if (!$this->status_check())
		{
			return false;
		}

		/*
		 * Okay, so...
		 * Creating a fulltext index inside MySQL can take a significant amount of time. All the while, php is waiting until
		 * either MySQL finishes or the script hits the max execution time. This is of course a bad thing and the end user
		 * thinks we've failed. By ignoring phpBB's db interface and handling it ourselves we can set a read timeout for
		 * MySQL queries. Once the connection times out, the php script will continue while MySQL continues to work in the
		 * background. The ALTER TABLE command doesn't care if we wait for it to finish or not.
		 */

		// Todo get these from config
		global $dbhost, $dbuser, $dbpasswd, $dbname, $dbport;
		$id = mysqli_init();

		// Limit time spent waiting for a query to finish executing
		mysqli_options($id, MYSQLI_OPT_READ_TIMEOUT, 5);

		// Copy port/socket handling code from phpbb mysqli driver
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
		// TODO: handle connection errors
		mysqli_real_connect($id, $dbhost, $dbuser, $dbpasswd, $dbname, $port, $socket);

		// Update collation to a case-insensitive type
		// First fetch the columns to change
		$result = $this->db->sql_query('SHOW FULL COLUMNS FROM ' . PRIVMSGS_TABLE . ' WHERE Field IN ("message_text","message_subject") AND Collation != "utf8mb4_unicode_ci"');
		foreach ($this->db->sql_fetchrowset($result) as $row)
		{
			// Change column collation to utf8mb4-unicode-ci
			$result = @mysqli_query($id, 'ALTER TABLE ' . PRIVMSGS_TABLE . ' MODIFY ' . $row['Field'] . ' ' . $row['Type'] . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
			if ($result === false)
			{
				$err_id = mysqli_errno($id);
				// "MySQL server has gone away", aka client timeout
				if ($err_id == 2006)
				{
					$this->error_msg = 'ACP_PMSEARCH_IN_PROGRESS';
					return false;
				}
				// Some other error
				else if ($err_id)
				{
					$this->error_msg      = 'GENERAL_ERROR';
					$this->error_msg_full = mysqli_errno($id) . ": " . mysqli_error($id);
					return false;
				}
			}
		}

		// Index the things
		$sql = "ALTER TABLE phpbb_privmsgs
	ADD FULLTEXT IF NOT EXISTS pmsearch_b (message_subject, message_text),
    ADD FULLTEXT IF NOT EXISTS pmsearch_s (message_subject),
    ADD FULLTEXT IF NOT EXISTS pmsearch_t (message_text),
    ADD FULLTEXT IF NOT EXISTS pmsearch_ta(to_address),
    ADD FULLTEXT IF NOT EXISTS pmsearch_ba(bcc_address)";

		// This will either finish without incident, or if there is a lot of messages
		//  to index, the connection will time out.
		$result = @mysqli_query($id, $sql);

		if ($result === false)
		{
			$err_id = mysqli_errno($id);
			// "MySQL server has gone away", aka client timeout
			if ($err_id == 2006)
			{
				$this->error_msg = 'ACP_PMSEARCH_IN_PROGRESS';
				return false;
			}
			// Some other error
			else if ($err_id)
			{
				$this->error_msg      = 'GENERAL_ERROR';
				$this->error_msg_full = mysqli_errno($id) . ": " . mysqli_error($id);
				return false;
			}
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function delete_entry($ids, $uid, $folder)
	{
		// Not required for mysql
	}

	/**
	 * @inheritDoc
	 */
	public function delete_index()
	{
		// Quick check to avoid altering the table while altering the table
		if (!$this->status_check())
		{
			return false;
		}

		$sql = 'ALTER TABLE ' . PRIVMSGS_TABLE . ' DROP INDEX IF EXISTS pmsearch_b, DROP INDEX IF EXISTS pmsearch_s, DROP INDEX IF EXISTS pmsearch_t, DROP INDEX IF EXISTS pmsearch_ta';
		$this->db->sql_query($sql);

		// No point in trying to catch any error, phpBB will do it for us
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function ready()
	{
		return $this->status_check() && $this->index_check();
	}

	/**
	 * @inheritDoc
	 */
	public function reindex()
	{
		// Drop and rebuild
		return $this->delete_index() && $this->create_index();
	}

	/**
	 * @inheritDoc
	 */
	public function search(int $uid, array $indexes, string $keywords, array $from, array $to, array $folders, string $order, string $direction, int $offset)
	{
		// Check if ready
		if (!$this->status_check())
		{
			return false;
		}

		// More checks
		if (!$this->config['pmsearch_enable'] || !$this->index_check())
		{
			return false;
		}

		// Only search messages to the user
		$where = ['t.user_id = ' . $uid];

		// Convert indexes to string
		$columns = implode(',', $indexes);

		// Prep keywords for matching
		if ($keywords)
		{
			// Find unmatched double quotes
			if (substr_count($keywords, '"') % 2 == 1)
			{
				$this->error_msg = 'UCP_PMSEARCH_ERR_QUERY';
				return false;
			}

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

				// Let phpBB handle any escaping
				$v = $this->db->sql_escape($v);

				// Add "must include word" search operator (+) to each keyword
				// Unless the "must not include word" operator (-) is found
				// Technically we don't need a (+) if the word is part of a quoted phrase but mysql don't care
				$match[] = (substr($v, 0, 1) != '-') ? '+' . $v : $v;
			}
			// Boolean mode enables text operators
			$where[] = 'MATCH(' . $columns . ") AGAINST('" . implode(' ', $match) . "' IN BOOLEAN MODE)";
		}

		// Search for messages sent from these authors
		if ($from)
		{
			if (count($from) == 1)
			{
				$where[] = 'p.author_id = ' . $from[0];
			}
			else
			{
				$where[] = 'p.author_id IN (' . implode(',', $from) . ')';
			}
		}

		// Search for messages sent to these recipients
		if ($to)
		{
			/*
			 * To/Bcc addresses are stored as strings of u_<user id>.
			 * Add 'u_' to each address to use full text matching to search inside the strings.
			 */

			// Limit messages to ones the user has sent
			$where[] = 'p.author_id = ' . $uid;

			if (count($to) == 1)
			{
				// Single address checking in both to and bcc
				$where[] = '(MATCH(p.to_address) AGAINST("u_' . $to[0] . '") OR MATCH(p.bcc_address) AGAINST("u_' . $to[0] . '"))';
			}
			else
			{
				// Multi address matching
				$addresses = 'u_' . implode(' u_', $to);
				$where[]   = '(MATCH(p.to_address) AGAINST("' . $addresses . '") OR MATCH(p.bcc_address) AGAINST("' . $addresses . '"))';
			}
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
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$this->message_ids[] = $row['id'];
		}

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
	public function status()
	{
		{
			$template = [];
			$status   = $this->status_check();

			// Something is wrong
			if (!$status)
			{
				$template['MYSQL_STATUS'] = $this->error_msg;
				return $template;
			}

			// The indexes are ready
			if ($this->index_check())
			{
				$template['MYSQL_STATUS'] = 'ACP_PMSEARCH_READY';
			}
			// The indexes are incomplete?
			else
			{
				$template['MYSQL_STATUS'] = 'ACP_PMSEARCH_NO_INDEX';
			}
			return $template;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function update_entry($id)
	{
		// Not required for mysql
	}

	private function index_check(): bool
	{
		// Fetch list of all indexes
		$this->db->sql_query('SHOW INDEX FROM ' . PRIVMSGS_TABLE . ' WHERE Key_name LIKE "pmsearch_%"');
		$rows = $this->db->sql_fetchrowset();
		if (count($rows) == 6)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Check if the indexes still processing
	 *
	 * @return bool
	 */
	private function status_check()
	{
		// Must use MySQL to use MySQL
		if ($this->db->get_sql_layer() != 'mysqli')
		{
			$this->error_msg = 'FULLTEXT_MYSQL_INCOMPATIBLE_DATABASE';
			return false;
		}

		// TODO add a where statement
		// Stop if any table changes are currently in progress
		$result = $this->db->sql_query('SHOW PROCESSLIST');
		while ($row = $this->db->sql_fetchrow($result))
		{
			// The Info column contains the SQL query of the process
			if (strpos($row['Info'], 'ALTER TABLE ' . PRIVMSGS_TABLE) !== false)
			{
				// MySQL is still processing the indexes
				$this->error_msg = 'ACP_PMSEARCH_PROCESSING';
				return false;
			}
		}
		return true;
	}
}
