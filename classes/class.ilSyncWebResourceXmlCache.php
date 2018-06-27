<?php
/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * File xml writer and cache
 *
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 */
class ilSyncWebResourceXmlCache
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

		$this->logger = $DIC->logger()->webr();

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

		$webr = ilObjectFactory::getInstanceByRefId($ref_id,false);
		if(!$webr instanceof ilObjLinkResource)
		{
			$this->logger->warning('Cannot create webresource instance for obj_id: ' . $this->obj_id);
			throw new InvalidArgumentException('Cannot create webresource instance for obj_id: ' . $this->obj_id);
		}

		$xml_writer = new ilWebLinkXmlWriter(false);
		try {
			$xml_writer->setObjId($this->obj_id);
			$xml_writer->write();

		}
		catch(\UnexpectedValueException $e) {
			$this->logger->warning('Cannot create webresource instance for obj_id: ' . $this->obj_id);
			throw new InvalidArgumentException('Cannot create webresource instance for obj_id: ' . $this->obj_id);
		}

		$this->logger->info($xml_writer->xmlDumpMem(true));
		$this->xml = $xml_writer->xmlDumpMem(false);
	}
}