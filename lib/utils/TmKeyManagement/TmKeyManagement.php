<?php
/**
 * Created by PhpStorm.
 * User: roberto
 * Date: 02/09/14
 * Time: 15.01
 */
include_once INIT::$MODEL_ROOT . "/queries.php";

class TmKeyManagement_TmKeyManagement {

    /**
     * Returns a TmKeyManagement_TmKeyStruct object. <br/>
     * If a proper associative array is passed, it fills the fields
     * with the array values.
     *
     * @param array|null $tmKey_arr An associative array having
     *                              the same keys of a
     *                              TmKeyManagement_TmKeyStruct object
     *
     * @return TmKeyManagement_TmKeyStruct The converted object
     */
    public static function getTmKeyStructure( $tmKey_arr = null ) {
        return new TmKeyManagement_TmKeyStruct( $tmKey_arr );
    }

    /**
     * Returns a TmKeyManagement_ClientTmKeyStruct object. <br/>
     * If a proper associative array is passed, it fills the fields
     * with the array values.
     *
     * @param array|null $tmKey_arr An associative array having
     *                              the same keys of a
     *                              TmKeyManagement_ClientTmKeyStruct object
     *
     * @return TmKeyManagement_ClientTmKeyStruct The converted object
     */
    public static function getClientTmKeyStructure( $tmKey_arr = null ) {
        return new TmKeyManagement_ClientTmKeyStruct( $tmKey_arr );
    }

    /**
     * Converts a string representing a json_encoded array of TmKeyManagement_TmKeyStruct into an array
     * and filters the elements according to the grants passed.
     *
     * @param   $jsonTmKeys  string  A json string representing an array of TmKeyStruct Objects
     * @param   $grant_level string  One of the following strings : "r", "w", "rw"
     * @param   $type        string  One of the following strings : "tm", "glossary", "tm,glossary"
     * @param   $user_role   string  A constant string of one of the following: TmKeyManagement_Filter::ROLE_TRANSLATOR, TmKeyManagement_Filter::ROLE_REVISOR
     * @param   $uid         int     The user ID, used to retrieve the personal keys
     *
     * @return  array|mixed  An array of TmKeyManagement_TmKeyStruct objects
     * @throws  Exception    Throws Exception if :<br/>
     *                   <ul>
     *                      <li>Json string is malformed</li>
     *                      <li>grant_level string is wrong</li>
     *                      <li>if type string is wrong</li>
     *                      <li>if user role string is wrong</li>
     *                  </ul>
     *
     * @see TmKeyManagement_TmKeyStruct
     */
    public static function getJobTmKeys( $jsonTmKeys, $grant_level = 'rw', $type = "tm", $user_role = TmKeyManagement_Filter::ROLE_TRANSLATOR, $uid = null ) {

        $tmKeys = json_decode( $jsonTmKeys, true );

        if ( is_null( $tmKeys ) ) {
            throw new Exception ( __METHOD__ . " -> Invalid JSON " );
        }

        $filter = new TmKeyManagement_Filter( $uid );
        $filter->setGrants( $grant_level )
                ->setTmType( $type );

        switch ( $user_role ) {
            case TmKeyManagement_Filter::ROLE_TRANSLATOR:
                $tmKeys = array_filter( $tmKeys, array( $filter, 'byTranslator' ) );
                break;
            case TmKeyManagement_Filter::ROLE_REVISOR:
                $tmKeys = array_filter( $tmKeys, array( $filter, 'byRevisor' ) );
                break;
            default:
                throw new Exception( "Filter type $user_role not allowed." );
                break;
        }

        $tmKeys = array_values( $tmKeys );
        $tmKeys = array_map( array( 'self', 'getTmKeyStructure' ), $tmKeys );

        return $tmKeys;
    }

    /**
     * @param $id_job   int
     * @param $job_pass string
     * @param $tm_keys  array
     *
     * @return int|null Returns null if all is ok, otherwise it returns the error code of the mysql Query
     */
    public static function setJobTmKeys( $id_job, $job_pass, $tm_keys ) {
        return setJobTmKeys( $id_job, $job_pass, json_encode( $tm_keys ) );
    }

