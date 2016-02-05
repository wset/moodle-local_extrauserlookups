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
 require_once("$CFG->dirroot/cohort/externallib.php");

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

     /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.2
     */
    public static function create_users_parameters() {
        global $CFG;
        return new external_function_parameters(
            array(
                'users' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'username' =>
                                new external_value(PARAM_USERNAME, 'Username policy is defined in Moodle security config.'),
                            'password' =>
                                new external_value(PARAM_RAW, 'Plain text password consisting of any characters', VALUE_OPTIONAL),
                            'createpassword' =>
                                new external_value(PARAM_BOOL, 'True if password should be created and mailed to user.',
                                    VALUE_OPTIONAL),
                            'firstname' =>
                                new external_value(PARAM_NOTAGS, 'The first name(s) of the user'),
                            'lastname' =>
                                new external_value(PARAM_NOTAGS, 'The family name of the user'),
                            'email' =>
                                new external_value(PARAM_EMAIL, 'A valid and unique email address'),
                            'auth' =>
                                new external_value(PARAM_PLUGIN, 'Auth plugins include manual, ldap, imap, etc', VALUE_DEFAULT,
                                    'manual', NULL_NOT_ALLOWED),
                            'idnumber' =>
                                new external_value(PARAM_RAW, 'An arbitrary ID code number perhaps from the institution',
                                    VALUE_DEFAULT, ''),
                            'lang' =>
                                new external_value(PARAM_SAFEDIR, 'Language code such as "en", must exist on server', VALUE_DEFAULT,
                                    $CFG->lang, NULL_NOT_ALLOWED),
                            'calendartype' =>
                                new external_value(PARAM_PLUGIN, 'Calendar type such as "gregorian", must exist on server',
                                    VALUE_DEFAULT, $CFG->calendartype, VALUE_OPTIONAL),
                            'theme' =>
                                new external_value(PARAM_PLUGIN, 'Theme name such as "standard", must exist on server',
                                    VALUE_OPTIONAL),
                            'timezone' =>
                                new external_value(PARAM_TIMEZONE, 'Timezone code such as Australia/Perth, or 99 for default',
                                    VALUE_OPTIONAL),
                            'mailformat' =>
                                new external_value(PARAM_INT, 'Mail format code is 0 for plain text, 1 for HTML etc',
                                    VALUE_OPTIONAL),
                            'description' =>
                                new external_value(PARAM_TEXT, 'User profile description, no HTML', VALUE_OPTIONAL),
                            'city' =>
                                new external_value(PARAM_NOTAGS, 'Home city of the user', VALUE_OPTIONAL),
                            'country' =>
                                new external_value(PARAM_ALPHA, 'Home country code of the user, such as AU or CZ', VALUE_OPTIONAL),
                            'firstnamephonetic' =>
                                new external_value(PARAM_NOTAGS, 'The first name(s) phonetically of the user', VALUE_OPTIONAL),
                            'lastnamephonetic' =>
                                new external_value(PARAM_NOTAGS, 'The family name phonetically of the user', VALUE_OPTIONAL),
                            'middlename' =>
                                new external_value(PARAM_NOTAGS, 'The middle name of the user', VALUE_OPTIONAL),
                            'alternatename' =>
                                new external_value(PARAM_NOTAGS, 'The alternate name of the user', VALUE_OPTIONAL),
                            'institution' =>
                                new external_value(PARAM_NOTAGS, 'The instution of the user', VALUE_OPTIONAL),
                            'department' =>
                                new external_value(PARAM_NOTAGS, 'The department of the user', VALUE_OPTIONAL),
                            'skype' =>
                                new external_value(PARAM_NOTAGS, 'The skype id of the user', VALUE_OPTIONAL),
                            'msn' =>
                                new external_value(PARAM_NOTAGS, 'The msn id of the user', VALUE_OPTIONAL),
                            'aim' =>
                                new external_value(PARAM_NOTAGS, 'The aim id of the user', VALUE_OPTIONAL),
                            'yahoo' =>
                                new external_value(PARAM_NOTAGS, 'The yahoo id of the user', VALUE_OPTIONAL),
                            'icq' =>
                                new external_value(PARAM_NOTAGS, 'The icq id of the user', VALUE_OPTIONAL),
                            'phone1' =>
                                new external_value(PARAM_NOTAGS, 'The phone 1 of the user', VALUE_OPTIONAL),
                            'phone2' =>
                                new external_value(PARAM_NOTAGS, 'The phone 2 of the user', VALUE_OPTIONAL),
                            'address' =>
                                new external_value(PARAM_NOTAGS, 'The postal address of the user', VALUE_OPTIONAL),
                            'url' =>
                                new external_value(PARAM_NOTAGS, 'The url of the user', VALUE_OPTIONAL),
                            'preferences' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'type'  => new external_value(PARAM_ALPHANUMEXT, 'The name of the preference'),
                                        'value' => new external_value(PARAM_RAW, 'The value of the preference')
                                    )
                                ), 'User preferences', VALUE_OPTIONAL),
                            'customfields' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'type'  => new external_value(PARAM_ALPHANUMEXT, 'The name of the custom field'),
                                        'value' => new external_value(PARAM_RAW, 'The value of the custom field')
                                    )
                                ), 'User custom fields (also known as user profil fields)', VALUE_OPTIONAL)
                        )
                    )
                )
            )
        );
    }

        /**
     * Create one or more users.
     *
     * @throws invalid_parameter_exception
     * @param array $users An array of users to create.
     * @return array An array of arrays
     * @since Moodle 2.2
     */
    public static function create_users($users) {
        global $CFG, $DB;
        require_once($CFG->dirroot."/lib/weblib.php");
        require_once($CFG->dirroot."/user/lib.php");
        require_once($CFG->dirroot."/user/profile/lib.php"); // Required for customfields related function.
        // Ensure the current user is allowed to run this function.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:create', $context);
        // Do basic automatic PARAM checks on incoming data, using params description.
        // If any problems are found then exceptions are thrown with helpful error messages.
        $params = self::validate_parameters(self::create_users_parameters(), array('users' => $users));
        $availableauths  = core_component::get_plugin_list('auth');
        unset($availableauths['mnet']);       // These would need mnethostid too.
        unset($availableauths['webservice']); // We do not want new webservice users for now.
        $availablethemes = core_component::get_plugin_list('theme');
        $availablelangs  = get_string_manager()->get_list_of_translations();
        $transaction = $DB->start_delegated_transaction();
        $userids = array();
        $createpassword = false;
        foreach ($params['users'] as $user) {
            // Make sure that the username doesn't already exist.
            if ($DB->record_exists('user', array('username' => $user['username'], 'mnethostid' => $CFG->mnet_localhost_id))) {
                throw new invalid_parameter_exception('Username already exists: '.$user['username']);
            }
            // Make sure auth is valid.
            if (empty($availableauths[$user['auth']])) {
                throw new invalid_parameter_exception('Invalid authentication type: '.$user['auth']);
            }
            // Make sure lang is valid.
            if (empty($availablelangs[$user['lang']])) {
                throw new invalid_parameter_exception('Invalid language code: '.$user['lang']);
            }
            // Make sure lang is valid.
            if (!empty($user['theme']) && empty($availablethemes[$user['theme']])) { // Theme is VALUE_OPTIONAL,
                                                                                     // so no default value
                                                                                     // We need to test if the client sent it
                                                                                     // => !empty($user['theme']).
                throw new invalid_parameter_exception('Invalid theme: '.$user['theme']);
            }
            // Make sure we have a password or have to create one.
            if (empty($user['password']) && empty($user['createpassword'])) {
                throw new invalid_parameter_exception('Invalid password: you must provide a password, or set createpassword.');
            }
            $user['confirmed'] = true;
            $user['mnethostid'] = $CFG->mnet_localhost_id;
            // Start of user info validation.
            // Make sure we validate current user info as handled by current GUI. See user/editadvanced_form.php func validation().
            if (!validate_email($user['email'])) {
                throw new invalid_parameter_exception('Email address is invalid: '.$user['email']);
            } else if (empty($CFG->allowaccountssameemail) &&
                    $DB->record_exists('user', array('email' => $user['email'], 'mnethostid' => $user['mnethostid']))) {
                throw new invalid_parameter_exception('Email address already exists: '.$user['email']);
            }
            // End of user info validation.
            $createpassword = !empty($user['createpassword']);
            unset($user['createpassword']);
            if ($createpassword) {
                $user['password'] = '';
                $updatepassword = false;
            } else {
                $updatepassword = true;
            }
            // Create the user data now!
            $user['id'] = user_create_user($user, $updatepassword, false);
            // Custom fields.
            if (!empty($user['customfields'])) {
                foreach ($user['customfields'] as $customfield) {
                    // Profile_save_data() saves profile file it's expecting a user with the correct id,
                    // and custom field to be named profile_field_"shortname".
                    $user["profile_field_".$customfield['type']] = $customfield['value'];
                }
                profile_save_data((object) $user);
            }
            if ($createpassword) {
                $userobject = (object)$user;
                setnew_password_and_mail($userobject);
                unset_user_preference('create_password', $userobject);
                set_user_preference('auth_forcepasswordchange', 1, $userobject);
            }
            // Trigger event.
            \core\event\user_created::create_from_userid($user['id'])->trigger();
            // Preferences.
            if (!empty($user['preferences'])) {
                foreach ($user['preferences'] as $preference) {
                    set_user_preference($preference['type'], $preference['value'], $user['id']);
                }
            }
            $userids[] = array('id' => $user['id'], 'username' => $user['username']);
        }
        $transaction->allow_commit();
        return $userids;
    }
    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.2
     */
    public static function create_users_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id'       => new external_value(PARAM_INT, 'user id'),
                    'username' => new external_value(PARAM_USERNAME, 'user name'),
                )
            )
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.2
     */
    public static function update_users_parameters() {
        return new external_function_parameters(
            array(
                'users' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' =>
                                new external_value(PARAM_INT, 'ID of the user'),
                            'username' =>
                                new external_value(PARAM_USERNAME, 'Username policy is defined in Moodle security config.',
                                    VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
                            'password' =>
                                new external_value(PARAM_RAW, 'Plain text password consisting of any characters', VALUE_OPTIONAL,
                                    '', NULL_NOT_ALLOWED),
                            'firstname' =>
                                new external_value(PARAM_NOTAGS, 'The first name(s) of the user', VALUE_OPTIONAL, '',
                                    NULL_NOT_ALLOWED),
                            'lastname' =>
                                new external_value(PARAM_NOTAGS, 'The family name of the user', VALUE_OPTIONAL),
                            'email' =>
                                new external_value(PARAM_EMAIL, 'A valid and unique email address', VALUE_OPTIONAL, '',
                                    NULL_NOT_ALLOWED),
                            'auth' =>
                                new external_value(PARAM_PLUGIN, 'Auth plugins include manual, ldap, imap, etc', VALUE_OPTIONAL, '',
                                    NULL_NOT_ALLOWED),
                            'idnumber' =>
                                new external_value(PARAM_RAW, 'An arbitrary ID code number perhaps from the institution',
                                    VALUE_OPTIONAL),
                            'lang' =>
                                new external_value(PARAM_SAFEDIR, 'Language code such as "en", must exist on server',
                                    VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
                            'calendartype' =>
                                new external_value(PARAM_PLUGIN, 'Calendar type such as "gregorian", must exist on server',
                                    VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
                            'theme' =>
                                new external_value(PARAM_PLUGIN, 'Theme name such as "standard", must exist on server',
                                    VALUE_OPTIONAL),
                            'timezone' =>
                                new external_value(PARAM_TIMEZONE, 'Timezone code such as Australia/Perth, or 99 for default',
                                    VALUE_OPTIONAL),
                            'mailformat' =>
                                new external_value(PARAM_INT, 'Mail format code is 0 for plain text, 1 for HTML etc',
                                    VALUE_OPTIONAL),
                            'description' =>
                                new external_value(PARAM_TEXT, 'User profile description, no HTML', VALUE_OPTIONAL),
                            'city' =>
                                new external_value(PARAM_NOTAGS, 'Home city of the user', VALUE_OPTIONAL),
                            'country' =>
                                new external_value(PARAM_ALPHA, 'Home country code of the user, such as AU or CZ', VALUE_OPTIONAL),
                            'firstnamephonetic' =>
                                new external_value(PARAM_NOTAGS, 'The first name(s) phonetically of the user', VALUE_OPTIONAL),
                            'lastnamephonetic' =>
                                new external_value(PARAM_NOTAGS, 'The family name phonetically of the user', VALUE_OPTIONAL),
                            'middlename' =>
                                new external_value(PARAM_NOTAGS, 'The middle name of the user', VALUE_OPTIONAL),
                            'alternatename' =>
                                new external_value(PARAM_NOTAGS, 'The alternate name of the user', VALUE_OPTIONAL),
                            'institution' =>
                                new external_value(PARAM_NOTAGS, 'The instution of the user', VALUE_OPTIONAL),
                            'department' =>
                                new external_value(PARAM_NOTAGS, 'The department of the user', VALUE_OPTIONAL),
                            'skype' =>
                                new external_value(PARAM_NOTAGS, 'The skype id of the user', VALUE_OPTIONAL),
                            'msn' =>
                                new external_value(PARAM_NOTAGS, 'The msn id of the user', VALUE_OPTIONAL),
                            'aim' =>
                                new external_value(PARAM_NOTAGS, 'The aim id of the user', VALUE_OPTIONAL),
                            'yahoo' =>
                                new external_value(PARAM_NOTAGS, 'The yahoo id of the user', VALUE_OPTIONAL),
                            'icq' =>
                                new external_value(PARAM_NOTAGS, 'The icq id of the user', VALUE_OPTIONAL),
                            'phone1' =>
                                new external_value(PARAM_NOTAGS, 'The phone 1 of the user', VALUE_OPTIONAL),
                            'phone2' =>
                                new external_value(PARAM_NOTAGS, 'The phone 2 of the user', VALUE_OPTIONAL),
                            'address' =>
                                new external_value(PARAM_NOTAGS, 'The postal address of the user', VALUE_OPTIONAL),
                            'url' =>
                                new external_value(PARAM_NOTAGS, 'The url of the user', VALUE_OPTIONAL),
                            'customfields' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'type'  => new external_value(PARAM_ALPHANUMEXT, 'The name of the custom field'),
                                        'value' => new external_value(PARAM_RAW, 'The value of the custom field')
                                    )
                                ), 'User custom fields (also known as user profil fields)', VALUE_OPTIONAL),
                            'preferences' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'type'  => new external_value(PARAM_ALPHANUMEXT, 'The name of the preference'),
                                        'value' => new external_value(PARAM_RAW, 'The value of the preference')
                                    )
                                ), 'User preferences', VALUE_OPTIONAL),
                        )
                    )
                )
            )
        );
    }
    /**
     * Update users
     *
     * @param array $users
     * @return null
     * @since Moodle 2.2
     */
    public static function update_users($users) {
        global $CFG, $DB;
        require_once($CFG->dirroot."/user/lib.php");
        require_once($CFG->dirroot."/user/profile/lib.php"); // Required for customfields related function.
        // Ensure the current user is allowed to run this function.
        $context = context_system::instance();
        require_capability('moodle/user:update', $context);
        self::validate_context($context);
        $params = self::validate_parameters(self::update_users_parameters(), array('users' => $users));
        $transaction = $DB->start_delegated_transaction();
        foreach ($params['users'] as $user) {
            user_update_user($user, true, false);
            // Update user custom fields.
            if (!empty($user['customfields'])) {
                foreach ($user['customfields'] as $customfield) {
                    // Profile_save_data() saves profile file it's expecting a user with the correct id,
                    // and custom field to be named profile_field_"shortname".
                    $user["profile_field_".$customfield['type']] = $customfield['value'];
                }
                profile_save_data((object) $user);
            }
            // Trigger event.
            \core\event\user_updated::create_from_userid($user['id'])->trigger();
            // Preferences.
            if (!empty($user['preferences'])) {
                foreach ($user['preferences'] as $preference) {
                    set_user_preference($preference['type'], $preference['value'], $user['id']);
                }
            }
        }
        $transaction->allow_commit();
        return null;
    }
    /**
     * Returns description of method result value
     *
     * @return null
     * @since Moodle 2.2
     */
    public static function update_users_returns() {
        return null;
    }
 }
 
 class local_extrauserlookups_cohort_external extends core_cohort_external {
    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.5
     */
    public static function get_cohorts_parameters() {
        return new external_function_parameters(
            array(
                'cohortids' => new external_multiple_structure(new external_value(PARAM_INT, 'Cohort ID')
                    , 'List of cohort id. A cohort id is an integer.', VALUE_DEFAULT, array()),
            )
        );
    }
    /**
     * Get cohorts definition specified by ids
     *
     * @param array $cohortids array of cohort ids
     * @return array of cohort objects (id, courseid, name)
     * @since Moodle 2.5
     */
    public static function get_cohorts($cohortids = array()) {
        global $DB;
        $params = self::validate_parameters(self::get_cohorts_parameters(), array('cohortids' => $cohortids));
        if (empty($cohortids)) {
            $cohorts = $DB->get_records('cohort');
        } else {
            $cohorts = $DB->get_records_list('cohort', 'id', $params['cohortids']);
        }
        $cohortsinfo = array();
        foreach ($cohorts as $cohort) {
            // Now security checks.
            $context = context::instance_by_id($cohort->contextid, MUST_EXIST);
            if ($context->contextlevel != CONTEXT_COURSECAT and $context->contextlevel != CONTEXT_SYSTEM) {
                throw new invalid_parameter_exception('Invalid context');
            }
            self::validate_context($context);
            if (!has_any_capability(array('moodle/cohort:manage', 'moodle/cohort:view'), $context)) {
                throw new required_capability_exception($context, 'moodle/cohort:view', 'nopermissions', '');
            }
            list($cohort->description, $cohort->descriptionformat) =
                external_format_text($cohort->description, $cohort->descriptionformat,
                        $context->id, 'cohort', 'description', $cohort->id);
            $cohortsinfo[] = (array) $cohort;
        }
        return $cohortsinfo;
    }
    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.5
     */
    public static function get_cohorts_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'ID of the cohort'),
                    'name' => new external_value(PARAM_RAW, 'cohort name'),
                    'idnumber' => new external_value(PARAM_RAW, 'cohort idnumber'),
                    'description' => new external_value(PARAM_RAW, 'cohort description'),
                    'descriptionformat' => new external_format_value('description'),
                    'visible' => new external_value(PARAM_BOOL, 'cohort visible'),
                )
            )
        );
    } 
 }