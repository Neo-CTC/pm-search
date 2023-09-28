<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2021, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\controller;

use crosstimecafe\pmsearch\core\mysqlSearch;
use crosstimecafe\pmsearch\core\postgresSearch;
use crosstimecafe\pmsearch\core\sphinxSearch;
use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;
use phpbb\pagination;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;

/**
 * PM Search UCP controller.
 *
 */
class ucp_controller
{
	protected $auth;
	protected $config;
	protected $db;
	protected $ext;
	protected $language;
	protected $pagination;
	protected $request;
	protected $root;
	protected $template;
	protected $u_action;
	protected $uid;
	protected $user;

	private $folder_cache;
	private $name_cache;

	/**
	 * Constructor.
	 *
	 * @param \phpbb\db\driver\driver_interface $db       Database object
	 * @param \phpbb\language\language          $language Language object
	 * @param \phpbb\request\request            $request  Request object
	 * @param \phpbb\template\template          $template Template object
	 * @param \phpbb\user                       $user     User object
	 * @param \phpbb\pagination                 $page
	 * @param \phpbb\config\config              $conf
	 * @param \phpbb\auth\auth                  $auth
	 */
	public function __construct(driver_interface $db, language $language, request $request, template $template, user $user, pagination $page, config $conf, auth $auth)
	{
		$this->db         = $db;
		$this->language   = $language;
		$this->request    = $request;
		$this->template   = $template;
		$this->user       = $user;
		$this->uid        = $user->id();
		$this->pagination = $page;
		$this->config     = $conf;
		$this->auth       = $auth;

		global $phpbb_root_path, $phpEx;
		$this->root = $phpbb_root_path;
		$this->ext  = $phpEx;

		// Need to borrow a few functions from phpbb
		include($this->root . 'includes/functions_privmsgs.' . $this->ext);

		// Wait a minute, search is disabled. How did you get here?
		if (!$this->config['pmsearch_enable'])
		{
			trigger_error($this->language->lang('UCP_PMSEARCH_ERR_GENERIC'));
		}
		$this->name_cache = [];
	}

