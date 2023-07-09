<?php

namespace crosstimecafe\pmsearch\core;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;

class mysql implements pmsearch_base
{
	protected $uid;
	protected $config;
	protected $db;
	public $rows;
	public $total_found;
	public $error_msg;
	public $error_msg_full;

	public function __construct(int $uid, config $config, driver_interface $db)
	{
		$this->uid    = $uid;
		$this->config = $config;
		$this->db     = $db;
		$this->error_msg = '';
		$this->error_msg_full = '';
		$this->rows = [];
		$this->total_found = null;
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
		// TODO: Implement status() method.
	}

	/**
	 * @inheritDoc
	 */
	public function create_index()
	{
		// TODO: Implement create_index() method.
	}

	/**
	 * @inheritDoc
	 */
	public function reindex()
	{
		// TODO: Implement reindex() method.
	}

	/**
	 * @inheritDoc
	 */
	public function delete_index()
	{
		// TODO: Implement delete_index() method.
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
		if($from)
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
		$result = $this->db->sql_query($sql);
		$this->rows   = $this->db->sql_fetchrowset($result);

		// Count all the distinct message id's to find total count of rows
		$sql         = 'SELECT COUNT(DISTINCT p.msg_id) as total_count
                FROM ' . PRIVMSGS_TABLE . ' p 
				JOIN ' . PRIVMSGS_TO_TABLE . ' t ON p.msg_id = t.msg_id
				WHERE ' . $where;
		$result      = $this->db->sql_query($sql);
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
}
