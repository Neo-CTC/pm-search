<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2021, NeoDev
 * @license GNU General Public License, version 2 (GPL-2.0)
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
// in a url you again do not need to specify an order e.g., 'Click %sHERE%s' is fine
//
// Some characters you may want to copy&paste:
// ’ » “ ” …
//

$lang = array_merge($lang, [
	'ACP_PMSEARCH_TITLE'	=> 'PM Search',
	'ACP_PMSEARCH'			=> 'Settings',
	'ACP_PMSEARCH_REINDEX'	=> 'Rebuild search index',

	'ACP_PMSEARCH_PROGRAM_ERROR' 	=> 'Error with search backend.',
	'ACP_PMSEARCH_MISSING_TABLE' 	=> 'Private message search table missing and could not be created.',
	'ACP_PMSEARCH_CONNECTION_ERROR'	=> 'Could not connect to search.',
	'ACP_PMSEARCH_NO_INDEX'			=> 'Index is empty. Please rebuild search index.',

	'ACP_PMSEARCH_VERSION'			=> 'Search version',
	'ACP_PMSEARCH_TOTAL_MESSAGES'	=> 'Total messages indexed',
	'ACP_PMSEARCH_INDEX_BYTES'		=> 'Disk space used',
	'ACP_PMSEARCH_RAM_BYTES'		=> 'RAM used',

	'ACP_PMSEARCH_DONE'				=> 'Reindexing complete',
	'ACP_PMSEARCH_HOSTNAME'			=> 'Host address',
	'ACP_PMSEARCH_PORT'				=> 'Host port',

	'ACP_PMSEARCH_INDEX_COMMANDS'	=>	'Index maintenance'
]);
