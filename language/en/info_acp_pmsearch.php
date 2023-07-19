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
// in a url you again do not need to specify an order e.g., 'Click %sHERE%s' is fine
//
// Some characters you may want to copy&paste:
// ’ » “ ” …
//

$lang = array_merge($lang, [
	// ACP module
	'ACP_PMSEARCH_TITLE'             => 'PM Search',
	'ACP_PMSEARCH_MODE_SETTINGS'     => 'Settings',
	'ACP_PMSEARCH_MODE_STATUS'       => 'Status',

	// Index status
	'ACP_PMSEARCH_STATUS'            => 'Status',
	'ACP_PMSEARCH_READY'             => 'Ready for use',
	'ACP_PMSEARCH_PROCESSING'        => 'Indexing in process',
	'ACP_PMSEARCH_NO_INDEX'          => 'Index missing',
	'ACP_PMSEARCH_NO_INDEX_CREATE'   => 'Index missing, create index inside configuration file',
	'ACP_PMSEARCH_INDEX_EMPTY'       => 'Ready to index',
	'ACP_PMSEARCH_INCOMPLETE'        => 'Indexing incomplete',

	// MySQL index status
	'ACP_PMSEARCH_MYSQL_IN_PROGRESS' => 'MySQL is working in the background. Check status in a few minutes',

	// Sphinx index status
	'ACP_PMSEARCH_VERSION'           => 'Version',
	'ACP_PMSEARCH_TOTAL_MESSAGES'    => 'Total messages',
	'ACP_PMSEARCH_INDEX_BYTES'       => 'Disk usage',
	'ACP_PMSEARCH_RAM_BYTES'         => 'RAM usage',

	// Maintenance commands
	'ACP_PMSEARCH_INDEX_COMMANDS'    => 'index maintenance',
	'ACP_PMSEARCH_REINDEX'           => 'Rebuild search index',
	'ACP_PMSEARCH_ACTION_DROP'       => 'Delete search index',
	'ACP_PMSEARCH_CONFIG'            => 'Config entry',
	'ACP_PMSEARCH_CONFIG_EXPLAIN'    => 'Add this entry to your sphinx.config',

	// Maintenance responses
	'ACP_PMSEARCH_INDEX_DONE'        => 'Indexing complete',
	'ACP_PMSEARCH_DROP_DONE'         => 'Deletion complete',
	'ACP_PMSEARCH_ACTION_NONE'       => 'Unknown action',

	// Error messages
	'ACP_PMSEARCH_ERR_CONN'          => 'Search database connection error',
	'ACP_PMSEARCH_ERR_DB'            => 'Error while executing database query',
	'ACP_PMSEARCH_ERR_UNKNOWN'       => 'Unknown error in search database',
	'ACP_PMSEARCH_MISSING_TABLE'     => 'Search index missing',
	'ACP_UNSUPPORTED_ENGINE'         => 'Unsupported search engine',

	// Sphinx error messages
	'ACP_PMSEARCH_UNKNOWN_VERSION'   => 'Unknown or unsupported Sphinx/Manticore version',
	'ACP_PMSEARCH_UNSUPPORTED_INDEX' => 'Index type is unsupported. Must be of type RT.',

	// MySQL error messages
	'ACP_PMSEARCH_INDEX_CREATE_ERR_MYSQL' => 'Unknown error when creating search indexes for MySQL'
]);
