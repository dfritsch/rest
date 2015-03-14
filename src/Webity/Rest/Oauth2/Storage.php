<?php

namespace Webity\Rest\Oauth2;

use OAuth2\Storage\Pdo;
use Webity\Rest\Application\Api;

class Storage extends Pdo
{
    public function __construct($connection = array(), $config = array())
    {
        $app = Api::getInstance();

        $host = $app->get('database.host');
        $user = $app->get('database.user');
        $password = $app->get('database.password');
        $database = $app->get('database.name');

        $connection = array_merge(
            array(
                'dsn' => 'mysql:dbname='.$database.';host='.$host, 
                'username' => $user, 
                'password' => $password
            ),
            $connection
        );

        parent::__construct($connection, $config);

        $prefix = $app->get('database.prefix', 'jos_');

        $this->config = array_merge(
            $this->config,
            array(
                'client_table' => $prefix . 'oauth_clients',
                'access_token_table' => $prefix . 'oauth_access_tokens',
                'refresh_token_table' => $prefix . 'oauth_refresh_tokens',
                'code_table' => $prefix . 'oauth_authorization_codes',
                'user_table' => $prefix . $app->get('users_table', 'oauth_users'),
                'user_key' => $app->get('user_key', 'id'),
                'jwt_table'  => $prefix . 'oauth_jwt',
                'scope_table'  => $prefix . 'oauth_scopes',
                'public_key_table'  => $prefix . 'oauth_public_keys',
            ), 
            $config
        );
    }

    public function getUser($username)
    {
        $stmt = $this->db->prepare($sql = sprintf('SELECT * from %s where username=:username', $this->config['user_table']));
        $stmt->execute(array('username' => $username));

        if (!$userInfo = $stmt->fetch()) {
            return false;
        }

        // the default behavior is to use "username" as the user_id
        return array_merge(array(
            'user_id' => $userInfo[$this->config['user_key']]
        ), $userInfo);
    }

    // plaintext passwords are bad!  Override this for your application
    protected function checkPassword($user, $password)
    {
        $crypt = new \Joomla\Crypt\Password\Simple();
        $auth = $crypt->verify($password, $user['password']);

        return $auth;
    }

    /* OAuth2\Storage\ClientCredentialsInterface */
    public function checkClientCredentials($client_id, $client_secret = null)
    {
        $stmt = $this->db->prepare(sprintf('SELECT * from %s where client_id = :client_id', $this->config['client_table']));
        $stmt->execute(compact('client_id'));
        $result = $stmt->fetch();

        $crypt = new \Joomla\Crypt\Password\Simple();
        $auth = $crypt->verify($client_secret, $result['client_secret']);

        // make this extensible
        return $result && $auth;
    }
}