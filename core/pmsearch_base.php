<?php
/**
 *
 * PM Search. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2020, NeoDev
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\pmsearch\core;

interface pmsearch_base
{
	/**
	 * Check if search backend is ready for use.
	 *
	 * @return bool
	 */
	public function ready();

	/**
	 * Status of search backend
	 *
	 * @return array
	 */
	public function status();

	/**
	 * Create search index
	 *
	 * @return bool
	 */
	public function create_index();

	/**
	 * Drop and recreate index
	 *
	 * @return bool
	 */
	public function reindex();

	/**
	 * Delete index
	 *
	 * @return bool
	 */
	public function delete_index();

	/**
	 * Reindex one or more messages
	 *
	 * @param $id int|int[]
	 *
	 * @return bool
	 */
	public function update_entry($id);

	/**
	 * Search index for matching terms
	 * Returns array of matching message ids
	 *
	 * @param int      $uid      User ID
	 * @param string[] $indexes  Indexes to search
	 * @param string   $keywords Terms to match
	 * @param int[]    $from     Search for authors
	 * @param int[]    $to       Search for recipients
	 * @param string[] $folders
	 * @param string   $order
	 * @param string   $direction
	 * @param int      $offset
	 *
	 * @return false|array
	 */
	public function search(int $uid, array $indexes, string $keywords, array $from, array $to, array $folders, string $order, string $direction, int $offset);
}
