<?php
namespace Webity\Rest\Application;

use Joomla\Application\AbstractWebApplication;
use Joomla\Application\Web;
use Joomla\Session\Session;
use Joomla\Registry\Registry;
use Joomla\Database;
use Webity\Rest\Input\Router;
use Webity\Rest\Oauth2\Server as OauthServer;

class Api extends AbstractWebApplication
{

	// Override a few core variables to make this work for REST purposes

	public $mimeType = 'application/json';
	protected static $instances = array();
	protected $db = null;
	protected $user = null;
	protected $debug = false;
	protected $debug_values = array();
	protected $namespaces = array('Webity\\Rest');
	protected $objects = array('Objects');

	public function __construct(Input $input = null, Registry $config = null, Web\WebClient $client = null)
	{
		if (is_null($config)) {
			$config = $this->loadConfiguration();
		}

		if ($config->get('debug')) {
			$this->debug = true;
			$this->markDebug('Start');
		}

		parent::__construct($input, $config, $client);

		// We are going to make the body an object instead of an array.
		$this->response->body = new \stdClass;
	}

	protected function markDebug($msg) {
		if (!$this->debug) {
			return;
		}

		$array = array();
		$array['message'] = $msg;
		$array['time'] = microtime(true);

		if (isset($this->debug_values[0]['time']) && ($start = $this->debug_values[0]['time'])) {
			$array['elapsed'] = $array['time'] - $start;
		}

		$this->debug_values[] = $array;

		return true;
	}

	protected function loadConfiguration() {
		$registry = Registry::getInstance(1);

		if (file_exists(JPATH_ROOT . '/config.inc.php')) {
			$registry->loadFile(JPATH_ROOT . '/config.inc.php');
		}

		return $registry;
	}

	protected function initialiseDbo()
    {
        // Make the database driver.
        $dbFactory = new Database\DatabaseFactory;

        $this->db = $dbFactory->getDriver(
            $this->get('database.driver'),
            array(
                'host' => $this->get('database.host'),
                'user' => $this->get('database.user'),
                'password' => $this->get('database.password'),
                'database' => $this->get('database.name'),
                'prefix' => $this->get('database.prefix', 'jos_'),
            )
        );
	}

	public static function getInstance($id = 1)
	{
		if (empty(self::$instances[$id]))
		{
			// use static instead of self to return an instance of the class that was called
			self::$instances[$id] = new static;
		}

		return self::$instances[$id];
	}

	public function getDbo() {
		if (is_null($this->db)) {
			$this->initialiseDbo();
		}

		return $this->db;
	}

	// replace response with just content
	public function setBody($content)
	{
		$this->response->body = $content;

		return $this;
	}

	// merges objects keeping current values if already set
	public function prependBody($content)
	{
		if (is_array($content) || is_object($content)) {
			foreach ($content as $key => $val) {
				if (!$this->response->body->$key) {
					$this->response->body->$key = $val;
				}
			}
		}

		return $this;
	}

	// merges objects overwriting already set values
	public function appendBody($content)
	{
		if (is_array($content) || is_object($content)) {
			foreach ($content as $key => $val) {
				$this->response->body->$key = $val;
			}
		}

		return $this;
	}

	public function getBody()
	{
		return json_encode($this->response->body);
	}

	public function authenticate() {
		$this->markDebug('Start Authentication');
		try {
			$path = $this->get('uri.route');

			if (strpos($path, 'oauth') !== FALSE) {
				// time to route the application
				$this->route();

				// object should be 'oauth'
				// id will contain the action

				$server = OauthServer::getInstance();

				switch ($_REQUEST['id']) {
					case 'password':
					case 'token':
						$server->handleToken();
						break;
					case 'resource':
						$server->handleResource();
						break;
					case 'authorize':
						$server->authorize();
						break;
					default:
						throw new \Exception("Malformed oauth request", 400);
						break;
				}
			} elseif ($this->input->get('access_token', '')) {
				// validate the access through the access_token
				$server = OauthServer::getInstance();
				$data = $server->handleResource();

			    $db = $this->getDbo();
			    $query = $db->getQuery(true);

				$query->select('*')
			    	->from('#__' . $this->get('users_table', 'oauth_users'))
			    	->where('userName = ' . $db->quote($data['user_id']));

			    $user = $db->setQuery($query, 0, 1)->loadObject();

			    if (!$user) {
			    	throw new \Exception('User not found.', 401);
			    }

			    $this->setUser($user);
			} else {
				if (isset($_SERVER['PHP_AUTH_USER'])) {
				    $username = $_SERVER['PHP_AUTH_USER'];
				    $password = $_SERVER['PHP_AUTH_PW'];

				    $db = $this->getDbo();
				    $query = $db->getQuery(true);

				    $query->select('*')
				    	->from('#__' . $this->get('users_table', 'oauth_users'))
				    	->where('userName = ' . $db->quote($username));

				    $user = $db->setQuery($query, 0, 1)->loadObject();

				    if (!$user) {
				    	throw new \Exception('Invalid username or password.', 401);
				    }

				    $crypt = new \Joomla\Crypt\Password\Simple();
				    $auth = $crypt->verify($password, $user->password);

				    if (!$auth) {
				    	throw new \Exception('Invalid username or password.', 401);
				    }

				    $this->setUser($user);
				} else {
					$this->header('WWW-Authenticate: Basic realm="MIMIC API"');
				    throw new \Exception('Authentication required to access system.', 401);
				} 
			}
		} catch (\Exception $e) {
			$this->raiseError($e->getMessage(), $e->getCode());
		}

		$this->markDebug('Complete Authentication');
		return $this;
	}

