<?php
/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * File xml writer and cache
 *
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 */
class ilSyncGroupXmlCache
{

	/**
	 * @var \ilSyncGroupXmlCache[]
	 */
	private static $instances = [];

	/**
	 * @var \ilLogger
	 */
	private $logger = null;

	/**
	 * @var int
	 */
	private $obj_id = 0;

	/**
	 * @var string
	 */
	private $xml = '';

	/**
	 * ilSyncGroupXmlCache constructor.
	 * @param $a_obj_id
	 * @throws \InvalidArgumentException
	 */
	public function __construct($a_obj_id)
	{
		global $DIC;

		$this->logger = $DIC->logger()->grp();

		$this->obj_id = $a_obj_id;
		$this->init();
	}

	/**
	 * @param int $a_obj_id
	 * @return \ilSyncGroupXmlCache
	 * @throws \InvalidArgumentException
	 */
	public static function getInstanceByObjId($a_obj_id)
	{
		if(array_key_exists($a_obj_id,self::$instances))
		{
			return self::$instances[$a_obj_id];
		}
		return self::$instances[$a_obj_id] = new self($a_obj_id);
	}

	/**
	 * Get xml
	 * @return string
	 */
	public function getXml()
	{
		return $this->xml;
	}

	/**
	 * Init group xml
	 * @throws \InvalidArgumentException
	 */
	protected function init()
	{
		$references = ilObject::_getAllReferences($this->obj_id);
		$ref_id = end($references);

		$group = ilObjectFactory::getInstanceByRefId($ref_id,false);
		if(!$group instanceof ilObjGroup)
		{
			$this->logger->warning('Cannot create group instance for obj_id: ' . $this->obj_id);
			throw new InvalidArgumentException('Cannot create group instance for obj_id: ' . $this->obj_id);
		}

		$xml_writer = new ilGroupXMLWriter($group);
		$xml_writer->setAttachUsers(false);
		$xml_writer->setMode(ilGroupXMLWriter::MODE_EXPORT);
		$xml_writer->start();
		$this->xml = $xml_writer->getXML();
		$this->logger->debug($this->xml);
	}







}