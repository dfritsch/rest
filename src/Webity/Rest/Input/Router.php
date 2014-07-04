<?php
namespace Webity\Rest\Input;

use Webity\Rest\Application\Api;

class Router
{
	protected $app = null;

	protected $path = '';

	public function __construct(Api $app) {
		$this->app = $app;
	}

	public function parseRoute($path = '') {
		if (!$path) {
			$path = $this->app->get('uri.route');
		}

		$path = (string)$path;

		$this->path = $path;

		if ($query_pos = strpos($path, '?')) {
			$path = substr($path, 0, $query_pos);
		}

		$pieces = explode('/', $path);

		// allow there to be a version number passed
		// this is ignored for now, but will allow us to version this system later
		if (strtolower(substr($pieces[0], 0, 1)) == 'v' && intval(substr($pieces[0], 1)) == substr($pieces[0], 1)) {
			$this->app->input->set('version', substr(array_shift($pieces), 1));
		}
		
		$object = $pieces[0] ? $pieces[0] : null;
		$id = $pieces[1] ? $pieces[1] : null;

		$this->app->input->set('object', $object);
		$this->app->input->set('id', $id);
	}
}