	public function display_messages()
	{
		// Add folders to private message navbar
		$this->template->assign_var('S_PRIVMSGS', true);
		$folder_list = get_folder($this->uid);

		// Todo don't forget about group messages
		// Todo harden input
		// Todo get minimum characters
		// Todo max return limits
		// Todo pagination jump box not working
		// TODO: search by time frame
		// TODO: more sorting types
		// TODO: filter out operators for highlighting

		/*
		 *
		 * Collect input
		 *
		 */


		/** @var string $keywords */
		/** @var string $from */
		/** @var string $sent */
		/** @var int[] $folders */
		/** @var string $search_field */
		/** @var string $order */
		/** @var string $direction */
		/** @var int $start */

		$keywords     = $this->request->variable('keywords', '', true);
		$from         = $this->request->variable('from', '', true);
		$sent         = $this->request->variable('sent', '', true);
		$folders      = $this->request->variable('fid', [0]);
		$search_field = $this->request->variable('sf', 'all');
		$order        = $this->request->variable('sk', 'message_time');
		$direction    = $this->request->variable('sd', 'desc');
		$start        = $this->request->variable('start', 0);


		/*
		 *
		 * Process input
		 *
		 */


		// Todo remove explain from 'from' & 'to' fields, replace with find member link
		// Todo limit length of fields

		// Can not search by from and to in the same search
		if ($from && $sent)
		{
			trigger_error($this->language->lang('UCP_PMSEARCH_NOT_BOTH'));
		}

		// Decode quotes
		/** @var string $keywords */
		$keywords = str_replace('&quot;', '"', $keywords);

		// Split from/sent field into an id array if needed
		$from_id_array = [];
		$sent_id_array = [];
		if ($from)
		{
			$from_id_array = $this->get_ids($from);
		}
		if ($sent)
		{
			$sent_id_array = $this->get_ids($sent);
		}

		// Todo make sorting work with author and subject
		switch ($order)
		{
			case 't':
			default:
				$order = 'message_time';
		}
		switch ($direction)
		{
			case 'a':
				$direction = 'ASC';
			break;
			case 'd':
			default:
				$direction = 'DESC';
		}


		// Pick a backend
		switch ($this->config['pmsearch_engine'])
		{
			case 'sphinx':
				$backend = new sphinxSearch($this->config, $this->db);
			break;
			case 'mysql':
				$backend = new mysqlSearch($this->config, $this->db);
			break;
			case 'postgres':
				$backend = new postgresSearch($this->config, $this->db);
			break;
			default:
				trigger_error($this->language->lang('UCP_PMSEARCH_ERR_GENERIC'));
				return;
		}

		// TODO: handle invalid start numbers
		$result = $backend->search($this->uid, $search_field, $keywords, $from_id_array, $sent_id_array, $folders, $order, $direction, $start);
		if (!$result)
		{
			if ($this->auth->acl_get('a_') && $backend->error_msg)
			{
				trigger_error($this->language->lang($backend->error_msg) . '<br>' . $backend->error_msg_full);
			}
			else
			{
				trigger_error($this->language->lang('UCP_PMSEARCH_ERR_GENERIC'));
			}
			return;
		}
		else
		{
			$message_ids = $backend->message_ids;
			$total_found = $backend->total_found;
		}


		/*
		 *
		 * Fetch messages
		 *
		 */


		if ($message_ids)
		{
			// Collect message ids
			$sql_where = $this->db->sql_in_set('p.msg_id', $message_ids);

			// SQL to combine folder names
			switch ($this->db->get_sql_layer())
			{
				case 'mysqli':
					$folder_concat = "GROUP_CONCAT(t.folder_id SEPARATOR '&&') as fid";
				break;
				case 'postgres':
					$folder_concat = "STRING_AGG(t.folder_id,'&&') as fid";
				break;
				default:
					$folder_concat = 'MAX(t.folder_id) as fid';
			}

			// SQL for fetching messages from ids
			$sql_array = [
				'SELECT'    =>
					'p.msg_id,' .
					'p.author_id,' .
					'u.username as author_name,' .
					'u.user_colour as author_colour,' .
					'p.message_time,' .
					'p.message_subject,' .
					'p.message_text,' .
					'p.bbcode_uid,' .
					'p.bbcode_bitfield,' .
					'p.to_address,' .
					'p.bcc_address,' .
					$folder_concat,
				'FROM'      => [
					PRIVMSGS_TABLE => 'p',
				],
				// Get extra data
				'LEFT_JOIN' => [
					[
						// Get username and username colour for the author
						'FROM' => [USERS_TABLE => 'u'],
						'ON'   => 'p.author_id = u.user_id',
					],
					[
						// Get the folder id of the message for current user
						'FROM' => [PRIVMSGS_TO_TABLE => 't'],
						'ON'   => 'p.msg_id = t.msg_id and t.user_id = ' . $this->uid,
					],
				],
				'WHERE'     => $sql_where,
				'GROUP_BY'  => 'p.msg_id, u.user_id', // We don't need to group the user_id but PostgreSQL complains if we don't
				'ORDER_BY'  => $order . ' ' . $direction . ', p.msg_id DESC', // Todo better order logic
			];
			$sql       = $this->db->sql_build_query('SELECT', $sql_array);
			$result    = $this->db->sql_query($sql);


			/*
			 *
			 * Process returned rows
			 *
			 */


			while ($row = $this->db->sql_fetchrow($result))
			{
				/*
				 *
				 * Process message text and bbcode
				 *
				 */

				$row['message_subject'] = censor_text($row['message_subject']);
				if ($row['bbcode_uid'])
				{
					// Not entirely sure what this does, it was copied from search.php
					$row['message_text'] = str_replace('[*:' . $row['bbcode_uid'] . ']', '&sdot;&nbsp;', $row['message_text']);
				}
				$parse_flags         = ($row['bbcode_bitfield'] ? OPTION_FLAG_BBCODE : 0) | OPTION_FLAG_SMILIES;
				$row['message_text'] = generate_text_for_display($row['message_text'], $row['bbcode_uid'], $row['bbcode_bitfield'], $parse_flags, true);

				// Todo needs testing with operators, non A-z characters, numbers, hyphens, phrase matching
				// Todo doesn't highlight on non normalized words, e.g. cat vs cats
				// Add highlighting, as see in search.php
				$hilit = phpbb_clean_search_string(str_replace(['+', '-', '|', '(', ')', '&quot;'], ' ', $keywords));
				$hilit = str_replace(' ', '|', $hilit);
				if ($hilit)
				{
					// Remove bad highlights
					$hilit_array = array_filter(explode('|', $hilit), 'strlen');
					foreach ($hilit_array as $key => $value)
					{
						$hilit_array[$key] = phpbb_clean_search_string($value);
						$hilit_array[$key] = str_replace('\*', '\w*?', preg_quote($hilit_array[$key], '#'));
						$hilit_array[$key] = preg_replace('#(^|\s)\\\\w\*\?(\s|$)#', '$1\w+?$2', $hilit_array[$key]);
					}
					$hilit = implode('|', $hilit_array);
				}
				$row['message_text']    = preg_replace('#(?!<.*)(?<!\w)(' . $hilit . ')(?!\w|[^<>]*(?:</s(?:cript|tyle))?>)#isu', '<span class="posthilit">$1</span>', $row['message_text']);
				$row['message_subject'] = preg_replace('#(?!<.*)(?<!\w)(' . $hilit . ')(?!\w|[^<>]*(?:</s(?:cript|tyle))?>)#isu', '<span class="posthilit">$1</span>', $row['message_subject']);


				/*
				 *
				 * Build recipient list
				 *
				 */


				//Build to address string
				$to_address = $this->colorize_usernames(explode(':', $row['to_address']));

				//Build bcc address string
				$bcc_address = '';
				if ($row['bcc_address'])
				{
					// Let the author see all bcc
					if ($row['author_id'] == $this->uid)
					{
						$bcc_address = $this->colorize_usernames(explode(':', $row['bcc_address']));
					}
					// Let user see bcc to self
					else if (preg_match('/u_' . $this->uid . '(?:$|:)/', $row['bcc_address']))
					{
						$bcc_address = $this->colorize_usernames(['u_' . $this->uid]);
					}
				}

				/*
				 *
				 * Build folder list
				 *
				 */

				$folder_names = [];
				foreach (explode('&&', $row['fid']) as $fid)
				{
					$folder_names[] = $folder_list[$fid]['folder_name'];
				}
				$folder_names = implode(', ', $folder_names);


				/*
				 *
				 * Assign template block for messages
				 *
				 */


				$this->template->assign_block_vars('searchresults', [
					'DATE'          => (!empty($row['message_time'])) ? $this->user->format_date($row['message_time']) : '',
					'AUTHOR'        => $this->colorize_usernames([$row['author_id']]),
					'RECIPIENTS'    => $to_address,
					'BCC_RECIPIENT' => $bcc_address,
					'FOLDER'        => $folder_names,

					'SUBJECT' => $row['message_subject'],
					'MESSAGE' => $row['message_text'],

					'VIEW_MESSAGE' => append_sid("{$this->root}ucp.$this->ext", 'i=pm&mode=view&p=' . $row['msg_id']),
					'MESSAGE_ID'   => $row['msg_id'],
				]);
			}
			$this->db->sql_freeresult($result);
		}


		/*
		 *
		 * Run pagination
		 *
		 */


		$start      = $this->pagination->validate_start($start, $this->config['posts_per_page'], $total_found);
		$url_params = '&keywords=' . urlencode($keywords);
		$url_params .= '&from=' . urlencode($from);
		$url_params .= '&sent=' . urlencode($sent);
		foreach ($folders as $f)
		{
			$url_params .= '&fid[]=' . $f;
		}
		switch ($search_field)
		{
			case 'message_text':
				$search_field = 't';
			break;
			case 'message_subject':
				$search_field = 's';
			break;
			default:
				$search_field = 'b';
		}
		$url_params .= '&sf=' . $search_field;
		switch ($order)
		{
			case 'message_time':
			default:
				$order = 't';
		}
		$url_params .= '&sk=' . $order;
		$url_params .= $direction == 'ASC' ? '&sd=a' : '&sd=d';
		$base_url   = $this->u_action . $url_params;
		$this->pagination->generate_template_pagination($base_url, 'pagination', 'start', $total_found, $this->config['posts_per_page'], $start);

		// Store the current page so we can pass it on to ajax for refreshing
		$this->template->assign_var('PM_CURRENT_URL', $base_url . '&start=' . $start);


		/*
		 *
		 * Get folder names
		 *
		 */

		$search_folders = [];
		foreach ($folders as $folder)
		{
			$search_folders[] = $folder_list[$folder]['folder_name'] ?? $this->language->lang('UNKNOWN_FOLDER');
		}
		$search_folders = implode(', ', $search_folders);

		// Show the user what they used to search
		$this->template->assign_vars([
			'SEARCH_LINK'    => $this->u_action,
			'SEARCH_MATCHES' => $this->language->lang('FOUND_SEARCH_MATCHES', $total_found),

			'KEYWORDS' => $keywords,
			'FROM'     => $from,
			'SENT'     => $sent,
			'FOLDER'   => $search_folders,

			'U_PMSEARCH_ACTION' => $this->u_action,
		]);

		// Create move to list
		foreach ($folder_list as $fid => $value)
		{
			// Skip Out and Sent boxes
			if ($fid < 0)
			{
				continue;
			}
			$this->template->assign_block_vars('folder_move_to', [
				'id'     => $fid,
				'folder' => sprintf($this->language->lang('MOVE_MARKED_TO_FOLDER'), $value['folder_name']),
			]);
		}
	}

