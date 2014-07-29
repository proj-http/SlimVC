<?php
namespace App\Lib\SlimVC;

use \Slim\Views\TwigExtension as TwigExtension;
use \App\Lib\SlimVC\Logger;

class Router{

	// opts & instances
	protected $controllerNamespace = '\\App\\Controllers\\';
	protected $Slim = null;
	protected $options = array();
	protected $Logger = null;
	protected $logLevel = 0;
	protected $enableLogging = false;

	// routing vars
	protected $explicitRoutes = array();
	protected $conditionalTags = array(); 
	protected $conditionalRoutes = array();

	public function __construct( \Slim\Slim $slimInstance ){
		$this->Slim = $slimInstance;
		$this->Logger = new Logger();

		// inherit loglevel from Slim instance
		$this->logLevel = $slimInstance->log->getLevel();

		$slimInstance->view()->parserOptions = array('debug' => true);
		$slimInstance->view()->parserExtensions = new TwigExtension();
	}

	/**
	 * calls a slim routing method
	 * @param  [string] $method
	 * @param  [string] $path
	 * @param  [string] $controller
	 * @return [void]
	 */
	protected function callSlimApi( $method, $path, $controller ){
		$self = $this;

		if( 8 <= $this->logLevel && $this->enableLogging ){
			$this->Logger->write('adding route: ' . $method . '('.$path.') :: ' . $controller);
		}
		
		// e.g. $slim->get($path, $callback); the $callback calls the defined controller.
		call_user_func( array( $this->Slim, $method ), $path, function() use (&$self, &$controller){

			// params are optional routing args
			$params = func_get_args();

			// call controller
			$self->callController($controller, $params);
		});
	}

	/**
	 * sets internal conditional tags array
	 * @reference http://codex.wordpress.org/Conditional_Tags
	 * @uses  wp-conditional-tags-functions
	 * @return array
	 */
	protected function getConditionalTags(){
		return array(
			'home' => \is_home(),
			'front_page' => \is_front_page(),
			'blog_page' => \is_home() && is_front_page(),
			'admin' => \is_admin(),
			'single' => \is_single(),
			'page' => \is_page(),
			'page_template' => \is_page_template(),
			'category' => \is_category(),
			'tag' => \is_tag(),
			'tax' => \is_tax(),
			'archive' => \is_archive(),
			'search' => \is_search(),
			'singular' => \is_singular(),
			'404' => \is_404()
		);
	}

	/**
	 * checks for matching conditional tags.
	 * if something matches it runs the controller.
	 * returns true if route was found otherwise false.
	 * @return [boolean]
	 */
	protected function runConditionalRoutes(){
		$routeMatches = false;
		$routeController = false;

		foreach( $this->conditionalRoutes as $conditionKey => $controller ){

			$conditions = explode(',', $conditionKey);

			// logging
			if( 8 <= $this->logLevel && $this->enableLogging ){
				$this->Logger->write('checking conditional match for: '. $conditionKey);
			}

			if( $this->matchConditionalRoute( $conditions ) ){
				$routeMatches = true;
				$routeController = $controller;

				// logging
				if( 8 <= $this->logLevel && $this->enableLogging ){
					$this->Logger->write('matched');
				}

				break;
			}else{

				// logging
				if( 8 <= $this->logLevel && $this->enableLogging ){
					$this->Logger->write('no match found.');
				}

			}
		}

		if( $routeMatches ){
			$this->callController($controller);
		}

		return $routeMatches;
	}

	/**
	 * checks if an array of condition matches to the 
	 * conditionalTags of this request.
	 * returns true if a match was found
	 * @param  array  $conditions
	 * @return [boolean]
	 */
	protected function matchConditionalRoute( array $conditions ){

		$matches = 0;
		$count = count($conditions);

		foreach($conditions as $condition){
			if( true === $this->conditionalTags[ $condition ] ){
				$matches++;
			}
		}

		if( $matches === $count ){
			return true;
		}
		return false;
	}

	/**
	 * calls $controller with $controllerNamespace
	 * also passes $params for optional arguments in route 
	 * e.g. /foo( /:param1 ( /:param2 ) )
	 * param1 & param2 is optional
	 * @param  [string] $controller
	 * @param  [array] $params
	 * @return [void]
	 */
	protected function callController( $controller, array $params=array() ){
		$baseNamespace = $this->controllerNamespace;
		$class = $baseNamespace . $controller;
		$reflection = new \ReflectionClass( $class );
		$instance = $reflection->newInstanceArgs(array(
			$this->Slim,
			$params
		));
	}

	/**
	 * runs the routing engine.
	 * first: checks if conditional tags have been found.
	 * second: run Slim routes.
	 * @return [type] [description]
	 */
	public function run(){
		$this->Slim->run();
	}

	/**
	 * sets the Slim instance
	 * @param SlimSlim $Slim
	 */
	public function setSlimInstance( \Slim\Slim $Slim){
		$this->Slim = $Slim;
		return $this;
	}

	/**
	 * returns the Slim instance
	 * @return [Slim]
	 */
	public function getSlimInstance(){
		return $this->Slim;
	}

	/**
	 * sets the controllerNamespace
	 * @param [string] $ns
	 * @return  Router
	 */
	public function setControllerNamespace( $ns ){
		$this->controllerNamespace = $ns;
		return $this;
	}

	/**
	 * sets conditional routing logic
	 * @param  [string|array]  $conditionals
	 * @param  [controller]  $controller
	 * @return this
	 */
	public function is( $conditionals, $controller ){
		if( is_string( $conditionals) ){
			$this->conditionalRoutes[ $conditionals ] = $controller;
		}elseif( is_array($conditionals) ){
			$key = implode(',', $conditionals);
			$this->conditionalRoutes[$key] = $controller;
		}

		return $this;
	}

	/**
	 * assigns routes to Slim instance;
	 * explicit routes are preferred;
	 * conditional routes are run if NO explicit routes are found
	 * @return [type] [description]
	 */
	public function assignRoutes(){

		$self = $this;

		// explicit routes will be called BEFORE this one
		// use default Route
		// which handles the conditional Logic
		$this->Slim->get('/.*?', function() use (&$self){
			if( 8 <= $this->logLevel && $this->enableLogging ){
				$this->Logger->write('checking conditional routes...');
			}
			if( false === $self->runConditionalRoutes() ){
				echo "no routes found.";
			}
		});
	}

	/**
	 * sets internal Conditional tags
	 */
	public function setConditionalTags(){
		$this->conditionalTags = $this->getConditionalTags();
	}

	/**
	 * adds Middleware to Slim application
	 * @param [object] $middleware
	 */
	public function addMiddleware( $middleware ){
		$this->Slim->add( $middleware );
	}

	// slim api shortcut
	public function get($path, $controller){
		$this->callSlimApi('get', $path, $controller);
	}

	// slim api shortcut
	public function post(){
		$this->callSlimApi('post', $path, $controller);
	}

	// slim api shortcut
	public function put(){
		$this->callSlimApi('put', $path, $controller);
	}

	// slim api shortcut
	public function delete(){
		$this->callSlimApi('delete', $path, $controller);
	}

	// slim api shortcut
	public function patch(){
		$this->callSlimApi('patch', $path, $controller);
	}

}