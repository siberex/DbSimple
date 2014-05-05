<?php
/**
 * Naive implementation of DB model.
 *
 * @author Stepan Legachev (www.sib.li)
 */


/*
 * Include some config with DB params, ex.:
    define("DATABASE_USER",     "root");
    define("DATABASE_PASSWORD", "");
    define("DATABASE_HOST",     "localhost");
    define("DATABASE_SCHEMA",   "mydb");

    define(
        "DATABASE_DSN",
        sprintf( "mysqli://%s:%s@%s/%s", DATABASE_USER, DATABASE_PASSWORD, DATABASE_HOST, DATABASE_SCHEMA)
    );
 */
require_once __DIR__ . '../path/to/config.inc.php';

/*
 * Also redefine debuglog() if you wish.
 */
function debuglog($msg, $prio)
{
    return syslog($prio, $msg);
    //Or you can write to php log:
    //return error_log($msg);
} // debuglog


require_once __DIR__ . '/DbSimple/Generic.php';



/**
 * Class ModelSimple
 */
abstract class ModelSimple {

    /* CONFIGURE SECTION */

    /**
     * DSN connection string.
     * @var string
     */
    protected static $DSN = DATABASE_DSN;

    /**
     * Database name (global).
     * Can be set to null.
     * @var string|null
     */
    protected static $SCHEMA = DATABASE_SCHEMA;

    /**
     * Global debug level.
     * 0 — log nothing, exits on error;
     * 1 — log errors, throws exceptions on error;
     * 2 — log all queries, errors and throws exceptions.
     * @var int
     */
    protected static $DEBUG = DEBUG_LEVEL;

    /* END OF CONFIGURE SECTION */




    /**
     * Table name.
     * Must be overridden in subclasses.
     * @static
     */
    const T = '/undefined '; // do not change here!

    /**
     * Model fields defined as array.
     * Must be overridden in subclasses.
     *
     * Can be defined as plain indexed array or as key-value pairs,
     * where key is a name of field and value is its data type, one of:
     * T_STRING, T_LNUMBER, T_DNUMBER, T_UNSET_CAST
     *
     * Example:
     *      protected $fields = array(
     *          'id'      => T_LNUMBER
     *          ,'domain' => T_STRING
     *      );
     *
     * @var array
     */
    protected $fields = array();

    /**
     * List of fields required to be filled on Model item creation.
     * Should be set as plain array with field names
     * or as k=>v array filled with keyName => defaultValue pairs.
     * Where field with NULL as defaultValue should be treated as
     * field required to be set explicitly.
     *
     * @todo Implement usage when needed
     * @var array
     */
    protected $requiredFields = array();

    /**
     *
     * @var string Name of unique identifier field for row in DB.
     *             By default will be set as first field from $fields.
     *             Or can be set specifically.
     */
    protected $uidField = null; // do not change here!

    /**
     * @var DbSimple_Mysql
     */
    protected $dbo;

    /**
     * Database name (local).
     * @var string
     */
    protected $schema = null;

    /**
     * Debug level per model instance.
     * @var int 0, 1, 2
     */
    protected $debugLevel = 0;

    /**
     * @var array
     */
    protected $data = array();

    /**
     * @var int Run-time unique identifier.
     */
    protected $uid = null;

    /**
     * Do not touch.
     * @var array
     */
    protected $fieldNames = array();

    /**
     * @var DbSimple_Mysql
     * @static
     */
    protected static $DBO;

    /**
     * Internal map for field types definitions.
     *
     * @var array
     * @static
     */
    protected static $typeMap = array(
        T_STRING     => '?',  // generic type, strings
        T_LNUMBER    => '?d', // integers
        T_DNUMBER    => '?f', // floats
        T_UNSET_CAST => '?n', // like '?', but casts empty strings "" and zeros 0 as NULL
        // Keys below ↓ should NOT be used in field value types!
        T_ARRAY      => '?a', // for updates with assoc arrays and selects with IN(?a)
        T_CONST      => '?_', // table prefixes
        T_CLASS      => '?#'  // table & column name identifiers
    );