	public function setUser($user) {
		$this->user = $user;
	}

	public function getUser()
	{
		return $this->user;
	}

	public function route()
	{
		$this->markDebug('Start Routing');
		$router = new Router($this);
		$router->parseRoute();

		$this->markDebug('Complete Routing');
		return $this;
	}

	public function execute()
	{
		$this->markDebug('Start Execution');
		try {
			parent::execute();
		} catch (\Exception $e) {
			$this->raiseError($e->getMessage(), $e->getCode());
		}
	}

	public function registerNamespaces($namespace)
	{
		$this->namespaces[] = $namespace;
	}

	public function registerObjects($object)
	{
		$this->objects[] = $object;
	}

    public function doExecute()
    {
    	$object_name = ucwords($this->input->get('object'));

    	// reverse the array to get the last added namespaces/objects first
    	// then use the first namespace/object pair that exists (should support easy overriding)
    	$namespaces = array_reverse($this->namespaces);
    	$objects = array_reverse($this->objects);

    	foreach ($namespaces as $namespace) {
    		foreach ($objects as $object) {
		    	$name = '\\'.ucwords($namespace).'\\'.$object.'\\'.$object_name;

		    	if (class_exists($name)) {
		    		$class = new $name($this);
		    		break 2;
		    	}
		    }
	    }

	    if (!$class) {
	    	throw new \Exception('Object ('.$object_name.') not found.', 404);
	    }

        $this->appendBody($class->execute());
    }

    protected function respond()
	{
		// print debug to response if set up
		if ($this->debug) {
			$this->markDebug('Respond');
			$obj = new \stdClass;
			$obj->debug = $this->debug_values;
			$this->appendBody($obj);
		}

		parent::respond();
	}

    public function raiseError($message = '', $code = 404)
    {
    	http_response_code($code);

    	$data = new \stdClass;
    	$data->error = true;
    	$data->message = $message;

    	echo json_encode($data);

		$this->close();
    }
}

// a function that I stole from php.net, to provide support back to php 5.3.10 (like Joomla)
if (!function_exists('http_response_code')) {
    function http_response_code($code = NULL) {

        if ($code !== NULL) {

            switch ($code) {
                case 100: $text = 'Continue'; break;
                case 101: $text = 'Switching Protocols'; break;
                case 200: $text = 'OK'; break;
                case 201: $text = 'Created'; break;
                case 202: $text = 'Accepted'; break;
                case 203: $text = 'Non-Authoritative Information'; break;
                case 204: $text = 'No Content'; break;
                case 205: $text = 'Reset Content'; break;
                case 206: $text = 'Partial Content'; break;
                case 300: $text = 'Multiple Choices'; break;
                case 301: $text = 'Moved Permanently'; break;
                case 302: $text = 'Moved Temporarily'; break;
                case 303: $text = 'See Other'; break;
                case 304: $text = 'Not Modified'; break;
                case 305: $text = 'Use Proxy'; break;
                case 400: $text = 'Bad Request'; break;
                case 401: $text = 'Unauthorized'; break;
                case 402: $text = 'Payment Required'; break;
                case 403: $text = 'Forbidden'; break;
                case 404: $text = 'Not Found'; break;
                case 405: $text = 'Method Not Allowed'; break;
                case 406: $text = 'Not Acceptable'; break;
                case 407: $text = 'Proxy Authentication Required'; break;
                case 408: $text = 'Request Time-out'; break;
                case 409: $text = 'Conflict'; break;
                case 410: $text = 'Gone'; break;
                case 411: $text = 'Length Required'; break;
                case 412: $text = 'Precondition Failed'; break;
                case 413: $text = 'Request Entity Too Large'; break;
                case 414: $text = 'Request-URI Too Large'; break;
                case 415: $text = 'Unsupported Media Type'; break;
                case 500: $text = 'Internal Server Error'; break;
                case 501: $text = 'Not Implemented'; break;
                case 502: $text = 'Bad Gateway'; break;
                case 503: $text = 'Service Unavailable'; break;
                case 504: $text = 'Gateway Time-out'; break;
                case 505: $text = 'HTTP Version not supported'; break;
                default:
                	$text = 'Unknown http status code'; break;
                break;
            }

            $protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');

            header($protocol . ' ' . $code . ' ' . $text);

            $GLOBALS['http_response_code'] = $code;

        } else {

            $code = (isset($GLOBALS['http_response_code']) ? $GLOBALS['http_response_code'] : 200);

        }

        return $code;

    }
}
