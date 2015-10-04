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

	function uploadFile($file_obj, $target_dir, $with_thumbnails = false) {
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
    
    //largely an imitation of uploadFileS3 but more tailored to the pieces specific to images (especially cropping)
    function uploadImageS3($file_obj, $target_dir, $settings = array()) {
        
        $ext = $this->validateFile($file_obj);
        
        $defaultSettings = array('with_thumbnails' => true, 'crop_orginal' => false, 'crop_settings' => false);
        
        //set the defaults that aren't present in the settings array passed
        foreach($defaultSettings as $settingName => $settingValue) {
            if(!array_key_exists($settingName, $settings)) {
                $settings[$settingName] = $settingValue;
            }
        }
        
        $api = Api::getInstance();
        
        if( !empty($api->get('aws.bucket')) && !empty($api->get('aws.key')) ) {
            
//            $ext = $this->validateFile($file_obj);
            //set the filename
            $file_location = sprintf($target_dir.'/%s.%s',
                sha1_file($file_obj['tmp_name']),
                $ext
            );
            
            // trim off JPATH_ROOT/web if it exists at the start
			if (strpos($file_location, JPATH_ROOT . '/web/') === 0) {
				$file_location = substr($file_location, strlen(JPATH_ROOT . '/web/'));
			}
            
            // Instantiate the client.
			$s3 = S3Client::factory(array(
				'key'    => $api->get('aws.key'),
				'secret' => $api->get('aws.secret'),
			));
            
            if(is_array($settings['crop_settings'])) {
                $crop_coordinates = array();
                $crop_coordinates['x1'] = $settings['crop_settings']['x'];
                $crop_coordinates['y1'] = $settings['crop_settings']['y'];
                $crop_coordinates['x2'] = $crop_coordinates['x1'] + $settings['crop_settings']['width'];
                $crop_coordinates['y2'] = $crop_coordinates['y1'] + $settings['crop_settings']['height'];
            }
            
            if($settings['crop_original'] && isset($crop_coordinates)) {
                
                $img = new \abeautifulsite\SimpleImage($file_obj['tmp_name']);
                $img->crop($crop_coordinates['x1'], $crop_coordinates['y1'], $crop_coordinates['x2'], $crop_coordinates['y2']);
                $img->save($file_obj['tmp_name']);
            
            }
            
            // Upload data.
            try {
                $result = $s3->putObject(array(
                    'Bucket' => $api->get('aws.bucket'),
                    'Key'    => $file_location,
                    'SourceFile'   => $file_obj['tmp_name'],
                    'ACL'    => 'public-read'
                ));
                
                if($settings['with_thumbnails']) {
                    //now we can see about saving thumbnails too
                    $thumbnail_sizes = array('small' => 150, 'medium' => 400, 'large' => 1000, 'thumb' => 400);

                    foreach($thumbnail_sizes as $size => $size_constraint) {

                        $img = new \abeautifulsite\SimpleImage($file_obj['tmp_name']);

                        if($size == 'thumb') {

                            if(isset($crop_coordinates) && !$settings['crop_original']) {
                                $img->crop($crop_coordinates['x1'], $crop_coordinates['y1'], $crop_coordinates['x2'], $crop_coordinates['y2']);
                                
                                //now take it down to at most 400 px in either direction
                                switch($img->get_orientation()) {
                                    case 'portrait':
                                        $img->fit_to_height($size_constraint);
                                        break;
                                    case 'landscape':
                                        $img->fit_to_width($size_constraint);
                                        break;
                                    case 'square':
                                        $img->best_fit($size_constraint, $size_constraint);
                                        break;
                                }
                                
                            } else {
                                //no crop coordinates have been set so we need to just go with a generic crop
                                $img->thumbnail($size_constraint, $size_constraint);
                            }

                        } else {

                            switch($img->get_orientation()) {
                                case 'portrait':
                                    $img->fit_to_height($size_constraint);
                                    break;
                                case 'landscape':
                                    $img->fit_to_width($size_constraint);
                                    break;
                                case 'square':
                                    $img->best_fit($size_constraint, $size_constraint);
                                    break;
                            }

                        }

                        $thumbnail_filename = $size . '-' . basename($result['ObjectURL']);

                        $thumbnail_key = $target_dir . '/' . $thumbnail_filename;

                        if (strpos($thumbnail_key, JPATH_ROOT . '/web/') === 0) {
                            $thumbnail_key = substr($thumbnail_key, strlen(JPATH_ROOT . '/web/'));
                        }

                        $img->save($thumbnail_filename);
                        
                        $s3->putObject(array(
                            'Bucket' => $api->get('aws.bucket'),
                            'Key'    => $thumbnail_key,
                            'SourceFile'   => $thumbnail_filename,
                            'ACL'    => 'public-read'
                        ));
                        
                        unlink($thumbnail_filename); //now that's it's been saved to s3 we can delete from the server...

                    }
                }
                
                if(file_exists($file_obj['tmp_name'])) {
                    unlink($file_obj['tmp_name']); //make sure the original file was deleted as well
                }
                
                return $result['ObjectURL'];
                
            } catch(S3Exception $e) {
                return $e->getMessage();
            }
        }
        
        return 'amazon configuration has not been set correctly';
    }

	function uploadFileS3($file_obj, $target_dir, $with_thumbnails = false) {
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
            
//            return $file;

			try {
			    // Upload data.
			    $result = $s3->putObject(array(
			        'Bucket' => $api->get('aws.bucket'),
			        'Key'    => $file,
			        'SourceFile'   => $file_obj['tmp_name'],
			        'ACL'    => 'public-read'
		    	));
                
                if($with_thumbnails) {
                    //now try to save the 3 types of thumbnails
                    $thumbnail_sizes = array('small' => 150, 'medium' => 400, 'large' => 1000, 'thumb' => 400);
                    
                    foreach($thumbnail_sizes as $size => $size_constraint) {
                        
                        $img = new \abeautifulsite\SimpleImage($file_obj['tmp_name']);
                        
                        if($size == 'thumb') {
                            
                            $img->thumbnail($size_constraint, $size_constraint);
                            
                        } else { 
                        
                            switch($img->get_orientation()) {
                                case 'portrait':
                                    $img->fit_to_height($size_constraint);
                                    break;
                                case 'landscape':
                                    $img->fit_to_width($size_constraint);
                                    break;
                                case 'square':
                                    $img->best_fit($size_constraint, $size_constraint);
                                    break;
                            }
                            
                        }
                        
                        $thumbnail_filename = sprintf('%s.%s',
                            $size . '-' . sha1_file($file_obj['tmp_name']),
                            $ext
                        );
                        
                        $thumbnail_key = sprintf($target_dir . '/%s.%s',
                            $size . '-' . sha1_file($file_obj['tmp_name']),
                            $ext
                        );
                        
                        if (strpos($thumbnail_key, JPATH_ROOT . '/web/') === 0) {
                            $thumbnail_key = substr($thumbnail_key, strlen(JPATH_ROOT . '/web/'));
                        }
                        
                        $img->save($thumbnail_filename);
                        
                        $s3->putObject(array(
                            'Bucket' => $api->get('aws.bucket'),
                            'Key'    => $thumbnail_key,
                            'SourceFile'   => $thumbnail_filename,
                            'ACL'    => 'public-read'
                        ));
                        
                        unlink($thumbnail_filename); //now that's it's been saved to s3 we can delete from the server...
                        
                    }
                    
                }

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
    
    function copyS3File($from_filename, $to_filename) {
        
        $api = Api::getInstance();
        // Instantiate the client.
        $s3 = S3Client::factory(array(
            'key'    => $api->get('aws.key'),
            'secret' => $api->get('aws.secret'),
        ));
        
        try {
            
            $result = $s3->copyObject(array(
                'Bucket'     => $api->get('aws.bucket'),
                'Key'        => $to_filename,
                'CopySource' => $api->get('aws.bucket') . '/' . $from_filename,
                'ACL'    => 'public-read',
            ));
            
            return $result;
            
        } catch(S3Exception $e) {
            
            file_put_contents(realpath(dirname(__FILE__)) . '/s3_copy_error.log', '{ s3_exception: ' . $e->getMessage() . ' }', FILE_APPEND); //so we can log any errors we might be getting on this.
            
            return $e->getMessage();
            
        }
        
        return 'amazon configuration has not been set correctly';
        
    }
    
    /**
     This method is used to sync tables by passing an array of ids that should be associated with the id of another table
     */
    protected function syncAssociativeTable($associativeTable, $single_column, $many_column, $single_column_id, $many_column_ids = array()) {
        //first let's remove the items
        $db = $this->_db;
        $query = $db->getQuery(true);
        
        $query->delete($associativeTable)
              ->where($single_column . ' = ' . (int) $single_column_id);
        
        $db->setQuery($query);
        $db->execute();
        
        //now we can add the many_column_ids to the
        if(count($many_column_ids) !== 0) {
            $query = $db->getQuery(true);
            $query->insert($db->quoteName($associativeTable));
            $query->columns(array($single_column, $many_column));
            
            $values = array();
            foreach($many_column_ids as $many_id) {
                 $values[] = $single_column_id . ', ' . $many_id;
            }
            
            $query->values($values);
            $db->setQuery($query);
            $db->execute();
        }
    }

	protected function checkPrivate() {
		if($this->isPrivate) {
			//now we need to check if the user is logged in
			if(!Api::getInstance()->getUser()) {
				throw new \Exception('This object is private. Token must be authenticated by a user', 401);
			}
		}
	}
    
    //NESTED TABLE functionality
    //data should be in the form of stdClass
    public function addNode($table = '', $data, $parent_id = 0, $original = 0, $primary_key = 'deckId') {
        
        $db = $this->_db;
        
        if($parent_id > 0) {
            
            $query = $db->getQuery(true);
            $query->select('lft, depth')
                  ->from($table)
                  ->where($primary_key . ' = ' . (int) $parent_id);
            
            $db->setQuery($query);
            $result = $db->loadObject();
            
            if( !$result ) {
                return false;
            }
            
            $lft = $result->lft;
            
            $query = "UPDATE $table SET rgt = rgt + 2 WHERE rgt > $lft";
            
            $db->setQuery($query);
            $db->execute();
            
            $query = "UPDATE $table SET lft = lft + 2 WHERE lft > $lft";
            
            $db->setQuery($query);
            $db->execute();
            
            $data->lft = $lft + 1;
            $data->rgt = $data->lft + 1;
            $data->depth = $result->depth + 1;
            
        } else {
            //we need to find the highest value to know what the new node's lft / rgt will be
            $rgt = $this->_lastNodePosition($table);
            
            $data->lft = $rgt + 1;
            $data->rgt = $rgt + 2;
            $data->depth = 0;
        }
        
        $data->original = $original;
        
        $db->insertObject($table, $data);
        
        return $db->insertid();
    }
    
    //set the state to a value (-2 for "deleted but restorable" -3 for "fully delete and clean me up")
    public function deleteNode($table = '', $id, $soft_delete = true, $primary_key = 'deckId') {
        
        $db = $this->_db;
        $state = $soft_delete ? -2 : -3;
        
        $query = $db->getQuery(true);
        $query->select('rgt, lft, (rgt - lft + 1) AS width')
              ->from($table)
              ->where($primary_key . ' = ' . (int) $id . ' OR original = ' . (int) $id);
        
        $db->setQuery($query);
        
        $results = $db->loadObjectList();
        
        if( !$results ) {
            return false;
        }
        
        //go through each instance where the row (or it's duplicates) can be updated 
        foreach($results as $result) {
            
            $query = "UPDATE $table SET state = $state WHERE lft BETWEEN $result->lft AND $result->rgt";
            
            $db->setQuery($query);
            $db->execute();
            
            if($state < -2) {
            
                //we still want soft deleted items to retain their position in the hierarchy because they're visible to admins and can be restored
                $query = "UPDATE $table SET rgt = rgt - $result->width WHERE rgt > $result->rgt";

                $db->setQuery($query);
                $db->execute();

                $query = "UPDATE $table SET lft = lft - $result->width WHERE lft > $result->rgt";

                $db->setQuery($query);
                $db->execute();
                
            }
            
        }
        
        return true;
    }
    
    //for now simply to make sure we are deleting nodes properly
    public function testDeleteNode($table = '', $id, $soft_delete = true, $primary_key = 'deckId') {
        
        $db = $this->_db;
        $state = $soft_delete ? -2 : -3;
        
        $query = $db->getQuery(true);
        $query->select('rgt, lft, (rgt - lft + 1) AS width')
              ->from($table)
              ->where($primary_key . ' = ' . (int) $id . ' OR original = ' . (int) $id);
        
        $db->setQuery($query);
        
        $results = $db->loadObjectList();
        
        //go through each instance where the row (or it's duplicates) can be updated 
        foreach($results as $result) {
            
            $query = "DELETE FROM $table WHERE lft BETWEEN $result->lft AND $result->rgt";
            
            $db->setQuery($query);
            $db->execute();
            
            $query = "UPDATE $table SET rgt = rgt - $result->width WHERE rgt > $result->rgt";
            
            $db->setQuery($query);
            $db->execute();
            
            $query = "UPDATE $table SET lft = lft - $result->width WHERE lft > $result->rgt";
            
            $db->setQuery($query);
            $db->execute();
            
        }
    }
    
    //only would work for nodes that are not set to state -3
    public function restoreNode($table = '', $id, $primary_key = 'deckId') {
        
        $db = $this->_db;
        $state = 1;
        
        $query = $db->getQuery(true);
        $query->select('rgt, lft, (rgt - lft + 1) AS width')
              ->from($table)
              ->where($primary_key . ' = ' . (int) $id . ' OR original = ' . (int) $id);
        
        $db->setQuery($query);
        
        $results = $db->loadObjectList();
        
        if( !$results ) {
            return false;
        }
        
        //go through each instance where the row (or it's duplicates) can be updated 
        foreach($results as $result) {
            
            $query = "UPDATE $table SET state = $state WHERE lft BETWEEN $result->lft AND $result->rgt";
            
            $db->setQuery($query);
            $db->execute();
            
        }
        
        return true;
        
    }
    
    //moves a node and it's children to the desired location (including root)
    public function moveNode($table = '', $id, $new_parent = 0, $primary_key = 'deckId') {
        
        $db = $this->_db;
        $query = $db->getQuery(true);
        $query->select('rgt, lft, (rgt - lft + 1) AS width, depth')
              ->from($table)
              ->where($primary_key . ' = ' . (int) $id);
        
        $db->setQuery($query);
        
        $subtree = $db->loadObject();
        
        if( !$subtree ) {
            return false;
        }
        
        $width = $subtree->width;
        $old_position_lft = $subtree->lft;
        $old_position_rgt = $subtree->rgt;
        $old_depth = $subtree->depth - 1; //we want the depth of the parent to compare to (no parent then it should be -1)
        
        //different approach if we are moving the node to the root
        if($new_parent > 0) {
        
            $query->clear();
            $query->select('lft, depth')
                  ->from($table)
                  ->where($primary_key . ' = ' . (int) $new_parent);
            
            $db->setQuery($query);
            
            $new_parent = $db->loadObject();
            
            if( !$new_parent ) {
                return false;
            }
            
            $new_position_lft = $new_parent->lft + 1;
            $new_depth = $new_parent->depth;
        
        } else {
        
            $new_position_lft = $this->_lastNodePosition($table) + 1;
            $new_depth = -1; //because there is no parent
            
        }
        
        $depth_difference = $new_depth - $old_depth;
        $distance = $new_position_lft - $subtree->lft;
        
        if($distance < 0) {
            $distance -= $width;
            $old_position_lft += $width;
        }
        
        /**
         *  -- create new space for subtree
         *  UPDATE tags SET lpos = lpos + :width WHERE lpos >= :newpos
         *  UPDATE tags SET rpos = rpos + :width WHERE rpos >= :newpos
         */
        
        $query = "UPDATE $table SET lft = lft + $width WHERE lft >= $new_position_lft";
        $db->setQuery($query);
        $db->execute();
        
        $query = "UPDATE $table SET rgt = rgt + $width WHERE rgt >= $new_position_lft";
        $db->setQuery($query);
        $db->execute();
        
        /**
         *  -- move subtree into new space
         *  UPDATE tags SET lpos = lpos + :distance, rpos = rpos + :distance
         *           WHERE lpos >= :tmppos AND rpos < :tmppos + :width
         */
        
        $query = "UPDATE $table SET lft = lft + $distance, rgt = rgt + $distance, depth = depth + $depth_difference WHERE lft >= $old_position_lft AND rgt < $old_position_lft + $width";
        $db->setQuery($query);
        $db->execute();
        
        /*
         *  -- remove old space vacated by subtree
         *  UPDATE tags SET lpos = lpos - :width WHERE lpos > :oldrpos
         *  UPDATE tags SET rpos = rpos - :width WHERE rpos > :oldrpos
         */
        
        $query = "UPDATE $table SET lft = lft - $width WHERE lft > $old_position_rgt";
        $db->setQuery($query);
        $db->execute();
        
        $query = "UPDATE $table SET rgt = rgt - $width WHERE rgt > $old_position_rgt";
        $db->setQuery($query);
        $db->execute();
        
        return true;
        
    }
    
        
    //similar to duplicating a node except it simply defines a new location where the 'original' column is set based on the id
    //warning, it may be possible to reassign to the same location as the original so it's potentially kind of weird behavior
    public function reassignNode($table = '', $id, $parent_id = 0, $primary_key = 'deckId') {
        
        $db = $this->_db;
        $query = $db->getQuery(true);
        
        $query->select($primary_key . ', state, original')
              ->from($table)
              ->where($primary_key . ' = ' . $primary_key);
        
        $db->setQuery($query);
        
        $result = $db->loadObject();
        
        if( !$result ) {
            return false;
        }
        
        $data = new \stdClass();
        
        echo $result->{ $primary_key };
        
        $data->original = $result->original > 0 ? $result->original : $result->{ $primary_key };
        $data->state = $result->state;
        
        $new_id = $this->addNode($table, $data, $parent_id, $data->original);
        
        if($new_id === false) {
            return false;
        }
        
        return $this->moveNode($table, $new_id, $parent_id);
        
        return true;
    }
    
    //nested table helper functions
    
    //keep in mind this get's the highest RIGHT max position
    protected function _lastNodePosition($table = '') {
        
        $db = $this->_db;
        
        //we need to find the highest value to know what the new node's lft / rgt will be
        $query = $db->getQuery(true);
        $query->select('MAX(rgt) as max_rgt')
              ->from($table);
            
        $db->setQuery($query);
        $result = $db->loadObject();
            
        $rgt = $result->max_rgt;
        
        return $rgt;
    }
}
