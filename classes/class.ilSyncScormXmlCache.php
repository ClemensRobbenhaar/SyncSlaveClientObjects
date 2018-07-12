<?php

/**
 * Scorm xml writer and cache
 *
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 */
class ilSyncScormXmlCache
{

	/**
	 * @var \ilSyncScormXmlCache[]
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

		$this->logger = $DIC->logger()->sahs();

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
		if (array_key_exists($a_obj_id, self::$instances)) {
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

		$scorm = ilObjectFactory::getInstanceByRefId($ref_id, false);
		if (!$scorm instanceof ilObjSAHSLearningModule)
		{
			$this->logger->warning('Cannot create scorm instance for obj_id: ' . $this->obj_id);
			$this->logger->warning('Current class is: ' . get_class($scorm));
			throw new InvalidArgumentException('Cannot create scorm instance for obj_id: ' . $this->obj_id);
		}


		$exporter = new ilScormAiccExporter();
		$this->xml = $exporter->getXmlRepresentation("sahs", "5.1.0", $ref_id);

		//$this->logger->dump($this->xml);
	}


}