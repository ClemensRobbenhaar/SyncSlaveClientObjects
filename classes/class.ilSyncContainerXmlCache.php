<?php
/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * Container xml cache
 *
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 */
class ilSyncContainerXmlCache
{
	private static $instances = array();

	private $logger = null;


	private $obj_id = 0;
	private $path = '';


	/**
	 * Constructor
	 */
	public function __construct($a_obj_id)
	{
		global $DIC;

		$this->logger = $DIC->logger()->exp();
		$this->obj_id = $a_obj_id;
		$this->init();
	}

	/**
	 * Get instance by obj_id
	 * @param int $a_obj_id
	 * @return ilSyncContainerXmlCache
	 */
	public static function getInstance($a_obj_id)
	{
		if(self::$instances[$a_obj_id])
		{
			return self::$instances[$a_obj_id];
		}
		return self::$instances[$a_obj_id] = new self($a_obj_id);
	}

	/**
	 * @return string
	 */
	public function getPath()
	{
		return $this->path;
	}

	/**
	 * Init xml cache
	 */
	protected function init()
	{
		$refs = ilObject::_getAllReferences($this->obj_id);
		$ref_id = end($refs);

		$eo = ilExportOptions::newInstance(ilExportOptions::allocateExportId());
		$eo->addOption(ilExportOptions::KEY_ROOT,0,0,$this->obj_id);
		$eo->addOption(
			ilExportOptions::KEY_ITEM_MODE,
			$ref_id,
			$this->obj_id,
			ilExportOptions::EXPORT_BUILD
		);

		$export_container = new ilExport();
		$export_info = $export_container->exportObject(
			ilObject::_lookupType($this->obj_id),
			$this->obj_id
		);

		$this->path = $export_info['directory'].'/'.$export_info['file'];

		ilLoggerFactory::getLogger('root')->dump($this->path,ilLogLevel::NOTICE);

		return true;
	}


}