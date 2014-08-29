<?php

namespace Webity\Rest\Oauth2;

use Webity\Rest\Application\Api;
use Webity\Rest\Oauth2\Storage as OauthStorage;

class Server
{
	protected static $instances = array();
	protected $app;
	protected $storage;
	protected $server;

	function __construct() {
		$this->app = Api::getInstance();
		$this->storage = new OauthStorage();

		$this->server = new \OAuth2\Server($this->storage);

		// Add the "Client Credentials" grant type (it is the simplest of the grant types)
		$this->server->addGrantType(new \OAuth2\GrantType\ClientCredentials($this->storage));

		// Add the "Authorization Code" grant type (this is where the oauth magic happens)
		$this->server->addGrantType(new \OAuth2\GrantType\AuthorizationCode($this->storage));

		// Add the "User Credentials" grant type (this allows a client to send user/password combos for a token)
		$this->server->addGrantType(new \OAuth2\GrantType\UserCredentials($this->storage));

		// Add the "RefreshToken" grant type (this allows prolonged access)
		$this->server->addGrantType(
			new \OAuth2\GrantType\RefreshToken(
				$this->storage, 
				array('always_issue_new_refresh_token' => true)
			)
		);
	}

	public static function getInstance($id = 1)
	{
		if (empty(self::$instances[$id]))
		{
			self::$instances[$id] = new self;
		}

		return self::$instances[$id];
	}

	public function handleToken() {
		$this->server->handleTokenRequest(\OAuth2\Request::createFromGlobals())->send();
		exit();
	}

	public function handleResource() {
		if (!$this->server->verifyResourceRequest(\OAuth2\Request::createFromGlobals())) {
		    $this->server->getResponse()->send();
		    die;
		}

		$data = $this->server->getAccessTokenData(\OAuth2\Request::createFromGlobals());

		return $data;
	}

	public function authorize() {
		$request = \OAuth2\Request::createFromGlobals();
		$response = new \OAuth2\Response();

		// validate the authorize request
		if (!$this->server->validateAuthorizeRequest($request, $response)) {
		    $response->send();
		    die;
		}
		// display an authorization form
		if (empty($_POST)) {
		  exit('
		<form method="post">
		  <h3>Login to authorize '. Api::getInstance()->get('name', 'Webity') .' API Connection</h3><br />
		  <label>Username</label><input type="text" name="username" /><br>
		  <label>Password</label><input type="password" name="password" /><br>
		  <input type="submit" name="authorized" value="Submit">
		</form>');
		}

		$username = $this->app->input->post->get('username', '', 'STRING');
		$password = $this->app->input->post->get('password', '', 'STRING');

		$is_authorized = $this->storage->checkUserCredentials($username, $password);

		$this->server->handleAuthorizeRequest($request, $response, $is_authorized, $username);

		$response->send();
		exit();
	}
}
