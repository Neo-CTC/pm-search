<?php

namespace crosstimecafe\pmsearch\core;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;

class postgresSearch implements pmsearch_base
{
	public $error_msg;
	public $error_msg_full;
	public $message_ids;
	public $total_found;

	private $config;
	private $db;

	private $index_text;
	private $index_subject;

	public function __construct(config $config, driver_interface $db)
	{
		$this->config = $config;
		$this->db     = $db;

		$this->message_ids    = [];
		$this->total_found    = null;
		$this->error_msg      = '';
		$this->error_msg_full = '';

		global $table_prefix;
		$this->index_subject = $table_prefix . "pmsearch_s";
		$this->index_text    = $table_prefix . "pmsearch_t";
	}

	/**
	 * @inheritDoc
	 */
	public function create_index()
	{
		// Quick check to avoid altering the table while altering the table
		// Todo test if still needed for postgresSearch
		// if (!$this->status_check())
		// {
		// 	return false;
		// }

		// Index all the things
		$sql[] = "CREATE INDEX " . $this->index_text . " on " . PRIVMSGS_TABLE . " USING gin(to_tsvector('" . $this->config['fulltext_postgres_ts_name'] . "', message_text))";
		$sql[] = "CREATE INDEX " . $this->index_subject . " on " . PRIVMSGS_TABLE . " USING gin(to_tsvector('" . $this->config['fulltext_postgres_ts_name'] . "', message_subject))";

		foreach ($sql as $value)
		{
			$this->db->sql_query($value);
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function delete_entry($ids, $uid, $folder)
	{
		// Not required for postgres
	}

	/**
	 * @inheritDoc
	 */
	public function delete_index()
	{
		// Quick check to avoid altering the table while altering the table
		// Todo test if still required
		// if (!$this->status_check())
		// {
		// 	return false;
		// }

		$sql[] = 'DROP INDEX IF EXISTS ' . $this->index_subject;
		$sql[] = 'DROP INDEX IF EXISTS ' . $this->index_text;

		foreach ($sql as $value)
		{
			$this->db->sql_query($value);
		}

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

		// Prep keywords for matching
		if ($keywords)
		{
			// Todo no phrase matching yet
			// Find unmatched double quotes
			// if (substr_count($keywords, '"') % 2 == 1)
			// {
			// 	$this->error_msg = 'UCP_PMSEARCH_ERR_QUERY';
			// 	return false;
			// }

			// Keywords to AND Keywords
			$query_keywords = implode(' & ', explode(' ', $keywords));

			// Escape
			$query_keywords = $this->db->sql_escape($query_keywords);

			// Build match query
			$ts_query = '';
			foreach ($indexes as $k => $v)
			{
				// Matching more than one index
				$ts_query .= $k != 0 ? ' OR ' : '';

				$ts_query .= "to_tsvector('" . $this->config['fulltext_postgres_ts_name'] . "', " . $v . ") @@ to_tsquery('" . $this->config['fulltext_postgres_ts_name'] . "', '" . $query_keywords . "')";
			}
			$where[] = '(' . $ts_query . ')';
		}

		// Search for messages sent from these authors
		if ($from)
		{
			$where[] = $this->db->sql_in_set('p.author_id', $from);
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
			$where[] = $this->db->sql_in_set('t.folder_id', $folders);
		}

		// Combine the where
		$where = implode(' AND ', $where);

		$sql = 'SELECT DISTINCT p.msg_id id, ' . $order . '
				FROM ' . PRIVMSGS_TABLE . ' p 
				JOIN ' . PRIVMSGS_TO_TABLE . ' t ON p.msg_id = t.msg_id
				WHERE ' . $where . '
				ORDER BY ' . $order . ' ' . $direction . '
				LIMIT ' . $this->config['posts_per_page'] . ' OFFSET ' . $offset;

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
				$template['POSTGRES_STATUS'] = $this->error_msg;
				return $template;
			}

			// The indexes are ready
			if ($this->index_check())
			{
				$template['POSTGRES_STATUS'] = 'ACP_PMSEARCH_READY';
			}
			// The indexes are incomplete?
			else
			{
				$template['POSTGRES_STATUS'] = 'ACP_PMSEARCH_NO_INDEX';
			}
			return $template;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function update_entry($id)
	{
		// Not required for postgres
	}

	private function index_check(): bool
	{
		// Fetch list of all indexes
		$result = $this->db->sql_query("select indexname from pg_indexes where tablename = '" . PRIVMSGS_TABLE . "' and " . $this->db->sql_in_set('indexname', [$this->index_text, $this->index_subject]));
		$rows   = $this->db->sql_fetchrowset($result);
		if (count($rows) == 2)
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
		// Must use Postgres to use Postgres
		if ($this->db->get_sql_layer() != 'postgres')
		{
			$this->error_msg = 'FULLTEXT_POSTGRES_INCOMPATIBLE_DATABASE';
			return false;
		}

		// TODO make an actual status check
		return true;
	}
}
