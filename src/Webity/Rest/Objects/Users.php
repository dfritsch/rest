<?php
namespace Webity\Rest\Objects;

use Webity\Rest\Objects;
use Webity\Rest\Application\Api;

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
		$query->select('*')
			->from('#__' . Api::getInstance()->get('users_table', 'oauth_users'))
			->where('id = '.(int)$id);

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
			->from('#__' . Api::getInstance()->get('users_table', 'oauth_users') .' as u');

		$this->processSearch($query, Api::getInstance()->input->get('users', array(), 'ARRAY'));

		$items = $db->setQuery($query, $limitstart, $limit)->loadObjectList();

		// add the total numbers to the result;
		$data->total = $this->_getListCount($query);

		// wrap the items in an object
		$data->users = $items;

		return $data;
	}

	// TODO: rewrite this to work
	public function createUser($email, $name, $code='') {
		// create user and insert base record into advantage users page
		jimport( 'joomla.user.helper' );

		$app =& Api::getInstance();
		// because we have a class named user as well, we need to grab the user class on our own
		require_once (JPATH_ROOT . '/libraries/joomla/database/table/user.php');

		$jom_id = JUserHelper::getUserId($email);
		if ($jom_id) {
			$db = Api::getInstance()->getDbo();
			if (!$db->setQuery('SELECT id FROM #__' . Api::getInstance()->get('users_table', 'oauth_users') . ' WHERE id='.$jom_id)->loadResult()) {
				$db->setQuery('INSERT INTO #__' . Api::getInstance()->get('users_table', 'oauth_users') . ' SET id='.$jom_id)->query();
			}
			return $jom_id;
		}

		if (!$code) {
			$code = JUserHelper::genRandomPassword();
		}

		// $user_data = array(
		// 	'username' => $email,
		// 	'email' => $email,
		// 	'password' => $code,
		// 	'password2' => $code,
		// 	'name' => $name
		// 	);

		$user_data = array(
			'userName' => $email,
			'password' => $code,
			'password2' => $code
			);

		$user  = new JUser;
		$user->bind($user_data);
		$jom_users_id = $user->save();

		// if($jom_users_id) {
		// 	$jom_users_id = $user->id;
		// 	JUserHelper::addUserToGroup($jom_users_id, 2);

		// 	$db = Api::getInstance()->getDbo();
		// 	$base_user = new stdClass();
		// 	$base_user->id = $jom_users_id;
		// 	$db->insertObject('#__advantage_users', $base_user);

		// 	$mailer =& JFactory::getMailer();
		// 	// Set a sender
		// 	$config =& JFactory::getConfig();
		// 	$sender = array(
		// 		$config->get( 'mailfrom' ),
		// 		$config->get( 'fromname' ) );

		// 	$mailer->setSender($sender);

		// 	// Recipient
		// 	$mailer->addRecipient($user->email);

		// 	$mailer->setSubject('Your Real Estate Ally User Details!');

		// 	// Set email
		// 	$body	= '<h3>Hello '.$user->name.',</h3>';
		// 	$body	.= '<p>You have been added as a user for Real Estate Ally.</p>';
		// 	$body	.= '<p>This email contains your username and password to log into <a href="http://www.yourrealestateally.com">http://www.yourrealestateally.com</a></p>';
		// 	$body	.= '<p>Username: '.$user->email.'<br />';
		// 	$body	.= 'Password: '.$code.'</p>';
		// 	$body 	.= '<p>This password can be changed after logging in to the system and going to your profile from the orange dropdown in the upper right of any page.</p>';
		// 	$body	.= '<p>Please do not respond to this message as it is automatically generated and is for information purposes only.</p>';
		// 	$mailer->isHTML(true);
		// 	$mailer->Encoding = 'base64';
		// 	$mailer->setBody($body);
		// 	// Optionally add embedded image
		// 	//$mailer->AddEmbeddedImage( JPATH_COMPONENT.DS.'assets'.DS.'logo128.jpg', 'logo_id', 'logo.jpg', 'base64', 'image/jpeg' );

		// 	if (strpos($user->email, '@makethewebwork.com')) {
		// 		$send =& $mailer->Send();
		// 	} else {
		// 		$send = false;
		// 	}

		// 	if ( $send !== true ) {
		// 		$app->enqueueMessage( 'Error sending email: ' . $send->message );
		// 	} else {
		// 		$app->enqueueMessage( 'Mail sent' );
		// 	}
		// }

		return $jom_users_id;
	}

	static public function checkEmail($email) {
		$db = Api::getInstance()->getDbo();
		$query = $db->getQuery(true);

		// $query->select('id')
		// 	->from('#__oauth_users')
		// 	->where('email LIKE '.$db->quote($email) .' OR username LIKE '.$db->quote($email));

		$query->select('id')
			->from('#__' . Api::getInstance()->get('users_table', 'oauth_users'))
			->where('userName LIKE '.$db->quote($email) .' OR username LIKE '.$db->quote($email));

		return $db->setQuery($query, 0, 1)->loadResult();
	}
}
