<?php
/**
 * The Horde_Registry:: class provides a set of methods for communication
 * between Horde applications and keeping track of application
 * configuration information.
 *
 * Copyright 1999-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jon Parise <jon@horde.org>
 * @author  Anil Madhavapeddy <anil@recoil.org>
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Core
 */
class Horde_Registry
{
    /* Session flags. */
    const SESSION_NONE = 1;
    const SESSION_READONLY = 2;

    /* Error codes for pushApp(). */
    const AUTH_FAILURE = 1;
    const NOT_ACTIVE = 2;
    const PERMISSION_DENIED = 3;
    const HOOK_FATAL = 4;

    /**
     * Singleton value.
     *
     * @var Horde_Registry
     */
    static protected $_instance;

    /**
     * Cached information.
     *
     * @var array
     */
    protected $_cache = array();

    /**
     * The Horde_Cache object.
     *
     * @var Horde_Cache
     */
    protected $_cacheob;

    /**
     * The last modified time of the newest modified registry file.
     *
     * @var integer
     */
    protected $_regmtime;

    /**
     * Stack of in-use applications.
     *
     * @var array
     */
    protected $_appStack = array();

    /**
     * The list of APIs.
     *
     * @param array
     */
    protected $_apis = array();

    /**
     * Cached values of the image directories.
     *
     * @param array
     */
    protected $_imgDir = array();

    /**
     * Hash storing information on each registry-aware application.
     *
     * @var array
     */
    public $applications = array();