    public function __construct($data = null)
    {
        // Emulate abstract constant before late static binding:
        // $c = get_called_class();
        // if ( constant("$c::T") == '/undefined ') {}
        // Emulate abstract constant:
        if ( static::T == '/undefined ') {
            throw new Exception( 'Undefined const T (table name) in Model ' . get_called_class() );
        }

        if ( !is_array($this->fields) || empty($this->fields) ) {
            throw new Exception( 'Undefined fields for Model ' . get_called_class() );
        }

        foreach ($this->fields as $k => $v) {
            if ( empty($this->uidField) ) {
                $this->uidField = is_numeric( $k ) ? $v : $k;
            }

            if ( is_numeric($k) ) {
                if ( !isset($this->fields[$v]) ) {
                    $this->fields[$v] = T_STRING;
                }
                unset($this->fields[$k]);
            } else {
                if ( !isset(self::$typeMap[$v]) ) {
                    $this->fields[$k] = T_STRING;
                }
            }
        }

        $this->fieldNames = array_keys($this->fields);

        $this->dbo = self::connect();
        $this->debugLevel = self::$DEBUG;

        if (!empty(self::$SCHEMA)) {
            $this->schema = self::$SCHEMA;
        }

        if ( is_array($data) ) {
            return $this->create($data);
        }

        if ( is_numeric($data) ) {
            return $this->get($data);
        }

        return $this;
    } // constructor



    public function load()
    {
        return $this->get($this->getId());
    } // load


    /**
     * Gets item from DB and fills Model fields with data.
     * Can be found by uid or by other fields.
     *
     * @param int/array $data Uid or key=>value pairs
     * @return ModelSimple/boolean $this or false on error.
     */
    public function get($data)
    {
        if ( is_array($data) ) {
            $data = array_intersect_key($data, $this->fields);

            if ( !empty($data) ) {

                // E.g.: SELECT ... WHERE (name, email, time) = ('John', 'john@example.com', '2013-10-27 12:28:50');

                $res = $this->dbo->selectRow(
                    'SELECT ?# FROM ?#.?# WHERE (?#) = (?a) LIMIT 1',
                    array_keys($this->fields),
                    $this->schema,
                    static::T,
                    array_keys($data),
                    array_values($data)
                );

                if ($res) {
                    return $this->create($res);
                } else {
                    return false;
                }
            }
        } else if ( is_numeric($data) ) {

            $res = $this->dbo->selectRow(
                'SELECT ?# FROM ?#.?# WHERE ?# = ' . $this->t() . ' LIMIT 1',
                array_keys($this->fields),
                $this->schema,
                static::T,
                $this->uidField,
                $data
            );

            if ($res) {
                return $this->create($res);
            } else {
                return false;
            }
        }

        return $this;
    } // get


    /**
     *
     * @param $data array
     * @return ModelSimple $this
     */
    public function create($data = null)
    {
        if ( !is_array($data) || empty($data) ) {
            return false;
        }

        foreach ($data as $k=>$v) {
            if ( isset($this->fields[$k]) ) {

                if ( empty($this->uid) && $k == $this->uidField ) {
                    $this->uid = $v;
                }

                $name = self::camelize($k);

                // Check if callable setter is present and invoke it
                $setter = 'set' . ucfirst($name);
                if( is_callable( array($this, $setter) ) ) {
                    call_user_func( array($this, $setter), $v );
                } else {
                    $this->data[$k] = $v;
                }

            } else {
                unset($data[$k]);
            }
        }

        return $this;
    } // create


    public function delete()
    {
        if ( empty($this->uid) ) {
            return false;
        }

        return
            $this->dbo->query(
                'DELETE FROM ?#.?# WHERE ?# = ' . $this->t() . ' LIMIT 1',
                $this->schema,
                static::T,
                $this->uidField,
                $this->uid
            );
    } // delete


    /**
     * Saves model data to DB.
     *
     * @return ModelSimple $this
     */
    public function save()
    {

        if ( !empty($this->uid) ) {
            foreach($this->fields as $field=>$type) {

                if ($field == $this->uidField) {
                    continue;
                }

                // Check if callable saver is present and invoke it
                $saver = 'save' . ucfirst( self::camelize($field) );
                if( is_callable( array($this, $saver) ) ) {
                    call_user_func( array($this, $saver), $this->data[$field] );
                } else {
                    // DbSimple supports auto-prepare & execute,
                    //   so it is OK to do N updates instead of 1.
                    // @todo Implement dirty state for fields?
                    $this->saveField( $field, $this->data[$field] );

                }
            }

            // $this->dbo->query('UPDATE tbl SET ?a', $this->data);

        } else {
            // Insert new row

            if ( !empty( $this->data[$this->uidField] ) ) {
                $this->data[$this->uidField] = null;
            }

            /*
             * // fields can be defined as plain array :-(
            $this->data = array_intersect_key(
                $this->data,
                $this->fields
            );
            */

            $uid = $this->dbo->query(
                'INSERT INTO ?#.?#(?#) VALUES(?a)',
                $this->schema,
                static::T,
                array_keys($this->data),
                array_values($this->data)
            );

            if ($uid) {
                $this->uid = $uid;
                $this->data[$this->uidField] = $uid;
                //WRONG! camelize/decamelize!
                //$this->__set($this->uidField, $uid);
            }

        }
        return $this;
    } // save

