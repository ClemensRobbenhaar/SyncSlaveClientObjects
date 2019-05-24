<?php
/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * ItemGroup export file cache
 * 
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 */
class ilSyncItemGroupXmlCache
{
	/**
	 * @var ilSyncLearningModuleXmlCache[]
	 */
	private static $instances = array();

	/**
	 * @var \ilLogger
	 */
	private $logger = null;


	/**
	 * @var int
	 */
	private $obj_id = 0;

	/**
	 * @var int
	 */
	private $path = '';


	/**
	 * ilSyncLearningModuleXmlCache constructor.
	 * @param int $a_obj_id
	 */
	public function __construct($a_obj_id)
	{
		global $DIC;

		$this->logger = $DIC->logger()->ssco();
		$this->obj_id = $a_obj_id;
		$this->init();
	}

	/**
	 * @param int $a_obj_id
	 * @return ilSyncFileXmlCache
	 */
	public static function getInstance($a_obj_id)
	{
		if(self::$instances[$a_obj_id])
		{
			return self::$instances[$a_obj_id];
		}
		return self::$instances[$a_obj_id] = new ilSyncItemGroupXmlCache($a_obj_id);
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
	 * Init export file
	 */
	protected function init()
	{
		$exp = new ilExport();
		$ret = $exp->exportObject(ilObject::_lookupType($this->obj_id),$this->obj_id, "4.1.0");
		$this->logger->dump($ret, \ilLogLevel::INFO);
		$this->path = $ret['directory'].'/'.$ret['file'];
	}
}
?>
