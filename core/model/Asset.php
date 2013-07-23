<?php
/**
 * Asset class
 * Catalog product assets (metadata, images, downloads)
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, 28 March, 2008
 * @package shopp
 **/

defined( 'WPINC' ) || header( 'HTTP/1.1 403' ) & exit; // Prevent direct access

/**
 * FileAsset class
 *
 * Foundational class to provide a useable asset framework built on the meta
 * system introduced in Shopp 1.1.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage asset
 **/
class FileAsset extends MetaObject {

	public $mime;
	public $size;
	public $storage;
	public $uri;
	public $context = 'product';
	public $type = 'asset';
	public $_xcols = array('mime', 'size', 'storage', 'uri');

	public function __construct ( $id = false ) {
		$this->init(self::$table);
		$this->extensions();
		if ( ! $id ) return;
		$this->load($id);

		if ( ! empty($this->id) )
			$this->expopulate();
	}

	/**
	 * Populate extended fields loaded from the MetaObject
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function expopulate () {
		parent::expopulate();
		$this->uri = stripslashes($this->uri);
	}

	/**
	 * Store the file data using the preferred storage engine
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function store ( $data, $type = 'binary' ) {
		$Engine = $this->engine();
		$this->uri = $Engine->save($this, $data, $type);
		if ($this->uri === false) return false;
		return true;
	}

	/**
	 * Retrieve the resource data
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function retrieve () {
		$Engine = $this->engine();
		return $Engine->load($this->uri);
	}

	/**
	 * Retreive resource meta information
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function readmeta () {
		$Engine = $this->engine();
		list($this->size, $this->mime) = array_values($Engine->meta($this->uri, $this->name));
	}

	/**
	 * Determine if the resource exists
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function found ( $uri = false ) {
		if ( ! empty($this->data) ) return true;
		if ( ! $uri && ! $this->uri ) return false;
		if ( ! $uri ) $uri = $this->uri;
		$Engine = $this->engine();
		return $Engine->exists($uri);
	}

	/**
	 * Determine the storage engine to use
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function &engine () {
		global $Shopp;

		if ( ! isset($Shopp->Storage) )	$Shopp->Storage = new StorageEngines();
		$StorageEngines = $Shopp->Storage;

		$Engine = false;
		if ( empty($this->storage) )
			$this->storage = $StorageEngines->type($this->type);

		$Engine = $StorageEngines->get($this->storage);

		if ( false === $Engine ) // If no engine found, force DBStorage (to provide a working StorageEngine to the Asset)
			$Engine = $StorageEngines->activate('DBStorage');

		if ( false === $Engine ) // If no engine is available at all, we're screwed.
			die('No Storage Engine available. Cannot continue.');

		$Engine->context($this->type);

		return $Engine;
	}

	/**
	 * Stub for extensions
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function extensions () {
		/** Not Implemented **/
	}

} // END class FileAsset

/**
 * ImageAsset class
 *
 * A specific implementation of the FileAsset class that provides helper
 * methods for imaging-specific tasks.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 **/
class ImageAsset extends FileAsset {

	// Allowable settings
	public $_scaling = array('all', 'matte', 'crop', 'width', 'height');
	public $_sharpen = 500;
	public $_quality = 100;

	public $width;
	public $height;
	public $alt;
	public $title;
	public $settings;
	public $filename;
	public $type = 'image';

	// Direct URL support
	public $direct_url = '';
	protected $is_directly_accessible = null;
	protected $base_dir ='';
	protected $base_url = '';

	/**
	 * Determines if the (original) image is directly accessible. This method must be called and a
	 * (bool) true result obtained before trying to access the direct_url property.
	 *
	 * Where direct image URLs are undesirable, SHOPP_DIRECT_IMG_MODE should be defined
	 * as false.
	 *
	 * @return bool
	 */
	public function directly_accessible () {
		return false;
		// Only determine this once then save the result
		if ($this->is_directly_accessible === null) {
			if (defined('SHOPP_DIRECT_IMG_MODE') && !SHOPP_DIRECT_IMG_MODE) // Direct mode can be disallowed
				$this->is_directly_accessible = false;

			if ($this->determine_base_url()) {
				$this->set_direct_url();
				$this->is_directly_accessible = true;
			}
			else $this->is_directly_accessible = false;
		}
		// Return the saved result
		return $this->is_directly_accessible;
	}