	public function display_options()
	{
		// Todo permission checking

		/*
		 *
		 * Build search options
		 *
		 */


		// Default folders
		$this->template->assign_block_vars_array('folder_select', [
			['id' => 0, 'name' => 'Inbox'],
			['id' => -1, 'name' => 'Sent'],
			['id' => -2, 'name' => 'Outbox'],
		]);

		// Custom folders
		$results = $this->db->sql_query('SELECT folder_id, folder_name FROM ' . PRIVMSGS_FOLDER_TABLE . ' WHERE user_id = ' . $this->uid);
		while ($row = $this->db->sql_fetchrow($results))
		{
			$this->template->assign_block_vars('folder_select', ['id' => $row['folder_id'], 'name' => $row['folder_name']]);
		}

		// Sort options
		$this->template->assign_block_vars_array('sorting', [
			// This is the only one that works at this time
			['value' => 't', 'selected' => 1, 'text' => $this->language->lang('UCP_PMSEARCH_TIME')],
		]);


		/*
		 *
		 * Assign template variables
		 *
		 */


		// Displays the folders on the left of the page
		$this->template->assign_var('S_PRIVMSGS', true);
		get_folder($this->uid);

		// We'll be making a get request, carry over module and mode
		$s_hidden_fileds = build_hidden_fields([
			'i'    => $this->request->variable('i', ''),
			'mode' => $this->request->variable('mode', ''),
		]);

		$this->template->assign_vars([
			// TODO: error handling
			//'S_ERROR'		=> $s_errors,
			//'ERROR_MSG'	=> $s_errors ? implode('<br />', $errors) : '',
			'U_UCP_ACTION'    => $this->u_action,
			'S_HIDDEN_FIELDS' => $s_hidden_fileds,
		]);
	}

