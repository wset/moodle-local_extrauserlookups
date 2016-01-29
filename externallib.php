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
 *  Extra User Lookup Webservice Functions
 *
 * @package    local
 * @subpackage extrauserlookups
 * @copyright  2016 Owen Barritt, Wine & Spirit Education Trust
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 
 require_once("$CFG->dirroot/user/externallib.php");
 require_once("$CFG->dirroot/user/profile/lib.php");
 
 class local_extrauserlookups_external extends core_user_external {
     /**
     * Returns description of get_users() parameters.
     *
     * @return external_function_parameters
     * @since Moodle 2.5
     */
    public static function get_users_parameters() {
        return new external_function_parameters(
            array(
                'criteria' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'key' => new external_value(PARAM_TEXT, 'the user column to search, expected keys (value format) are:
                                "id" (int) matching user id,
                                "lastname" (string) user last name (Note: you can use % for searching but it may be considerably slower!),
                                "firstname" (string) user first name (Note: you can use % for searching but it may be considerably slower!),
                                "idnumber" (string) matching user idnumber,
                                "username" (string) matching user username (Note: you can use % for searching but it may be considerably slower!),
                                "email" (string) user email (Note: you can use % for searching but it may be considerably slower!),
                                "auth" (string) matching user auth plugin,
                                "custom_field_xxx" (string) match value of user custom profile field named xxx (replace xxx with field name) (Note: using this param may make the lookup considerably slower!)'),
                            'value' => new external_value(PARAM_RAW, 'the value to search')
                        )
                    ), 'the key/value pairs to be considered in user search. Values can not be empty.
                        Specify different keys only once (fullname => \'user1\', auth => \'manual\', ...) -
                        key occurences are forbidden.
                        The search is executed with AND operator on the criterias. Invalid criterias (keys) are ignored,
                        the search is still executed on the valid criterias.
                        You can search without criteria, but the function is not designed for it.
                        It could very slow or timeout. The function is designed to search some specific users.'
                )
            )
        );
    }
    /**
     * Retrieve matching user.
     *
     * @throws moodle_exception
     * @param array $criteria the allowed array keys are id/lastname/firstname/idnumber/username/email/auth.
     * @return array An array of arrays containing user profiles.
     * @since Moodle 2.5
     */
    public static function get_users($criteria = array()) {
        global $CFG, $USER, $DB;
        require_once($CFG->dirroot . "/user/lib.php");
        $params = self::validate_parameters(self::get_users_parameters(),
                array('criteria' => $criteria));
        // Validate the criteria and retrieve the users.
        $c = 0;
        $users = array();
        $warnings = array();
        $sqlparams = array();
        $usedkeys = array();
        $sqltables = '{user}';
        // Do not retrieve deleted users.
        $sqlwhere = ' deleted = 0';
        // Get List of custom profile fields
        $fields = profile_get_custom_fields(true);
        $customprofilefields = array();
        foreach ($fields as $field) {
            $customprofilefields[] = $field->shortname;
        }
        
        foreach ($params['criteria'] as $criteriaindex => $criteria) {
            // Check that the criteria has never been used.
            if (array_key_exists($criteria['key'], $usedkeys)) {
                throw new moodle_exception('keyalreadyset', '', '', null, 'The key ' . $criteria['key'] . ' can only be sent once');
            } else {
                $usedkeys[$criteria['key']] = true;
            }
            $invalidcriteria = false;
            // Clean the parameters.
            $paramtype = PARAM_RAW;
            switch ($criteria['key']) {
                case 'id':
                    $paramtype = PARAM_INT;
                    break;
                case 'idnumber':
                    $paramtype = PARAM_RAW;
                    break;
                case 'username':
                    $paramtype = PARAM_RAW;
                    break;
                case 'email':
                    // We use PARAM_RAW to allow searches with %.
                    $paramtype = PARAM_RAW;
                    break;
                case 'auth':
                    $paramtype = PARAM_AUTH;
                    break;
                case 'lastname':
                case 'firstname':
                    $paramtype = PARAM_TEXT;
                    break;
                default:
                    if( substr($criteria['key'],0,14) == 'profile_field_' && in_array(substr($criteria['key'],14,strlen($criteria['key'])), $customprofilefields)) {
                        $paramtype = PARAM_TEXT;
                    }
                    else {
                        // Send back a warning that this search key is not supported in this version.
                        // This warning will make the function extandable without breaking clients.
                        $warnings[] = array(
                            'item' => $criteria['key'],
                            'warningcode' => 'invalidfieldparameter',
                            'message' =>
                                'The search key \'' . $criteria['key'] . '\' is not supported, look at the web service documentation'
                        );
                        // Do not add this invalid criteria to the created SQL request.
                        $invalidcriteria = true;
                        unset($params['criteria'][$criteriaindex]);
                    }
                    break;
            }
            if (!$invalidcriteria) {
                $cleanedvalue = clean_param($criteria['value'], $paramtype);
                $sqlwhere .= ' AND ';
                // Create the SQL.
                switch ($criteria['key']) {
                    case 'id':
                    case 'idnumber':
                    case 'auth':
                        $sqlwhere .= '{user}.' . $criteria['key'] . ' = :' . $criteria['key'];
                        $sqlparams[$criteria['key']] = $cleanedvalue;
                        break;
                    case 'username':
                    case 'email':
                    case 'lastname':
                    case 'firstname':
                        $sqlwhere .= $DB->sql_like('{user}.' . $criteria['key'], ':' . $criteria['key'], false);
                        $sqlparams[$criteria['key']] = $cleanedvalue;
                        break;
                    default:
                        if( substr($criteria['key'],0,14) == 'profile_field_' && in_array(substr($criteria['key'],14,strlen($criteria['key'])), $customprofilefields)) {
                            $c++;
                            $sqltables .= " LEFT JOIN {user_info_data} AS cfdata". $c ." ON {user}.id = cfdata". $c .".userid LEFT JOIN {user_info_field} AS cfield" . $c ." ON cfdata". $c .".fieldid = cfield". $c .".id";
                            $sqlwhere .= 'cfield'.$c . '.shortname = :cfield'.$c . ' AND cfdata'. $c .'.data = :cfdata'. $c;
                            $sqlparams['cfield'.$c] = substr($criteria['key'],14,strlen($criteria['key']));
                            $sqlparams['cfdata'.$c] = $cleanedvalue;
                            $warnings[] = array('warningcode' => 'customfieldname','message' => 'cfield'.$c ." = ". substr($criteria['key'],14,strlen($criteria['key'])));
                            $warnings[] = array('warningcode' => 'customfielddata','message' => 'cfdata'.$c ." = ". $cleanedvalue);
                        }
                        break;
                }
            }
        }

        $sql = 'SELECT {user}.* FROM ' . $sqltables . ' WHERE '. $sqlwhere . ' ORDER BY id ASC';
        
        $users = $DB->get_records_sql($sql, $sqlparams);
        // Finally retrieve each users information.
        $returnedusers = array();
        foreach ($users as $user) {
            $userdetails = user_get_user_details_courses($user);
            $customfields = profile_user_record($user->id);
            // Return the user only if all the searched fields are returned.
            // Otherwise it means that the $USER was not allowed to search the returned user.
            if (!empty($userdetails)) {
                $validuser = true;
                foreach ($params['criteria'] as $criteria) {
                    if (substr($criteria['key'],0,14) != 'profile_field_' && empty($userdetails[$criteria['key']])) {
                        $validuser = false;
                    }
                }
                if ($validuser) {
                    $returnedusers[] = $userdetails;
                }
            }
        }
        return array('users' => $returnedusers, 'warnings' => $warnings);
    }
    /**
     * Returns description of get_users result value.
     *
     * @return external_description
     * @since Moodle 2.5
     */
    public static function get_users_returns() {
        return new external_single_structure(
            array('users' => new external_multiple_structure(
                                self::user_description()
                             ),
                  'warnings' => new external_warnings('always set to \'key\'', 'faulty key name')
            )
        );
    }

 }