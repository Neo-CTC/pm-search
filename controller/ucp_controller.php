<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2021, NeoDev
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */
namespace crosstimecafe\pmsearch\controller;

use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use Foolz\SphinxQL\SphinxQL;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;
use phpbb\pagination;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;

include('includes/functions_privmsgs.php');

/**
 * PM Search UCP controller.
 */
class ucp_controller
{
	protected $db;
	protected $language;
	protected $request;
	protected $template;
	protected $user;
	protected $pagination;
	protected $config;

	protected $u_action;
	protected $uid;
	protected $root;
	protected $ext;

	/**
	 * Constructor.
	 *
	 * @param \phpbb\db\driver\driver_interface	$db			Database object
	 * @param \phpbb\language\language			$language	Language object
	 * @param \phpbb\request\request			$request	Request object
	 * @param \phpbb\template\template			$template	Template object
	 * @param \phpbb\user						$user		User object
	 */
	public function __construct(driver_interface $db, language $language, request $request, template $template, user $user, pagination $page, config $conf)
	{
		$this->db			= $db;
		$this->language		= $language;
		$this->request		= $request;
		$this->template		= $template;
		$this->user			= $user;
		$this->uid			= $user->id();
		$this->pagination	= $page;
		$this->config		= $conf;

		global $phpbb_root_path, $phpEx;
		$this->root	= $phpbb_root_path;
		$this->ext	= $phpEx;
	}