	/**
	 * Tries to determine the URL of image assets stored using FSStorage.
	 * Returns (bool) true on success, otherwise false.
	 *
	 * @return bool
	 */
	public function determine_base_url() {
		// Allow the base URL to be provided from within a theme/plugin
		$this->base_url = apply_filters('shopp_direct_img_base', '');

		// Otherwise try to form the base storage URL
		if (empty($this->base_url)) {
			$storage = shopp_setting('FSStorage');
			if (empty($storage) || !isset($storage['path']['image']))
				return false;

			$this->base_dir = trailingslashit($storage['path']['image']);
			$this->base_url = $this->find_public_url($this->base_dir);
		}

		if (empty($this->base_url)) return false;
		return true;
	}


	/**
	 * Tries to find the public URL for Shopp product images stored using the
	 * FSStorage engine. Not bulletproof, it assumes that either the directory
	 * is subordinate to ABSPATH or is anyway relative to wp-content.
	 *
	 * @param string $storagepath
	 * @return string
	 */
	protected function find_public_url($storage_path) {
		$wp_url = get_option('siteurl');
		$wp_dir = trim(ABSPATH, '/');
		$storage_dir = trim($storage_path, '/');

		$wp_dir = explode('/', $wp_dir);
		$storage_dir = explode('/', $storage_dir);

		// Determine if the storage path leads to a WP sub-directory
		for ($segment = 0; $segment < count($wp_dir); $segment++)
			if ($wp_dir[$segment] !== $storage_dir[$segment])
				// Bad match? Check if we have a relative-to-wp-content path instead
				return $this->relative_path_or_false($storage_path);

		// Supposing the image directory isn't the WP root, append the trailing component
		if (count($storage_dir) > count($wp_dir))
			$trailing_component = join('/', array_slice($storage_dir, count($wp_dir)));

		// Under normal circumstances we now have the public URL for the image dir
		if (isset($trailing_component)) $public_url = trailingslashit($wp_url).$trailing_component;
		else $public_url = $wp_url;

		return trailingslashit($public_url);
	}



	/**
	 * Tests if the path leads to a real directory that is subordinate to the
	 * wp-content dir, or returns bool false.
	 */
	protected function relative_path_or_false($path) {
		$path = trim($path, '/');
		$wp_content = trailingslashit(WP_CONTENT_DIR);
		$path = $wp_content.$path;

		if (is_dir($path)) return trailingslashit(WP_CONTENT_URL).$path;
		return false;
	}


	/**
	 * Combines the object's base_url and uri properties (uri is dynamically assigned)
	 * into a single directly accessible URL.
	 */
	protected function set_direct_url() {
		if (property_exists($this, 'uri')) $this->direct_url = $this->base_url.$this->uri;
	}


	/**
	 * Returns a URL for a resized image.
	 *
	 * If direct mode is enabled (which it is by
	 * default) and the image is already cached to the file system then a URL for that
	 * file will be returned.
	 *
	 * In all other cases a Shopp Image Server URL will be returned.
	 *
	 * @param $width
	 * @param $height
	 * @param $scale
	 * @param $sharpen
	 * @param $quality
	 * @param $fill
	 * @return string
	 */
	public function resized_url($width, $height, $scale = false, $sharpen = false, $quality = false, $fill = false) {
		$size_query = $this->resizing($width, $height, $scale, $sharpen, $quality, $fill);

		$accessible = $this->directly_accessible();

		if ($this->directly_accessible()) {
			$size = $this->cache_filename_params($size_query);
			$path = "cache_{$size}_{$this->uri}";

			// Final check: the cached image may not exist in this size
			if (file_exists($this->base_dir.$path))
				return $this->base_url.$path;
		}
		return add_query_string($size_query, Shopp::url($this->id, 'images'));
	}


