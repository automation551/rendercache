<?php

/*
 * RenderCache
 * Caches rendered files such as images or pdfs.
 *
 */
 
class RenderCache
{
	//The Render Cache path relative to HABARI_PATH. (trailing slash only)
	//Also represents the url relative to Site::get_url('habari').
	protected static $rel_cache_path = 'user/files/cache/';
	
	protected static $cache_path;
	protected static $cache_url;
	
	protected static $enabled;
	
	protected static $cache_data = array();
	protected static $group_list = array();
	
	protected static $default_group = 'default';
	
	
	/**
	 * Constructor for RenderCache
	 *
	 * Sets up paths and gets the list of groups from file
	 */
	public static function __static()
	{
		//Define the cache path and url
		self::$cache_path = HABARI_PATH . '/' . self::$rel_cache_path;
		self::$cache_url = Site::get_url('habari') . '/' . self::$rel_cache_path;
		
		//If the cache directory doesn't exist, make it
		if ( !is_dir ( self::$cache_path )) {
			mkdir( self::$cache_path, 0755 );
		}
		
		//Enable only if the cache directory now exists and is writable
		self::$enabled = ( is_dir( self::$cache_path ) && is_writeable( self::$cache_path ) );
		
		//Give an error if the cache directory is not writable
		if ( !self::$enabled ) {
			Session::error( sprintf( _t("The cache directory '%s' is not writable - the cache is disabled. The user, or group, which your web server is running as, needs to have read, write, and execute permissions on this directory."), self::$cache_path ), 'RenderCache' );
			EventLog::log( sprintf( _t("The cache directory '%s' is not writable - the cache is disabled."), self::$cache_path ), 'notice', 'RenderCache', 'habari' );
			return;
		}
		
		//Get the list of group names
		$group_file = self::get_group_list_file();
		if ( file_exists( $group_file ) ) {
			self::$group_list = unserialize( file_get_contents ( $group_file ) );
		}
		else {
			self::$group_list = array();
		}
	}


/************************ COMMON CACHE FUNCTIONS *************************/

	
	/*
	 * Caches a file by copying it to the relavent directory and storing the
	 * filename in a data file.
	 *
	 * @param string $name or array ($group, $name)
	 * @param string $file The file to cache (ex: /tmp/renderedimage.png)
	 * @param integer $expiry The number of seconds before the file expires
	 * @param boolean $keep If true, retain the file even after expiry but report the cache as expired
	 * @return false = failure to cache file
	 */
	public static function put( $name, $file, $expiry = 86400, $keep = false )
	{
		if ( !self::$enabled ) {
			return;
		}
		
		if ( !file_exists( $file ) ) {
			return false;
		}
			
		list( $group, $name ) = self::extract_group_and_name( $name );
		
		$ghash = self::get_group_hash( $group );
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}
		
		//Call the private function to do the work.
		self::_put( $name, $file, $expiry = 86400, $keep = false, $group );