    /**
     * Saves field value to DB.
     *
     * @param $key   string Key from $fields definition
     * @param $value mixed (optional) Value to save. Can be omitted to get value from $this->data.
     * @return mixed DB result (0 or 1) or false on error
     */
    public function saveField($key, $value = array()) {

        if ( empty($this->uid) ) {
            return false;
        }

        if ( !isset($this->fields[$key]) ) {
            return false;
        }

        if ( !isset($value) || $value === array() ) {
            $value = $this->data[$key];
        }

        if ( $key == $this->uidField && empty($value) ) {
            return false;
        }

        // $res is number of updated rows, 0 or 1.
        $res = $this->dbo->query( 'UPDATE ?#.?#'
            . ' SET ?# = ' . $this->t($key)
            . ' WHERE ?# = ' . $this->t() . ' LIMIT 1',
            $this->schema,
            static::T,
            $key,
            $value,
            $this->uidField,
            $this->uid
        );

        return $res;

    } // saveField


    /* *** *** *** MAGIC begins { *** *** *** */

    /**
     * @param $name string
     * @param $value mixed
     * @return void
     */
    public function __set($name, $value)
    {
        $name = (string) $name;

        // Ex.:
        // $this->runStopTime = 'some value';
        // runStopTime → run_stop_time
        $k = self::decamelize($name);

        if ( !isset($this->fields[$k]) ) {
            return;
        }

        // Check if callable setter is present and invoke it
        $setter = 'set' . ucfirst($name);
        if( is_callable( array($this, $setter) ) ) {
            call_user_func( array($this, $setter), $value );
        } else {
            $this->data[$k] = $value;
        }
    }

    /**
     * @param $name string
     * @return mixed|null
     */
    public function __get($name)
    {
        $name = (string) $name;

        // Ex.:
        // $var = $this->runStartTime;
        // runStartTime → run_start_time
        $k = self::decamelize($name);

        $getter = 'get' . ucfirst($name);

        if ( isset($this->fields[$k]) ) {
            if ( array_key_exists($k, $this->data) ) {
                return $this->data[$k];
            } else {

                // Check if callable getter is present and invoke it
                if( is_callable( array($this, $getter) ) ) {
                    $this->data[$k] = call_user_func( array($this, $getter) );
                    return $this->data[$k];
                } else {
                    // @todo Test this code
                    // Try to fetch value directly from DB
                    if ( !empty($this->uid) ) {
                        $res = $this->dbo->selectCell(
                            'SELECT ?# FROM ?#.?# WHERE ?# = ' . $this->t() . ' LIMIT 1',
                            $k,
                            $this->schema,
                            static::T,
                            $this->uidField,
                            $this->uid
                        );
                        if ($res) {
                            $this->data[$k] = $res;
                        }
                        return $res;
                    }
                }

            }
        } else {
            // Check if callable getter is present and invoke it
            if( is_callable( array($this, $getter) ) ) {
                return call_user_func( array($this, $getter) );
            }
        }

        if ($this->debugLevel >= 2) {
            $trace = debug_backtrace();
            trigger_error(
                'Undefined property ' . $name .
                ' of Model ' . get_called_class() .
                ' in ' . $trace[0]['file'] .
                ' on line ' . $trace[0]['line'],
                E_USER_NOTICE
            );
        }

        return null;
    }

    /**
     *
     * @param $name
     * @return bool
     */
    public function __isset($name)
    {
        $name = (string) $name;
        $k = self::decamelize($name);
        $getter = 'get' . ucfirst($name);
        return (
            array_key_exists($k, $this->data)
            ||
            is_callable( array($this, $getter) )
        );
    }

    public function __unset($name)
    {
        $name = (string) $name;
        $k = self::decamelize($name);
        unset($this->data[$k]);
    }

    public function __sleep()
    {
        return array('uid', 'data');
    }

    public function __wakeup()
    {
        $this->__construct($this->data);
    }

    public function __toString()
    {
        return json_encode($this->data);
    }

    /* *** *** *** } MAGIC ends :-( *** *** *** */



    /**
     * Returns item UID.
     *
     * @return int|null
     */
    public function getId()
    {
        return $this->uid;
    } // getId


