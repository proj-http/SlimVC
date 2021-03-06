<?php

namespace App\Lib\SlimVC;

use \App\Lib\SlimVC\PageTemplate as PageTemplate;


class ConfigManager{

	/**
	 * default configuration directory
	 * @var string
	 */
	protected $configDir = '../../Config';

	/**
	 * extension of config files
	 * @var string
	 */
	protected $extension = '.php';

	/**
	 * holds a PageTemplate instance
	 * @var [\App\Lib\SlimVC\PageTemplate]
	 */
	protected $PageTemplate;

	/**
	 * filenames which will be included
	 * @var array
	 */
	protected $fileNames = array(
		'application',
		'images',
		'menus',
		'sidebars',
		'templates',
		'postTypes',
		'taxonomies',
		'routes'
	);

	protected $defaults = array(
		// app defaults are @getApplicationDefaults()
		'application' => array(),
		'images' => array(),
		'menus' => array(),
		'sidebars' => array(),
		'templates' => array(),
		'postTypes' => array(),
		'taxonomies' => array(),
		'routes' => array(
			'explicit' => array(),
			'conditional' => array()
		)
	);

	/**
	 * APC specific 
	 * @var boolean
	 */
	protected $APC_ENABLED = false;

	/**
	 * APC namespace-prefix
	 * @var string
	 */
	protected $APC_PREFIX = 'CONFIG_MANAGER_';

	/**
	 * default image size definition array
	 * @var array
	 */
	protected $defaultImageSize = array(0,0,false);

	/**
	 * construcor. takes a SLimVC object instnace
	 * @param [SlimVC] $parent
	 */
	public function __construct( $parent ){
		$this->parent = $parent;

		$this->APC_ENABLED = extension_loaded('apc') && ini_get('apc.enabled');

		$this->PageTemplate = new PageTemplate();
		$this->readConfigDir();
		$this->applyConfiguration();
		$this->initWpHooks();
	}

	/**
	 * reads the configuration directory
	 * and sets the internals per configuration
	 * @return [void]
	 */
	protected function readConfigDir(){

		// remove application conf from filenames
		$strippedFilenames = array_diff($this->fileNames, array('application') );

		// read app conf directly
		$this->application = array_merge( $this->getApplicationDefaults(), $this->read('application') );

		// the rest might be cached if available
		foreach( $strippedFilenames as $file ){

			// apc enabled ?
			if( $this->APC_ENABLED ){
				// fetch from cache if exists
				if( (!apc_exists( $this->APC_PREFIX . 'FILE_' . $file )) && (true !== $this->application['debug']) ){
					$content = $this->mergeWithDefaults( $file, $this->read($file) );
					apc_add($this->APC_PREFIX . 'FILE_' . $file, $content);
					$this->$file = $content;
				}else{
					$this->$file = apc_fetch($this->APC_PREFIX . 'FILE_' . $file);
				}
			}else{
				$this->$file = $this->mergeWithDefaults( $file, $this->read($file) );
			}
		}
	}

	/**
	 * merges $file as key from $this->defaults array with provided $config
	 * @param  [string] $file
	 * @param  [array] $config
	 * @return [array]
	 */
	protected function mergeWithDefaults($file, $config){
		if( isset( $this->defaults[$file] ) ){
			return array_merge($this->defaults[$file], $config);
		}
		return $config;
	}

	/**
	 * reads a file and returns its content
	 * @param  [string] $file
	 * @return [mixed]
	 */
	protected function read($file){
		$path = trailingslashit(dirname(__FILE__)) . trailingslashit($this->configDir) . $file . $this->extension;
		if( file_exists( $path ) ){
			return include $path;
		}else{
			return array();
		}
	}

	/**
	 * application-wide default configuration
	 * @var array
	 */
	protected function getApplicationDefaults(){
		return array(
			'debug' => true,
			'namespace.controller' => '\\App\\Conrollers\\',
			'log.enabled' => true,
			'log.level' => \Slim\Log::DEBUG,
			'view' => new \Slim\Views\Twig(),
			'templates.path' => dirname(__FILE__) . '/../Views',
			'twig.cache.dir' => dirname(__FILE__) . '/../../cache'
		);
	}

	/**
	 * applys the configuration to wp core
	 * @return [void]
	 */
	protected function applyConfiguration(){

		if( is_array($this->images) ){
			// register image sizes
			foreach( $this->images as $name => $opts){
				$options = $opts + $this->defaultImageSize;
				add_image_size($name, $options[0], $options[1], $options[2]);
			}
		}
		
		if( is_array($this->templates) ){
			// add page templates
			foreach( $this->templates as $slug=>$name ){
				$this->PageTemplate->addPageTemplate($name, $slug);
			}
		}

		if( is_array($this->menus) ){
			// add nav menus
			foreach( $this->menus as $slug=>$name ){
				register_nav_menu( $slug, $name );
			}
		}
		
		if( is_array($this->sidebars) ){
			// add sidebars
			foreach( $this->sidebars as $config ){
				register_sidebar($config);
			}
		}
		

		if( is_array($this->application) && isset($this->application['slim']) && is_array($this->application['slim']) ){
			$slimOptions = array_merge( $this->parent->slimOptions, $this->application['slim'] );
			$this->parent->slimOptions = $slimOptions;
			$this->application['slim'] = $slimOptions;
		}
		
		$this->parent->routeConfiguration = $this->routes;
		$this->parent->applicationConfiguration = $this->application;

	}

	/**
	 * sets up the CT & CPT registration hooks
	 * this is called from SlimVC
	 * @return [void]
	 */
	public function initWpHooks(){
		$ct = $this->taxonomies;
		$cpt = $this->postTypes;

		// register CT
		if( is_array($ct) ){
			
			foreach( $ct as $slug=>$opts ){
				
				$args = null;
				$postType = 'post';

				if( is_array($opts) ){
					
					// check for args array
					if( isset($opts['args']) && is_array($opts['args']) && !empty($opts['args']) ){
						$args = $opts['args'];
					}

					// check for postType def
					if( isset($opts['postType']) && !empty($opts['postType']) ){
						$postType = $opts['postType'];
					}
					// register
					register_taxonomy($slug, $postType, $args);
				}
				
			}
		}	
			
		// register CPT
		if( is_array($cpt) ){
			
			foreach($cpt as $slug=>$opts){
				register_post_type($slug, $opts);
			}
		}
		
	}

}