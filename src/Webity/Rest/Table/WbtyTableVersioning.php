<?php
namespace Webity\Rest\Table;

use Webity\Rest\Application\Api;
use Joomla\Registry\Registry;
use Joomla\Date\Date;
use Joomla\Utilities\ArrayHelper;

abstract class WbtyTableVersioning extends WbtyTable
{
	protected $_ignoreColumns = array(
		'checked_out', 'checked_out_time',
		'created_by', 'created_time',
		'modified_by', 'modified_time',
		'base_id'
	);

	public function setIgnoreColumns(array $array) {
		$this->_ignoreColumns = $array;
	}

	// special version of the store function to save the current record as a new row if a current record exists
	public function store($updateNulls = false)
	{
		// Initialise variables.
		$k = $this->_tbl_key;
		if (!empty($this->asset_id))
		{
			$currentAssetId = $this->asset_id;
		}

		// The asset id field is managed privately by this class.
		if ($this->_trackAssets)
		{
			unset($this->asset_id);
		}

		// If a primary key exists update the object, otherwise insert it.
		if ($this->$k)
		{
			// load the currect row from the database
			$query = $this->_db->getQuery(true);
			$query->select('*');
			$query->from($this->_tbl);
			$query->where($this->_tbl_key . '=' . $this->$k);
			$this->_db->setQuery($query);
			$old_row = $this->_db->loadObject();

			$perfect_match = true;
			foreach ($old_row as $key=>$val) {
				if ($this->$key != $val && !in_array($key, $this->_ignoreColumns)) {
					$perfect_match = false;
					echo "{$this->_tbl}: $key has vals {$this->$key} and $val\n";
					break;
				}
			}

			if (!$perfect_match) {
				// unset the primary key
				$key = $this->_tbl_key;
				unset($old_row->$key);
				// also unset checked out and checked out time, if exist
				if (isset($old_row->checked_out)) { unset($old_row->checked_out); }
				if (isset($old_row->checked_out_time)) { unset($old_row->checked_out_time); }

				// set the base_id value, which is necessary to link back to the main row
				$old_row->base_id = $this->$k;

				// store the new row
				$check = $this->_db->insertObject($this->_tbl, $old_row);

				// if we successfully made the new row, then update the old (i.e. base row) to the new values
				if ($check) {
					$stored = $this->_db->updateObject($this->_tbl, $this, $this->_tbl_key, $updateNulls);
				}
			} else {
				// skip storing anything. It is already set to everything.
				// also no need to update the modified time since it wasn't modified
				$stored = true;
			}
		}
		else
		{
			$stored = $this->_db->insertObject($this->_tbl, $this, $this->_tbl_key);
		}

		// If the store failed return false.
		if (!$stored)
		{
			$e = new Exception(sprintf('JLIB_DATABASE_ERROR_STORE_FAILED', get_class($this), $this->_db->getErrorMsg()));
			$this->setError($e);
			return false;
		}

		// If the table is not set to track assets return true.
		if (!$this->_trackAssets)
		{
			return true;
		}

		if ($this->_locked)
		{
			$this->_unlock();
		}

		return true;
	}


	public function publish($pks = null, $state = 1, $userId = 0)
    {
        // Initialise variables.
        $k = $this->_tbl_key;

        // Sanitize input.
        ArrayHelper::toInteger($pks);
        $userId = (int) $userId;
        $state  = (int) $state;

        // If there are no primary keys set check to see if the instance key is set.
        if (empty($pks))
        {
            if ($this->$k) {
                $pks = array($this->$k);
            }
            // Nothing to set publishing state on, return false.
            else {
                $this->setError('JLIB_DATABASE_ERROR_NO_ROWS_SELECTED');
                return false;
            }
        }

        // Build the WHERE clause for the primary keys.
        $where = $k.'='.implode(' OR '.$k.'=', $pks);

        // Determine if there is checkin support for the table.
        if (!property_exists($this, 'checked_out') || !property_exists($this, 'checked_out_time')) {
            $checkin = '';
        }
        else {
            $checkin = ' AND (checked_out = 0 OR checked_out = '.(int) $userId.')';
        }

        // Update the publishing state for rows with the given primary keys.
        foreach ($pks as $pk) {
        	$this->load($pk);
        	if ($this->checked_out != 0 && $this->checked_out != $userId) {
        		continue;
        	}
        	$this->state = $state;
        	$this->store();
        }

        // If checkin is supported and all rows were adjusted, check them in.
        if ($checkin && (count($pks) == $this->_db->getAffectedRows()))
        {
            // Checkin the rows.
            foreach($pks as $pk)
            {
                $this->checkin($pk);
            }
        }

        // If the JTable instance value is in the list of primary keys that were set, set the instance.
        if (in_array($this->$k, $pks)) {
            $this->state = $state;
        }

        $this->setError('');
        return true;
    }

}
