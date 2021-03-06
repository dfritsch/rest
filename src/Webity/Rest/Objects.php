<?php
namespace Webity\Rest;

use Webity\Rest\Application\Api;
use Webity\Rest\Objects\User;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

abstract class Objects
{
	protected $text_fields = array();
	protected static $instances = array();
	protected $data = array();
	protected $_db = null;
	protected $directory = '';
    protected $namespace = '';
	protected $valid_files = array(
		'jpg' => 'image/jpeg',
	    'png' => 'image/png',
	    'gif' => 'image/gif',
	);
	protected $isPrivate = true;

	public function __construct ()
	{
		$rc = new \ReflectionClass(get_class($this));
        $this->directory = dirname($rc->getFileName());
        $this->namespace = $rc->getNamespaceName();

		$this->_db = Api::getInstance()->getDbo();
	}

	public function execute() {
		$app = Api::getInstance();
		$this->_db = $app->getDbo();

		$this->checkPrivate(); //so we can check if a resource requires user authentication...

		$id = $app->input->get('id', null, 'PASSWORD');
		$task = $app->input->get('task'); //a way to do more than just a single thing depending on the request type

		switch ($app->input->getMethod()) {
			case 'GET':
			default:
				return ($id ? $this->getData($id) : $this->getList());
				break;
			case 'POST':
			case 'PUT':
				if(method_exists($this, $task)) {
					return $this->$task();
				} else {
					return $this->modifyRecord($id);
				}
				break;
			case 'DELETE':
				if ($id) {
					$this->getData($id);
				} else {
					throw new \Exception('ID required for DELETE method.');
				}

				break;
		}

	}

	/*
	**	Should be loading the data based on the id, so no search
	*/
	protected function getData($id = 0, $check_agency = true)
	{
		if (empty($this->data[$id]) || $this->data[$id]->simple)
		{
			$this->data[$id] = $this->load($id, $check_agency);
		}

		return $this->data[$id];
	}

	/*
	** This checks the input for a few parameters:
	** start - 0
	** limit - 10 (min 1, max 100)
	** sort - created_time
	** direction - desc
	** search - ''
	*/
	protected function getList() {
		$app = Api::getInstance();
		$input = $app->input;

		$data = new \stdClass;
		$data->start = $input->get->get('start', 0, 'INT');
		$data->limit = $input->get->get('limit', 10, 'INT');
		$data->sort = $input->get->get('sort', 'created_time', 'STRING');
		$data->direction = $input->get->get('direction', 'desc', 'STRING');
		$data->isOn = $input->get->get('isOn', null, 'STRING');

		if ($data->limit < 1 || $data->limit > 100) {
			throw new \Exception('Limit exceeds allowed bounds. Should be between 1 and 100', 400);
		}

		// TODO return total as well
		// $data->total = $this->getTotal();

		$app->appendBody($data);

		//because we pass so many options to the load many function
		return $this->loadMany($data);
	}

	protected function clearData($id = 0) {
		if (isset($this->data[$id])) {
			unset($this->data[$id]);
		}
		return true;
	}

	// function to allow children classes to tweak search string
	protected function parseSearch($search) {
		return $search;
	}

	protected function processSearch($query) {
		$search['search'] = Api::getInstance()->input->get('search', '', 'STRING');

		$search = $this->parseSearch($search);

		if (is_array($search)) {
			foreach ($search as $key=>$val) {
				switch($key) {
					case 'search':
						if ($val) {
							$vals = explode(' ', $val);
							foreach ($vals as $val) {
								$where = array();
								$s = $this->_db->quote('%'.$this->_db->escape(trim($val, '-./\\,;:[]{}|`~!@#$%^&*()'), true).'%');
								foreach ($this->text_fields as $field) {
									$where[] = ''.$field.' LIKE ('.$s.')';
								}
								if ($where) {
									$query->where('(('.implode(') OR (', $where).'))');
								}
							}
						}
						break;
					default:
						if ($val) {
							$query->where(''.$key.'='.$this->_db->quote($val));
						}
						break;
				}
			}
		}
	}