	/**
	 * Takes the comma separated output of the resizing() method and returns the
	 * equivalent filename component.
	 *
	 * @param $size_query
	 * @return string
	 */
	protected function cache_filename_params($size_query) {
		$size_query = explode(',', $size_query);
		array_pop($size_query); // Lop off the validation variable
		return implode('_', $size_query);
	}


	public function output ($headers=true) {

		if ( $headers ) {
			$Engine = $this->engine();
			$data = $this->retrieve($this->uri);

			$etag = md5($data);
			$offset = 31536000;

			if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
				if (@strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $this->modified ||
					trim($_SERVER['HTTP_IF_NONE_MATCH']) == $etag) {
					header("HTTP/1.1 304 Not Modified");
					header("Content-type: {$this->mime}");
					exit;
				}
			}

			header("Cache-Control: public, max-age=$offset");
			header('Expires: ' . gmdate( "D, d M Y H:i:s", time() + $offset ) . ' GMT');
			header('Last-Modified: '.date('D, d M Y H:i:s', $this->modified).' GMT');
			if (!empty($etag)) header('ETag: '.$etag);

			header("Content-type: $this->mime");

			$filename = empty($this->filename) ? "image-$this->id.jpg" : $this->filename;
			header('Content-Disposition: inline; filename="'.$filename.'"');
			header("Content-Description: Delivered by WordPress/Shopp Image Server ({$this->storage})");
		}

		if (!empty($data)) echo $data;
		else $Engine->output($this->uri);
		ob_flush(); flush();
		return;
	}

	public function scaled ( $width, $height, $fit = 'all' ) {
		if ( preg_match('/^\d+$/', $fit) )
			$fit = $this->_scaling[ $fit ];

		$d = array('width'=>$this->width,'height'=>$this->height);
		switch ($fit) {
			case "width": return $this->scaledWidth($width,$height); break;
			case "height": return $this->scaledHeight($width,$height); break;
			case "crop":
			case "matte":
				$d['width'] = $width;
				$d['height'] = $height;
				break;
			case "all":
			default:
				if ($width/$this->width < $height/$this->height) return $this->scaledWidth($width,$height);
				else return $this->scaledHeight($width,$height);
				break;
		}

		return $d;
	}

	public function scaledWidth ($width,$height) {
		$d = array('width'=>$this->width,'height'=>$this->height);
		$scale = $width / $this->width;
		$d['width'] = $width;
		$d['height'] = ceil($this->height * $scale);
		return $d;
	}

	public function scaledHeight ($width,$height) {
		$d = array('width'=>$this->width,'height'=>$this->height);
		$scale = $height / $this->height;
		$d['height'] = $height;
		$d['width'] = ceil($this->width * $scale);
		return $d;
	}

	/**
	 * Generate a resizing request message
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param $width
	 * @param $height
	 * @param bool $scale
	 * @param bool $sharpen
	 * @param bool $quality
	 * @param bool $fill
	 * @return void
	 */
	public function resizing ($width,$height,$scale=false,$sharpen=false,$quality=false,$fill=false) {
		$key = (defined('SECRET_AUTH_KEY') && SECRET_AUTH_KEY != '') ? SECRET_AUTH_KEY : DB_PASSWORD;
		$args = func_get_args();

		if ($args[1] == 0) $args[1] = $args[0];

		$message = rtrim(join(',',$args),',');

		$validation = sprintf('%u',crc32($key.$this->id.','.$message));
		$message .= ",$validation";
		return $message;
	}

	public function extensions () {
		array_push($this->_xcols,'filename','width','height','alt','title','settings');
	}

	/**
	 * unique - returns true if the the filename is unique, or can be made unique reasonably
	 *
	 * @author John Dillick
	 * @since 1.2
	 *
	 * @return bool true on success, false on fail
	 **/
	public function unique () {
		$Existing = new ImageAsset();
		$Existing->uri = $this->filename;
		$limit = 100;
		while ( $Existing->found() ) { // Rename the filename of the image if it already exists
			list( $name, $ext ) = explode(".", $Existing->uri);
			$_ = explode("-", $name);
			$last = count($_) - 1;
			$suffix = $last > 0 ? intval($_[$last]) + 1 : 1;
			if ( $suffix == 1 ) $_[] = $suffix;
			else $_[$last] = $suffix;
			$Existing->uri = join("-", $_).'.'.$ext;
			if ( ! $limit-- ) return false;
		}
		if ( $Existing->uri !== $this->filename )
			$this->filename = $Existing->uri;
		return true;
	}
}