    /**
     * Converts an array of strings representing a json_encoded array
     * of TmKeyManagement_TmKeyStruct objects into the corresponding array.
     *
     * @param $jsonTmKeys_array array An array of strings representing a json_encoded array of TmKeyManagement_TmKeyStruct objects
     *
     * @return array                  An array of TmKeyManagement_TmKeyStruct objects
     * @throws Exception              Throws Exception if the input is not an array or if a string is not a valid json
     * @see TmKeyManagement_TmKeyStruct
     */
    public static function getOwnerKeys( Array $jsonTmKeys_array ) {

        $result_arr = array();

        foreach ( $jsonTmKeys_array as $pos => $tmKey ) {

            $tmKey = json_decode( $tmKey, true );

            if ( is_null( $tmKey ) ) {
                Log::doLog( __METHOD__ . " -> Invalid JSON." );
                Log::doLog( var_export( $tmKey, true ) );
                throw new Exception ( "Invalid JSON", -2 );
            }

            $filter = new TmKeyManagement_Filter();
            $tmKey  = array_filter( $tmKey, array( $filter, 'byOwner' ) );

            $result_arr[ ] = $tmKey;

        }

        /**
         *
         * Note: Take the shortest array of keys, it's like an intersection between owner keys
         */
        asort( $result_arr );

        //take only the first Job entries
        $result_arr = array_shift( $result_arr );

        //convert tm keys into TmKeyManagement_TmKeyStruct objects
        $result_arr = array_map( array( 'self', 'getTmKeyStructure' ), $result_arr );

        return $result_arr;
    }

    /**
     * Checks if a given array has the same structure of a TmKeyManagement_TmKeyStruct object
     *
     * @param $arr array The array whose structure has to be tested
     *
     * @return TmKeyManagement_TmKeyStruct|bool True if the structure is compliant to a TmKeyManagement_TmKeyStruct object. False otherwise.
     */
    public static function isValidStructure( $arr ) {
        try {
            $myObj = new TmKeyManagement_TmKeyStruct( $arr );
        } catch ( Exception $e ) {
            return false;
        }

        return $myObj;
    }