	protected function _getListCount($query)
	{
		// Use fast COUNT(*) on JDatabaseQuery objects if there no GROUP BY or HAVING clause:
		if ($query instanceof JDatabaseQuery
			&& $query->type == 'select'
			&& $query->group === null
			&& $query->having === null)
		{
			$query = clone $query;
			$query->clear('select')->clear('order')->select('COUNT(*)');

			$this->_db->setQuery($query);
			return (int) $this->_db->loadResult();
		}

		// Otherwise fall back to inefficient way of counting all results.
		$this->_db->setQuery($query);
		$this->_db->execute();

		return (int) $this->_db->getNumRows();
	}

	abstract protected function load($id, $check_agency);
	abstract protected function loadMany(\stdClass $request);
	abstract protected function modifyRecord($id);

	function uploadFile($file_obj, $target_dir) {
	    $ext = $this->validateFile($file_obj);

		if (!file_exists($target_dir)) {
			mkdir($target_dir);
		}

		$file_location = sprintf($target_dir.'/%s.%s',
			sha1_file($file_obj['tmp_name']),
			$ext
		);

	    // You should name it uniquely.
	    // DO NOT USE $file_obj['name'] WITHOUT ANY VALIDATION !!
	    // On this example, obtain safe unique name from its binary data.
	    if (!move_uploaded_file(
	        $file_obj['tmp_name'],
	        $file_location
	    )) {
	        throw new \RuntimeException('Failed to move uploaded file.');
	    }

	    return $file_location;
	}

	function uploadFileS3($file_obj, $target_dir) {
		$ext = $this->validateFile($file_obj);
		$api = Api::getInstance();

		if( !empty($api->get('aws.bucket')) && !empty($api->get('aws.key')) ) {

			$file_location = sprintf($target_dir.'/%s.%s',
				sha1_file($file_obj['tmp_name']),
				$ext
			);
			// Instantiate the client.
			$s3 = S3Client::factory(array(
				'key'    => $api->get('aws.key'),
				'secret' => $api->get('aws.secret'),
			));

			$file = $file_location;
			// trim off JPATH_ROOT/web if it exists at the start
			if (strpos($file_location, JPATH_ROOT . '/web/') === 0) {
				$file = substr($file_location, strlen(JPATH_ROOT . '/web/'));
			}

			try {
			    // Upload data.
			    $result = $s3->putObject(array(
			        'Bucket' => $api->get('aws.bucket'),
			        'Key'    => $file,
			        'SourceFile'   => $file_obj['tmp_name'],
			        'ACL'    => 'public-read'
		    	));

		    	// Print the URL to the object.
		    	return $result['ObjectURL'];
			} catch (S3Exception $e) {
			    return $e->getMessage();
			}
		}

		return 'amazon configuration has not been set correctly';
	}

	protected function validateFile($file_obj) {
		// Undefined | Multiple Files | $_FILES Corruption Attack
	    // If this request falls under any of them, treat it invalid.
	    if (
	        !isset($file_obj['error']) ||
	        is_array($file_obj['error'])
	    ) {
	        throw new \RuntimeException('Invalid parameters.');
	    }

	    // Check $file_obj['error'] value.
	    switch ($file_obj['error']) {
	        case UPLOAD_ERR_OK:
	            break;
	        case UPLOAD_ERR_NO_FILE:
	            throw new \RuntimeException('No file sent.');
	        case UPLOAD_ERR_INI_SIZE:
	        case UPLOAD_ERR_FORM_SIZE:
	            throw new \RuntimeException('Exceeded filesize limit.');
	        default:
	            throw new \RuntimeException('Unknown errors.');
	    }

	    // You should also check filesize here.
	    if ($file_obj['size'] > 512000000) {
	        throw new \RuntimeException('Exceeded filesize limit.');
	    }

	    // DO NOT TRUST $file_obj['mime'] VALUE !!
	    // Check MIME Type by yourself.
	    // $finfo = new \finfo(FILEINFO_MIME_TYPE);
	    // if (false === $ext = array_search(
	    //     $finfo->file($file_obj['tmp_name']),
	    //     $this->valid_files,
	    //     true
	    // )) {
	    //     throw new \RuntimeException('Invalid file format.');
	    // }

		$ext = pathinfo($file_obj['name'], PATHINFO_EXTENSION);

	    return $ext;
	}

	protected function checkPrivate() {
		if($this->isPrivate) {
			//now we need to check if the user is logged in
			if(!Api::getInstance()->getUser()) {
				throw new \Exception('This object is private. Token must be authenticated by a user', 401);
			}
		}
	}
}
