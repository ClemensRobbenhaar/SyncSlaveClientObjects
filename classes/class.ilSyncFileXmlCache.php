<?php
/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * File xml writer and cache
 * 
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 * $Id: class.ilSyncFileXmlCache.php 32621 2012-01-12 15:58:44Z smeyer $
 */
class ilSyncFileXmlCache
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
		return self::$instances[$a_obj_id] = new ilSyncFileXmlCache($a_obj_id);
	}

	/**
	 * Get absolute path to temp file
	 * @return string
	 */
	public function getFile()
	{
		return $this->path;
	}

	protected function init()
	{
		$file = ilObjectFactory::getInstanceByObjId($this->obj_id, false);

   	    // store into xml result set
		include_once './Modules/File/classes/class.ilFileXMLWriter.php';

		// create writer
		$xmlWriter = new ilFileXMLWriter();
		$xmlWriter->setOmitHeader(true);
		$xmlWriter->setFile($file);
		$xmlWriter->setAttachFileContents(ilFileXMLWriter::$CONTENT_ATTACH_ABSOLUTE_PATH);
		$xmlWriter->setFileTargetDirectories('', ilUtil::ilTempnam());
		$xmlWriter->start();

		$this->path = ilUtil::ilTempnam();
		file_put_contents($this->path, $xmlWriter->getXML());

		$GLOBALS['ilLog']->write(__METHOD__.': '.$this->path);

	}
}
?>
