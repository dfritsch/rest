<?php
namespace Webity\Rest\Objects;

use Webity\Rest\Objects;
use Webity\Rest\Application\Api;
use Webity\Rest\Table\TableUserGroup as UserGroup; //so we don't have to do any of that nesting business ouselves
use Joomla\Crypt\Password\Simple;
use Joomla\Crypt\PasswordInterface;

/**
 * Methods supporting a list of {Name} records.
 */
class Users extends Objects
{
	protected $text_fields = array(
			'username',
		);
	protected $agent_id = 0;
	protected static $instances = array();

	protected $users_table; //so we don't have to keep calling api over and over again

	public function __construct ()
	{
		$this->users_table = '#__' . Api::getInstance()->get('users_table', 'oauth_users');
		parent::__construct();
	}

	public static function getInstance($identifier = 0)
	{
		// Find the user id
		if (!is_numeric($identifier))
		{
			// TODO
		}
		else
		{
			$id = $identifier;
		}

		if ($id === 0) {
			$app = Api::getInstance();
			$user = $app->getUser();
			if (isset($user->id)) {
				$id = $user->id;
			}
		}

		if (empty(self::$instances[$id]) || !(self::$instances[$id] instanceof User))
		{
			$user = new User();
			$user->load($id);
			self::$instances[$id] = $user;
		}

		return self::$instances[$id];
	}

	protected function load($id, $check_agency = false)
	{
		$item = new \stdClass;
		$db = Api::getInstance()->getDbo();
		$query = $db->getQuery(true);
		// $query->select('*')
		// 	->from('#__oauth_users')
		// 	->where('id = '.(int)$id);
		//replaced with the other users table
		$query->select('a.*, ug.id AS group_key, ug.title AS group_title')
			  ->from($this->users_table . ' AS a')
			  ->join('LEFT', '#__user_usergroup_map AS ugm ON ugm.user_id = a.id')
			  ->join('LEFT', '#__usergroups AS ug ON ug.id = ugm.group_id');
			  //we also will need to add the organization they are a part of
		if(is_numeric($id)) {
			$query->where('id = ' . (int)$id);
		} else {
			$query->where('username = "' . $id . '"');
		}

		$item->user = $db->setQuery($query, 0, 1)->loadObject();

		if (!$item->user) {
			throw new \Exception('User not found', 404);
		}

		// mimic JUser from CMS side
		foreach ($item->user as $key=>$val) {
			$this->$key = $val;
		}

		// let's not return the password...
		unset($item->user->password);

		return $item;
	}

	protected function loadMany(\stdClass $request) {
		// TODO: support this function

		$db = Api::getInstance()->getDbo();
		$query = $db->getQuery(true);
		$data = new \stdClass;

		// $query->select('u.id, u.name, u.username, u.email, u.block, u.sendEmail')
		// 	->from('#__oauth_users as u');

		//load the users
		$query->select('u.id, u.username')
			  ->from($this->users_table .' as u');

		$this->processSearch($query, Api::getInstance()->input->get('users', array(), 'ARRAY'));

		$items = $db->setQuery($query, $limitstart, $limit)->loadObjectList();

		// add the total numbers to the result;
		$data->total = $this->_getListCount($query);

		// wrap the items in an object
		$data->users = $items;

		return $data;
	}

	static public function checkEmail($email) {
		$db = Api::getInstance()->getDbo();
		$query = $db->getQuery(true);

		// $query->select('id')
		// 	->from('#__oauth_users')
		// 	->where('email LIKE '.$db->quote($email) .' OR username LIKE '.$db->quote($email));

		$query->select('id')
			->from($this->users_table)
			->where('userName LIKE '.$db->quote($email) .' OR username LIKE '.$db->quote($email));

		return $db->setQuery($query, 0, 1)->loadResult();
	}

	//allows us to create a user or update an existing user...
	protected function modifyRecord($id) {
		$api = Api::getInstance();
		$db = $api->getDbo();

		$username = $api->input->post->get('username', null, 'STRING');
		$password = $api->input->post->get('password', null, 'STRING');
		//we need more than just username and password. we also need to link organization and group

		if (is_null($username) && !$id) {
			throw new \Exception('Missing required field "username"', 400);
		}

		if ($id) {
			$query = $db->getQuery(true)
				->select('*')
				->from($this->users_table)
				->where('id = ' . (int)$id);

			$data = $db->setQuery($query)->loadObject();

			if (!$data) {
				throw new \Exception('User not found. Update failed.', 404);
			}
		} else {
			$data = new \stdClass;
		}

		$hasher = new Simple;

		// only overwrite values if they change
		$data->username = $username ? $username : $data->username;
		$data->password = $password ? $hasher->create($password, PasswordInterface::JOOMLA) : $data->password;

		if ($data->id) {
			$db->updateObject($this->users_table, $data, 'id');
		} else {
			$db->insertObject($this->users_table, $data);
			$data->id = $db->insertid();
		}

		return $data;
	}
}
