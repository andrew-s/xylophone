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
namespace Xylophone\libraries\DbForge\Pdo\subdrivers;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PDO MySQL Database Forge Class
 *
 * @package     Xylophone
 * @subpackage  libraries/DbForge/Pdo/subdrivers
 * @link        http://xylophone.io/user_guide/database/
 */
class DbForgePdoMysql extends \Xylophone\libraries\DbForge\Pdo\DbForgePdo
{
    /** @var    string  CREATE DATABASE statement */
    protected $db_create_database = 'CREATE DATABASE %s CHARACTER SET %s COLLATE %s';

    /** @var    string  CREATE TABLE IF statement */
    protected $db_create_table_if = 'CREATE TABLE IF NOT EXISTS';

    /** @var    bool    Whether table keys are created from within the CREATE TABLE statement */
    protected $db_create_table_keys = true;

    /** @var    string  DROP TABLE IF EXISTS statement */
    protected $db_drop_table_if = 'DROP TABLE IF EXISTS';

    /** @var    array   UNSIGNED support */
    protected $db_unsigned = array(
        'TINYINT',
        'SMALLINT',
        'MEDIUMINT',
        'INT',
        'INTEGER',
        'BIGINT',
        'REAL',
        'DOUBLE',
        'DOUBLE PRECISION',
        'FLOAT',
        'DECIMAL',
        'NUMERIC'
    );

    /** @var    string  NULL value representation in CREATE/ALTER TABLE statements */
    protected $db_null = 'NULL';

    /**
     * Constructor
     *
     * @param   array   $config     Config params
     * @param   array   $extras     Extra config params
     * @return  void
     */
    public function __construct($config, $extras)
    {
        parent::__construct($config, $extras);

        $this->db_create_table .= ' DEFAULT CHARSET '.$this->db->char_set.' COLLATE '.$this->db->dbcollat;
    }

    /**
     * ALTER TABLE Query
     *
     * @param   string  $alter_type ALTER type
     * @param   string  $table      Table name
     * @param   mixed   $field      Column definition
     * @return  mixed   ALTER string or array of strings
     */
    protected function alterTableQuery($alter_type, $table, $field)
    {
        if ($alter_type === 'DROP') {
            return parent::alterTableQuery($alter_type, $table, $field);
        }

        $sql = 'ALTER TABLE '.$this->db->escapeIdentifiers($table);
        for ($i = 0, $c = count($field); $i < $c; $i++) {
            if ($field[$i]['_literal'] !== false) {
                $field[$i] = ($alter_type === 'ADD') ? "\n\tADD ".$field[$i]['_literal'] :
                    "\n\tMODIFY ".$field[$i]['_literal'];
            }
            else {
                if ($alter_type === 'ADD') {
                    $field[$i]['_literal'] = "\n\tADD ";
                }
                else {
                    $field[$i]['_literal'] = empty($field[$i]['new_name']) ? "\n\tMODIFY " : "\n\tCHANGE ";
                }

                $field[$i] = $field[$i]['_literal'].$this->processColumn($field[$i]);
            }
        }

        return array($sql.implode(',', $field));
    }

    /**
     * Process column
     *
     * @param   array   $field  Field definition
     * @return  string  Column definition string
     */
    protected function processColumn($field)
    {
        $extra_clause = isset($field['after']) ? ' AFTER '.$this->db->escapeIdentifiers($field['after']) : '';

        if (empty($extra_clause) && isset($field['first']) && $field['first'] === true) {
            $extra_clause = ' FIRST';
        }

        return $this->db->escapeIdentifiers($field['name']).
            (empty($field['new_name']) ? '' : ' '.$this->db->escapeIdentifiers($field['new_name'])).
            ' '.$field['type'].$field['length'].$field['unsigned'].$field['null'].$field['default'].
            $field['auto_increment'].$field['unique'].$extra_clause;
    }

    /**
     * Process indexes
     *
     * @param   string  $table  Table name
     * @return  array   INDEX clauses
     */
    protected function processIndexes($table)
    {
        $sql = '';

        for ($i = 0, $c = count($this->keys); $i < $c; $i++) {
            if (is_array($this->keys[$i])) {
                for ($i2 = 0, $c2 = count($this->keys[$i]); $i2 < $c2; $i2++) {
                    if (!isset($this->fields[$this->keys[$i][$i2]])) {
                        unset($this->keys[$i][$i2]);
                        continue;
                    }
                }
            }
            elseif (!isset($this->fields[$this->keys[$i]])) {
                unset($this->keys[$i]);
                continue;
            }

            is_array($this->keys[$i]) || $this->keys[$i] = array($this->keys[$i]);

            $sql .= ",\n\tKEY ".$this->db->escapeIdentifiers(implode('_', $this->keys[$i])).
                ' ('.implode(', ', $this->db->escapeIdentifiers($this->keys[$i])).')';
        }

        $this->keys = array();

        return $sql;
    }
}