		//Update the group data file
		self::put_group_data_to_file( $group );
	}
	/* The following function is private and works the data array only without	*/
	/* pulling any data from file												*/
	private static function _put( $name, $file, $expiry = 86400, $keep = false, $group )
	{
		//Plugin hook
		if ( Plugins::act( 'rendercache_put_before', $name, $file, $expiry, $keep, $group ) === false ) {
			return;
		}
		
		//Build the filename of the document
		$filename = self::get_filename_hash( $name, $group ) . '.' . self::get_file_extension( $file );
		
		//Copy the file to the cache directory and give it the above filename
		copy( $file, self::$cache_path . $filename );
		
		$ghash = self::get_group_hash( $group );
		$hash = self::get_name_hash( $name );
		
		//Add the item to the array
		self::$cache_data[$ghash][$hash] = array( 'filename' => $filename, 'expires' => time() + $expiry, 'keep' => $keep, 'name' => $name );
		
		//Update the group list
		if( !isset ( self::$group_list[$ghash] ) ) {
			self::$group_list[$ghash] = $group;
		}

		//Plugin hook
		Plugins::act( 'rendercache_put_after', $name, $file, $expiry, $keep, $group );
	}


	/*
	 * Returns the urlwise location of a cached item.  Used for links, img src, etc
	 *
	 * @param string $name or array ($group, $name)
	 * @return The urlwise location of the cached item
	 */	
	public static function get_url( $name )
	{
		if ( !self::$enabled ) {
			return;
		}
			
		list( $group, $name ) = self::extract_group_and_name( $name );

		$ghash = self::get_group_hash( $group );
		$hash = self::get_name_hash( $name );
		
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}

		return self::$cache_url . self::$cache_data[$ghash][$hash]['filename'];
	}
	
	
	/*
	 * Returns the pathwise location of a cached item
	 *
	 * @param string $name or array ($group, $name)
	 * @return The pathwise location of the cached item
	 */
	public static function get_path( $name )
	{
		if ( !self::$enabled ) {
			return;
		}
			
		list( $group, $name ) = self::extract_group_and_name( $name );

		$ghash = self::get_group_hash( $group );
		$hash = self::get_name_hash( $name );
		
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}

		return self::$cache_path . self::$cache_data[$ghash][$hash]['filename'];
	}
	
	
	/**
	 * Is record with $name in the cache?  Update the records from file first if necessary.
	 *
	 * @param string $name or array ($group, $name)
	 * @return boolean TRUE if item is cached, FALSE if not
	 */
	public static function has( $name )
	{
		if ( !self::$enabled ) {
			return;
		}
			
		list( $group, $name ) = self::extract_group_and_name( $name );
		
		$ghash = self::get_group_hash( $group );
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}
		
		return self::_has( $name, $group );
	}
	/* The following function is private and works the data array only without	*/
	/* pulling any data from file												*/
	private static function _has( $name, $group )
	{
		$ghash = self::get_group_hash( $group );
		$hash = self::get_name_hash( $name );
		
		//If the group or name is not set in the array, return false
		if ( ! isset( self::$cache_data[$ghash][$hash] ) ) {
			return false;
		}
		//If the item is expired, return false
		else if ( self::$cache_data[$ghash][$hash]['expires'] < time() && !self::$cache_data[$ghash][$hash]['keep'] ) {
			return false;
		}
		//If the cached file does not exist, return false
		else if ( !file_exists( self::$cache_path . self::$cache_data[$ghash][$hash]['filename'] ) ) {
			return false;
		}
		
		return true;
	}


	/**
	 * Is group an existing group which has at least one active element?  Update the
	 * data from the file if necessary and find out
	 *
	 * @param string $group The group to check.
	 * @return boolean TRUE if group is "alive", FALSE if not
	 */	
	public static function has_group ( $group )
	{
		if ( !self::$enabled ) {
			return false;
		}
		
		$ghash = self::get_group_hash( $group );
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}
		
		return self::_has_group( $group );
	}
	/* The following function is private and works the data array only without	*/
	/* pulling any data from file												*/
	private static function _has_group ( $group )
	{
		$ghash = self::get_group_hash( $group );
	
		//If the group key is not in the data array, return false
		if ( !isset( self::$cache_data[$ghash] ) ) {
			return false;
		}
		
		//If the group has an active cache item, return true
		foreach ( self::$cache_data[$ghash] as $record ) {
			if ( self::_has( $record['name'], $group ) ) {
				return true;
			}
		}
		
		//The group has no active cache items: return false
		return false;
	}
	

	/**
	 * Returns the group from the cache.
	 *
	 * @param string $group The cache group
	 * @return mixed An array of records belonging to the group.
	 */
	public static function get_group( $group )
	{
		if ( !self::$enabled ) {
			return;
		}
		
		$ghash = self::get_group_hash( $group );
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}
		
		$ghash = self::get_group_hash( $group );
		
		return self::$cache_data[$ghash];
	}
	
	
	/**
	 * Extend the expiration of the named cached file.
	 *
	 * @param string $name or array ($group,$name)
	 * @param integer $expiry The duration in seconds to extend the cache expiration
	 */
	public static function extend( $name, $expiry )
	{
		if ( !self::$enabled ) {
			return;
		}
		
		list( $group, $name ) = self::extract_group_and_name( $name );		
		
		$ghash = self::get_group_hash( $group );
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}
		
		//Call the private function to do the work
		self::_extend( $name, $expiry, $group );
		
		//Update the group data file
		self::put_group_data_to_file( $group );
	}
	/* The following function is private and works the data array only without	*/
	/* pulling any data from file												*/
	private static function _extend( $name, $expiry, $group )
	{
		//Plugin hook
		if ( Plugins::act( 'rendercache_extend_before', $name, $expiry ) === false ) {
			return;
		}
		
		$ghash = self::get_group_hash( $group );
		$hash = self::get_name_hash( $name );
		
		//If the item is in the array, extend its expiration
		if ( isset( self::$cache_data[$ghash][$hash] ) ) {
			self::$cache_data[$ghash][$hash]['expiry'] += $expiry;
		}
		
		//Plugin hook
		Plugins::act( 'rendercache_extend_after', $name, $expiry );
	}
	

	/**
	 * Expires the named value from the cache.
	 *
	 * @param string $name The name of the cached item
	 * @param string $match_mode (optional) how to match bucket names ('strict', 'regex', 'glob') (default 'strict')
	 */	
	public static function expire( $name, $match_mode='strict' )
	{
		if ( !self::$enabled ) {
			return;
		}
		
		list( $group, $name ) = self::extract_group_and_name( $name );
		
		$ghash = self::get_group_hash( $group );
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}
		
		$ghash = self::get_group_hash( $group );
		
		$keys = array();
		switch ( strtolower($match_mode) ) {
			case 'glob':
				if ( isset( self::$cache_data[$ghash] ) ) {
					//Make an array relating hashes to names
					$names = array();
					foreach( self::$cache_data[$ghash] as $hash => $record ) {
						$names[$hash] = $record['name'];
					}
					//Find matching names
					$names = preg_grep( Utils::glob_to_regex( $name ), $names );
				}
				break;
			case 'regex':
				if ( isset( self::$cache_data[$ghash] ) ) {
					//Make an array relating hashes to names
					$names = array();
					foreach( self::$cache_data[$ghash] as $hash => $record ) {
						$names[$hash] = $record['name'];
					}
					//Find matching names
					$names = preg_grep( $name, $names );
				}
				break;
			case 'strict':
			default:
				$hash = self::get_name_hash( $name );
				$names[$hash] = $name;
				break;
		}
		
		//Loop through all matching item names
		foreach ( $names as $hash => $name ) {
			//Remove the item from the data array
			self::_expire( $name, $group );
		}
		
		//Update the group data file
		self::put_group_data_to_file( $group );
	}
	/* The following function is private and works the data array only without	*/
	/* pulling any data from file												*/
	private static function _expire( $name, $group )
	{
		//Plugin hook
		if ( Plugins::act( 'rendercache_expire_before', $name, $group ) === false ) {
			return;
		}

		$ghash = self::get_group_hash( $group );
		$hash = self::get_name_hash( $name );
	
		//If the cached file exists
		if ( file_exists( self::$cache_path . self::$cache_data[$ghash][$hash]['filename'] ) ) {
			//Delete the cached file
			unlink( self::$cache_path . self::$cache_data[$ghash][$hash]['filename'] );
		}
				
		//Remove the record from array
		unset ( self::$cache_data[$ghash][$hash] );
		
		//If the group is no longer alive, remove the group from the group list
		if ( ! self::_has_group ( $group ) ) {
			unset( self::$cache_data[$ghash] );
			unset( self::$group_list[$ghash] );
		}
		
		//Plugin hook
		Plugins::act( 'rendercache_expire_after', $name, $group );
	}
	
	
	/**
	 * Return whether a named cache value has expired
	 * 
	 * @param string $name The name of the cached item
	 * @param string $group The group of the cached item
	 * @return boolean true if the stored value has expired
	 */	
	public static function expired( $name )
	{
		if ( !self::$enabled ) {
			return;
		}
		
		list( $group, $name ) = self::extract_group_and_name( $name );
		
		$ghash = self::get_group_hash( $group );
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}
		
		return self::_expired( $name, $group );
	}
	/* The following function is private and works the data array only without	*/
	/* pulling any data from file												*/
	private static function _expired( $name, $group )
	{
		$ghash = self::get_group_hash( $group );
		$hash = self::get_name_hash( $name );
		
		//If there is no cache item by that name, return true
		if ( !isset( self::$cache_data[$ghash][$hash] ) ) {
			return true;
		}
		//If the cache item is expired, return true (even if "keep" is set)
		else if ( self::$cache_data[$ghash][$hash]['expiry'] < time() ) {
			return true;
		}
		//If the cached file does not exist, declare the item expired
		else if ( !file_exists( self::$cache_path . self::$cache_data[$ghash][$hash]['filename'] ) ) {
			return true;
		}
		
		return false;
	}
	
	
	/*
	 * Empty the cache completely including all data files and cached files
	 */
	public static function purge()
	{
		if ( !self::$enabled ) {
			return;
		}
		
		//Plugin hook
		if ( Plugins::act( 'rendercache_purge_before' ) === false ) {
			return;
		}
		
		//Loop through all groups
		foreach( self::$group_list as $ghash => $group ) {
		
			//If the group is not in the array
			if ( !isset( self::$cache_data[$ghash] ) ) {
				//Get the group data from the data file
				self::get_group_data_from_file( $group );
			}
		
			//Loop through all available records for the group
			foreach ( self::$cache_data[$ghash] as $name => $record ) {
				//Expire each item
				self::_expire( $name, $group );
			}
			//The group should be deleted by _expire when all items are removed
		
			//Update the data files
			self::set_group_data_to_file( $group );
		
		}
		
		//Plugin hook
		Plugins::act( 'rendercache_purge_after' );
	}
	
	
	/*
	 * Clears the expired items in a given group (reads/writes data file)
	 *
	 * @param string $group The group whose expired items to clear
	 */
	public static function clear_expired( $group )
	{
		if ( !self::$enabled ) {
			return;
		}
		
		//Plugin hook
		if ( Plugins::act( 'rendercache_clear_expired_before', $group ) === false ) {
			return;
		}
		
		$ghash = self::get_group_hash( $group );
		//If the group is not in the array
		if ( !isset( self::$cache_data[$ghash] ) ) {
			//Get the group data from the data file
			self::get_group_data_from_file( $group );
		}
		
		//Clear the expired items in the group
		self::_clear_expired( $group );
		
		//Plugin hook
		Plugins::act( 'rendercache_clear_expired_after', $group );
	}
	/* The following function is private and works the data array only without	*/
	/* pulling any data from file												*/
	private static function _clear_expired( $group )
	{
		$ghash = self::get_group_hash( $group );
		
		//Loop through all cached items for this group
		foreach( self::$cache_data[$ghash] as $hash => $record ) {
			$name = self::$cache_data[$ghash][$hash]['name'];
			//If the item is expired
			if ( !self::_has( $name, $group ) ) {
				//Remove the item
				self::_expire( $name, $group );
			}
		}
	}
	
	