    /**
     * Merge the keys from CLIENT with those from DATABASE ( jobData )
     *
     * @param string $Json_clientKeys A json_encoded array of objects having the following structure:<br />
     * <pre>
     * array(
     *    'key'  => &lt;private_tm_key>,
     *    'name' => &lt;tm_name>,
     *    'r'    => true,
     *    'w'    => true
     * )
     * </pre>
     * @param string $Json_jobKeys    A json_encoded array of TmKeyManagement_TmKeyStruct objects
     * @param string $userRole        One of the following strings: "owner", "translator", "revisor"
     * @param int    $uid
     *
     * @see TmKeyManagement_TmKeyStruct
     *
     * @return array TmKeyManagement_TmKeyStruct[]
     *
     * @throws Exception
     */
    public static function mergeJsonKeys( $Json_clientKeys, $Json_jobKeys, $userRole = TmKeyManagement_Filter::ROLE_TRANSLATOR, $uid = null ) {

        //we put the already present job keys so they can be checked against the client keys when cycle advances
        //( jobs has more elements than the client objects )
        $clientDecodedJson = json_decode( $Json_clientKeys, true );
        Utils::raiseJsonExceptionError();
        $serverDecodedJson = json_decode( $Json_jobKeys, true );
        Utils::raiseJsonExceptionError();

        if( !array_key_exists( $userRole, TmKeyManagement_Filter::$GRANTS_MAP ) ) {
            throw new Exception ( "Invalid Role Type string.", 4 );
        }

        $client_tm_keys = array_map( array( 'self', 'getTmKeyStructure' ), $clientDecodedJson );
        $job_tm_keys    = array_map( array( 'self', 'getTmKeyStructure' ), $serverDecodedJson );

        $server_reorder_position = array( );
        $reverse_lookup_client_json = array( 'pos' => array(), 'elements' => array() );
        foreach ( $client_tm_keys as $_j => $_client_tm_key ) {

            /**
             * @var $_client_tm_key TmKeyManagement_TmKeyStruct
             */

            //create a reverse lookup
            $reverse_lookup_client_json[ 'pos' ][ $_j ]      = $_client_tm_key->key;
            $reverse_lookup_client_json[ 'elements' ][ $_j ] = $_client_tm_key;

            if( empty( $_client_tm_key->r ) && empty( $_client_tm_key->w ) ){
                throw new Exception( "Read and Write grants can not be both empty" );
            }

        }

        //update existing job keys
        foreach ( $job_tm_keys as $i => $_job_Key ) {
            /**
             * @var $_job_Key TmKeyManagement_TmKeyStruct
             */

            $_index_position = array_search( $_job_Key->key, $reverse_lookup_client_json[ 'pos' ] );
            if ( $_index_position !== false ) { // so, here the key exists in client

                //this is an anonymous user, and a key exists in job
                if( $_index_position !== false && $uid == null ){

                    //check anonymous user, an anonymous user can not change a not anonymous key
                    if ( $userRole == TmKeyManagement_Filter::ROLE_TRANSLATOR ) {

                        if( $_job_Key->uid_transl != null ) throw new Exception( "Anonymous user can not modify existent keys." , 1 );

                    } elseif ( $userRole == TmKeyManagement_Filter::ROLE_REVISOR ) {

                        if( $_job_Key->uid_rev != null ) throw new Exception( "Anonymous user can not modify existent keys." , 2 );

                    } else {

                        if( $uid == null )  throw new Exception( "Anonymous user can not be OWNER" , 3 );

                    }

                }

                //override the static values
                $_job_Key->tm   = filter_var( $reverse_lookup_client_json[ 'elements' ][ $_index_position ]->tm, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                $_job_Key->glos = filter_var( $reverse_lookup_client_json[ 'elements' ][ $_index_position ]->glos, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

                if ( $userRole == TmKeyManagement_Filter::OWNER ) {

                    //override grants
                    $_job_Key->r = filter_var( $reverse_lookup_client_json[ 'elements' ][ $_index_position ]->r, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                    $_job_Key->w = filter_var( $reverse_lookup_client_json[ 'elements' ][ $_index_position ]->w, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

                } elseif ( $userRole == TmKeyManagement_Filter::ROLE_REVISOR || $userRole == TmKeyManagement_Filter::ROLE_TRANSLATOR ) {

                    //override role specific grants
                    $_job_Key->{TmKeyManagement_Filter::$GRANTS_MAP[ $userRole ][ 'r' ]} = filter_var( $reverse_lookup_client_json[ 'elements' ][ $_index_position ]->r, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                    $_job_Key->{TmKeyManagement_Filter::$GRANTS_MAP[ $userRole ][ 'w' ]} = filter_var( $reverse_lookup_client_json[ 'elements' ][ $_index_position ]->w, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

                }

                //choose a name instead of null
                if ( empty( $_job_Key->name ) ) {
                    $_job_Key->name = $reverse_lookup_client_json[ 'elements' ][ $_index_position ]->name;
                }

                //set as owner if it is but should be already set
//                $_job_Key->owner = ( $userRole == TmKeyManagement_Filter::OWNER );

                //reduce the stack
                unset( $reverse_lookup_client_json[ 'pos' ][ $_index_position ] );
                unset( $reverse_lookup_client_json[ 'elements' ][ $_index_position ] );

                //take the new order
                $server_reorder_position[ $_index_position ] = $_job_Key;

            } elseif ( array_search( $_job_Key->getHash(), $reverse_lookup_client_json[ 'pos' ] ) !== false ) {
                //DO NOTHING
                //reduce the stack
                $hashPosition = array_search( $_job_Key->getHash(), $reverse_lookup_client_json[ 'pos' ] );

                unset( $reverse_lookup_client_json[ 'pos' ][ $hashPosition ] );
                unset( $reverse_lookup_client_json[ 'elements' ][ $hashPosition ] );
                //PASS

                //take the new order
                $server_reorder_position[ $hashPosition ] = $_job_Key;

            } else {

                //the key must be deleted
                if ( $userRole == TmKeyManagement_Filter::OWNER ) {

                    //override grants
                    $_job_Key->r = null;
                    $_job_Key->w = null;
                    $_job_Key->owner = false;

                } elseif ( $userRole == TmKeyManagement_Filter::ROLE_REVISOR || $userRole == TmKeyManagement_Filter::ROLE_TRANSLATOR ) {

                    //override role specific grants
                    $_job_Key->{TmKeyManagement_Filter::$GRANTS_MAP[ $userRole ][ 'r' ]} = null;
                    $_job_Key->{TmKeyManagement_Filter::$GRANTS_MAP[ $userRole ][ 'w' ]} = null;

                }

                //remove the uid property
                if ( $userRole == TmKeyManagement_Filter::ROLE_TRANSLATOR ) {
                    $_job_Key->uid_transl = null;
                } elseif ( $userRole == TmKeyManagement_Filter::ROLE_REVISOR ) {
                    $_job_Key->uid_rev = null;
                }

                //if the key is no more linked to someone, don't add to the resultset, else reorder if it is not an owner key.
                if ( $_job_Key->owner || !is_null( $_job_Key->uid_transl ) || !is_null( $_job_Key->uid_rev ) ) {

                    if ( !$_job_Key->owner ){

                        //take the new order, put the deleted key at the end of the array
                        //a position VERY LOW ( 1 Million )
                        $server_reorder_position[ 1000000 + $i ] = $_job_Key;

                    } else {
                        //place on top of the owner keys, preserve the order of owner keys by adding it's normal index position
                        $server_reorder_position[ -1000000 + $i ] = $_job_Key;
                    }

                }

            }

        }

        /*
         * There are some new keys from client? Add them
         */
        if ( !empty( $reverse_lookup_client_json[ 'pos' ] ) ) {

            $justCreatedKey = new TmKeyManagement_TmKeyStruct();

            foreach ( $reverse_lookup_client_json[ 'elements' ] as $_pos => $newClientKey ) {

                /**
                 * @var $newClientKey TmKeyManagement_TmKeyStruct
                 */

                //set the key value
                $justCreatedKey->key = $newClientKey->key;

                //override the static values
                $justCreatedKey->tm   = filter_var( $newClientKey->tm, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                $justCreatedKey->glos = filter_var( $newClientKey->glos, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

                if ( $userRole != TmKeyManagement_Filter::OWNER ) {
                    $justCreatedKey->{TmKeyManagement_Filter::$GRANTS_MAP[ $userRole ][ 'r' ]} = filter_var( $newClientKey->r, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                    $justCreatedKey->{TmKeyManagement_Filter::$GRANTS_MAP[ $userRole ][ 'w' ]} = filter_var( $newClientKey->w, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                } else {
                    //override grants
                    $justCreatedKey->r = filter_var( $newClientKey->r, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                    $justCreatedKey->w = filter_var( $newClientKey->w, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                }

                //set the uid property
                if ( $userRole == TmKeyManagement_Filter::ROLE_TRANSLATOR ) {
                    $justCreatedKey->uid_transl = $uid;
                } elseif ( $userRole == TmKeyManagement_Filter::ROLE_REVISOR ) {
                    $justCreatedKey->uid_rev = $uid;
                }

                //choose a name instead of null
                $justCreatedKey->name = $newClientKey->name;

                //choose an owner instead of null
                $justCreatedKey->owner = ( $userRole == TmKeyManagement_Filter::OWNER );


                //finally append to the job keys!!
                //take the new order, put the deleted key at the end of the array
                //a position VERY LOW, but before the deleted keys, so it goes not to the end ( 100 hundred thousand )
                $server_reorder_position[ 100000 + $_pos ] = $justCreatedKey;

                if ( $uid != null ) {

                    //if uid is provided, check for key and try to add to it's memory key ring
                    try {

                        /*
                         * Take the keys of the user
                         */
                        $_keyDao = new TmKeyManagement_MemoryKeyDao( Database::obtain() );
                        $dh      = new TmKeyManagement_MemoryKeyStruct( array(
                                'uid'    => $uid,
                                'tm_key' => new TmKeyManagement_TmKeyStruct( array(
                                        'key' => $justCreatedKey->key
                                ) )
                        ) );

                        $keyList = $_keyDao->read( $dh );

                        if ( empty( $keyList ) ) {

                            // add the key to a new row struct
                            $dh->uid    = $uid;
                            $dh->tm_key = $justCreatedKey;

                            $_keyDao->create( $dh );

                        }

                    } catch ( Exception $e ) {
                        Log::doLog( $e->getMessage() );
                    }

                }

            }

        }

        ksort( $server_reorder_position, SORT_NUMERIC );
        return array_values( $server_reorder_position );

    }

    /**
     * Removes a tm key from an array of tm keys for a specific user type. <br/>
     * If the tm key is still linked to some other user, the result will be the same input array,
     * except for the tm key wo be removed, whose attributes are properly changed according to the user that wanted to
     * remove it.<br/>
     * If user type is wrong, this function will return the input array.
     *
     * @param array                       $tmKey_arr
     * @param TmKeyManagement_TmKeyStruct $newTmKey
     * @param string                      $user_role
     *
     * @return array
     */
    public static function deleteTmKey( Array $tmKey_arr, TmKeyManagement_TmKeyStruct $newTmKey, $user_role = TmKeyManagement_Filter::OWNER ) {
        $result = array();

        foreach ( $tmKey_arr as $i => $curr_tm_key ) {
            /**
             * @var $curr_tm_key TmKeyManagement_TmKeyStruct
             */
            if ( $curr_tm_key->key == $newTmKey->key ) {
                switch ( $user_role ) {

                    case TmKeyManagement_Filter::ROLE_TRANSLATOR:
                        $curr_tm_key->uid_transl = null;
                        $curr_tm_key->r_transl   = null;
                        $curr_tm_key->w_transl   = null;
                        break;

                    case TmKeyManagement_Filter::ROLE_REVISOR:
                        $curr_tm_key->uid_rev = null;
                        $curr_tm_key->r_rev   = null;
                        $curr_tm_key->w_rev   = null;
                        break;

                    case TmKeyManagement_Filter::OWNER:
                        $curr_tm_key->owner = false;
                        $curr_tm_key->r     = null;
                        $curr_tm_key->w     = null;
                        break;

                    case null:
                        break;

                    default:
                        break;
                }

                //if the key is still linked to someone, add it to the result.
                if ( $curr_tm_key->owner ||
                        !is_null( $curr_tm_key->uid_transl ) ||
                        !is_null( $curr_tm_key->uid_rev )
                ) {
                    $result[ ] = $curr_tm_key;
                }
            } else {
                $result[ ] = $curr_tm_key;
            }
        }

        return $result;
    }

}