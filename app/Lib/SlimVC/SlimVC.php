<?php
namespace App\Lib\SlimVC;

use \App\Lib\SlimVC\EventEmitter as EventEmitter;
use \App\Lib\SlimVC\ConfigManager as ConfigManager;
use \App\Lib\SlimVC\Debugger as Debugger;
use \App\Lib\SlimVC\Router as Router;
use \Slim\Views\Twig as TwigView;
use \Slim\Slim as Slim;

/**
 * This is a singleton Class that wrapps:
 * - \Slim\Slim  (router micro framework, TWIG templating engine)
 * - \SlimVC\Router (router extension for WP conditionals)
 * - \SlimVC\ConfigManager (set-up, include & cache your app/conf dir)
 * - \SlimVC\EventEmitter (public EventEmitter API provider)
 *
 * There is no public API on this class,
 * BUT there are public methods for the Wordpress action API callbacks.
 * (which SHOULD NOT be called)
 *
 * @example 
 * 		$instance = \App\Lib\SlimVC::getInstance();
 */
class SlimVC{

	/**
	 * holds our singleton 
	 * @var [SlimVC]
	 */
	private static $instance = null;

	/**
	 * holds our merged slimOptions
	 * @var [array]
	 */
	public $slimOptions = null;

	/**
	 * our application configuration
	 * this is set by ConfigurationManager
	 * which uses app/Config/application.php
	 * @var array
	 */
	public $applicationConfiguration = array();

	/**
	 * holds our Router class
	 * @var [Router]
	 */
	public $Router = null;

	/**
	 * private clone method.
	 * we dont want this object to be cloned from outside
	 * @return [void]
	 */
	private function __clone(){}

	/**
	 * sets the slimOptions, registers the wp-core-callbacks
	 * 
	 * @param [array] $slimOptions [description]
	 * @uses  add_action [wordpress-core]
	 */
	private function __construct(){
		$that = $this;

		// init conf manager
		$this->ConfigManager = new ConfigManager( $this );
		
		// call slim with merged conf
		$this->Slim = new Slim( $this->applicationConfiguration );

		// init the rest of our helper calsses
		$this->Slim->Event = new EventEmitter();
		$this->Slim->Router = new Router( $this );

		if( true === $this->applicationConfiguration['debug'] ){
			// debugger is not ready yet.
			//$this->Slim->Debugger = new Debugger();
		}
		// @TODO
		// register a custom error handler for slim
		$this->Slim->error(function($e) use (&$that){
			if( is_array($that->applicationConfiguration)
				&&
				true === $that->applicationConfiguration['debug'] ){
				call_user_func(array($that->Router, 'errorHandler'));
			}
		});

		// configure slim view cache
		$this->Slim->view()->parserOptions = array(
			'debug' => $this->applicationConfiguration['debug'],
			'cache' => $this->applicationConfiguration['twig.cache.dir']
		);

		// add necessary action & filter callbacks
		add_action( 'muplugins_loaded', array($this, 'onMuPluginsLoaded') );		
		add_action( 'plugins_loaded', array($this, 'onPluginsLoaded') );		
		add_action( 'setup_theme', array($this, 'onSetupTheme') );		
		add_action( 'after_setup_theme', array($this, 'onAfterSetupTheme') );		
		add_action( 'init' , array($this, 'onInit') );
		add_action( 'wp_loaded', array($this, 'onWpLoaded') );
		add_action( 'template_redirect', array($this, 'onTemplateRedirect') );
		
		// lets use our own canonical redirect filter
		// we do not want to redirect misspelled URLs 
		// because this would redirect before our router is initialized
		remove_filter('template_redirect', 'redirect_canonical');

	}

	/**
	 * sets the ACF-Export path for the json files.
	 * on each save on a field group the json is created.
	 * @return  [void]
	 */
	protected function setAcfJsonPath(){
		if( is_admin() && function_exists('acf_update_setting') && function_exists('acf_append_setting') ){
			acf_update_setting('save_json', get_stylesheet_directory() . '/app/Config/acf');
			acf_append_setting('load_json', get_stylesheet_directory() . '/app/Config/acf');
		}
	}

	/**
	 * singleton constructor / getter
	 * @param  [array] $opts
	 * @return [SlimVC]
	 */
	public static function getInstance( array $opts = array() ){
		if( null === self::$instance ){
			self::$instance = new self($opts);
		}
		return self::$instance->Slim;
	}

	/**
	 * event callback for muplugins_loaded
	 * @return [void]
	 */
	public function onMuPluginsLoaded(){
		$this->Slim->Event->emit('muplugins_loaded');
	}

	/**
	 * event callback for plugins_loaded
	 * @return [void]
	 */
	public function onPluginsLoaded(){
		$this->Slim->Event->emit('plugins_loaded');
	}

	/**
	 * event callback for setup_theme
	 * @return [void]
	 */
	public function onSetupTheme(){
		$this->Slim->Event->emit('setup_theme');
	}

	/**
	 * event callback for after_setup_theme
	 * @return [void]
	 */
	public function onAfterSetupTheme(){
		$this->Slim->Event->emit('after_setup_theme');
	}

	/**
	 * registers custom post-types and custom taxonomies
	 * event callback for init
	 * @return [void]
	 */
	public function onInit(){
		$this->setAcfJsonPath();
		$this->ConfigManager->initWpHooks();
		$this->Slim->Event->emit('init');
	}

	/**
	 * event callback for wp_loaded
	 * @return [void]
	 */
	public function onWpLoaded(){
		$this->Slim->Event->emit('wp_loaded');
	}

	/**
	 * event callback for template_redirect
	 * this is the first action hook when conditional tags are available
	 * @return [void]
	 */
	public function onTemplateRedirect(){
		$this->Slim->Event->emit('template_redirect');
		// use own canonical redirect filter.
		$this->Slim->Router->setConditionalTags();
		$this->Slim->Router->assignRoutes();
		$this->Slim->Router->run();
		if( $this->Slim->Debugger ){
			$this->Slim->Debugger->printStack();
		}
		
		echo $this->Slim->Router->Logger->flush();
	}

}