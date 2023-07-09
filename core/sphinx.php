<?php

namespace crosstimecafe\pmsearch\core;

use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use Foolz\SphinxQL\SphinxQL;

use phpbb\config\config;

class sphinx implements pmsearch_base
{
	protected $uid;
	protected $config;
	public $rows;
	public $total_found;
	public $error_msg;
	public $error_msg_full;

	/**
	 * @var $sphinxql SphinxQL
	 */
	public $sphinxql;

	public function __construct(int $uid, config $config)
	{
		$this->uid    = $uid;
		$this->config = $config;
		$this->connect();

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
		$search = $this->sphinxql;

		/*
		 *
		 * SphinxQL is SQL
		 *
		 */

		// Columns
		$search->select('id');

		// Table
		$search->from('index_phpbb_' . $this->config['fulltext_sphinx_id'] . '_private_messages');

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
			$this->error_msg = 'UCP_PMSEARCH_ERR_CONN';
			$this->error_msg_full = $e->getMessage();
			// Todo better error handling
		}
		catch (DatabaseException $e)
		{
			// Bad sql or missing table or some other problem
			$this->error_msg = 'UCP_PMSEARCH_ERR_DB';
			$this->error_msg_full = $e->getMessage();
		}
		catch (SphinxQLException $e)
		{
			// Unknown error
			$this->error_msg = 'UCP_PMSEARCH_ERR_UNKNOWN';
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

	/**
	 * @inheritDoc
	 */
	public function query($query)
	{
		// TODO: Implement query() method.
	}

	private function connect()
	{
		$conn = new Connection();
		$conn->setParams(['host' => $this->config['pmsearch_host'], 'port' => $this->config['pmsearch_port']]);
		$this->sphinxql = new SphinxQL($conn);
	}
}