	public function pm_actions()
	{
		$action  = $this->request->variable('action', '');
		$msg_ids = $this->request->variable('msg_ids', [0]);

		if (!$msg_ids)
		{
			return [];
		}

		// TODO: try out the helper api
		// We'll need this so we can reload the page after modifying the messages
		$page_url = html_entity_decode($this->request->variable('page_url', ''));

		// Set defaults for ajax
		$title   = $this->language->lang('ERROR');
		$text    = '';
		$refresh = false;

		switch ($action)
		{
			// Copying from functions_privmsgs
			case 'mark_important':
				$sql = 'UPDATE ' . PRIVMSGS_TO_TABLE . ' SET pm_marked = 1 - pm_marked WHERE user_id = ' . $this->uid . ' AND ' . $this->db->sql_in_set('msg_id', $msg_ids);
				$this->db->sql_query($sql);

				$title = $this->language->lang('INFORMATION');
				$text  = $this->language->lang('UCP_PMSEARCH_MESSAGES_MARKED');
			break;

			case 'delete_marked':


				if (!confirm_box(true))
				{
					$fields = build_hidden_fields(['action' => $action, 'msg_ids' => $msg_ids, 'page_url' => $page_url]);
					confirm_box(false, 'DELETE_MARKED_PM', $fields);
				}

				if (!$this->auth->acl_get('u_pm_delete'))
				{
					send_status_line(403, 'Forbidden');
					trigger_error('NO_AUTH_DELETE_MESSAGE');
				}

				foreach ($msg_ids as $msg_id)
				{
					// TODO: deal with messages in multiple folders
					// Sometimes a message is in 2 folders, this only happens when a user sends a message to self
					// They'll both be deleted
					$sql    = 'SELECT folder_id FROM ' . PRIVMSGS_TO_TABLE . ' WHERE user_id = ' . $this->uid . ' AND msg_id = ' . $msg_id;
					$result = $this->db->sql_query($sql);

					// Fetch all the folders
					$f_ids = [];
					while ($row = $this->db->sql_fetchrow($result))
					{
						$f_ids[] = $row['folder_id'];
					}

					$this->db->sql_freeresult($result);

					// Let phpbb handle the actual deletion
					foreach ($f_ids as $f_id)
					{
						delete_pm($this->uid, $msg_id, $f_id);
					}
				}

				$title   = $this->language->lang('INFORMATION');
				$text    = $this->language->lang('MESSAGES_DELETED');
				$refresh = true;
			break;

			// Most likely an action to move to a folder
			default:
				// Not an action to move to a folder
				if (strpos($action, 'move_to') !== 0)
				{
					break;
				}

				$dest_id = (int) substr($action, 8);

				$backend = false;
				// Prep sphinx
				if ($this->config['pmsearch_engine'] == 'sphinx')
				{
					$backend = new sphinxSearch($this->config, $this->db);
				}

				foreach ($msg_ids as $msg_id)
				{
					// Fetch 1 and only 1 folder id
					// Sometimes a message is in 2 folders, this only happens when a user sends a message to self
					$sql    = 'SELECT folder_id FROM ' . PRIVMSGS_TO_TABLE . ' WHERE user_id = ' . $this->uid . ' AND msg_id = ' . $msg_id . ' ORDER BY folder_id DESC LIMIT 1';
					$result = $this->db->sql_query($sql);

					// Fetch the first folder found
					$f_ids = $this->db->sql_fetchfield('folder_id', false, $result);
					$this->db->sql_freeresult($result);

					// Let phpbb handle the actual move
					set_user_message_limit();
					move_pm($this->uid, $this->user->data['message_limit'], $msg_id, $dest_id, $f_ids);

					// Yes we are running this on each message and not all at once. This is due to the
					// chance that move_pm may fail if a box is full
					if ($backend)
					{
						$backend->update_entry($msg_id);
					}
				}

				$title   = $this->language->lang('INFORMATION');
				$text    = $this->language->lang('UCP_PMSEARCH_MESSAGES_MOVED');
				$refresh = true;
			break;
		}
		$response = ['MESSAGE_TITLE' => $title, 'MESSAGE_TEXT' => $text];
		if ($refresh)
		{
			$response['REFRESH_DATA'] = ['time' => 2, 'url' => $page_url];
		}
		return $response;
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	/**
	 * Add links and color to an array of to and bcc usernames.
	 * Copied from functions_privmsgs.php->write_pm_addresses()
	 *
	 * @param $user_ids string[] Array of usernames to colorize
	 *
	 * @return string
	 */
	private function colorize_usernames(array $user_ids)
	{
		$find_names = [];
		$colorized  = [];
		foreach ($user_ids as $id)
		{
			// Todo groups vs users

			// Remove prefix
			if (substr($id, 1, 1) === '_')
			{
				$id = substr($id, 2);
			}

			if (isset($this->name_cache[$id]))
			{
				$colorized[$id] = $this->name_cache[$id];
			}
			else
			{
				$find_names[] = $id;
			}
		}


		if ($find_names)
		{
			$sql    = 'SELECT user_id, username, user_colour
			FROM ' . USERS_TABLE . '
			WHERE ' . $this->db->sql_in_set('user_id', $find_names);
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$this->name_cache[$row['user_id']] = get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']);
				$colorized[$row['user_id']]        = $this->name_cache[$row['user_id']];
			}
		}
		return implode(' ', $colorized);
	}