/************************ DATA FILE FUNCTIONS *************************/	
	
	
	/*
	 * If the group data is not already in the array, load the data from file
	 *
	 * @param string $group The group for the requested data
	 */
	private static function get_group_data_from_file( $group )
	{
		$ghash = self::get_group_hash( $group );
	
		$data_file = self::get_group_data_file( $group );

		//If the data file exists
		if ( file_exists( $data_file ) ) {
			//Get the data from file and put it in the array
			self::$cache_data[$ghash] = unserialize( file_get_contents( $data_file ) );
			//Clear expired cache items for this group
			//NOTE: This is the only place where we clear expired items and should only run once per group loaded from file
			self::_clear_expired( $group );
		}
		//Otherwise
		else {
			//Assume the user knew what they were doing and set the cache for this group as a blank array.  Unless a file is cached in this group, the group will be deleted anyway.
			self::$cache_data[$ghash] = array();			
		}
	}
	
	
	/*
	 * Write the group data in the array to the group data file
	 *
	 * @param string $group The group for the data
	 */
	private static function put_group_data_to_file( $group )
	{
		$data_file = self::get_group_data_file( $group );
	
		//If the group is active
		if ( self::_has_group( $group ) ) {
			$ghash = self::get_group_hash( $group );
			//Write the group data to file
			file_put_contents( $data_file, serialize( self::$cache_data[$ghash] ) );
		}
		//Otherwise
		else {
			//If the group data file exists
			if ( file_exists( $data_file ) ) {
				//Delete the group data file
				unlink( $data_file );
			}
		}
		
		//Write the list of group names (to make sure it stays updated)
		$group_file = self::get_group_list_file();
		file_put_contents( $group_file, serialize( self::$group_list ) );
	}
	
	
