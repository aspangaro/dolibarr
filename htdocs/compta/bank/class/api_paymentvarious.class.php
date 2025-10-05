<?php
/* Copyright (C) 2015		Alexandre Spangaro	<alexandre@inovea-conseil.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';

/**
 * API class for various payments
 *
 * @property DoliDB $db
 * @access protected
 * @class DolibarrApiAccess {@requires user,external}
 */
class PaymentVariousApi extends DolibarrApi
{
	/**
	 * array $FIELDS Mandatory fields, checked when creating an object
	 */
	public static $FIELDS = array(
		'datep',
		'label',
		'amount',
		'fk_account',
		'type_payment',
		'accountancy_code',
		'sens'
	);

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Get the list of various payments
	 *
	 * @since	23.0.0	Initial implementation
	 *
	 * @param string	$sortfield	Sort field
	 * @param string	$sortorder	Sort order
	 * @param int		$limit		Limit for list
	 * @param int		$page		Page number
	 * @param string    $sqlfilters Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'SO-%') and (t.date_creation:<:'20160101')"
	 * @param string    $properties	Restrict the data returned to these properties. Ignored if empty. Comma separated list of properties names
	 * @return  array               Array of User objects
	 * @phan-return Object[]
	 * @phpstan-return Object[]
	 *
	 * @throws RestException
	 */
	public function index($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '', $properties = '')
	{
		$list = array();

		if (!DolibarrApiAccess::$user->hasRight('banque', 'lire')) {
			throw new RestException(403);
		}
		$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."payment_various AS t";
		$sql .= ' WHERE t.entity IN (' . getEntity('paymentvarious') . ')';
		// Add sql filters
		if ($sqlfilters) {
			$errormessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errormessage);
			if ($errormessage) {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$offset = $limit * $page;

			$sql .= $this->db->plimit($limit + 1, $offset);
		}

		dol_syslog("API Rest request - list payment_various");
		$result = $this->db->query($sql);

		if ($result) {
			$num = $this->db->num_rows($result);
			$min = min($num, ($limit <= 0 ? $num : $limit));
			for ($i = 0; $i < $min; $i++) {
				$obj = $this->db->fetch_object($result);
				$pv = new PaymentVarious($this->db);
				if ($pv->fetch($obj->rowid) > 0) {
					$list[] = $this->_filterObjectProperties($this->_cleanObjectDatas($pv), $properties);
				}
			}
		} else {
			throw new RestException(503, 'Error when retrieving list of various payments: ' . $this->db->lasterror());
		}
		return $list;
	}

	/**
	 * Get a various payment by ID
	 *
	 * @since	23.0.0    Initial implementation
	 *
	 * @param	int			$id				ID of various payment
	 * @return  Object                      Object with cleaned properties
	 *
	 * @url GET /paymentvarious/{id}
	 *
	 * @throws RestException
	 */
	public function get($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('banque', 'lire')) {
			throw new RestException(403);
		}

		$pv = new PaymentVarious($this->db);
		if ($pv->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Various payment not found');
		}
		return $this->_cleanObjectDatas($pv);
	}

	/**
	 * Create a various payment object
	 *
	 * @since	23.0.0    Initial implementation
	 *
	 * @param	array $request_data		Request data
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	int						ID of various payment
	 *
	 * @url POST /paymentvarious
	 *
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('banque', 'modifier')) {
			throw new RestException(403);
		}
		// Check mandatory fields
		$this->_validate($request_data);

		$pv = new PaymentVarious($this->db);
		foreach ($request_data as $field => $value) {
			$pv->$field = $this->_checkValForAPI($field, $value, $pv);
		}

		if ($pv->create(DolibarrApiAccess::$user) < 0) {
			throw new RestException(500, 'Error creating various payment', array_merge(array($pv->error), $pv->errors));
		}
		return $pv->id;
	}

	/**
	 * Delete a various payment
	 *
	 * @since	23.0.0    Initial implementation
	 *
	 * @param int    $id    ID of various payment
	 * @return array
	 * @phan-return array{success:array{code:int,message:string}}
	 * @phpstan-return array{success:array{code:int,message:string}}
	 *
	 * @url DELETE /paymentvarious/{id}
	 *
	 */
	public function delete($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('banque', 'modifier')) {
			throw new RestException(403);
		}
		$pv = new PaymentVarious($this->db);
		if ($pv->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Various payment not found');
		}
		if ($pv->delete(DolibarrApiAccess::$user) < 0) {
			throw new RestException(500, $pv->error ?: 'Delete failed');
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Various payment deleted'
			)
		);
	}

	/**
	 * Validate fields before creating an object
	 *
	 * @param ?array<string,string> $data   Data to validate
	 * @return array<string,string>
	 *
	 * @throws RestException
	 */
	private function _validate($data)
	{
		if ($data === null) {
			$data = array();
		}
		$validated = array();

		foreach (PaymentVarious::$FIELDS as $field => $type) {
			if (!array_key_exists($field, $data)) {
				throw new RestException(400, "$field field missing");
			}
			$validated[$field] = $data[$field];
		}

		return $validated;
	}
}