/**
 * ProductImage class
 *
 * An ImageAsset used in a product context.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 **/
class ProductImage extends ImageAsset {
	public $context = 'product';

	/**
	 * Truncate image data when stored in a session
	 *
	 * A ProductImage can be stored in the session with a cart Item object. We
	 * strip out unnecessary fields here to keep the session data as small as
	 * possible.
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return array
	 **/
	public function __sleep () {
		$ignore = array('numeral', 'created', 'modified', 'parent');
		$properties = get_object_vars($this);
		$session = array();
		foreach ( $properties as $property => $value ) {
			if ( substr($property, 0, 1) == "_" ) continue;
			if ( in_array($property,$ignore) ) continue;
			$session[] = $property;
		}
		return $session;
	}
}

/**
 * CategoryImage class
 *
 * An ImageAsset used in a category context.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage asset
 **/
class CategoryImage extends ImageAsset {
	public $context = 'category';
}

/**
 * DownloadAsset class
 *
 * A specific implementation of a FileAsset that includes helper methods
 * for downloading routines.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage asset
 **/
class DownloadAsset extends FileAsset {

	public $type = 'download';
	public $context = 'product';
	public $etag = "";
	public $purchased = false;

	public function loadby_dkey ($key) {
		$db = &DB::get();
		if (!class_exists('Purchased')) require(SHOPP_MODEL_PATH."/Purchased.php");
		$pricetable = DatabaseObject::tablename(Price::$table);

		$Purchased = new Purchased($key,'dkey');
		if (!empty($Purchased->id)) {
			// Handle purchased line-item downloads
			$Purchase = new Purchase($Purchased->purchase);
			$record = $db->query("SELECT download.* FROM $this->_table AS download INNER JOIN $pricetable AS pricing ON pricing.id=download.parent WHERE pricing.id=$Purchased->price AND download.context='price' AND download.type='download' ORDER BY modified DESC LIMIT 1");
			$this->populate($record);
			$this->expopulate();
			$this->purchased = $Purchased->id;
		} else {
			// Handle purchased line-item meta downloads (addon downloads)
			$this->load(array(
				'context' => 'purchased',
				'type' => 'download',
				'name' => $key
			));
			$this->expopulate();
			$this->purchased = $this->parent;
		}

		$this->etag = $key;
	}

	public function purchased () {
		if (!class_exists('Purchased')) require(SHOPP_MODEL_PATH."/Purchased.php");
		if (!$this->purchased) return false;
		return new Purchased($this->purchased);
	}

