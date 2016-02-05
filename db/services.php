<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *  Extra User Lookup Webservice Function Declarations
 *
 * @package    local
 * @subpackage extrauserlookups
 * @copyright  2016 Owen Barritt, Wine & Spirit Education Trust
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 
 defined('MOODLE_INTERNAL') || die;
 
 $functions = array(
        'local_extrauserlookups_get_users' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.                                                                                
                'classname'   => 'local_extrauserlookups_external', // create this class in local/PLUGINNAME/externallib.php
                'methodname'  => 'get_users', // implement this function into the above class
                'classpath'   => 'local/extrauserlookups/externallib.php',
                'description' => 'Amended user lookup functions',
                'type'        => 'read', // the value is 'write' if your function does any database change, otherwise it is 'read'.
                'capabilities'  => 'moodle/user:viewdetails, moodle/user:viewhiddendetails, moodle/course:useremail, moodle/user:update',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
        ),        
        'local_extrauserlookups_create_users' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.                                                                                
                'classname'   => 'local_extrauserlookups_external', // create this class in local/PLUGINNAME/externallib.php
                'methodname'  => 'create_users', // implement this function into the above class
                'classpath'   => 'local/extrauserlookups/externallib.php',
                'description' => 'Amended user create function',
                'type'        => 'write', // the value is 'write' if your function does any database change, otherwise it is 'read'.
                'capabilities'  => 'moodle/user:create',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
        )
);