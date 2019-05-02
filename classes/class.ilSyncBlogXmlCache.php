<?php
/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * File xml writer and cache
 * 
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 * $Id$
 */
class ilSyncBlogXmlCache
{
	private static $instances = array();


	private $obj_id = 0;
	private $path = '';


	/**
	 * Constructor
	 */
	public function __construct($a_obj_id)
	{
		$this->obj_id = $a_obj_id;
		$this->init();
	}

	/**
	 * Get instance by obj_id
	 * @param int $a_obj_id
	 * @return ilSyncFileXmlCache
	 */
	public static function getInstance($a_obj_id)
	{
		if(self::$instances[$a_obj_id])
		{
			return self::$instances[$a_obj_id];
		}
		return self::$instances[$a_obj_id] = new ilSyncBlogXmlCache($a_obj_id);
	}

	/**
	 * Get absolute path to temp file
	 * @return string
	 */
	public function getFile()
	{
		return $this->path;
	}

	/**
	 * Init cache
	 */
	protected function init()
	{
		include_once("./Services/Export/classes/class.ilExport.php");
		$exp = new ilExport();
		$ret = $exp->exportObject(ilObject::_lookupType($this->obj_id),$this->obj_id, "5.0.0");

		$this->path = $ret['directory'].'/'.$ret['file'];
	}
}
?>