	public function download ($dkey=false) {
		$found = $this->found();
		if (!$found) return new ShoppError(sprintf(__('Download failed. "%s" could not be found.','Shopp'),$this->name),'false');

		add_action('shopp_download_success',array($this,'downloaded'));

		// send immediately if the storage engine is redirecting
		if ( isset($found['redirect']) ) {
			$this->send();
			exit();
		}

		// Close the session in case of long download
		@session_write_close();

		// Don't want interference from the server
		if ( function_exists('apache_setenv') ) @apache_setenv('no-gzip', 1);
		@ini_set('zlib.output_compression', 0);

		set_time_limit(0);	// Don't timeout on long downloads

		// Use HTTP/1.0 Expires to support bad browsers (trivia: timestamp used is the Shopp 1.0 release date)
		header('Expires: '.date('D, d M Y H:i:s O',1230648947));

		header('Cache-Control: maxage=0, no-cache, must-revalidate');
		header('Content-type: application/octet-stream');
		header("Content-Transfer-Encoding: binary");
		header('Content-Disposition: attachment; filename="'.$this->name.'"');
		header('Content-Description: Delivered by WordPress/Shopp '.SHOPP_VERSION);

		ignore_user_abort(true);
		ob_end_flush(); // Don't use the PHP output buffer

		$this->send();	// Send the file data using the storage engine

		flush(); // Flush output to browser (to poll for connection)
		if (connection_aborted()) return new ShoppError(__('Connection broken. Download attempt failed.','Shopp'),'download_failure',SHOPP_COMM_ERR);

		return true;
	}

	public function downloaded ($Purchased=false) {
		if (false === $Purchased) return;
		$Purchased->downloads++;
		$Purchased->save();
	}

	public function send () {
		$Engine = $this->engine();
		$Engine->output($this->uri,$this->etag);
	}

}

class ProductDownload extends DownloadAsset {
	public $context = 'price';
}

/**
 * StorageEngines class
 *
 * Storage engine file manager to load storage engines that are active.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage storage
 **/
class StorageEngines extends ModuleLoader {

	public $engines = array();
	public $contexts = array('image', 'download');
	public $activate = false;

	/**
	 * Initializes the shipping module loader
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function __construct () {
		$this->path = SHOPP_STORAGE;

		if ( function_exists('add_action') )
			add_action('shopp_module_loaded', array($this, 'actions'));

		$this->installed();
		$this->activated();
		$this->load();
	}

	/**
	 * Determines the activated storage engine modules
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return array List of module names for the activated modules
	 **/
	public function activated () {
		global $Shopp;

		$this->activated = array();

		$systems = array();
		$systems['image'] = shopp_setting('image_storage');
		$systems['download'] = shopp_setting('product_storage');

		foreach ( $systems as $system => $storage ) {
			foreach ( $this->modules as $engine ) {
				if ( $engine->subpackage == $storage ) {
					$this->activated[] = $engine->subpackage;
					$this->engines[$system] = $engine->subpackage;
					break; // Check for next system engine
				}
			}
		}

		return $this->activated;
	}

	/**
	 * Get a specific storage engine
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return StorageEngine or false if not able to be loaded
	 **/
	public function &get ( $module ) {

		if ( empty($this->active) )
			$this->activate($module);

		if ( ! isset($this->active[ $module ]) )
			return false;

		return $this->active[$module];
	}

	/**
	 * Gets the module name for the StorageEngine context type
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param string $type The context type
	 * @return string The engine module name or false
	 **/
	public function type ( $type ) {
		if ( ! isset($this->engines[ $type ]) ) return false;
		return $this->engines[ $type ];

	}

	/**
	 * Loads all the installed storage engine modules for the settings page
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function settings () {
		$this->load(true);
	}

	/**
	 * Initializes the settings UI for each loaded module
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function ui () {
		foreach ( $this->contexts as $context ) {
			foreach ( $this->active as $package => &$module ) {
				$module->context($context);
				$module->initui($package, $context);
			}
		}
	}

	public function templates () {
		foreach ( $this->active as $package => &$module )
			$module->uitemplate($package, $this->modules[ $package ]->name);
	}


	public function actions ( $module ) {
		if ( ! isset($this->active[ $module ]) ) return;

		// Register contexts the module is a handler for
		foreach ( $this->engines as $system => $handler )
			if ($module == $handler) $this->active[ $module ]->contexts[] = $system;

		if ( method_exists($this->active[ $module ], 'actions') )
			$this->active[ $module ]->actions();
	}

}

/**
 * StorageEngine interface
 *
 * Provides a template for storage engine modules to implement
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage storage
 **/
interface StorageEngine {