    /**
     * Returns item data.
     *
     * @param bool $native Optional
     * @return array
     */
    public function getData($native = true) {
        if ((bool) $native) {
            return $this->data;
        }
        $res = array();
        foreach($this->data as $k=>$v) {
            $res[ lcfirst( self::camelize($k) ) ] = $v;
        }
        return $res;
    } // getData


    /**
     * Changes Database.
     *
     * @param $schema string Database name.
     * @return bool true on success, false on failure.
     */
    public function setSchema($schema)
    {
        if ($this->dbo->query('USE ?#', $schema) !== false) {
            $this->schema = $schema;
            return true;
        }
        return false;
    } // setSchema


    /**
     * Connects to DB.
     *
     * @static
     */
    public static function connect()
    {
        if ( !isset(self::$DEBUG) ) {
            self::$DEBUG = 0;
        }

        if ( empty(self::$DSN) ) {
            self::errorHandler('Database DSN undefined.');
            return null;
        }

        if ( empty(self::$DBO) ) {

            $dbo = DbSimple_Generic::connect(self::$DSN);

            if ( empty($dbo) ) {
                self::errorHandler('Could not connect to DB.');
                return null;
            }

            $dbo->setErrorHandler(__CLASS__ . '::errorHandler');

            if (self::$DEBUG >= 2) {
                // Set query logging.
                $dbo->setLogger(__CLASS__ . '::logHandler');
            }

            /*
            if ( !empty(self::$SCHEMA) ) {
                if ( false === $dbo->query('USE ?#', self::$SCHEMA) ) {
                    self::$SCHEMA = null;
                }
            }
            */

            self::$DBO = $dbo;
        }

        return self::$DBO;

    } // connect

    /**
     * Handling DB errors here.
     *
     * @param string $message
     * @param array $info Array(
     *                      [code] => 2005
     *                      [message] => Unknown MySQL Server Host 'localhost1' (11001)
     *                      [query] => mysql_connect()
     *                      [context] => .../connect.php line 17
     *                    );
     * @throws Exception
     * @static
     */
    static function errorHandler($message, $info = null)
    {
        // If @ was used, do nothing.
        if (!error_reporting()) return;

        self::log("DB Error: {$message}\n", LOG_EMERG);

        if (self::$DEBUG >= 1) {
            if (self::$DEBUG >= 2)
                throw new Exception(var_export($info, true));
            else
                throw new Exception($message);
        } else {
            // Quotes used to not broke JSON-parsing anywhere.
            exit('"Fatal DB error occurred."');
        }

    } // errorHandler

    /**
     * DB query logging handler.
     *
     * @param $db DbSimple_Generic
     * @param $sql
     */
    static function logHandler($db, $sql)
    {
        // Calling context
        //$caller = $db->findLibraryCaller();
        //$context = @$caller['file'].' line '.@$caller['line'];
        //self::log($sql ."\t". $context . "\n", LOG_DEBUG);

        self::log($sql . "\n", LOG_DEBUG);
    } // logHandler

    /**
     * Logs passed message to log file.
     *
     * @param $msg
     * @param $priority (optional)
     * @static
     */
    public static function log($msg = null, $priority = LOG_WARNING)
    {
        if ( !empty($msg) )
            debuglog($msg, $priority);
    } // log


    /**
     * Returns DBSimple data type placeholder for specified field key.
     * @param $key
     */
    protected function t($key = null)
    {
        if ( empty($key) )
            return self::$typeMap[ $this->fields[$this->uidField] ];
        if ( isset($this->fields[$key]) )
            return self::$typeMap[$this->fields[$key]];

        return self::$typeMap[T_STRING];
    } // t


    /**
     * DoNotWantCamelCase → do_not_want_camel_case
     *
     * @param $word string
     * @return string
     */
    static function decamelize($word)
    {
        $word = preg_replace('#([A-Z\d]+)([A-Z][a-z])#','\1_\2', $word);
        $word = preg_replace('#([a-z\d])([A-Z])#', '\1_\2', $word);
        return strtolower(strtr($word, '-', '_'));

        // $word = preg_replace('/(?!^)[[:upper:]]+/', '-$0', $word);
        // $word = preg_replace('/(?!^)[[:upper:]][[:lower:]]/', '$0', $word);
        // return strtolower($word);
    } // decamelize

    /**
     * want_camel_case → WantCamelCase
     *
     * @param $word string
     * @return string
     */
    static function camelize($word)
    {
        $func = create_function('$c', 'return strtoupper($c[1]);');
        return preg_replace_callback('/_([a-z])/', $func, $word);
    } // camelize

} // ModelSimple class