	public function display_messages()
	{
		// Form key for preventing CSRF attacks
		add_form_key('crosstimecafe_pmsearch_ucp');
		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key('crosstimecafe_pmsearch_ucp'))
			{
				trigger_error($this->language->lang('FORM_INVALID'));
			}
		}

		// Todo don't forget about group messages
		// Todo add try/catch
		// Todo harden input
		// Todo get minimum characters
		// Todo need to strip bbcode from indexer
		// Todo max return limits
		// Todo pagination jump box not working
		// Todo author and keyword not working together?
		// Todo allow searching multiple authors/sent to


		/*
		 *
		 * Collect input
		 *
		 */


		$keywords = $this->request->variable('keywords', '', true);
		$from = $this->request->variable('from', '', true);
		$sent = $this->request->variable('sent', '', true);
		$folders = $this->request->variable('fid', [0]);
		$search_field = $this->request->variable('sf', 'all');
		$order = $this->request->variable('sk','message_time');
		$direction = $this->request->variable('sd','desc');
		$start = $this->request->variable('start',0);


		/*
		 *
		 * Process input
		 *
		 */


		// Todo test bcc. make sure you can't search for users in the bcc
		// Todo remove explain from 'from' & 'to' fields, replace with find member link
		// Todo limit length of fields

		// Can not search by from and to in the same search
		if ($from && $sent)
		{
			trigger_error($this->language->lang('UCP_PMSEARCH_NOT_BOTH'));
		}

		// Un-escape quotes
		$keywords = str_replace('&quot;','"',$keywords);

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

		// Odd setup to make folder searching work with the default folders
		$folder_ids = '';
		$folder_id_array = [];
		if($folders)
		{
			foreach ($folders as $f)
			{
				// Because everyone has the same folder ids for Inbox, Sent, and Outbox, we add `<user id>_` to the start of all
				// folders. This allows us to run a full text match for matching folders.
				$folder_id_array[] = '"' . $this->uid . '_' . $f . '"';
			}
			unset($f);
			// Adds an OR operator for searching
			$folder_ids = implode('|', $folder_id_array);
		}

		// Which full text fields to search
		switch ($search_field)
		{
			case 't':
				$search_field = 'message_text';
				break;
			case 's':
				$search_field = 'message_subject';
				break;
			case 'b':
			default:
				$search_field = ['message_text','message_subject'];
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


		/*
		 *
		 * Setup SphinxQL
		 *
		 */


		$conn = new Connection();
		// Todo get host/port from config
		$conn->setParams(['host' => 'localhost', 'port' => 9306]);

		$search = new SphinxQL($conn);
		$search->select('id');
		$search->from('pm');
		$search->where('user_id', $this->uid);

		if($keywords)
		{
			$search->match($search_field, $keywords, true);
		}
		if ($from_id_array)
		{
			$search->where('author_id', 'IN', $from_id_array);
		}
		if ($sent_id_array)
		{
			$search->where('author_id', '=', $this->uid);
			foreach ($sent_id_array as $id)
			{
				$search->where('user_id', $id);
			}
		}
		if ($folder_ids)
		{
			$search->match('folder_id',$folder_ids,true);
		}
		$search->orderBy($order,$direction);
		$search->limit($start,$this->config['posts_per_page']);


		/*
		 *
		 * Process SphinxQL
		 *
		 */


		$rows = [];
		$total_found = 0;
		try
		{
			// Fetch matches
			$result = $search->execute();
			$rows = $result->fetchAllAssoc();

			// Todo also fetch any errors
			// Fetch the 'total found' variable from metadata
			$search->query("SHOW META LIKE 'total_found'");
			$result = $search->execute();
			$meta_data = $result->fetchAllNum();
			$total_found = $meta_data[0][1];

		}
		catch (ConnectionException $e)
		{
			// Can't connect
			trigger_error($this->language->lang('UCP_PMSEARCH_ERR_CONN'));
			// Todo log errors
		}
		catch (DatabaseException $e)
		{
			// Bad sql or missing table or some other problem
			trigger_error($this->language->lang('UCP_PMSEARCH_ERR_DB'));
		}
		catch (SphinxQLException $e)
		{
			// Unknown error
			trigger_error($this->language->lang('UCP_PMSEARCH_ERR_SPHINX'));
		}


		/*
		 *
		 * Fetch messages
		 *
		 */

		if ($rows)
		{
			// Collect message ids
			$message_ids = [];
			foreach ($rows as $row)
			{
				$message_ids[] = $row['id'];

			}
			$sql_where = $this->db->sql_in_set('p.msg_id', $message_ids);

			// SQL for fetching messages from ids
			$sql_array = [
				'SELECT'    => 'p.msg_id, p.author_id, u.username as author_name, u.user_colour as author_colour, p.message_time, p.message_subject, p.message_text, p.bbcode_uid, p.bbcode_bitfield, p.to_address, p.bcc_address, t.folder_id, f.folder_name',
				'FROM'      => [
					PRIVMSGS_TABLE => 'p',
				],
				// Get extra data
				'LEFT_JOIN' => [
					[
						// Get username and username colour for the author
						'FROM'	=> [USERS_TABLE => 'u'],
						'ON'	=> 'p.author_id = u.user_id',
					],
					[
						// Get the folder id of the message for current user
						'FROM'	=> [PRIVMSGS_TO_TABLE => 't'],
						'ON'	=> 'p.msg_id = t.msg_id and t.user_id = ' . $this->uid,
					],
					[
						// Get the folder name from id
						'FROM'	=> [PRIVMSGS_FOLDER_TABLE => 'f'],
						'ON'	=> 't.folder_id = f.folder_id'
					],
				],
				'WHERE'     => $sql_where,
				'ORDER_BY'  => $order . ' ' . $direction,
			];
			$sql = $this->db->sql_build_query('SELECT', $sql_array);
			$result = $this->db->sql_query($sql);


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
				$parse_flags = ($row['bbcode_bitfield'] ? OPTION_FLAG_BBCODE : 0) | OPTION_FLAG_SMILIES;
				$row['message_text'] = generate_text_for_display($row['message_text'], $row['bbcode_uid'], $row['bbcode_bitfield'], $parse_flags, true);


				/*
				 *
				 * Build recipient header
				 *
				 */


				// Todo: We're not ready to handle bcc yet
				$row['bcc_address'] = '';
				$recipient = get_recipient_strings([ $row['msg_id'] => $row ]);
				$recipient = implode(' ',$recipient[$row['msg_id']]);


				/*
				 *
				 * Assign template block for messages
				 *
				 */


				// Process folder name
				switch ($row['folder_id'])
				{
					case -2:
						$folder_name = 'Outbox';
						break;
					case -1:
						$folder_name = 'Sent';
						break;
					case 0:
						$folder_name = 'Inbox';
						break;
					default:
						$folder_name = $row['folder_name'];
				}
				$this->template->assign_block_vars('searchresults', [
					'DATE'		=> (!empty($row['message_time'])) ? $this->user->format_date($row['message_time']) : '',
					'AUTHOR'	=> get_username_string('full', $row['author_id'], $row['author_name'], $row['author_colour']),
					'RECIPIENTS'=> $recipient,
					'FOLDER'	=> $folder_name,

					'SUBJECT'	=> $row['message_subject'],
					'MESSAGE'	=> $row['message_text'],

					'VIEW_MESSAGE'	=> append_sid("{$this->root}ucp.$this->ext", 'i=pm&mode=view&p=' . $row['msg_id']),
				]);
			}
			$this->db->sql_freeresult($result);
		}


		/*
		 *
		 * Run pagination
		 *
		 */


		$start = $this->pagination->validate_start($start, $this->config['posts_per_page'], $total_found);
		$url_parms  = $keywords ? '&keywords=' . urlencode($keywords) : '';
		$url_parms .= $from ? '&from=' . urlencode($from) : '';
		$url_parms .= $sent ? '&to='. urlencode($sent) : '';
		foreach ($folders as $f)
		{
			$url_parms .= '&fid[]=' . substr($f,strpos($f,'_') + 1,-1);
		}
		switch($search_field)
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
		$url_parms .= '&sf='.$search_field;
		switch($order)
		{
			case 'message_time':
			default:
				$order = 't';
		}
		$url_parms .= '&sk='.$order;
		$url_parms .= $direction == 'ASC'? '&sd=a' : '&sd=d';
		$this->pagination->generate_template_pagination($this->u_action.$url_parms, 'pagination', 'start', $total_found, $this->config['posts_per_page'], $start);


		/*
		 *
		 * Get folder names
		 *
		 */


		$folder_list = '';
		if ($folders)
		{
			// Default folders
			$folder_list .= in_array(0, $folders) ? 'Inbox ' : '';
			$folder_list .= in_array(-1, $folders) ? 'Sent ' : '';
			$folder_list .= in_array(-2, $folders) ? 'Outbox ' : '';

			// Custom folders
			$sql_where = $this->db->sql_in_set('folder_id', $folders);
			$result = $this->db->sql_query('SELECT folder_name FROM ' . PRIVMSGS_FOLDER_TABLE . ' WHERE user_id = ' . $this->uid . ' AND ' . $sql_where);
			foreach ($result as $row)
			{
				$folder_list .= $row['folder_name'];
			}
		}


		/*
		 *
		 * Assign more template variables
		 *
		 */


		// Adds user folders to private message navbar
		$this->template->assign_var('S_PRIVMSGS', true);
		get_folder($this->uid);

		$this->template->assign_vars([
			'SEARCH_LINK'		=> $this->u_action,
			'SEARCH_MATCHES'	=> $this->language->lang('FOUND_SEARCH_MATCHES', $total_found),

			'KEYWORDS'	=> $keywords,
			'FROM'		=> $from,
			'SENT'		=> $sent,
			'FOLDER'	=> $folder_list,
		]);

	}
	public function display_options()
	{
		// Todo permission checking
		// Todo remove stop-gap styles

		// Form key for preventing CSRF attacks
		add_form_key('crosstimecafe_pmsearch_ucp');
		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key('crosstimecafe_pmsearch_ucp'))
			{
				trigger_error($this->language->lang('FORM_INVALID'));
			}
		}


		/*
		 *
		 * Build search options
		 *
		 */


		// Default folders
		$this->template->assign_block_vars_array('folder_select',[
			['id'=>0,'name'=>'Inbox'],
			['id'=>-1,'name'=>'Sent'],
			['id'=>-2,'name'=>'Outbox'],
		]);

		// Custom folders
		$folders = $this->db->sql_query('SELECT folder_id, folder_name FROM ' . PRIVMSGS_FOLDER_TABLE . ' WHERE user_id = ' .$this->uid);
		foreach ($folders as $f)
		{
			$this->template->assign_block_vars('folder_select', ['id'=>$f['folder_id'],'name'=>$f['folder_name']]);
		}

		// Sort options
		$this->template->assign_block_vars_array('sorting',[
			// This is the only one that works at this time
			['value'=>'t','selected'=>1,'text'=>$this->language->lang('UCP_PMSEARCH_TIME')],
		]);


		/*
		 *
		 * Assign template variables
		 *
		 */


		// Displays the folders on the left of the page
		$this->template->assign_var('S_PRIVMSGS', true);
		get_folder($this->uid);

		$this->template->assign_vars([
			//'S_ERROR'		=> $s_errors,
			//'ERROR_MSG'		=> $s_errors ? implode('<br />', $errors) : '',
			'U_UCP_ACTION'	=> $this->u_action,
		]);
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	/**
	 * Converts string of usernames to array of user ids
	 *
	 * @param string $str String of usernames seperated by commas
	 * @return array
	 */
	private function get_ids(string $str): array
	{
		$id_array = [];
		// Split from field and clean the strings
		$names = explode(',',$str);
		foreach ($names as &$name)
		{
			$name = utf8_clean_string($name);
			// sql_in_set does its own escaping but just in case...
			$name = $this->db->sql_escape($name);
		}
		unset($name);

		// Fetch user ids from usernames
		$where = $this->db->sql_in_set('username_clean',$names);
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