	/**
	 * Load a resource by the uri
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $uri The uniform resource indicator
	 * @return void
	 **/
	public function load( $uri );

	/**
	 * Output the asset data of a given uri
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $uri The uniform resource indicator
	 * @return void
	 **/
	public function output( $uri );

	/**
	 * Checks if the binary data of an asset exists
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $uri The uniform resource indicator
	 * @return boolean
	 **/
	public function exists( $uri );

	/**
	 * Store the data for an asset
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param FileAsset $asset The parent asset for the data
	 * @param mixed $data The raw data to be stored
	 * @param string $type (optional) Type of data source, one of binary or file (file referring to a filepath)
	 * @return void
	 **/
	public function save( $asset, $data, $type = 'binary' );

}

/**
 * StorageModule class
 *
 * A framework for storage engine modules.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage storage
 **/
abstract class StorageModule {

	public $contexts;
	public $settings;

	public function __construct () {
		global $Shopp;
		$this->module = get_class($this);
		$this->settings = shopp_setting($this->module);
	}

	public function context ($setting) {

	}

	public function settings ($context) {

	}

	/**
	 * Generate the settings UI for the module
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $module The module class name
	 * @param string $name The formal name of the module
	 * @return void
	 **/
	// public function setupui ($module,$name) {
	// 	$this->ui = new StorageSettingsUI('storage',$module,$name,false,false);
	// 	$this->settings();
	// }

	public function output ( $uri ) {
		$data = $this->load($uri);
		header ("Content-length: " . strlen($data));
		echo $data;
	}

	public function meta ( $arg1 = false, $arg2 = false ) {
		return false;
	}

	public function handles ( $context ) {
		return in_array($context, $this->contexts);
	}

	/**
	 * Generate the settings UI for the module
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $module The module class name
	 * @param string $name The formal name of the module
	 * @return void
	 **/
	public function initui ( $name, $context ) {
		$label = isset($this->settings['label']) ? $this->settings['label'] : $name;
		if ( ! isset($this->ui) || ! is_array($this->ui) ) $this->ui = array();
		$this->ui[ $context ] = new StorageSettingsUI($this, $name);
		$this->settings($context);
	}

	public function uitemplate () {
		$this->ui['image']->template();
	}

	public function ui ( $context ) {
		$editor = $this->ui[ $context ]->generate();

		$data = array('${context}' => $context);
		foreach ( $this->settings as $name => $value )
			$data['${'.$name.'}'] = $value[ $context ];

		return str_replace(array_keys($data), $data, $editor);
	}


}

class StorageSettingsUI extends ModuleSettingsUI {

	public function generate () {

		$_ = array();
		$_[] = '<div id="'.$this->id.'-settings">';
		foreach ($this->markup as $markup) {
			if (empty($markup)) continue;
			else $_[] = join("\n",$markup);
		}

		$_[] = '</div>';

		return join("\n",$_);

	}

	/**
	 * Renders a checkbox input
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; use 'checked' to set whether the element is toggled on or not
	 *
	 * @return void
	 **/
	public function checkbox ($column = 0, $attributes = array()) {
		if (isset($attributes['name']))
			$attributes['name'] .= '][${context}';
		parent::checkbox($column, $attributes);
	}

	/**
	 * Renders a drop-down menu element
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; use 'selected' to set the selected option
	 * @param array $options The available options in the menu
	 *
	 * @return void
	 **/
	public function menu ($column = 0, $attributes = array(), $options = array()) {
		$attributes['title'] = '${'.$attributes['name'].'}';
		if (isset($attributes['name']))
			$attributes['name'] .= '][${context}';
		parent::menu($column,$attributes,$options);
	}

	/**
	 * Renders a multiple-select widget
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; pass a 'selected' attribute as an array to set the selected options
	 * @param array $options The available options in the menu
	 *
	 * @return void
	 **/
	public function multimenu ($column = 0, $attributes = array(), $options = array()) {
		if (isset($attributes['name']))
			$attributes['name'] .= '][${context}';
		parent::multimenu($column,$attributes,$options);
	}

