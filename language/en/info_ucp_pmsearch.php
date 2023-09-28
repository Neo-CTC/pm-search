<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2021, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

// DEVELOPERS PLEASE NOTE
//
// All language files should use UTF-8 as their encoding and the files must not contain a BOM.
//
// Placeholders can now contain order information, e.g. instead of
// 'Page %s of %s' you can (and should) write 'Page %1$s of %2$s', this allows
// translators to re-order the output of data while ensuring it remains correct
//
// You do not need this where single placeholders are used, e.g. 'Message %d' is fine
// equally where a string contains only two placeholders which are used to wrap text
// in an url you again do not need to specify an order e.g., 'Click %sHERE%s' is fine
//
// Some characters you may want to copy&paste:
// ’ » “ ” …
//

$lang = array_merge($lang, [
	'UCP_PMSEARCH'       => 'Search',
	'UCP_PMSEARCH_TITLE' => 'Private Message Search',

	'UCP_PMSEARCH_IN_FOLDER'      => 'Search in folders',
	'UCP_PMSEARCH_FOLDER_EXPLAIN' => 'Select the folder or folders you wish to search in.',

	'UCP_PMSEARCH_FROM'      => 'Search received from',
	'UCP_PMSEARCH_TO'        => 'Search sent to',
	'UCP_PMSEARCH_SEPARATOR' => 'Use , to separate usernames',

	'UCP_PMSEARCH_SEARCH_BOTH'    => 'Subject and text',
	'UCP_PMSEARCH_SEARCH_SUBJECT' => 'Message subject only',
	'UCP_PMSEARCH_SEARCH_TEXT'    => 'Message text only',

	'UCP_PMSEARCH_MESSAGE' => 'Message',
	'UCP_PMSEARCH_SUBJECT' => 'Subject',
	'UCP_PMSEARCH_FOLDER'  => 'Folders',
	'UCP_PMSEARCH_TIME'    => 'Time',

	'UCP_PMSEARCH_MESSAGES_MOVED'  => 'Messages moved',
	'UCP_PMSEARCH_MESSAGES_MARKED' => 'Messages marked',

	'UCP_PMSEARCH_NOT_BOTH' => "Can not search both 'from' and 'to' fields in the same search ",
	'UCP_PMSEARCH_RETURN'   => 'Return to search',
	'UCP_PMSEARCH_FOUND'    => 'Search found ',
	'UCP_PMSEARCH_JUMP'     => 'Jump to message',

	'UCP_PMSEARCH_ERR_CONN'    => 'Problem connecting to search engine',
	'UCP_PMSEARCH_ERR_DB'      => 'Problem processing search query',
	'UCP_PMSEARCH_ERR_UNKNOWN' => 'Unknown error in search',
	'UCP_PMSEARCH_ERR_GENERIC' => 'Search is not available at this time',
	'UCP_PMSEARCH_ERR_QUERY'   => 'Invalid search query',
]);
