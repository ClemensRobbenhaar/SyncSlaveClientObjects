<?php

/**
 * Facade class for the object tasks
 * performed on slave clients
 *
 * @author		Björn Heyser <bheyser@databay.de>
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 *
 */
class sscoSlaveClientObjectAdministration
{
	const SOAP_RESPONSE_TIMEOUT = 300;
	const SOAP_TIMEOUT = 600;

	/**
	 * @static
	 * @var array
	 */
	private static $instances = array();
	
	private static $sync_roles = array();
	private static $sync_locations = array();
	
	private $oi_cache = array();
	private $ri_cache = array();


	/**
	 * @var ilSoapClient
	 */
	private $soapClient = null;
	
	/**
	 * @var string
	 */
	private $soapSid = null;

	/**
	 * @var \ilLogger
	 */
	private $logger = null;
	
	
	/**
	 * @param string $slaveClientId
	 * @param string $soapUser
	 * @param string $soapPass
	 *
	 * @throws
	 */
	public function __construct($slaveClientId, $soapUser, $soapPass)
	{
		global $DIC;

		$this->logger = $DIC->logger()->ssco();


		require_once 'Services/WebServices/SOAP/classes/class.ilSoapClient.php';
		
		$soapClient = new ilSoapClient();
		$soapClient->setThrowException(true);
		$soapClient->setTimeout(self::SOAP_TIMEOUT);
		$soapClient->setResponseTimeout(self::SOAP_RESPONSE_TIMEOUT);
		$soapClient->init();

		$soapSid = $soapClient->call(
				'login', array($slaveClientId, $soapUser, $soapPass)
		);

		$this->logger->debug('Response is: ' . $soapSid);

		if( !preg_match('/^[a-zA-Z0-9]+::[a-zA-Z0-9_]+$/', $soapSid) )
		{
			$this->logger->error('Calling webservice failed with message: invalid session id returned' );
			throw new ilException("Cannot create soap session for client : " . $slaveClientId);
		}

		$this->setSoapClient($soapClient);
		$this->setSoapSid($soapSid);
	}

	/**
	 * @return ilSoapClient $soapClient
	 */
	public function getSoapClient()
	{
		return $this->soapClient;
	}

	/**
	 * @param ilSoapClient $soapClient 
	 */
	public function setSoapClient(ilSoapClient $soapClient)
	{
		$this->soapClient = $soapClient;
	}
	
	/**
	 * @return string $soapSid
	 */
	public function getSoapSid()
	{
		return $this->soapSid;
	}

	/**
	 * @param string $soapSid 
	 */
	public function setSoapSid($soapSid)
	{
		$this->soapSid = $soapSid;
	}
	
	public function updateDatabase()
	{
		return $this->getSoapClient()->call(
				'updateInstallation',
				array('sid' => $this->getSoapSid())
			);
	}

	// Groups

	/**
	 * @param $objId
	 * @param $refId
	 */
	public function createGroupObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();

		// abort this action if group has crs in path
		$parent_crs = $tree->checkForParentType($refId,'crs');
		if($parent_crs)
		{
			$this->logger->info('Ignoring group creation for group inside course.');
			return 0;
		}

		$this->logger->info('Handling create event for group '. ilObject::_lookupTitle($objId).' '.$refId);
		$writer = $this->buildObjectXml($objId, $refId);
		$remote_ref = $this->readParentId($refId, true);
		$remote_item_ref = $this->getObjIdByImportId(IL_INST_ID.'::'.$objId);
		if($remote_item_ref)
		{
			$this->logger->info('Group already created in previous run. Aborting!');
			return;
		}

