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

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\request\request;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use crosstimecafe\pmsearch\core\sphinxSearch;


class main_listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			// When a user views their new PMs, place_pm_into_folder is called, and
			// we need to reindex the folders for the messages. There is no event
			// for when a message is moved into a folder. These events are the best we can use.

			// Update after moving messages or when new messages are delivered
			'core.ucp_pm_view_folder_get_pm_from_sql' => 'update', // Update on view folder
			'core.ucp_pm_view_messsage' => 'update', // Update on view message


			// Add message to index
			'core.submit_pm_after'  => 'submit',

			// I wish there was an event after the deletion, but I can work with this
			// Remove message from index
			'core.delete_pm_before' => 'remove',

			'core.ucp_display_module_before' => [
				['hide_me'], // Hide the search page if search is not enabled
				['pm_check'], // Check for and store new or moved messages
			],

			// Todo There is no event to catch a change in folders
		];
	}

	private $db;
	private $user;
	private $config;
	private $request;

	/**
	 * Stores ids of messages moving to inbox
	 * @var int[]
	 */
	private static $new_message_ids;

	public function __construct(driver_interface $db, user $user, config $config, request $request)
	{
		$this->db      = $db;
		$this->user    = $user;
		$this->config  = $config;
		$this->request = $request;
	}

	/**
	 * Hides search module when search is disabled
	 *
	 * @param $event
	 *
	 * @return void
	 */
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

	/**
	 * Check and store new message ids
	 *
	 * @return void
	 */
	public function pm_check($event)
	{
		// Skip if not sphinx
		if ($this->config['pmsearch_engine'] !== 'sphinx')
		{
			return;
		}

		// Skip if not in private message module
		if (!in_array($this->request->variable('i',''), ['pm','ucp_pm'],true))
		{
			return;
		}

		// Skip if not viewing a folder or message
		if ($this->request->variable('folder', '') === '' && $this->request->variable('mode', 'view') !== 'view')
		{
			return;
		}

		$ids = [];

		// There are messages waiting to be moving into inbox
		if ($this->user->data['user_new_privmsg'])
		{
			$sql = 'SELECT t.msg_id
				FROM ' . PRIVMSGS_TO_TABLE . ' t, ' . PRIVMSGS_TABLE . ' p, ' . USERS_TABLE . " u
				WHERE t.user_id = " . $this->user->id() . "
					AND p.author_id = u.user_id
					AND t.folder_id = " . PRIVMSGS_NO_BOX . '
					AND t.msg_id = p.msg_id';
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$ids[] = (int) $row['msg_id'];
			}
		}

		// Moving messages; logic copied from ucp_pm.php
		$mark_option	= $this->request->variable('mark_option', '');
		$submit_mark	= $this->request->variable('submit_mark', false);
		$move_pm		= $this->request->variable('move_pm', false);

		// Messages selected for moving from folder view
		if (!in_array($mark_option, ['mark_important', 'delete_marked']) && $submit_mark)
		{
			$move_pm = true;
		}

		if($move_pm)
		{
			$move_msg_ids	= $this->request->variable('marked_msg_id', [0]);
			if (count($move_msg_ids))
			{
				$ids = empty($ids) ? $move_msg_ids : array_merge($ids, $move_msg_ids);
			}
		}

		// Store the message ids before they get cleared
		self::$new_message_ids = $ids;
	}

	/**
	 * Index messages when created or edited
	 *
	 * @param $event
	 *
	 * @return void
	 */
	public function submit($event)
	{
		// Only Sphinx needs to be updated
		if ($this->config['pmsearch_engine'] != 'sphinx')
		{
			return;
		}

		$sphinx = new sphinxSearch($this->config, $this->db);
		$sphinx->update_entry($event['data']['msg_id']);
	}


	/**
	 * Hands off message ids for reindexing
	 *
	 * @return void
	 */
	public function update()
	{
		// Todo what if the message was deleted by the sender before it could be viewed by the recipient
		// Todo reindex message text on edit
		// By this point all messages should be done moving around, update their entries
		if (!self::$new_message_ids || $this->config['pmsearch_engine'] !== 'sphinx')
		{
			return;
		}
		$backend = new sphinxSearch($this->config, $this->db);
		$backend->update_entry(self::$new_message_ids);
	}

	public function remove($event)
	{
		// TODO: Delete if last user, update if not last user
	}
}
