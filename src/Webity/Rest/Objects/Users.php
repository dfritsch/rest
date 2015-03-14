<?php
namespace Webity\Rest\Objects;

use Webity\Rest\Objects;
use Webity\Rest\Application\Api;
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
	protected $isPrivate = false; //so that clients can access it without needed to be logged in with a user
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
		$query->select('a.*')
			  ->from($this->users_table . ' AS a');

		if(is_numeric($id)) {
			$query->where('a.id = ' . (int)$id);
		} else {
			$query->where('a.username = ' . $db->quote($id));
		}

		$item->data = $db->setQuery($query, 0, 1)->loadAssoc();

		if (!$item->data) {
			throw new \Exception('User not found', 404);
		}

		// let's not return the password...
		unset($item->data['password']);

		return $item;
	}

	protected function loadMany(\stdClass $request) {
		// TODO: support this function
		$api = Api::getInstance();
		$db = $api->getDbo();
		$query = $db->getQuery(true);
		$data = new \stdClass;

		//load the users
		$query->select('a.*')
			  ->from($this->users_table .' AS a'); //later we should make it so they can select multiple organizations or groups that they're a part of

		$this->processSearch($query, Api::getInstance()->input->get('users', array(), 'ARRAY'));

		$items = $db->setQuery($query, $request->start, $request->limit)->loadAssocList();

		//remove all of the passwords
		foreach($items as $key => $item) {
			unset($items[$key]['password']);
		}

		// add the total numbers to the result;
		$data->total = $this->_getListCount($query);

		// wrap the items in an object
		$data->data = $items;

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
			->where('username LIKE '. $db->quote($email));

		return $db->setQuery($query, 0, 1)->loadResult();
	}

	//allows us to create a user or update an existing user...
	protected function modifyRecord($id) {
		$api = Api::getInstance();
		$db = $api->getDbo();

		$username = $api->input->post->get('username', null, 'STRING');
		$password = $api->input->post->get('password', null, 'STRING');
		$first = $api->input->post->get('first', null, 'STRING');
		$last = $api->input->post->get('last', null, 'STRING');
		//we need more than just username and password. we also need to link organization and group

		if (is_null($username) && !$id) {
			throw new \Exception('Missing required field "email"', 400);
		}

		if (is_null($first) && !$id) {
			throw new \Exception('Missing required field "first name"', 400);
		}

		if (is_null($last) && !$id) {
			throw new \Exception('Missing required field "last name"', 400);
		}

		if ($id) {
			//they are wanting to modify an already existing user. check that they are modifying themselves
			if($api->getUser()->id != $id) {
				throw new \Exception('Users can only modify their own account', 400);
			}

			$query = $db->getQuery(true)
						->select('*')
						->from($this->users_table)
						->where('id = ' . (int)$id);

			$data = $db->setQuery($query)->loadObject();

			if (!$data) {
				throw new \Exception('User not found. Update failed.', 404);
			}
		} else {
			//first let's check if the username has already been taken or not...
			$query = $db->getQuery(true)
						->select('id')
						->from($this->users_table)
						->where('username = ' . $db->quote($username) );

			$user = $db->setQuery($query)->loadObject();

			if($user) {
				throw new \Exception('A user with that username has already been taken', 400);
			}

			$data = new \stdClass;
		}

		$hasher = new Simple;

		// only overwrite values if they change
		$data->username = $username ? $username : $data->username;
		$data->password = $password ? $hasher->create($password, PasswordInterface::JOOMLA) : $data->password;
		$data->first = $first ? $first : $data->first;
		$data->last = $last ? $last : $data->last;

		if ($data->id) {
			$db->updateObject($this->users_table, $data, 'id');
		} else {
			$db->insertObject($this->users_table, $data);
			$data->id = $db->insertid();

			//now we need to initialize the user_question_map table
			$query->clear()
				  ->select('q.id')
				  ->from('#__questions AS q');

			$questions = $db->setQuery($query)->loadObjectList();

			foreach($questions as $question) {
				$q = new \stdClass;
				$q->userId = $data->id;
				$q->questionId = $question->id;

				$db->insertObject('#__user_question_map', $q);
			}
		}

		return $data;
	}
}