	private function fetch_folder_name($f_id)
	{
		if (!isset($this->folder_cache[$f_id]))
		{
			$result                    = $this->db->sql_query('SELECT folder_name FROM ' . PRIVMSGS_FOLDER_TABLE . ' WHERE folder_id = ' . $f_id . ' AND user_id = ' . $this->uid);
			$this->folder_cache[$f_id] = $this->db->sql_fetchfield('folder_name', false, $result);
		}
		return $this->folder_cache[$f_id];
	}

	/**
	 * Converts string of usernames to array of user ids
	 *
	 * @param string $str String of usernames seperated by commas
	 *
	 * @return array
	 */
	private function get_ids(string $str): array
	{
		$id_array = [];
		// Split from field and clean the strings
		$names = explode(',', $str);
		foreach ($names as &$name)
		{
			$name = utf8_clean_string($name);
			// sql_in_set does its own escaping but just in case...
			$name = $this->db->sql_escape($name);
		}
		unset($name);

		// Fetch user ids from usernames
		$where  = $this->db->sql_in_set('username_clean', $names);
		$result = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' . $where);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$id_array[] = (int) $row['user_id'];
		}
		$this->db->sql_freeresult($result);

		// Stop here if we could not find any user ids
		if (empty($id_array))
		{
			return [0];
			//trigger_error('NO_SEARCH_RESULTS');
		}
		return $id_array;
	}
}
