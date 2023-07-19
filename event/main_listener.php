<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2021, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\event;

use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use Foolz\SphinxQL\SphinxQL;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\user;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


class main_listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			// When a user views their new PMs, place_pm_into_folder is called, and we need to reindex the folders for the messages.
			// However, there is no event for when a message is moved into a folder. Therefore, we use these two events.
			'core.ucp_pm_view_folder_get_pm_from_sql' => 'update',
			'core.ucp_pm_view_message_before'         => 'update',

			'core.submit_pm_after'  => 'submit',
			'core.delete_pm_before' => 'remove', // I wish there was an event after the deletion, but I can work with this

			'core.ucp_display_module_before' => 'hide_me', // Hide the search page if search is not enabled

			// Todo There is no event to catch a change in folders
		];
	}

	private $db;
	private $user;
	private $indexer;
	private $config;

	private $sql_fetch;
	private $sphinx_id;

	public function __construct(driver_interface $db, user $user, config $config)
	{
		$this->db      = $db;
		$this->user    = $user;
		$this->config  = $config;

		$conn          = new Connection();
		$this->indexer = new SphinxQL($conn);

		$this->sphinx_id = 'index_phpbb_' . $this->config['fulltext_sphinx_id'] . '_private_messages';

		// Mysql only. Might work with others but I don't know.
		$this->sql_fetch = [
			'SELECT'    => 'p.msg_id as id,p.author_id author_id,GROUP_CONCAT(t.user_id SEPARATOR \' \') user_id,p.message_time,p.message_subject,p.message_text,GROUP_CONCAT( CONCAT(t.user_id,\'_\',t.folder_id) SEPARATOR \' \') folder_id',
			'FROM'      => [PRIVMSGS_TABLE => 'p'],
			'LEFT_JOIN' => [
				[
					'FROM' => [PRIVMSGS_TO_TABLE => 't'],
					'ON'   => 'p.msg_id = t.msg_id',
				],
			],
			'GROUP_BY'  => 'p.msg_id',
			'ORDER_BY'  => 'p.msg_id ASC',
		];
	}

	public function hide_me($event)
	{
		if (!$this->config['pmsearch_enable'])
		{
			/** @var \p_master $module */
			$module = $event['module'];
			foreach ($module->module_ary as $id => $item_array)
			{
				if ($item_array['name'] == '\crosstimecafe\pmsearch\ucp\main_module')
				{
					$module->module_ary[$id]['display'] = 0;
				}
			}
		}
	}

	public function submit($event)
	{
		// Todo skip if not using sphinx
		// Todo catch errors
		$this->sql_fetch['WHERE'] = 'p.msg_id = ' . $event['data']['msg_id'];
		$sql                      = $this->db->sql_build_query('SELECT', $this->sql_fetch);
		$result                   = $this->db->sql_query($sql);
		$row                      = $this->db->sql_fetchrow($result);
		$row['user_id']           = array_map('intval', explode(' ', $row['user_id']));

		($event['mode'] != 'edit') ? $this->indexer->insert() : $this->indexer->replace();
		$this->indexer->into($this->sphinx_id)->set($row)
		;

		try
		{
			$this->indexer->execute();
		}
		catch (ConnectionException|DatabaseException|SphinxQLException $e)
		{
		}
	}

	public function update($event)
	{
		// Todo what if the message was deleted by the sender before it could be viewed by the recipient
		// Todo reindex message text on edit
		// By this point all new messages should be placed into the inbox or some other folder

		$this->indexer->select('id')
					  ->from($this->sphinx_id)
					  ->where('user_id', $this->user->id())
					  ->match('folder_id', '"' . $this->user->id() . '_-3"')
		;
		$result      = false;
		$total_found = 0;
		try
		{
			$result = $this->indexer->execute();

			$meta        = $this->indexer->query("SHOW META LIKE 'total_found'")->execute();
			$meta_data   = $meta->fetchAllNum();
			$total_found = $meta_data[0][1];
		}
		catch (ConnectionException|DatabaseException|SphinxQLException $e)
		{
		}
		if ($total_found > 0)
		{
			$id_arr = [];
			foreach ($result as $row)
			{
				$id_arr[] = $row['id'];
			}

			$this->sql_fetch['WHERE'] = $this->db->sql_in_set('p.msg_id', $id_arr);
			$this->replace();

		}
	}

	public function remove($event)
	{
		// Message was not yet sent to recipient, delete from index
		if ($event['folder_id'] == PRIVMSGS_OUTBOX)
		{
			$this->indexer->delete()
						  ->from($this->sphinx_id)
						  ->where('id', 'IN', $event['msg_ids'])
			;
		}
		else
		{
			// Are we the last user with this message?

			// This will give us a list of messages which other users still have
			$keep   = [];
			$sql    = 'SELECT msg_id
				FROM ' . PRIVMSGS_TO_TABLE . '
				WHERE ' . $this->db->sql_in_set('msg_id', array_map('intval', $event['msg_ids'])) . ' AND user_id != ' . $event['user_id'] . '
				GROUP BY msg_id';
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$keep[] = $row['msg_id'];
			}

			$delete = array_diff($event['msg_ids'], $keep);

			if ($delete)
			{
				$this->indexer->delete()
							  ->from($this->sphinx_id)
							  ->where('id', 'IN', $delete)
				;
				try
				{
					$this->indexer->execute();
				}
				catch (Exception $e)
				{
					// Todo catch me if you can
				}
			}

			if ($keep)
			{
				$this->sql_fetch['WHERE'] = $this->db->sql_in_set('p.msg_id', $keep) . ' AND t.user_id != ' . $event['user_id'];
				$this->replace();
			}

		}
		// Delete if last user
		// Update if not last user
	}

	private function replace()
	{
		$sql    = $this->db->sql_build_query('SELECT', $this->sql_fetch);
		$result = $this->db->sql_query($sql);

		$this->indexer->replace()
					  ->into($this->sphinx_id)
		;
		while ($row = $this->db->sql_fetchrow($result))
		{
			$row['user_id'] = array_map('intval', explode(' ', $row['user_id']));
			$this->indexer->set($row);
		}
		try
		{
			$this->indexer->execute();
		}
		catch (ConnectionException|DatabaseException|SphinxQLException $e)
		{
		}
	}
}