/************************ HELPER FUNCTIONS *************************/

	
	/*
	 * Return the data file for a given group
	 *
	 * @param string $group The group name
	 * @return string The group data file location
	 */
	private static function get_group_data_file( $group )
	{
		$ghash = self::get_group_hash( $group );
		
		return self::$cache_path . $ghash . '.data';
	}
	
	
	/*
	 * Return the list of groups from the group list file
	 *
	 * @return array The list of recorded groups
	 */
	private static function get_group_list_file()
	{
		return self::$cache_path . 'groups.data';
	}
	
	
	/**
	 * Get the unique hash for a given key.
	 *
	 * @param string $name The name of the cached item.
	 */
	private static function get_name_hash( $name )
	{
		return md5( $name . Options::get( 'GUID' ) );
	}


	/**
	 * Get the unique hash for a given key.
	 *
	 * @param string $name The name of the cached group.
	 */
	private static function get_group_hash( $group )
	{
		return md5( $group . Options::get( 'GUID' ) );
	}
	
	
	/*
	 * Given a string $name or array ($group,$name), return ($group,$name)
	 *
	 * @param string $name or array ($group,$name)
	 */
	private static function extract_group_and_name( $name )
	{
		if ( ! is_array( $name ) ) {
			$name = array( self::$default_group, $name );
		}
		
		return $name;
	}
	
	
	/*
	 * Get the file extension of a given file
	 *
	 * @param string $file A file location
	 * @return string The file extension (ex: png)
	 */
	private static function get_file_extension( $file )
	{
	    $path_info = pathinfo( $file );
    	return $path_info['extension'];
	}
	
	
	/*
	 * Return the unique filename hash for the given group and name.
	 *
	 * @param $name The name of the cache item
	 * @param $group The group of the cache item
	 */
	private static function get_filename_hash( $name, $group )
	{
		return self::get_group_hash( $group ) . '.' . self::get_name_hash( $name );
	}
	

}

?>