	/**
	 * Renders a text input
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	public function input ($column = 0, $attributes = array()) {
		if (isset($attributes['name'])) {
			$name = $attributes['name'];
			$attributes['value'] = '${'.$name.'}';
			$attributes['name'] .= '][${context}';
		}
		parent::input($column,$attributes);
	}


	/**
	 * Renders a text input
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	public function textarea ($column=0,$attributes=array()) {
		if (isset($attributes['name'])) {
			$name = $attributes['name'];
			$attributes['value'] = '${'.$name.'}';
			$attributes['name'] .= '][${context}';
		}
		parent::textarea($column,$attributes);
	}


	/**
	 * Renders a styled button element
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	public function button ($column=0,$attributes=array()) {
		if (isset($attributes['name'])) {
			$name = $attributes['name'];
			$attributes['value'] = '${'.$name.'}';
			$attributes['name'] .= '][${context}';
		}
		parent::button($column,$attributes);
	}

	public function behaviors ($script) {
		shopp_custom_script('system-settings',$script);
	}

}


// Prevent loading image setting classes when run in image server script context
if ( !class_exists('ListFramework') ) return;

class ImageSetting extends MetaObject {

	static $qualities = array(100,92,80,70,60);
	static $fittings = array('all','matte','crop','width','height');

	public $width;
	public $height;
	public $fit = 0;
	public $quality = 100;
	public $sharpen = 100;
	public $bg = false;
	public $context = 'setting';
	public $type = 'image_setting';
	public $_xcols = array('width','height','fit','quality','sharpen','bg');

	public function __construct ($id=false,$key='id') {
		$this->init(self::$table);
		$this->load($id,$key);
	}

	public function fit_menu () {
		return array(	__('All','Shopp'),
			__('Fill','Shopp'),
			__('Crop','Shopp'),
			__('Width','Shopp'),
			__('Height','Shopp')
		);
	}

	public function quality_menu () {
		return array(	__('Highest quality, largest file size','Shopp'),
			__('Higher quality, larger file size','Shopp'),
			__('Balanced quality &amp; file size','Shopp'),
			__('Lower quality, smaller file size','Shopp'),
			__('Lowest quality, smallest file size','Shopp')
		);
	}

	public function fit_value ($value) {
		if (isset(self::$fittings[$value])) return self::$fittings[$value];
		return self::$fittings[0];
	}

	public function quality_value ($value) {
		if (isset(self::$qualities[$value])) return self::$qualities[$value];
		return self::$qualities[2];
	}

	public function options ($prefix='') {
		$settings = array();
		$properties = array('width','height','fit','quality','sharpen','bg');
		foreach ($properties as $property) {
			$value = $this->{$property};
			if ('quality' == $property) $value = $this->quality_value($this->{$property});
			if ('fit' == $property) $value = $this->fit_value($this->{$property});
			$settings[$prefix.$property] = $value;
		}
		return $settings;
	}

} // END class ImageSetting

class ImageSettings extends ListFramework {

	private static $instance;

	public function __construct () {
		$ImageSetting = new ImageSetting();
		$table = $ImageSetting->_table;
		$where = array(
			"type='$ImageSetting->type'",
			"context='$ImageSetting->context'"
		);
		$options = compact('table','where');
		$query = DB::select($options);
		$this->populate(DB::query($query,'array',array($ImageSetting,'loader'),false,'name'));
		$this->found = DB::found();
	}

	/**
	 * Prevents cloning the DB singleton
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @return void
	 **/
	public function __clone () {
		trigger_error('Clone is not allowed.', E_USER_ERROR);
	}

	/**
	 * Provides a reference to the instantiated singleton
	 *
	 * The ImageSettings class uses a singleton to ensure only one DB object is
	 * instantiated at any time
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @return DB Returns a reference to the DB object
	 **/
	public static function &__instance () {
		if (!self::$instance instanceof self)
			self::$instance = new self;
		return self::$instance;
	}


} // END class ImageSettings