    /**
     * Returns a reference to the global Horde_Registry object, only creating
     * it if it doesn't already exist.
     *
     * This method must be invoked as:
     *   $registry = Horde_Registry::singleton()
     *
     * @param integer $session_flags  Any session flags.
     *
     * @return Horde_Registry  The Horde_Registry instance.
     * @throws Horde_Exception
     */
    static public function singleton($session_flags = 0)
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self($session_flags);
        }

        return self::$_instance;
    }

    /**
     * Create a new Horde_Registry instance.
     *
     * @param integer $session_flags  Any session flags.
     *
     * @throws Horde_Exception
     */
    protected function __construct($session_flags = 0)
    {
        /* Import and global Horde's configuration values. Almost a chicken
         * and egg issue - since loadConfiguration() uses registry in certain
         * instances. However, if HORDE_BASE is defined, and app is
         * 'horde', registry is not used in the method so we are free to
         * call it here (prevents us from duplicating a bunch of code
         * here. */
        $this->_cache['conf-horde'] = Horde::loadConfiguration('conf.php', 'conf', 'horde');
        $conf = $GLOBALS['conf'] = &$this->_cache['conf-horde'];

        /* Initial Horde-wide settings. */

        /* Set the maximum execution time in accordance with the config
         * settings. */
        error_reporting(0);
        set_time_limit($conf['max_exec_time']);

        /* Set the error reporting level in accordance with the config
         * settings. */
        error_reporting($conf['debug_level']);

        /* Set the umask according to config settings. */
        if (isset($conf['umask'])) {
            umask($conf['umask']);
        }

        /* Start a session. */
        if ($session_flags & self::SESSION_NONE ||
            (PHP_SAPI == 'cli') ||
            (((PHP_SAPI == 'cgi') || (PHP_SAPI == 'cgi-fcgi')) &&
             empty($_SERVER['SERVER_NAME']))) {
            /* Never start a session if the session flags include
               SESSION_NONE. */
            $_SESSION = array();
        } else {
            $this->setupSessionHandler();
            session_start();
            if ($session_flags & self::SESSION_READONLY) {
                /* Close the session immediately so no changes can be
                   made but values are still available. */
                session_write_close();
            }
        }

        /* Initialize the localization routines and variables. We can't use
         * Horde_Nls::setLanguageEnvironment() here because that depends on the
         * registry to be already initialized. */
        Horde_Nls::setLang();
        Horde_Nls::setTextdomain('horde', HORDE_BASE . '/locale', Horde_Nls::getCharset());
        Horde_String::setDefaultCharset(Horde_Nls::getCharset());

        /* Check for caching availability. Using cache while not authenticated
         * isn't possible because, although storage is possible, retrieval
         * isn't since there is no MD5 sum in the session to use to build
         * the cache IDs. */
        if (Horde_Auth::getAuth()) {
            try {
                $this->_cacheob = Horde_Cache::singleton($conf['cache']['driver'], Horde::getDriverConfig('cache', $conf['cache']['driver']));
            } catch (Horde_Exception $e) {
                // @TODO Log error
            }
        }

        $this->_regmtime = max(filemtime(HORDE_BASE . '/config/registry.php'),
                               filemtime(HORDE_BASE . '/config/registry.d'));

        $vhost = null;
        if (!empty($conf['vhosts'])) {
            $vhost = HORDE_BASE . '/config/registry-' . $conf['server']['name'] . '.php';
            if (file_exists($vhost)) {
                $this->_regmtime = max($this->_regmtime, filemtime($vhost));
            } else {
                $vhost = null;
            }
        }

        /* Always need to load applications information. */
        $this->_loadApplicationsCache($vhost);

        /* Stop system if Horde is inactive. */
        if ($this->applications['horde']['status'] == 'inactive') {
            throw new Horde_Exception(_("This system is currently deactivated."));
        }

        /* Create the global permissions object. */
        // TODO: Remove(?)
        $GLOBALS['perms'] = Horde_Perms::singleton();
    }

    /**
     * Stores cacheable member variables in the session at shutdown.
     */
    public function __destruct()
    {
        /* Register access key logger for translators. */
        if (!empty($GLOBALS['conf']['log_accesskeys'])) {
            Horde::getAccessKey(null, null, true);
        }

        /* Register memory tracker if logging in debug mode. */
        if (!empty($GLOBALS['conf']['log']['enabled']) &&
            ($GLOBALS['conf']['log']['priority'] == PEAR_LOG_DEBUG) &&
            function_exists('memory_get_peak_usage')) {
            Horde::logMessage('Max memory usage: ' . memory_get_peak_usage(true) . ' bytes', __FILE__, __LINE__, PEAR_LOG_DEBUG);
        }
    }

    /**
     * TODO
     */
    public function __get($api)
    {
        if (in_array($api, $this->listAPIs())) {
            return new Horde_Registry_Caller($this, $api);
        }
    }

    /**
     * Clone should never be called on this object. If it is, die.
     *
     * @throws Horde_Exception
     */
    public function __clone()
    {
        throw new Horde_Exception('Horde_Registry objects should never be cloned.');
    }

    /**
     * Clear the registry cache.
     */
    public function clearCache()
    {
        unset($_SESSION['_registry']);
        $this->_saveCacheVar('api', true);
        $this->_saveCacheVar('appcache', true);
    }

    /**
     * Fills the registry's application cache with application information.
     *
     * @param string $vhost  TODO
     */
    protected function _loadApplicationsCache($vhost)
    {
        /* First, try to load from cache. */
        if ($this->_loadCacheVar('appcache')) {
            $this->applications = $this->_cache['appcache'][0];
            $this->_cache['interfaces'] = $this->_cache['appcache'][1];
            return;
        }

        $this->_cache['interfaces'] = array();

        /* Read the registry configuration files. */
        require HORDE_BASE . '/config/registry.php';
        $files = glob(HORDE_BASE . '/config/registry.d/*.php');
        foreach ($files as $r) {
            include $r;
        }

        if ($vhost) {
            include $vhost;
        }

        /* Scan for all APIs provided by each app, and set other common
         * defaults like templates and graphics. */
        foreach (array_keys($this->applications) as $appName) {
            $app = &$this->applications[$appName];
            if ($app['status'] == 'heading') {
                continue;
            }

            if (isset($app['fileroot']) && !file_exists($app['fileroot'])) {
                $app['status'] = 'inactive';
            }

            if (($app['status'] != 'inactive') &&
                isset($app['provides']) &&
                (($app['status'] != 'admin') || Horde_Auth::isAdmin())) {
                if (is_array($app['provides'])) {
                    foreach ($app['provides'] as $interface) {
                        $this->_cache['interfaces'][$interface] = $appName;
                    }
                } else {
                    $this->_cache['interfaces'][$app['provides']] = $appName;
                }
            }

            if (!isset($app['templates']) && isset($app['fileroot'])) {
                $app['templates'] = $app['fileroot'] . '/templates';
            }
            if (!isset($app['jsuri']) && isset($app['webroot'])) {
                $app['jsuri'] = $app['webroot'] . '/js';
            }
            if (!isset($app['jsfs']) && isset($app['fileroot'])) {
                $app['jsfs'] = $app['fileroot'] . '/js';
            }
            if (!isset($app['themesuri']) && isset($app['webroot'])) {
                $app['themesuri'] = $app['webroot'] . '/themes';
            }
            if (!isset($app['themesfs']) && isset($app['fileroot'])) {
                $app['themesfs'] = $app['fileroot'] . '/themes';
            }
        }

        $this->_cache['appcache'] = array(
            // Index 0
            $this->applications,
            // Index 1
            $this->_cache['interfaces']
        );
        $this->_saveCacheVar('appcache');
    }

    /**
     * Fills the registry's API cache with the available external services.
     *
     * @throws Horde_Exception
     */
    protected function _loadApiCache()
    {
        /* First, try to load from cache. */
        if ($this->_loadCacheVar('api')) {
            return;
        }

        /* Generate api/type cache. */
        $status = array('active', 'notoolbar', 'hidden');
        if (Horde_Auth::isAdmin()) {
            $status[] = 'admin';
        }

        $this->_cache['api'] = array();

        foreach (array_keys($this->applications) as $app) {
            if (in_array($this->applications[$app]['status'], $status)) {
                try {
                    $api = $this->_getApiInstance($app, 'api');
                    $this->_cache['api'][$app] = array(
                        'api' => array_diff(get_class_methods($api), array('__construct'), $api->disabled),
                        'links' => $api->links,
                        'noperms' => $api->noPerms
                    );
                } catch (Horde_Exception $e) {
                    Horde::logMessage($e->getMessage(), __FILE__, __LINE__, PEAR_LOG_DEBUG);
                }
            }
        }

        $this->_saveCacheVar('api');
    }

    /**
     * Retrieve an API object.
     *
     * @param string $app   The application to load.
     * @param string $type  Either 'application' or 'api'.
     *
     * @return Horde_Registry_Api|Horde_Registry_Application  The API object.
     * @throws Horde_Exception
     */
    protected function _getApiInstance($app, $type)
    {
        if (isset($this->_cache['ob'][$app][$type])) {
            return $this->_cache['ob'][$app][$type];
        }

        $cname = Horde_String::ucfirst($type);

        /* Can't autoload here, since the application may not have been
         * initialized yet. */
        $classname = Horde_String::ucfirst($app) . '_' . $cname;
        $path = $this->get('fileroot', $app) . '/lib/' . $cname . '.php';
        if (file_exists($path)) {
            include_once $path;
        } else {
            $classname = 'Horde_Registry_' . $cname;
        }

        if (!class_exists($classname, false)) {
            throw new Horde_Exception("$app does not have an API");
        }

        $this->_cache['ob'][$app][$type] = new $classname;
        return $this->_cache['ob'][$app][$type];
    }

    /**
     * Return a list of the installed and registered applications.
     *
     * @param array $filter   An array of the statuses that should be
     *                        returned. Defaults to non-hidden.
     * @param boolean $assoc  Associative array with app names as keys.
     * @param integer $perms  The permission level to check for in the list.
     *
     * @return array  List of apps registered with Horde. If no
     *                applications are defined returns an empty array.
     */
    public function listApps($filter = null, $assoc = false,
                             $perms = Horde_Perms::SHOW)
    {
        $apps = array();
        if (is_null($filter)) {
            $filter = array('notoolbar', 'active');
        }

        foreach ($this->applications as $app => $params) {
            if (in_array($params['status'], $filter) &&
                $this->hasPermission($app, $perms)) {
                $apps[$app] = $app;
            }
        }

        return $assoc ? $apps : array_values($apps);
    }

    /**
     * Returns all available registry APIs.
     *
     * @return array  The API list.
     */
    public function listAPIs()
    {
        if (empty($this->_apis)) {
            foreach (array_keys($this->_cache['interfaces']) as $interface) {
                list($api,) = explode('/', $interface, 2);
                $this->_apis[$api] = true;
            }
        }

        return array_keys($this->_apis);
    }

    /**
     * Returns all of the available registry methods, or alternately
     * only those for a specified API.
     *
     * @param string $api  Defines the API for which the methods shall be
     *                     returned.
     *
     * @return array  The method list.
     */
    public function listMethods($api = null)
    {
        $methods = array();

        $this->_loadApiCache();

        foreach (array_keys($this->applications) as $app) {
            if (isset($this->applications[$app]['provides'])) {
                $provides = $this->applications[$app]['provides'];
                if (!is_array($provides)) {
                    $provides = array($provides);
                }
                foreach ($provides as $method) {
                    if (strpos($method, '/') !== false) {
                        if (is_null($api) ||
                            (substr($method, 0, strlen($api)) == $api)) {
                            $methods[$method] = true;
                        }
                    } elseif (is_null($api) || ($method == $api)) {
                        if (isset($this->_cache['api'][$app])) {
                            foreach ($this->_cache['api'][$app]['api'] as $service) {
                                $methods[$method . '/' . $service] = true;
                            }
                        }
                    }
                }
            }
        }

        return array_keys($methods);
    }

    /**
     * Determine if an interface is implemented by an active application.
     *
     * @param string $interface  The interface to check for.
     *
     * @return mixed  The application implementing $interface if we have it,
     *                false if the interface is not implemented.
     */
    public function hasInterface($interface)
    {
        return !empty($this->_cache['interfaces'][$interface]) ?
            $this->_cache['interfaces'][$interface] :
            false;
    }

    /**
     * Determine if a method has been registered with the registry.
     *
     * @param string $method  The full name of the method to check for.
     * @param string $app     Only check this application.
     *
     * @return mixed  The application implementing $method if we have it,
     *                false if the method doesn't exist.
     */
    public function hasMethod($method, $app = null)
    {
        if (is_null($app)) {
            list($interface, $call) = explode('/', $method, 2);
            if (!empty($this->_cache['interfaces'][$method])) {
                $app = $this->_cache['interfaces'][$method];
            } elseif (!empty($this->_cache['interfaces'][$interface])) {
                $app = $this->_cache['interfaces'][$interface];
            } else {
                return false;
            }
        } else {
            $call = $method;
        }

        $this->_loadApiCache();

        return (isset($this->_cache['api'][$app]) && in_array($call, $this->_cache['api'][$app]['api']))
            ? $app
            : false;
    }

    /**
     * Determine if an application method exists for a given application.
     *
     * @param string $app     The application name.
     * @param string $method  The full name of the method to check for.
     *
     * @return boolean  Existence of the method.
     */
    public function hasAppMethod($app, $method)
    {
        try {
            $appob = $this->_getApiInstance($app, 'application');
        } catch (Horde_Exception $e) {
            return false;
        }
        return (method_exists($appob, $method) && !in_array($method, $appob->disabled));
    }

    /**
     * Return the hook corresponding to the default package that
     * provides the functionality requested by the $method
     * parameter. $method is a string consisting of
     * "packagetype/methodname".
     *
     * @param string $method  The method to call.
     * @param array $args     Arguments to the method.
     *
     * @return mixed  TODO
     * @throws Horde_Exception
     */
    public function call($method, $args = array())
    {
        list($interface, $call) = explode('/', $method, 2);

        if (!empty($this->_cache['interfaces'][$method])) {
            $app = $this->_cache['interfaces'][$method];
        } elseif (!empty($this->_cache['interfaces'][$interface])) {
            $app = $this->_cache['interfaces'][$interface];
        } else {
            throw new Horde_Exception('The method "' . $method . '" is not defined in the Horde Registry.');
        }

        return $this->callByPackage($app, $call, $args);
    }

    /**
     * Output the hook corresponding to the specific package named.
     *
     * @param string $app   The application being called.
     * @param string $call  The method to call.
     * @param array $args   Arguments to the method.
     *
     * @return mixed  TODO
     * @throws Horde_Exception
     */
    public function callByPackage($app, $call, $args = array())
    {
        /* Note: calling hasMethod() makes sure that we've cached
         * $app's services and included the API file, so we don't try
         * to do it again explicitly in this method. */
        if (!$this->hasMethod($call, $app)) {
            throw new Horde_Exception(sprintf('The method "%s" is not defined in the API for %s.', $call, $app));
        }

        /* Load the API now. */
        $api = $this->_getApiInstance($app, 'api');

        /* Make sure that the function actually exists. */
        if (!method_exists($api, $call)) {
            throw new Horde_Exception('The function implementing ' . $call . ' is not defined in ' . $app . '\'s API.');
        }

        /* Switch application contexts now, if necessary, before
         * including any files which might do it for us. Return an
         * error immediately if pushApp() fails. */
        $pushed = $this->pushApp($app, array('check_perms' => !in_array($call, $this->_cache['api'][$app]['noperms'])));

        try {
            $result = call_user_func_array(array($api, $call), $args);
        } catch (Horde_Exception $e) {
            $result = $e;
        }

        /* If we changed application context in the course of this
         * call, undo that change now. */
        if ($pushed === true) {
            $this->popApp();
        }

        if ($result instanceof Exception) {
            throw $e;
        }
        if (is_a($result, 'PEAR_Error')) {
            throw new Horde_Exception($result);
        }

        return $result;
    }

    /**
     * Call a private Horde application method.
     *
     * @param string $app     The application name.
     * @param string $call    The method to call.
     * @param array $options  Additional options:
     * <pre>
     * 'args' - (array) Additional parameters to pass to the method.
     * 'noperms' - (boolean) If true, don't check the perms.
     * </pre>
     *
     * @return mixed  Various. Returns null if the method doesn't exist.
     * @throws Horde_Exception  Application methods should throw this if there
     *                          is a fatal error.
     */
    public function callAppMethod($app, $call, $options = array())
    {
        /* Make sure that the method actually exists. */
        if (!$this->hasAppMethod($app, $call)) {
            return null;
        }

        /* Load the API now. */
        $api = $this->_getApiInstance($app, 'application');

        /* Switch application contexts now, if necessary, before
         * including any files which might do it for us. Return an
         * error immediately if pushApp() fails. */
        $pushed = $this->pushApp($app, array('check_perms' => empty($options['noperms'])));

        try {
            $result = call_user_func_array(array($api, $call), empty($options['args']) ? array() : $options['args']);
        } catch (Horde_Exception $e) {
            $result = $e;
        }

        /* If we changed application context in the course of this
         * call, undo that change now. */
        if ($pushed === true) {
            $this->popApp();
        }

        if ($result instanceof Exception) {
            throw $e;
        }

        return $result;
    }

    /**
     * Return the hook corresponding to the default package that
     * provides the functionality requested by the $method
     * parameter. $method is a string consisting of
     * "packagetype/methodname".
     *
     * @param string $method  The method to link to.
     * @param array $args     Arguments to the method.
     * @param mixed $extra    Extra, non-standard arguments to the method.
     *
     * @return mixed  TODO
     * @throws Horde_Exception
     */
    public function link($method, $args = array(), $extra = '')
    {
        list($interface, $call) = explode('/', $method, 2);

        if (!empty($this->_cache['interfaces'][$method])) {
            $app = $this->_cache['interfaces'][$method];
        } elseif (!empty($this->_cache['interfaces'][$interface])) {
            $app = $this->_cache['interfaces'][$interface];
        } else {
            throw new Horde_Exception('The method "' . $method . '" is not defined in the Horde Registry.');
        }

        return $this->linkByPackage($app, $call, $args, $extra);
    }

    /**
     * Output the hook corresponding to the specific package named.
     *
     * @param string $app   The application being called.
     * @param string $call  The method to link to.
     * @param array $args   Arguments to the method.
     * @param mixed $extra  Extra, non-standard arguments to the method.
     *
     * @return mixed  TODO
     * @throws Horde_Exception
     */
    public function linkByPackage($app, $call, $args = array(), $extra = '')
    {
        /* Make sure the link is defined. */
        $this->_loadApiCache();
        if (empty($this->_cache['api'][$app]['links'][$call])) {
            throw new Horde_Exception('The link ' . $call . ' is not defined in ' . $app . '\'s API.');
        }

        /* Initial link value. */
        $link = $this->_cache['api'][$app]['links'][$call];

        /* Fill in html-encoded arguments. */
        foreach ($args as $key => $val) {
            $link = str_replace('%' . $key . '%', htmlentities($val), $link);
        }
        if (isset($this->applications[$app]['webroot'])) {
            $link = str_replace('%application%', $this->get('webroot', $app), $link);
        }

        /* Replace htmlencoded arguments that haven't been specified with
           an empty string (this is where the default would be substituted
           in a stricter registry implementation). */
        $link = preg_replace('|%.+%|U', '', $link);

        /* Fill in urlencoded arguments. */
        foreach ($args as $key => $val) {
            $link = str_replace('|' . Horde_String::lower($key) . '|', urlencode($val), $link);
        }

        /* Append any extra, non-standard arguments. */
        if (is_array($extra)) {
            $extra_args = '';
            foreach ($extra as $key => $val) {
                $extra_args .= '&' . urlencode($key) . '=' . urlencode($val);
            }
        } else {
            $extra_args = $extra;
        }
        $link = str_replace('|extra|', $extra_args, $link);

        /* Replace html-encoded arguments that haven't been specified with
           an empty string (this is where the default would be substituted
           in a stricter registry implementation). */
        $link = preg_replace('|\|.+\||U', '', $link);

        return $link;
    }

    /**
     * Replace any %application% strings with the filesystem path to the
     * application.
     *
     * @param string $path  The application string.
     * @param string $app   The application being called.
     *
     * @return string  The application file path.
     * @throws Horde_Exception
     */
    public function applicationFilePath($path, $app = null)
    {
        if (is_null($app)) {
            $app = $this->getApp();
        }

        if (!isset($this->applications[$app])) {
            throw new Horde_Exception(sprintf(_("\"%s\" is not configured in the Horde Registry."), $app));
        }

        return str_replace('%application%', $this->applications[$app]['fileroot'], $path);
    }

    /**
     * Replace any %application% strings with the web path to the application.
     *
     * @param string $path  The application string.
     * @param string $app   The application being called.
     *
     * @return string  The application web path.
     */
    public function applicationWebPath($path, $app = null)
    {
        if (!isset($app)) {
            $app = $this->getApp();
        }

        return str_replace('%application%', $this->applications[$app]['webroot'], $path);
    }

    /**
     * Set the current application, adding it to the top of the Horde
     * application stack. If this is the first application to be
     * pushed, retrieve session information as well.
     *
     * pushApp() also reads the application's configuration file and
     * sets up its global $conf hash.
     *
     * @param string $app          The name of the application to push.
     * @param array $options       Additional options:
     * <pre>
     * 'check_perms' - (boolean) Make sure that the current user has
     *                 permissions to the application being loaded. Should
     *                 ONLY be disabled by system scripts (cron jobs, etc.)
     *                 and scripts that handle login.
     *                 DEFAULT: true
     * 'init' - (boolean) Init the application (by either loading the
     *          application's base.php file (deprecated) or calling init()
     *          on the Application object)?
     *          DEFAULT: false
     * 'logintasks' - (boolean) Perform login tasks? Only performed if
     *                'check_perms' is also true. System tasks are always
     *                peformed if the user is authorized.
     *                DEFAULT: false
     * </pre>
     *
     * @return boolean  Whether or not the _appStack was modified.
     * @throws Horde_Exception
     *         Code can be one of the following:
     *         Horde_Registry::AUTH_FAILURE
     *         Horde_Registry::NOT_ACTIVE
     *         Horde_Registry::PERMISSION_DENIED
     *         Horde_Registry::HOOK_FATAL
     */
    public function pushApp($app, $options = array())
    {
        if ($app == $this->getApp()) {
            return false;
        }

        /* Bail out if application is not present or inactive. */
        if (!isset($this->applications[$app]) ||
            $this->applications[$app]['status'] == 'inactive' ||
            ($this->applications[$app]['status'] == 'admin' && !Horde_Auth::isAdmin())) {
            throw new Horde_Exception($app . ' is not activated.', self::NOT_ACTIVE);
        }

        $checkPerms = !isset($options['check_perms']) || !empty($options['check_perms']);

        /* If permissions checking is requested, return an error if the
         * current user does not have read perms to the application being
         * loaded. We allow access:
         *  - To all admins.
         *  - To all authenticated users if no permission is set on $app.
         *  - To anyone who is allowed by an explicit ACL on $app. */
        if ($checkPerms && !$this->hasPermission($app, Horde_Perms::READ)) {
            if (!Horde_Auth::isAuthenticated(array('app' => $app))) {
                throw new Horde_Exception('User is not authorized', self::AUTH_FAILURE);
            }

            Horde::logMessage(sprintf('%s does not have READ permission for %s', Horde_Auth::getAuth() ? 'User ' . Horde_Auth::getAuth() : 'Guest user', $app), __FILE__, __LINE__, PEAR_LOG_DEBUG);
            throw new Horde_Exception(sprintf(_('%s is not authorized for %s.'), Horde_Auth::getAuth() ? 'User ' . Horde_Auth::getAuth() : 'Guest user', $this->applications[$app]['name']), self::PERMISSION_DENIED);
        }

        /* Set up autoload paths for the current application. This needs to
         * be done here because it is possible to try to load app-specific
         * libraries from other applications. */
        $app_lib = $this->get('fileroot', $app) . '/lib';
        Horde_Autoloader::addClassPattern('/^' . $app . '(?:$|_)/i', $app_lib);

        /* Chicken and egg problem: the language environment has to be loaded
         * before loading the configuration file, because it might contain
         * gettext strings. Though the preferences can specify a different
         * language for this app, the have to be loaded after the
         * configuration, because they rely on configuration settings. So try
         * with the current language, and reset the language later. */
        Horde_Nls::setLanguageEnvironment($GLOBALS['language'], $app);

        /* Import this application's configuration values. */
        $this->importConfig($app);

        /* Load preferences after the configuration has been loaded to make
         * sure the prefs file has all the information it needs. */
        $this->loadPrefs($app);

        /* Reset the language in case there is a different one selected in the
         * preferences. */
        $language = '';
        if (isset($GLOBALS['prefs'])) {
            $language = $GLOBALS['prefs']->getValue('language');
            if ($language != $GLOBALS['language']) {
                Horde_Nls::setLanguageEnvironment($language, $app);
            }
        }

        /* Once we know everything succeeded and is in a consistent state
         * again, push the new application onto the stack. */
        $this->_appStack[] = $app;

        /* Call post-push hook. */
        try {
            Horde::callHook('pushapp', array(), $app);
        } catch (Horde_Exception $e) {
            $e->setCode(self::HOOK_FATAL);
            $this->popApp();
            throw $e;
        } catch (Horde_Exception_HookNotSet $e) {}

        /* Initialize application. */
        if ($checkPerms || !empty($options['init'])) {
            try {
                if (file_exists($app_lib . '/base.php')) {
                    // TODO: Remove once there is no more base.php files
                    require_once $app_lib . '/base.php';
                } else {
                    $this->callAppMethod($app, 'init');
                }
            } catch (Horde_Exception $e) {
                $this->popApp();
                throw $e;
            }
        }

        /* Do login tasks. */
        if ($checkPerms) {
            $tasks = Horde_LoginTasks::singleton($app);
            if (!empty($options['logintasks'])) {
                $tasks->runTasks(false, Horde::selfUrl(true, true, true));
            }
        }

        return true;
    }

    /**
     * Remove the current app from the application stack, setting the current
     * app to whichever app was current before this one took over.
     *
     * @return string  The name of the application that was popped.
     * @throws Horde_Exception
     */
    public function popApp()
    {
        /* Pop the current application off of the stack. */
        $previous = array_pop($this->_appStack);

        /* Import the new active application's configuration values
         * and set the gettext domain and the preferred language. */
        $app = $this->getApp();
        if ($app) {
            $this->importConfig($app);
            $this->loadPrefs($app);
            $language = $GLOBALS['prefs']->getValue('language');
            Horde_Nls::setLanguageEnvironment($language, $app);
        }

        return $previous;
    }

    /**
     * Return the current application - the app at the top of the application
     * stack.
     *
     * @return string  The current application.
     */
    public function getApp()
    {
        return end($this->_appStack);
    }

    /**
     * Check permissions on an application.
     *
     * @param string $app     The name of the application
     * @param integer $perms  The permission level to check for.
     *
     * @return boolean  Whether access is allowed.
     */
    public function hasPermission($app, $perms = Horde_Perms::READ)
    {
        /* Always do isAuthenticated() check first. You can be an admin, but
         * application auth != Horde admin auth. And there can *never* be
         * non-SHOW access to an application that requires authentication. */
        if (!Horde_Auth::isAuthenticated(array('app' => $app)) &&
            Horde_Auth::requireAuth($app) &&
            ($perms != Horde_Perms::SHOW)) {
            return false;
        }

        /* Otherwise, allow access for admins, for apps that do not have any
         * have any explicit permissions, or for apps that allow the given
         * permission. */
        return Horde_Auth::isAdmin() ||
            !$GLOBALS['perms']->exists($app) ||
            $GLOBALS['perms']->hasPermission($app, Horde_Auth::getAuth(), $perms);
    }

    /**
     * Reads the configuration values for the given application and imports
     * them into the global $conf variable.
     *
     * @param string $app  The name of the application.
     */
    public function importConfig($app)
    {
        if (($app != 'horde') && !$this->_loadCacheVar('conf-' . $app)) {
            $appConfig = Horde::loadConfiguration('conf.php', 'conf', $app);
            if (empty($appConfig)) {
                $appConfig = array();
            }
            $this->_cache['conf-' . $app] = Horde_Array::array_merge_recursive_overwrite($this->_cache['conf-horde'], $appConfig);
            $this->_saveCacheVar('conf-' . $app);
        }

        $GLOBALS['conf'] = &$this->_cache['conf-' . $app];
    }

    /**
     * Loads the preferences for the current user for the current application
     * and imports them into the global $prefs variable.
     *
     * @param string $app  The name of the application.
     */
    public function loadPrefs($app = null)
    {
        if (is_null($app)) {
            $app = $this->getApp();
        }

        /* If there is no logged in user, return an empty Horde_Prefs::
         * object with just default preferences. */
        if (!Horde_Auth::getAuth()) {
            $GLOBALS['prefs'] = Horde_Prefs::factory('Session', $app, '', '', null, false);
        } else {
            if (!isset($GLOBALS['prefs']) ||
                ($GLOBALS['prefs']->getUser() != Horde_Auth::getAuth())) {
                $GLOBALS['prefs'] = Horde_Prefs::factory($GLOBALS['conf']['prefs']['driver'], $app, Horde_Auth::getAuth(), Horde_Auth::getCredential('password'));
            } else {
                $GLOBALS['prefs']->retrieve($app);
            }
        }
    }

    /**
     * Unload preferences from an application or (if no application is
     * specified) from ALL applications. Useful when a user has logged
     * out but you need to continue on the same page, etc.
     *
     * After unloading, if there is an application on the app stack to
     * load preferences from, then we reload a fresh set.
     *
     * @param string $app  The application to unload prefrences for. If null,
     *                     ALL preferences are reset.
     */
    public function unloadPrefs($app = null)
    {
        // TODO: $app not being used?
        if ($this->getApp()) {
            $this->loadPrefs();
        }
    }

    /**
     * Return the requested configuration parameter for the specified
     * application. If no application is specified, the value of
     * the current application is used. However, if the parameter is not
     * present for that application, the Horde-wide value is used instead.
     * If that is not present, we return null.
     *
     * @param string $parameter  The configuration value to retrieve.
     * @param string $app        The application to get the value for.
     *
     * @return string  The requested parameter, or null if it is not set.
     */
    public function get($parameter, $app = null)
    {
        if (is_null($app)) {
            $app = $this->getApp();
        }

        if (isset($this->applications[$app][$parameter])) {
            $pval = $this->applications[$app][$parameter];
        } else {
            $pval = ($parameter == 'icon')
                ? $this->getImageDir($app) . '/' . $app . '.png'
                : (isset($this->applications['horde'][$parameter]) ? $this->applications['horde'][$parameter] : null);
        }

        return ($parameter == 'name')
            ? _($pval)
            : $pval;
    }

    /**
     * Return the version string for a given application.
     *
     * @param string $app  The application to get the value for.
     *
     * @return string  The version string for the application.
     */
    public function getVersion($app = null)
    {
        if (empty($app)) {
            $app = $this->getApp();
        }

        try {
            $api = $this->_getApiInstance($app, 'application');
            return $api->version;
        } catch (Horde_Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Does the given application have a mobile view?
     *
     * @param string $app  The application to check.
     *
     * @return boolean  Whether app has mobile view.
     */
    public function hasMobileView($app = null)
    {
        if (empty($app)) {
            $app = $this->getApp();
        }

        try {
            $api = $this->_getApiInstance($app, 'application');
            return $api->mobileView;
        } catch (Horde_Exception $e) {
            return false;
        }
    }

    /**
     * Function to work out an application's graphics URI, optionally taking
     * into account any themes directories that may be set up.
     *
     * @param string $app        The application for which to get the image
     *                           directory. If blank will default to current
     *                           application.
     * @param boolean $usetheme  Take into account any theme directory?
     *
     * @return string  The image directory uri path.
     */
    public function getImageDir($app = null, $usetheme = true)
    {
        if (empty($app)) {
            $app = $this->getApp();
        }

        if ($this->get('status', $app) == 'heading') {
            $app = 'horde';
        }

        $sig = strval($app . '|' . $usetheme);

        if (isset($this->_imgDir[$sig])) {
            return $this->_imgDir[$sig];
        }

        /* This is the default location for the graphics. */
        $this->_imgDir[$sig] = $this->get('themesuri', $app) . '/graphics';

        /* Figure out if this is going to be overridden by any theme
         * settings. */
        if ($usetheme &&
            isset($GLOBALS['prefs']) &&
            ($theme = $GLOBALS['prefs']->getValue('theme'))) {
            /* Since theme information is so limited, store directly in the
             * session. */
            if (!isset($_SESSION['_registry']['theme'][$theme][$app])) {
                $_SESSION['_registry']['theme'][$theme][$app] = file_exists($this->get('themesfs', $app) . '/' . $theme . '/themed_graphics');
            }

            if ($_SESSION['_registry']['theme'][$theme][$app]) {
                $this->_imgDir[$sig] = $this->get('themesuri', $app) . '/' . $theme . '/graphics';
            }
        }

        return $this->_imgDir[$sig];
    }

    /**
     * Query the initial page for an application - the webroot, if there is no
     * initial_page set, and the initial_page, if it is set.
     *
     * @param string $app  The name of the application.
     *
     * @return string  URL pointing to the initial page of the application.
     * @throws Horde_Exception
     */
    public function getInitialPage($app = null)
    {
        if (is_null($app)) {
            $app = $this->getApp();
        }

        if (isset($this->applications[$app])) {
            return $this->applications[$app]['webroot'] . '/' . (isset($this->applications[$app]['initial_page']) ? $this->applications[$app]['initial_page'] : '');
        }

        throw new Horde_Exception(sprintf(_("\"%s\" is not configured in the Horde Registry."), $app));
    }

    /**
     * Saves a cache variable.
     *
     * @param string $name     Cache variable name.
     * @param boolean $expire  Expire the entry?
     */
    protected function _saveCacheVar($name, $expire = false)
    {
        if ($this->_cacheob) {
            if ($expire) {
                if ($id = $this->_getCacheId($name)) {
                    $this->_cacheob->expire($id);
                }
            } else {
                $data = serialize($this->_cache[$name]);
                $_SESSION['_registry']['md5'][$name] = $md5sum = hash('md5', $data);
                $id = $this->_getCacheId($name, false) . '|' . $md5sum;
                if ($this->_cacheob->set($id, $data, 86400)) {
                    Horde::logMessage('Horde_Registry: stored ' . $name . ' with cache ID ' . $id, __FILE__, __LINE__, PEAR_LOG_DEBUG);
                }
            }
        }
    }

    /**
     * Retrieves a cache variable.
     *
     * @param string $name  Cache variable name.
     *
     * @return boolean  True if value loaded from cache.
     */
    protected function _loadCacheVar($name)
    {
        if (isset($this->_cache[$name])) {
            return true;
        }

        if ($this->_cacheob &&
            ($id = $this->_getCacheId($name))) {
            $result = $this->_cacheob->get($id, 86400);
            if ($result !== false) {
                $this->_cache[$name] = unserialize($result);
                Horde::logMessage('Horde_Registry: retrieved ' . $name . ' with cache ID ' . $id, __FILE__, __LINE__, PEAR_LOG_DEBUG);
                return true;
            }
        }

        return false;
    }

    /**
     * Get the cache storage ID for a particular cache name.
     *
     * @param string $name  Cache variable name.
     * @param string $md5   Append MD5 value?
     *
     * @return mixed  The cache ID or false if cache entry doesn't exist in
     *                the session.
     */
    protected function _getCacheId($name, $md5 = true)
    {
        $id = 'horde_registry_' . $name . '|' . $this->_regmtime;

        if (!$md5) {
            return $id;
        } elseif (isset($_SESSION['_registry']['md5'][$name])) {
            return $id . '|' . $_SESSION['_registry']['md5'][$name];
        }

        return false;
    }

    /**
     * Sets a custom session handler up, if there is one.
     * If the global variable 'session_cache_limiter' is defined, its value
     * will override the cache limiter setting found in the configuration
     * file.
     *
     * The custom session handler object will be contained in the global
     * 'horde_sessionhandler' variable.
     *
     * @throws Horde_Exception
     */
    public function setupSessionHandler()
    {
        global $conf;

        ini_set('url_rewriter.tags', 0);
        if (!empty($conf['session']['use_only_cookies'])) {
            ini_set('session.use_only_cookies', 1);
            if (!empty($conf['cookie']['domain']) &&
                strpos($conf['server']['name'], '.') === false) {
                throw new Horde_Exception('Session cookies will not work without a FQDN and with a non-empty cookie domain. Either use a fully qualified domain name like "http://www.example.com" instead of "http://example" only, or set the cookie domain in the Horde configuration to an empty value, or enable non-cookie (url-based) sessions in the Horde configuration.');
            }
        }

        session_set_cookie_params($conf['session']['timeout'],
                                  $conf['cookie']['path'], $conf['cookie']['domain'], $conf['use_ssl'] == 1 ? 1 : 0);
        session_cache_limiter(Horde_Util::nonInputVar('session_cache_limiter', $conf['session']['cache_limiter']));
        session_name(urlencode($conf['session']['name']));

        $type = empty($conf['sessionhandler']['type'])
            ? 'none'
            : $conf['sessionhandler']['type'];

        if ($type == 'external') {
            $calls = $conf['sessionhandler']['params'];
            session_set_save_handler($calls['open'],
                                     $calls['close'],
                                     $calls['read'],
                                     $calls['write'],
                                     $calls['destroy'],
                                     $calls['gc']);
        } elseif ($type != 'none') {
            $sh = Horde_SessionHandler::singleton($conf['sessionhandler']['type'], array_merge(Horde::getDriverConfig('sessionhandler', $conf['sessionhandler']['type']), array('memcache' => !empty($conf['sessionhandler']['memcache']))));
            ini_set('session.save_handler', 'user');
            session_set_save_handler(array(&$sh, 'open'),
                                     array(&$sh, 'close'),
                                     array(&$sh, 'read'),
                                     array(&$sh, 'write'),
                                     array(&$sh, 'destroy'),
                                     array(&$sh, 'gc'));
            $GLOBALS['horde_sessionhandler'] = $sh;
        }
    }

    /**
     * Destroys any existing session on login and make sure to use a new
     * session ID, to avoid session fixation issues. Should be called before
     * checking a login.
     */
    public function getCleanSession()
    {
        // Make sure to force a completely new session ID and clear all
        // session data.
        session_regenerate_id(true);
        session_unset();

        /* Reset cookie timeouts, if necessary. */
        if (!empty($GLOBALS['conf']['session']['timeout'])) {
            $app = $this->getApp();
            if (Horde_Secret::clearKey($app)) {
                Horde_Secret::setKey($app);
            }
            Horde_Secret::setKey('auth');
        }
    }

}
