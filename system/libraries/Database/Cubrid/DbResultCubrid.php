<?php
/**
 * Xylophone
 *
 * An open source HMVC application development framework for PHP 5.3 or newer
 * Derived from CodeIgniter, Copyright (c) 2008 - 2013, EllisLab, Inc. (http://ellislab.com/)
 *
 * NOTICE OF LICENSE
 *
 * Licensed under the Open Software License version 3.0
 *
 * This source file is subject to the Open Software License (OSL 3.0) that is
 * bundled with this package in the files license.txt / license.rst. It is
 * also available through the world wide web at this URL:
 * http://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to obtain it
 * through the world wide web, please send an email to licensing@xylophone.io
 * so we can send you a copy immediately.
 *
 * @package     Xylophone
 * @author      Xylophone Dev Team, EllisLab Dev Team
 * @copyright   Copyright (c) 2014, Xylophone Team (http://xylophone.io/)
 * @license     http://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * @link        http://xylophone.io
 * @since       Version 1.0
 * @filesource
 */
namespace Xylophone\libraries\Database\Cubrid;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CUBRID Database Result Class
 *
 * @package     Xylophone
 * @subpackage  libraries/Database/Cubrid
 * @link        http://xylophone.io/user_guide/database/
 */
class DbResultCubrid extends \Xylophone\libraries\Database\DbResult
{
    /**
     * Number of rows in the result set
     *
     * @return  int     Number of rows
     */
    public function numRows()
    {
        return is_int($this->num_rows) ? $this->num_rows : ($this->num_rows = @cubrid_num_rows($this->result_id));
    }

    /**
     * Number of fields in the result set
     *
     * @return  int     Number of fields
     */
    public function numFields()
    {
        return @cubrid_num_fields($this->result_id);
    }

    /**
     * Fetch Field Names
     *
     * Generates an array of column names
     *
     * @return  array   Field listing
     */
    public function listFields()
    {
        return cubrid_column_names($this->result_id);
    }

    /**
     * Field data
     *
     * Generates an array of objects containing field meta-data
     *
     * @return  array   Field meta-data
     */
    public function fieldData()
    {
        $retval = array();

        for ($i = 0, $c = $this->numFields(); $i < $c; $i++) {
            $retval[$i] = new stdClass();
            $retval[$i]->name = cubrid_field_name($this->result_id, $i);
            $retval[$i]->type = cubrid_field_type($this->result_id, $i);
            $retval[$i]->max_length = cubrid_field_len($this->result_id, $i);
            $retval[$i]->primary_key = (int)(strpos(cubrid_field_flags($this->result_id, $i), 'primary_key') !== false);
        }

        return $retval;
    }

    /**
     * Free the result
     *
     * @return  void
     */
    public function freeResult()
    {
        if (is_resource($this->result_id) ||
                (get_resource_type($this->result_id) === 'Unknown' && preg_match('/Resource id #/', strval($this->result_id)))) {
            cubrid_close_request($this->result_id);
            $this->result_id = false;
        }
    }

    /**
     * Data Seek
     *
     * Moves the internal pointer to the desired offset. We call
     * this internally before fetching results to make sure the
     * result set starts at zero.
     *
     * @param   int     $n  Seek offset
     * @return  bool    TRUE on success, otherwise FALSE
     */
    public function dataSeek($n = 0)
    {
        return cubrid_data_seek($this->result_id, $n);
    }

    /**
     * Result - associative array
     *
     * Returns the result set as an array
     *
     * @return  array   Result array
     */
    protected function fetchAssoc()
    {
        return cubrid_fetch_assoc($this->result_id);
    }

    /**
     * Result - object
     *
     * Returns the result set as an object
     *
     * @param   string  $class_name Result object class name
     * @return  object  Result object
     */
    protected function fetchObject($class_name = 'stdClass')
    {
        return cubrid_fetch_object($this->result_id, $class_name);
    }
}

