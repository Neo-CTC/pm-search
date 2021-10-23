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
use Foolz\SphinxQL\SphinxQL;

use phpbb\extension\exception;
include('includes/functions_privmsgs.php');

/**
 * PM Search UCP controller.
 */
class ucp_controller
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	/** @var string Custom form action */
	protected $u_action;

	/** @var \phpbb\pagination */
	protected $pagination;

	/** @var \phpbb\config\config */
	protected $config;

	protected $root;
	protected $ext;

	/** @var  */

	/**
	 * Constructor.
	 *
	 * @param \phpbb\db\driver\driver_interface	$db			Database object
	 * @param \phpbb\language\language			$language	Language object
	 * @param \phpbb\request\request			$request	Request object
	 * @param \phpbb\template\template			$template	Template object
	 * @param \phpbb\user						$user		User object
	 */
	public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\language\language $language, \phpbb\request\request $request, \phpbb\template\template $template, \phpbb\user $user, \phpbb\pagination $page, \phpbb\config\config $conf)
	{
		$this->db			= $db;
		$this->language		= $language;
		$this->request		= $request;
		$this->template		= $template;
		$this->user			= $user;
		$this->pagination	= $page;
		$this->config		= $conf;

		global $phpbb_root_path, $phpEx;
		$this->root	= $phpbb_root_path;
		$this->ext	= $phpEx;
	}

	public function display_messages()
	{
		// Create a form key for preventing CSRF attacks
		add_form_key('crosstimecafe_pmsearch_ucp');

		// Is the form being submitted to us?
		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key('crosstimecafe_pmsearch_ucp'))
			{
				trigger_error($this->language->lang('FORM_INVALID'));
			}
		}

		// Todo add try/catch
		// Todo harden input
		// Todo get minimum characters
		// Todo need to strip bbcode from indexer
		// Todo max return limits
		// Todo pagination jump box not working
		// Todo author and keyword not working together?
		$keywords = $this->request->variable('keywords', '', true);
		$keywords = str_replace('&quot;','"',$keywords);
		$author = $this->request->variable('author', '', true);
		if ($keywords == '' and $author == '')
		{
			trigger_error($this->language->lang('UCP_PMSEARCH_MISSING'));
		}
		// Todo enable searching for multiple authors
		$author_id_ary = [];
		if ($author)
		{
			$sql_where = (strpos($author, '*') !== false) ? ' username_clean ' . $this->db->sql_like_expression(str_replace('*', $this->db->get_any_char(), utf8_clean_string($author))) : " username_clean = '" . $this->db->sql_escape(utf8_clean_string($author)) . "'";
			$sql = 'SELECT user_id FROM ' . USERS_TABLE . " WHERE $sql_where";
			$result = $this->db->sql_query_limit($sql, 100);

			while ($row = $this->db->sql_fetchrow($result))
			{
				$author_id_ary[] = (int) $row['user_id'];
			}
			if (!count($author_id_ary))
			{
				trigger_error('NO_SEARCH_RESULTS');
			}
			$this->db->sql_freeresult($result);
		}
		$folders = $this->request->variable('fid', [0]);
		$folder_ids = '';
		if($folders)
		{
			$fid = [];
			foreach ($folders as $f)
			{
				$fid[] = '"' . $this->user->id() . '_' . $f . '"';
			}
			$folder_ids = implode('|', $fid);
		}
		$search_field = $this->request->variable('sf', 'all');
		switch ($search_field)
		{
			case 'msgonly':
				$sf = 'message_text';
			break;
			case 'titleonly':
				$sf = 'message_subject';
			break;
			case 'all':
			default:
				$sf = ['message_text','message_subject'];
		}

		// Todo make sorting work with author and subject
		//$order = $this->request->variable('sk','message_time');
		$order = 'message_time';
		$direction = $this->request->variable('sd','desc');

		$start = $this->request->variable('start',0);
		// Todo get config for per page post



		// Todo add author search

		$conn = new Connection();
		// Todo get host/port from config
		$conn->setParams(['host' => 'localhost', 'port' => 9306]);

		$search = new SphinxQL($conn);
		$search->select('id');
		$search->from('pm');
		$search->where('user_id', $this->user->id());
		if ($folder_ids)
		{
			$search->match('folder_id',$folder_ids,true);
		}
		if ($author_id_ary)
		{
			$search->where('author_id', 'IN', $author_id_ary);
		}
		if($keywords)
		{
			$search->match($sf, $keywords, true);
		}
		$search->orderBy($order,$direction);
		$search->limit($start,$this->config['posts_per_page']);
		try
		{
			$result = $search->execute();
		}
		catch (exception $e)
		{
			// Todo actually catch the error and do something
		}
		$rows = $result->fetchAllAssoc();
		if (count($rows))
		{
			$id_ary = [];
			$sql_where = '';
			foreach ($rows as $row)
			{
				$id_ary[] = $row['id'];

			}
			$sql_where .= $this->db->sql_in_set('p.msg_id', $id_ary);
			$sql_array = [
				'SELECT'    => 'p.msg_id, p.author_id, p.message_time, p.message_subject, p.message_text, p.bbcode_uid, p.bbcode_bitfield, u.username, u.username_clean, u.user_colour',
				'FROM'      => [
					PRIVMSGS_TABLE => 'p',
				],
				'LEFT_JOIN' => [
					[
						'FROM' => [USERS_TABLE => 'u'],
						'ON'   => 'p.author_id = u.user_id',
					],
				],
				'WHERE'     => $sql_where,
				'ORDER_BY'  => $order . ' ' . $direction,
			];
			$sql = $this->db->sql_build_query('SELECT', $sql_array);
			$result = $this->db->sql_query($sql);

			while ($row = $this->db->sql_fetchrow($result))
			{
				// Do we even need all this?
				$row['post_subject'] = censor_text($row['post_subject']);
				$row['message_text'] = censor_text($row['message_text']);
				if ($row['bbcode_uid'])
				{
					$row['message_text'] = str_replace('[*:' . $row['bbcode_uid'] . ']', '&sdot;&nbsp;', $row['message_text']);
				}
				$parse_flags = ($row['bbcode_bitfield'] ? OPTION_FLAG_BBCODE : 0) | OPTION_FLAG_SMILIES;
				$row['message_text'] = generate_text_for_display($row['message_text'], $row['bbcode_uid'], $row['bbcode_bitfield'], $parse_flags, false);


				$this->template->assign_block_vars('searchresults', [
					'POST_AUTHOR_FULL'   => get_username_string('full', $row['author_id'], $row['username'], $row['user_colour']),
					'POST_AUTHOR_COLOUR' => get_username_string('colour', $row['author_id'], $row['username'], $row['user_colour']),
					'POST_AUTHOR'        => get_username_string('username', $row['author_id'], $row['username'], $row['user_colour']),
					'U_POST_AUTHOR'      => get_username_string('profile', $row['author_id'], $row['username'], $row['user_colour']),

					'POST_SUBJECT' => $row['message_subject'],
					'POST_DATE'    => (!empty($row['message_time'])) ? $this->user->format_date($row['message_time']) : '',
					'MESSAGE'      => $row['message_text'],

					'POST_ID'     => $row['id'], //http://127.0.0.1:8880/ucp.php?i=pm&mode=view&f=0&p=26812
					'U_VIEW_POST' => append_sid("{$this->root}ucp.$this->ext", 'i=pm&mode=view&p=' . $row['msg_id']),
				]);
			}
			$this->db->sql_freeresult($result);


			$meta = new SphinxQL($conn);
			$meta->query("SHOW META LIKE 'total_found'");
			$result = $meta->execute();
			$meta_data = $result->fetchAllNum();
			$total_found = $meta_data[0][1];

			$start = $this->pagination->validate_start($start, $this->config['posts_per_page'], $total_found);
			$url_parms  = $keywords ? '&keywords=' . urlencode($keywords) : '';
			$url_parms .= $author ? '&author='.urlencode($author): '';
			$url_parms .= $folders ? '&fid[]='.implode('&fid[]=', $folders): '';
			$url_parms .= $sf ? '&sf='.$search_field : '';
			// Sort by is broken
			//$url_parms .= $order ? '&sk='.$order : '';
			$url_parms .= $direction ? '&sd='.$direction : '';

			$this->pagination->generate_template_pagination($this->u_action.$url_parms, 'pagination', 'start', $total_found, $this->config['posts_per_page'], $start);

		}

		$this->template->assign_var('S_PRIVMSGS', true);
		// Assigns folder template var
		get_folder($this->user->id());

		$this->template->assign_vars([
			'SEARCH_MATCHES'	=> $this->language->lang('FOUND_SEARCH_MATCHES', $total_found),
			'SEARCH_WORDS'		=> $keywords,
			'U_SEARCH_TOPIC'	=> $this->u_action,
			//'SEARCHED_QUERY'	=> $keywords, // Todo maybe later
			//'SEARCHED_AUTHOR'	=> $author, // Todo maybe later
			//'SEARCHEC_FOLDER'	=> $folders // Todo maybe later
		]);

	}
	public function display_options()
	{
		// Create a form key for preventing CSRF attacks
		add_form_key('crosstimecafe_pmsearch_ucp');

		// Is the form being submitted to us?
		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key('crosstimecafe_pmsearch_ucp'))
			{
				trigger_error($this->language->lang('FORM_INVALID'));
			}
		}

		$this->template->assign_block_vars('folder_select', ['id'=>0,'name'=>'Inbox']);
		$this->template->assign_block_vars('folder_select', ['id'=>-1,'name'=>'Sent']);
		$this->template->assign_block_vars('folder_select', ['id'=>-2,'name'=>'Outbox']);

		$folder = $this->find_folders();
		foreach ($folder as $f)
		{
			$this->template->assign_block_vars('folder_select', ['id'=>$f['folder_id'],'name'=>$f['folder_name']]);
		}


		$sort = '<select id="sk" name="sk" style="background-color: #fff;">';
		//$sort .= '<option value="author_id">'.$this->language->lang('SORT_AUTHOR').'</option>';
		// This is the only one that works
		$sort .= '<option value="message_time" selected>'.$this->language->lang('UCP_PMSEARCH_TIME').'</option>';
		//$sort .= '<option value="f">'.$this->language->lang('UCP_PMSEARCH_FOLDER').'</option>';
		//$sort .= '<option value="subject">'.$this->language->lang('UCP_PMSEARCH_SUBJECT').'</option>';
		$sort .= '</select>';

		$this->template->assign_var('S_PRIVMSGS', true);

		// Assigns folder template var
		get_folder($this->user->id());
		$this->template->assign_vars([
			//'S_ERROR'		=> $s_errors,
			//'ERROR_MSG'		=> $s_errors ? implode('<br />', $errors) : '',
			'U_UCP_ACTION'	=> $this->u_action,
			'S_SELECT_SORT_KEY'	=> $sort,
		]);
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	private function find_folders()
	{
		$sql = 'SELECT folder_id, folder_name
			FROM ' . PRIVMSGS_FOLDER_TABLE . '
			WHERE user_id = ' .$this->user->id();
		return $this->db->sql_query($sql);
	}
}