		$new_remote_ref = $this->getSoapClient()->call(
			'addObject',
			array(
				$this->getSoapSid(),
				$remote_ref,
				$writer->xmlDumpMem(FALSE)
			)
		);
		$this->logger->info('Handling update after creation.');
		$this->updateGroupObject($objId, $refId);
		return $new_remote_ref;
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function updateGroupObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$this->logger->info('Handling update event for group '. ilObject::_lookupTitle($objId).' '.$refId);
		if($tree->isDeleted($refId))
		{
			return;
		}
		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.$objId);
		$remote_ref = end($remote_refs);
		if(!count($remote_refs))
		{
			return $this->createGroupObject($objId, $refId);
		}
		$plugin = new ilSyncSlaveClientObjectsPlugin();
		$plugin->includeClass('class.ilSyncGroupXmlCache.php');

		$gc = ilSyncGroupXmlCache::getInstanceByObjId($objId);
		$this->getSoapClient()->call(
			'updateGroup',
			array(
				$this->getSoapSid(),
				$remote_ref,
				$gc->getXml()
			)
		);

		$this->updateContainer($objId,$refId);
	}

	/**
	 * Handle trash group
	 * @param $objId
	 * @param $refId
	 */
	public function trashGroupObject($objId, $refId)
	{
		return $this->handleRemove($objId,$refId);
	}

	/**
	 * Handle restore from trash
	 * @param $objId
	 * @param $refId
	 */
	public function restoreGroupObject($objId, $refId)
	{
		$this->createGroupObject($objId, $refId);
	}

	/**
	 * Handle remove group
	 * @param $objId
	 * @param $refId
	 */
	public function removeGroupObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}

	// Folders
	public function createFolderObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();

		// abort this action if folder has crs in path
		$parent_crs = $tree->checkForParentType($refId,'crs');
		if($parent_crs)
		{
			$this->logger->info('Ignoring folder creation for group inside course.');
			return 0;
		}

		$this->logger->info('Handling create event for folder '. ilObject::_lookupTitle($objId).' '.$refId);
		$writer = $this->buildObjectXml($objId, $refId);
		$remote_ref = $this->readParentId($refId, true);
		$remote_item_ref = $this->getObjIdByImportId(IL_INST_ID.'::'.$objId);
		if($remote_item_ref)
		{
			$this->logger->info('Folder already created in previous run. Aborting!');
			return;
		}

		$new_remote_ref = $this->getSoapClient()->call(
			'addObject',
			array(
				$this->getSoapSid(),
				$remote_ref,
				$writer->xmlDumpMem(FALSE)
			)
		);
		$this->logger->info('Handling update after creation.');
		$this->updateFolderObject($objId, $refId);
		return $new_remote_ref;
	}

	/**
	 * Update folder in groups but not in courses.
	 * @param int $objId
	 * @param int $refId
	 */
	public function updateFolderObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$this->logger->info('Handling update event for '. ilObject::_lookupTitle($objId).' '.$refId);
		try {
			$writer = $this->buildObjectXml($objId, $refId, true);
		}
		catch(Exception $e) {
			$this->logger->error('Read object xml failed for '. ilObject::_lookupTitle($objId).' '.$refId);
			return $this->createFolderObject($objId, $refId);
		}
		$this->getSoapClient()->call(
			'updateObjects',
			array(
				$this->getSoapSid(),
				$writer->xmlDumpMem(FALSE)
			)
		);
		$this->updateContainer($objId,$refId);
	}

	/**
	 * Handle trash folder
	 * @param $objId
	 * @param $refId
	 */
	public function trashFolderObject($objId, $refId)
	{
		return $this->handleRemove($objId,$refId);
	}

	/**
	 * Handle restore from trash
	 * @param $objId
	 * @param $refId
	 */
	public function restoreFolderObject($objId, $refId)
	{
		$this->createFolderObject($objId, $refId);
	}

	/**
	 * Handle remove group
	 * @param $objId
	 * @param $refId
	 */
	public function removeFolderObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}

    /**
     * Update category object
     *
     * @param int $objId
     * @param int $refId
     */
	public function updateRootObject($objId, $refId)
    {
        global $DIC;

        $tree = $DIC->repositoryTree();
        $this->logger->info('Handling update event for '. ilObject::_lookupTitle($objId).' '.$refId);
        try {
            $writer = $this->buildObjectXml($objId, $refId, true);
            $this->getSoapClient()->call(
                'updateObjects',
                array(
                    $this->getSoapSid(),
                    $writer->xmlDumpMem(false)
                )
            );
        }
        catch(ilSoapClientException $e) {
            $this->logger->error('Update object xml failed for '. ilObject::_lookupTitle($objId).' '.$refId);
            return;
        }
        catch(Exception $e) {
            $this->logger->error('Read object xml failed for '. ilObject::_lookupTitle($objId).' '.$refId);
            return;
        }
        $this->updateContainer($objId,$refId);
    }


	/**
	 * @param int $objId
	 * @param int $refId
	 */
	public function createCategoryObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		include_once './Services/Xml/classes/class.ilXmlWriter.php';

		$this->logger->info('Handling create event for category '. ilObject::_lookupTitle($objId).' '.$refId);
		$writer = $this->buildObjectXml($objId, $refId);
		$remote_ref = $this->readParentId($refId, true);
		$remote_item_ref = $this->getObjIdByImportId(IL_INST_ID.'::'.$objId);
		if($remote_item_ref)
		{
			// update?
			$this->logger->info('Category already created in previous run. Aborting!');
			return;
		}

		$new_remote_ref = $this->getSoapClient()->call(
			'addObject',
				array(
					$this->getSoapSid(),
					$remote_ref,
					$writer->xmlDumpMem(FALSE)
			)
		);
		$this->logger->info('Handling update after creation.');
		$this->updateCategoryObject($objId, $refId);
		return $new_remote_ref;
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function updateCategoryObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$this->logger->info('Handling update event for '. ilObject::_lookupTitle($objId).' '.$refId);
		try {
			$writer = $this->buildObjectXml($objId, $refId, true);
		}
		catch(Exception $e) {
			$this->logger->error('Read object xml failed for '. ilObject::_lookupTitle($objId).' '.$refId);
			return $this->createCategoryObject($objId, $refId);
		}
		$this->getSoapClient()->call(
			'updateObjects',
			array(
				$this->getSoapSid(),
				$writer->xmlDumpMem(FALSE)
			)
		);

		$this->updateContainer($objId,$refId);
	}


	/**
	 * update container xml
	 * @param $a_obj_id
	 * @param $a_ref_id
	 */
	protected function updateContainer($a_obj_id, $a_ref_id)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();

		$plugin = new ilSyncSlaveClientObjectsPlugin();
		$plugin->includeClass('class.ilSyncContainerXmlCache.php');

		$cat_cache = ilSyncContainerXmlCache::getInstance($a_obj_id);
		$path = $cat_cache->getPath();

		$target_id = $tree->getParentId($a_ref_id);
		$target_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.ilObject::_lookupObjId($target_id));
		$target_ref = end($target_refs);
		$remote_refs = (array) $this->getRefIdByImportId(IL_INST_ID.'::'.$a_obj_id);
		$remote_ref = end($remote_refs);

		try {
			$this->getSoapClient()->call(
				'updateContainer',
				[
					$this->getSoapSid(),
					$remote_ref,
					$path,
					$a_obj_id
				]
			);
		}
		catch(Exception $e) {
			$this->logger->warning('Update container failed with message: ' . $e->getMessage());
		}

		if($target_ref && $remote_ref)
		{
			try {
				$this->getSoapClient()->call(
					'moveObject',
					array(
						$this->getSoapSid(),
						$remote_ref,
						$target_ref
					)
				);
			}
			catch(Exception $e) {
				// ignoring exception of same target here
			}
		}
	}

	/**
	 * @return ilXmlWriter
	 */
	protected function buildObjectXml($objId, $refId, $update = false)
	{
		global $tree,$objDefinition;

		include_once './Services/Xml/classes/class.ilXmlWriter.php';
		$writer = new ilXmlWriter();
		$writer->xmlStartTag('Objects');

		$params['type'] = ilObject::_lookupType($objId);
		if($update)
		{
			$params['obj_id'] = $this->getObjIdByImportId(IL_INST_ID.'::'.$objId);

			if(!$params['obj_id'])
			{
				$this->logger->error('Missing object id.');
				$this->logger->logStack();
				throw new Exception('Missing object id');
			}

		}
		$writer->xmlStartTag('Object',$params);
		$writer->xmlElement('Title',array(),ilObject::_lookupTitle($objId));
		
		if(
		    ilObject::_lookupType($objId) == 'cat' ||
            ilObject::_lookupType($objId) == 'root'
        )
		{
			include_once './Modules/Category/classes/class.ilObjCategory.php';
			$writer->xmlStartTag('Translations');
			foreach(ilObjCategory::lookupTranslations($objId) as $trans)
			{
				$writer->xmlElement('TranslationTitle', array('key' => $trans['lang'], 'default' => $trans['default']), $trans['title']);
				$writer->xmlElement('TranslationDescription', array(), $trans['desc']);
			}
			$writer->xmlEndTag('Translations');
		}
		
		
		$writer->xmlElement('Description',array(),ilObject::_lookupDescription($objId));
		$writer->xmlElement('ImportId',array(),IL_INST_ID.'::'.$objId);

		if($add_references)
		{
			$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.$objId);
			foreach($remote_refs as $ref)
			{
				$writer->xmlElement('References', array('ref_id' => $ref));
			}
		}
		if($objDefinition->isContainer(ilObject::_lookupType($objId)))
		{
			include_once './Services/Container/classes/class.ilContainerSorting.php';
			$sort = ilContainerSorting::_getInstance($objId);
			switch($sort->getSortingSettings()->getSortMode())
			{
				case ilContainer::SORT_MANUAL:
					$params['type'] = 'Manual';
					break;

				default:
					$params['type'] = 'Title';
					break;
			}

			$writer->xmlStartTag('Sorting', $params);

			// Add subitems
			foreach(ilContainerSorting::lookupPositions($objId) as $childRef => $pos)
			{
				$writer->xmlElement('Item',array('import_id' => IL_INST_ID.'::'.ilObject::_lookupObjId($childRef)));
			}
			$writer->xmlEndTag('Sorting');
		}

		$writer->xmlEndTag('Object');
		$writer->xmlEndTag('Objects');
		return $writer;
	}
	
	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function trashCategoryObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function restoreCategoryObject($objId, $refId)
	{
		$this->createCategoryObject($objId,$refId);
		
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function removeCategoryObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}


	// webr
	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function createWebResourceObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$this->logger->info('Handling create event for webresource '. ilObject::_lookupTitle($objId).' '.$refId);
		$writer = $this->buildObjectXml($objId, $refId);
		$remote_parent_ref = $this->readParentId($refId,true);
		$remote_item_ref = $this->getObjIdByImportId(IL_INST_ID.'::'.$objId);
		if($remote_item_ref)
		{
			$this->logger->info('Webreource already created in previous run. Aborting!');
			return;
		}

		$new_remote_ref = $this->getSoapClient()->call(
			'addObject',
			array(
				$this->getSoapSid(),
				$remote_parent_ref,
				$writer->xmlDumpMem(FALSE)
			)
		);

		$this->updateWebresourceObject($objId, $refId);
		return $new_remote_ref;
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function updateWebresourceObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$this->logger->info('Handling update event for webresource '. ilObject::_lookupTitle($objId).' '.$refId);
		if($tree->isDeleted($refId))
		{
			return;
		}
		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.$objId);
		$remote_ref = end($remote_refs);
		if(!count($remote_refs))
		{
			return $this->createWebResourceObject($objId, $refId);
		}
		$plugin = new ilSyncSlaveClientObjectsPlugin();
		$plugin->includeClass('class.ilSyncWebResourceXmlCache.php');

		$wlc = new ilSyncWebResourceXmlCache($objId);
		$this->getSoapClient()->call(
			'updateWebLink',
			array(
				$this->getSoapSid(),
				$remote_ref,
				$wlc->getXml()
			)
		);

		$this->updateMetaData($objId);

		// Add references
		$this->addReferences($objId,$refId,$remote_refs);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function trashWebresourceObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function restoreWebresourceObject($objId, $refId)
	{
		$this->createWebresourceObject($objId, $refId);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function removeWebresourceObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}

	// sahs
	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function createScormObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$this->logger->info('Handling create event for scorm '. ilObject::_lookupTitle($objId).' '.$refId);
		$writer = $this->buildObjectXml($objId, $refId);
		$remote_parent_ref = $this->readParentId($refId,true);
		$remote_item_ref = $this->getObjIdByImportId(IL_INST_ID.'::'.$objId);
		if($remote_item_ref)
		{
			$this->logger->info('Scorm already created in previous run. Aborting!');
			return;
		}

		$new_remote_ref = $this->getSoapClient()->call(
			'addObject',
			array(
				$this->getSoapSid(),
				$remote_parent_ref,
				$writer->xmlDumpMem(FALSE)
			)
		);

		$this->updateScormObject($objId, $refId);
		return $new_remote_ref;
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function updateScormObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$this->logger->info('Handling update event for scorm '. ilObject::_lookupTitle($objId).' '.$refId);
		if($tree->isDeleted($refId))
		{
			return;
		}
		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.$objId);
		$remote_ref = end($remote_refs);
		if(!count($remote_refs))
		{
			return $this->createScormObject($objId, $refId);
		}

		// Handle file upload
		$plugin = new ilSyncSlaveClientObjectsPlugin();
		$plugin->includeClass('class.ilSyncScormXmlCache.php');

		$scorm_xml_cache = new ilSyncScormXmlCache($objId);
		$scorm_xml_cache->getXml();

		$this->updateMetaData($objId);

		// Add references
		$this->addReferences($objId,$refId,$remote_refs);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function trashScormObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function restoreScormObject($objId, $refId)
	{
		$this->createScormObject($objId, $refId);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function removeScormObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}

	// Files
	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function createFileObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$this->logger->info('Handling create event for file '. ilObject::_lookupTitle($objId).' '.$refId);
		$writer = $this->buildObjectXml($objId, $refId);
		$remote_parent_ref = $this->readParentId($refId,true);
		$remote_item_ref = $this->getObjIdByImportId(IL_INST_ID.'::'.$objId);
		if($remote_item_ref)
		{
			$this->logger->info('File already created in previous run. Aborting!');
			return;
		}

		$new_remote_ref = $this->getSoapClient()->call(
				'addObject',
				array(
					$this->getSoapSid(),
					$remote_parent_ref,
					$writer->xmlDumpMem(FALSE)
				)
		);

		$this->updateFileObject($objId, $refId);
		return $new_remote_ref;
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function updateFileObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$this->logger->info('Handling update event for file '. ilObject::_lookupTitle($objId).' '.$refId);
		if($tree->isDeleted($refId))
		{
			return;
		}
		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.$objId);
		$remote_ref = end($remote_refs);
		if(!count($remote_refs))
		{
			return $this->createFileObject($objId, $refId);
		}
		$plugin = new ilSyncSlaveClientObjectsPlugin();
		$plugin->includeClass('class.ilSyncFileXmlCache.php');

		$fc = new ilSyncFileXmlCache($objId);
		$this->getSoapClient()->call(
			'updateFile',
			array(
				$this->getSoapSid(),
				$remote_ref,
				file_get_contents($fc->getFile())
			)
		);

		$this->updateMetaData($objId);

		// Add references
		$this->addReferences($objId,$refId,$remote_refs);
	}



	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function trashFileObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function restoreFileObject($objId, $refId)
	{
		$this->createFileObject($objId, $refId);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function removeFileObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
	}
	
	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function createHtlmObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();

		$this->logger->info('Handling create event for html learning module '. ilObject::_lookupTitle($objId).'  '.$refId);
		$writer = $this->buildObjectXml($objId, $refId);

		$remote_parent_ref = $this->readParentId($refId,true);

		$remote_item_ref = $this->getObjIdByImportId(IL_INST_ID.'::'.$objId);
		if($remote_item_ref)
		{
			$this->logger->info('HTML already created in previous run. Aborting!');
			return;
		}

		$new_remote_ref = $this->getSoapClient()->call(
			'addObject',
			array(
				$this->getSoapSid(),
				$remote_parent_ref,
				$writer->xmlDumpMem(FALSE)
			)
		);

		$this->updateHtlmObject($objId, $refId);
		return $new_remote_ref;
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function updateHtlmObject($objId, $refId)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();

		$this->logger->info('Handling update event for html '. ilObject::_lookupTitle($objId).' '.$refId);
		if($tree->isDeleted($refId))
		{
			return;
		}

		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.$objId);
		$remote_ref = end($remote_refs);
		if(!count($remote_refs))
		{
			return $this->createHtlmObject($objId, $refId);
		}

		include_once './Modules/HTMLLearningModule/classes/class.ilObjFileBasedLM.php';
		$plugin = new ilSyncSlaveClientObjectsPlugin();
		$plugin->includeClass('class.ilSyncHtmlXmlCache.php');
		$hc = ilSyncHtmlXmlCache::getInstance($objId);
		$this->getSoapClient()->call(
			'updateHtmlLearningModule',
			array(
				$this->getSoapSid(),
				$remote_ref,
				$hc->getFile(),
				ilObjFileBasedLM::_lookupOnline($objId),
				$objId,
				ilObject::_lookupTitle($objId),
				ilObject::_lookupDescription($objId),
				ilObjFileBasedLM::lookupStartFile($objId)
			)
		);
		
		$this->updateMetaData($objId);
		$this->addReferences($objId, $refId, $remote_refs);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function trashHtlmObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
		
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function restoreHtlmObject($objId, $refId)
	{
		$this->createHtlmObject($objId, $refId);
	}

	/**
	 * @param integer $objId
	 * @param integer $refId
	 */
	public function removeHtlmObject($objId, $refId)
	{
		$this->handleRemove($objId,$refId);
		
	}

	/**
	 * Get ref ids by import id
	 * @param int import id
	 */
	protected function getRefIdByImportId($a_import_id, $a_use_cache = false)
	{
		if(isset($this->ri_cache[$a_import_id]) and $a_use_cache)
		{
			return (array) $this->ri_cache[$a_import_id];
		}
		
		
		$ref_ids = $this->getSoapClient()->call(
			'getRefIdsByImportId',
			array(
				$this->getSoapSid(),
				$a_import_id
			)
		);

		$this->logger->info('Ref ids for '.$a_import_id.' are '.print_r($ref_ids,true));
		$this->ri_cache[$a_import_id] = ($ref_ids ? $ref_ids : array());
		return (array) $this->ri_cache[$a_import_id];
	}

	/**
	 * Get ref ids by import id
	 * @param int import id
	 */
	protected function getRefIdParentsByImportId($a_import_id)
	{
		$ref_ids = $this->getSoapClient()->call(
			'getRefIdParentsByImportId',
			array(
				$this->getSoapSid(),
				$a_import_id
			)
		);

		$this->logger->info('Ref id parents for '.$a_import_id.' are '.print_r($ref_ids,true));
		return $ref_ids ? $ref_ids : array();
	}

	/**
	 * Get ref ids by import id
	 * @param int import id
	 */
	protected function getObjIdByImportId($a_import_id, $a_use_cache = false)
	{
		if(isset($this->oi_cache[$a_import_id]) and $a_use_cache)
		{
			return (int) $this->oi_cache[$a_import_id];
		}
		
		
		$ref_ids = $this->getSoapClient()->call(
			'getObjIdByImportId',
			array(
				$this->getSoapSid(),
				$a_import_id
			)
		);
		$this->oi_cache[$a_import_id] = ($ref_ids ? $ref_ids : 0);
		return $this->oi_cache[$a_import_id];
	}

	/**
	 * Delete depends on trash setting
	 * @param int $objId
	 * @param int $refId
	 */
	protected function handleRemove($objId,$refId)
	{
		global $DIC;

		$ilSetting = $DIC->settings();

		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.$objId);
		$remote_ref = end($remote_refs);
		
		// not possible if trash is off
		#if(ilObject::_lookupType($objId) == 'cat')
		{
			// Check if empty
			$xml = $this->getSoapClient()->call(
					'getTreeChilds',
					array(
						$this->getSoapSid(),
						$remote_ref,
						array(),
						0
					)
				);
			$xml = simplexml_load_string(utf8_encode(utf8_decode($xml)));
			
			// check for imported objects
			if(count($xml->Object))
			{
				foreach($xml->Object as $client_obj)
				{
					$this->logger->info('Found '.(string) $client_obj->Title . ' with import id '. (string) $client_obj->ImportId);
					$import_id = (string) $client_obj->ImportId;
					if(preg_match('/^[0-9]+::/', $import_id))
					{
						$this->logger->debug('--- matches');
						continue;
					}
					$this->logger->warning('Cannot delete non empty category. Aborting!');
					$title = ilObject::_lookupTitle($objId);
					$client_id = explode('::',$this->getSoapSid());
					throw new Exception('Cannote delete non empty category with title "'. $title.'" for client ' . $client_id[1] . '. Found "'. (string) $client_obj->Title.'" of type '.(string) $client_obj['type'].'!');
				}
			}
		}
		
		
		if($remote_ref)
		{
			$this->getSoapClient()->call(
				'removeFromSystemByImportId',
				array(
					$this->getSoapSid(),
					IL_INST_ID . '::' . $objId
				)
			);
		}

		// Finally add left references
		$this->addReferences($objId, $refId, $remote_refs);
	}

	/**
	 * @static
	 * @param string $slaveClientId
	 * @param string $soapUser
	 * @param string $soapPass
	 * @return sscoSlaveClientObjectAdministration $soapClient
	 */
	public static function getInstance($slaveClientId, $soapUser = '', $soapPass = '')
	{
		$instanceKey = $slaveClientId;
		
		if( !isset(self::$instances[$instanceKey]) )
		{
			$setting = new ilSetting('ssco_plugin_settings');
			$soapUser = $setting->get('soap_user_login');
			$soapPass = $setting->get('soap_user_pass');
			self::$instances[$instanceKey] = new self($slaveClientId, $soapUser, $soapPass);
		}
		
		return self::$instances[$instanceKey];
	}

	/**
	 * @param $a_ref_id
	 * @param bool $create_parents
	 * @return bool|int|mixed|void
	 */
	protected function readParentId($a_ref_id, $create_parents = true)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();

		$parent_ref = $tree->getParentId($a_ref_id);
		$parent_obj = ilObject::_lookupObjId($parent_ref);
		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.$parent_obj);
		$remote_ref = end($remote_refs);

		if($remote_ref)
		{
			return $remote_ref;
		}

		/**
		 * if parent is ROOT_FOLDER ID return it
		 *
		 * end recursion!!!
		 */
		if($parent_ref == ROOT_FOLDER_ID)
		{
			return $parent_ref;
		}

		/**
		 * create parent category
		 */
		switch(ilObject::_lookupType($parent_obj))
		{
			case 'cat':
				return $this->createCategoryObject($parent_obj, $parent_ref);
			case 'grp':
				return $this->createGroupObject($parent_obj,$parent_ref);
			case 'fold':
				return $this->createFolderObject($parent_obj,$parent_ref);
			default:
				$this->logger->warning('Invalid parent type given: ' . ilObject::_lookupType($parent_obj));
		}
		return $parent_ref;
	}

	/**
	 * Add references
	 * @param <type> $objId
	 * @param array $refId
	 */
	protected function addReferences($objId,$refId,$remote_refs)
	{
		global $DIC;

		$tree = $DIC->repositoryTree();
		$remote_ref = end($remote_refs);
		
		$local_refs = $this->getAllReferences($objId);
		$remote_parent_refs_required = array();
		// Collect ref_ids
		foreach($local_refs as $local_ref)
		{
			$local_parent = $tree->getParentId($local_ref);
			$local_parent_obj = ilObject::_lookupObjId($local_parent);
			$local_parent_type = ilObject::_lookupType($local_parent_obj);
			if($local_parent_type != 'root' and $local_parent_type != 'cat')
			{
				continue;
			}
			$rpr = $this->getRefIdByImportId(IL_INST_ID.'::'.$local_parent_obj);
			$tmp = end($rpr);
			if($tmp)
			{
				$remote_parent_refs_required[] = $tmp;
			}
		}
		
		$remote_parent_refs_available = $this->getRefIdParentsByImportId(IL_INST_ID.'::'.$objId);
		
		// Add missing
		foreach(array_diff($remote_parent_refs_required, $remote_parent_refs_available) as $add_ref_target)
		{
			try {
				$this->getSoapClient()->call(
					'addReference', array(
						$this->getSoapSid(),
						$remote_ref,
						$add_ref_target
					)
				);
			} 
			catch (Exception $e) {
				// is ignored
			}
		}
		
		// Delete deprecated
		foreach(array_diff($remote_parent_refs_available, $remote_parent_refs_required) as $drop_ref_target)
		{
			$this->getSoapClient()->call(
				'removeReferenceByImportId', array(
					$this->getSoapSid(),
					IL_INST_ID.'::'.$objId,
					$drop_ref_target
				)
				);
		}
	}


	/**
	 * @param int $obj_id
	 * @return int[]
	 */
	protected function getAllReferences($obj_id)
	{
		$refs = array();
		foreach(ilObject::_getAllReferences($obj_id) as $ref_cand)
		{
			if(!$GLOBALS['tree']->isDeleted($ref_cand))
			{
				$refs[] = $ref_cand;
			}
		}
		return $refs;
	}

	/**
	 * Update lom meta data
	 * @param type $obj_id
	 * @return type
	 */
	protected function updateMetaData($obj_id)
	{
		$remote_obj = $this->getObjIdByImportId(IL_INST_ID.'::'.$obj_id);

		include_once './Services/MetaData/classes/class.ilMD2XML.php';
		$md_xml = new ilMD2XML($obj_id,$obj_id,ilObject::_lookupType($obj_id));
		$md_xml->setExportMode(true);
		$md_xml->startExport();
		
		return $this->getSoapClient()->call(
			'updateLomMetaData',
				array(
					$this->getSoapSid(),
					$remote_obj,
					$remote_obj,
					$md_xml->getXML()
			)
		);
	}
	
	/**
	 * Read remote roles by location
	 * @param type $a_remote_ref
	 * @param type $a_global
	 * @return type
	 */
	protected function readRemoteRoles($a_remote_ref, $a_global = true)
	{
		$this->logger->debug('Read remote roles: ' . $a_remote_ref);
		return $this->getSoapClient()->call(
				'getLocalRoles',
					array(
						$this->getSoapSid(),
						$a_remote_ref
					)
		);
	}
	
	/**
	 * Delete remote ref
	 * @param type $a_remote_ref
	 * @param type $a_remote_id
	 */
	protected function deleteRemoteRole($a_remote_ref, $a_remote_id)
	{
		return $this->getSoapClient()->call(
				'deleteLocalPolicy',
				array(
					$this->getSoapSid(),
					$a_remote_ref,
					$a_remote_id
				)
		);
	}
	
	/**
	 * Add a remote role
	 * @param type $a_role_def
	 */
	protected function addRemoteRole($a_role_def)
	{
		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.ilObject::_lookupObjId($a_role_def['location']),true);
		$remote_ref = end($remote_refs);
		
		include_once './Services/Xml/classes/class.ilXmlWriter.php';
		$writer = new ilXmlWriter();
		$writer->xmlStartTag('Objects');

		$params['type'] = 'role';
		$writer->xmlStartTag('Object',$params);
		$writer->xmlElement('Title',array(),$a_role_def['title']);
		$writer->xmlElement('Description',array(),ilObject::_lookupDescription($a_role_def['obj_id']));
		$writer->xmlElement('ImportId',array(),IL_INST_ID.'::'.$a_role_def['obj_id']);
		$writer->xmlEndTag('Object');
		$writer->xmlEndTag('Objects');

		$this->logger->dump($writer->xmlDumpMem(true));
		return $this->getSoapClient()->call(
				'addRole',
				array(
					$this->getSoapSid(),
					$remote_ref,
					$writer->xmlDumpMem()
				)
		);
	}
	
	/**
	 * Update remote template permissions
	 * @param array $a_role_def
	 */
	protected function updateRemoteTemplatePermissions($a_role_def)
	{
		$role_id = $this->getObjIdByImportId(IL_INST_ID.'::'.$a_role_def['obj_id'],true);
		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.ilObject::_lookupObjId($a_role_def['location']),true);
		$remote_ref = end($remote_refs);
		
		return $this->getSoapClient()->call(
				'updateRoleTemplatePermissions',
				array(
					$this->getSoapSid(),
					$role_id,
					$remote_ref,
					$a_role_def['rxml']
				)
		);
	}
	
	/**
	 * Update remote role permissions
	 * @param type $a_location
	 */
	protected function updateRemoteRolePermissions($a_location)
	{
		global $DIC;

		$ilDB = $DIC->database();
		$rbacreview = $DIC->rbac()->review();

		$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.ilObject::_lookupObjId($a_location),true);
		$remote_ref = end($remote_refs);

		$parent_roles = $rbacreview->getParentRoleIds($a_location);
		
		
		foreach((array) $parent_roles as $role_id => $role_data)
		{
			$query = 'SELECT ops_id FROM rbac_pa '.
					'WHERE ref_id = '.$ilDB->quote($a_location,'integer').' '.
					'AND rol_id = '.$ilDB->quote($role_id,'integer');
			$res = $ilDB->query($query);
			$operations = array();
			while($row = $res->fetchRow(DB_FETCHMODE_OBJECT))
			{
				$db_ops = unserialize($row->ops_id);
				foreach((array) $db_ops as $operation)
				{
					$operations[] = $operation;
				}
			}

			$role_id = $this->getObjIdByImportId(IL_INST_ID.'::'.$role_id,true);
			
			$this->getSoapClient()->call(
					'grantPermissions',
					array(
						$this->getSoapSid(),
						$remote_ref,
						$role_id,
						(array) $operations
					)
			);
		}
		return true;
	}
	
	/**
	 * Sync all roles and write import the import id to all clients.
	 * Roles that do not exist on the target platform are created (without template permissions).
	 * Role are compared 
	 * @return bool
	 */
	public function updateRbacImportIds()
	{
		$roles = self::readRelevantRoles();

		// Get roles of all locations
		$available_role_locations = array();
		foreach(self::$sync_locations as $location)
		{
			$remote_refs = $this->getRefIdByImportId(IL_INST_ID.'::'.ilObject::_lookupObjId($location),true);
			$remote_ref = end($remote_refs);
			
			$remote_roles = $this->readRemoteRoles($remote_ref,$location == SYSTEM_FOLDER_ID ? true : false);
			$this->logger->debug('Received roles: ');
			$this->logger->dump($remote_roles, ilLogLevel::DEBUG);

			// Update and delete remote roles
			$root = simplexml_load_string(utf8_encode(utf8_decode($remote_roles)));
			
			if(count($root->Object))
			{
				foreach($root->Object as $xmlRole)
				{
					$idx = $this->searchByRemoteRole($location, (string) $xmlRole->Title, (string) $xmlRole->ImportId);
					if($idx === FALSE)
					{
						// Delete this role
						if($xmlRole['type'] == 'role')
						{
							$this->logger->info('Deleting deprecated role: ' . (string) $xmlRole->Title);
							$this->deleteRemoteRole($remote_ref, (string) $xmlRole['obj_id']);
						}
						else
						{
							$this->logger->debug('Ignoring role template: ' . (string) $xmlRole->Title .' at location: ' . $location );
						}
					}
					else
					{
						$available_role_locations[] = $idx;
					}
				}
			}
		}
		$this->logger->debug('Available role locations...');
		$this->logger->dump($available_role_locations, ilLogLevel::DEBUG);

		// Add missing roles
		foreach(self::$sync_roles as $idx => $role)
		{
			if(!$role['assignable'])
			{
				continue;
			}
			if(!in_array($idx, $available_role_locations))
			{
				// Create this role
				$this->logger->info('Adding new local role...');
				//$this->logger->dump($role);
				$this->addRemoteRole($role);
			}
		}
		
		// Update rbac templates
		foreach(self::$sync_roles as $idx => $role)
		{
			$this->updateRemoteTemplatePermissions($role);
		}
		
		// Update permissions
		foreach(self::$sync_locations as $ref_id)
		{
			$this->updateRemoteRolePermissions($ref_id);
		}
	}
	
	/**
	 * Search remote role
	 * @param int $a_location
	 * @param string $a_title
	 * @param string $a_import_id
	 * @return bool
	 */
	protected function searchByRemoteRole($a_location, $a_title, $a_import_id)
	{
		$a_title = trim($a_title);
		foreach(self::$sync_roles as $idx => $role)
		{
			if($role['location'] == $a_location)
			{
				if($a_title == trim($role['title']))
				{
					$this->logger->info('Found role: ');
					//$this->logger->dump($role);
					return $idx;
				}
			}
		}
		$this->logger->warning('Cannot find remote role with title ' . $a_title);
		return FALSE;
	}
	
	/**
	 * Read all relevant roles
	 * @return type
	 */
	protected static function readRelevantRoles()
	{
		global $DIC;

		$ilDB = $DIC->database();
		$logger = $DIC->logger()->scco();
		
		if(self::$sync_roles)
		{
			return self::$sync_roles;
		}

		$locations = [];

		// Append all administration objects
		foreach($GLOBALS['objDefinition']->getAllRBACObjects() as $rbac_type)
		{
			if($GLOBALS['objDefinition']->isAdministrationObject($rbac_type))
			{
				$query = 'SELECT ref_id FROM object_reference obr '.
						'JOIN object_data obd ON obr.obj_id = obd.obj_id '.
						'WHERE type = '.$ilDB->quote($rbac_type,'text');
				$res = $ilDB->query($query);
				while($row = $res->fetchRow(DB_FETCHMODE_OBJECT))
				{
					// fix for invalic chatroom objects
					if(
						$row->ref_id != ROLE_FOLDER_ID &&
						ilObject::_lookupType($row->ref_id,true) == 'rolf'
					)
					{
						ilLoggerFactory::getLogger('ssco')->warning('Ignoring deprecated rolf object type: ' . $row->ref_id);
						continue;
					}
					$locations[] = $row->ref_id;
				}
			}
		}

		// Append all categories
		$query = 'SELECT child from tree join object_reference obr on child = obr.ref_id '.
				'join object_data obd on obr.obj_id = obd.obj_id '.
				'where type = '.$GLOBALS['ilDB']->quote('cat','text').' '.
				'and deleted is null '.
				'ORDER by lft';
		$res = $GLOBALS['ilDB']->query($query);
		while($row = $res->fetchRow(DB_FETCHMODE_OBJECT))
		{
			$locations[] = $row->child;
		}
		
		
		// store locations 
		self::$sync_locations = $locations;

		foreach(self::$sync_locations as $loc)
		{
			ilLoggerFactory::getLogger('ssco')->debug(ilObject::_lookupType($loc,true));
		}




		// collect all roles
		$roles = array();
		foreach($locations as $location)
		{
			if(!$location)
			{
				continue;
			}

			foreach((array) $GLOBALS['rbacreview']->getRolesOfRoleFolder($location,true) as $role)
			{
				if(ilObject::_lookupType($role) != 'role')
				{
					$logger->info('Ignoring role/rolt: '  . ilObject::_lookupTitle($role));
					continue;
				}

				$rdata['global'] = ($location == ROLE_FOLDER_ID ? 1 : 0);
				$rdata['assignable'] = $GLOBALS['rbacreview']->isAssignable($role,$location);
				$rdata['location'] = $location;
				$rdata['obj_id'] = $role;
				$rdata['import_id'] = IL_INST_ID.'::'.$role;
				$rdata['title'] = ilObject::_lookupTitle($role);
					
				// Write role template xml
				include_once './Services/AccessControl/classes/class.ilRoleXmlExport.php';
				$rexport = new ilRoleXmlExport();
				$rexport->addRole($role, $location);
				$rexport->write();
				$rdata['rxml'] = $rexport->xmlDumpMem();

				$roles[] = $rdata;
			}
		}
		return self::$sync_roles = $roles;
	}



}
?>