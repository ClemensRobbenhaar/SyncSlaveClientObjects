<?php

/**
 * Controller class for the slave client synchronization process
 *
 * @author		Björn Heyser <bheyser@databay.de>
 * @version		$Id: class.sscoSlaveClientSynchronization.php 44233 2013-08-16 11:56:39Z smeyer $
 *
 */
class sscoSlaveClientSynchronization
{
	const TOBC_PLUGIN_PATH = 'Customizing/global/plugins/Services/EventHandling/EventHook/TrackObjectChanges';
	/**
	 * @static
	 * @var array
	 */
	private static $firstRefIdByObjIdCache = array();
	
	/**
	 * @var string
	 */
	private $soapLogin = null;
	
	/**
	 * @var string
	 */
	private $soapPass = null;

	/**
	 * @var \ilLogger
	 */
	private $logger = null;
	
	/**
	 * @param ilSetting $sscoSettings
	 */
	public function __construct(ilSetting $sscoSettings)
	{
		global $DIC;

		$this->logger = $DIC->logger()->scco();

		$soapLogin = $sscoSettings->get('soap_user_login', null);
		$soapPass = $sscoSettings->get('soap_user_pass', null);
		
		if( is_null($soapLogin) || is_null($soapPass) )
		{
			throw new Exception("missing soap login information ($soapLogin/$soapPass)");
		}
		
		$this->setSoapLogin($soapLogin);
		$this->setSoapPass($soapPass);
		
		require_once self::TOBC_PLUGIN_PATH.'/classes/class.tobcObjectChangeEvent.php';
		require_once self::TOBC_PLUGIN_PATH.'/classes/class.tobcObjectChangeEventList.php';
	}

	/**
	 *
	 * @param <type> $slaveClients
	 *
	 * @throws Exception
	 */
	public function performConnectionCheck($slaveClients)
	{
		foreach((array) $slaveClients as $clientId)
		{
			$plugin = new ilSyncSlaveClientObjectsPlugin();
			$plugin->includeClass('class.sscoSlaveClientObjectAdministration.php');
			sscoSlaveClientObjectAdministration::getInstance($clientId);
		}
	}
	
	/**
	 * es werden alle in der queue gespeicherten Events abgearbeitet (nicht nur die mit datum nach dem letzten Sync)
	 * 
	 * abgearbeitete Events werden sofort gelöscht
	 * 
	 * Events werden nicht abgearbeitet sondern ignoriert (und somit auch nicht aus der queue gelöscht),
	 * wenn es ein "file" oder "htlm" Objekt ist und dieses keine vorhandene Referenz unterhalb einer 'cat' Objekts hat
	 * 
	 * Als Target Node wird immer der Parent der ersten gefundenen Referenz verwendet deren Parent auch ein 'cat' Objekt ist
	 * 
	 * Gerenerell werden als erstes alle Container Events ausgeführt, erst anschließend die Content Objekt Events
	 * 
	 * Bei den Container Objekten werden zunächst alle Objekte synchronisiert zu denen ein CREATE Event vorliegt,
	 * anschließend werden alle anderen Container Objekte synchronisiert zu denen KEIN DELETE Event vorliegt,
	 * anschließend werden die restlichen Container Objekte synchronisiert.
	 * 
	 * Bei den Content Objekten ist die Reihenfolge der Abarbeitung egal.
	 * 
	 * Sync Vorgänge laufen nur bei Content Objekten ASYNCHRON (mit ResponseTimeout von 0)
	 * 
	 * @param array $slaveClients 
	 */
	public function perform($slaveClients)
	{
		// process all create events regarding to 'cat' objects

		$eventTypes = array(
			tobcObjectChangeEvent::EVENT_TYPE_CREATE
		);
		
		$objChangeEventList = tobcObjectChangeEventList::getListByObjTypesAndEventTypes( array('cat','grp','fold'), $eventTypes );

		$this->logger->info('Starting creation of containers ["cat","grp","fold"]...');

		self::processEventList($objChangeEventList, $slaveClients);
		
		// process all events regarding to 'cat' objects except create or delete events
		
		$eventTypes = array(
			tobcObjectChangeEvent::EVENT_TYPE_UPDATE,
			tobcObjectChangeEvent::EVENT_TYPE_TOTRASH,
			tobcObjectChangeEvent::EVENT_TYPE_RESTORE
		);
		
		$objChangeEventList = tobcObjectChangeEventList::getListByObjTypesAndEventTypes( array('root','cat','grp','fold'), $eventTypes );

		$this->logger->info('Starting update of containers ["root","cat","grp","fold"]...');
		self::processEventList($objChangeEventList, $slaveClients);
		
		// process all remove events regarding to 'cat','grp' objects
		
		$eventTypes = array(
			tobcObjectChangeEvent::EVENT_TYPE_REMOVE
		);
		
		$objChangeEventList = tobcObjectChangeEventList::getListByObjTypesAndEventTypes( array('cat','grp','fold'), $eventTypes );
		

		$GLOBALS['ilLog']->write('Handling delete containers: '. memory_get_peak_usage());
		self::processEventList($objChangeEventList, $slaveClients);
		
		// process all events regarding to 'htlm' or 'file' objects
				
		$objChangeEventList = tobcObjectChangeEventList::getListByObjTypes( array('htlm', 'file', 'webr', 'lm', 'blog', 'itgr'));
		self::createItemGroupDummyEvents($objChangeEventList);

		$GLOBALS['ilLog']->write('Handling html/file/webr/scorm/blog objects: ' . count($objChangeEventList) . ' mem: ' . memory_get_peak_usage());
		self::processEventList($objChangeEventList, $slaveClients);
	}
	
	public function performDBUpdate($clients)
	{
		$GLOBALS['ilLog']->write(__METHOD__.': Starting db update...');
		foreach($clients as $client)
		{
			$slaveClientObjAdm = sscoSlaveClientObjectAdministration::getInstance($client);
			$slaveClientObjAdm->updateDatabase();
		}
	}


	/**
	 * @param $clients
	 */
	public function performRbacSync($clients)
	{
		$this->logger->info('starting rbac sync');
		$this->logger->info('Syncing rbac role import ids');
		foreach($clients as $client)
		{
			$slaveClientObjAdm = sscoSlaveClientObjectAdministration::getInstance($client);
			$this->logger->debug('Using soap sid: ' . $slaveClientObjAdm->getSoapSid());
			$slaveClientObjAdm->updateRbacImportIds();
		}
	}


	/**
	 * Synchronise help packages.
	 * @param string[] $clients
	 * @param string $file
	 *
	 */
	public function performHelpSync($clients, $file)
	{
		$this->logger->debug('Starting help synchronisation.');
		foreach($clients as $client)
		{
			$client_sync = sscoSlaveClientObjectAdministration::getInstance($client);
			$client_sync->updateHelp($file);
		}
		return true;
	}
	
	/**
	 * @param tobcObjectChangeEventList	$objChangeEventList
	 * @param array						$slaveClients
	 */
	private static function processEventList(tobcObjectChangeEventList $objChangeEventList, $slaveClients)
	{
		foreach($objChangeEventList as $objChangeEvent)
		{
			foreach($slaveClients as $slaveClientId)
			{
				self::processEvent($objChangeEvent, $slaveClientId);
			}
			
			$objChangeEvent->delete();
		}
	}
	
	/**
	 * @param tobcObjectChangeEvent	$objChangeEvent
	 * @param string				$slaveClientId 
	 */
	private static function processEvent(tobcObjectChangeEvent $objChangeEvent, $slaveClientId)
	{
		global $DIC;

		$logger = $DIC->logger()->ssco();

		switch( $objChangeEvent->getEventType() )
		{
			case tobcObjectChangeEvent::EVENT_TYPE_CREATE:		$method = 'create';		break;
			case tobcObjectChangeEvent::EVENT_TYPE_UPDATE:		$method = 'update';		break;
			case tobcObjectChangeEvent::EVENT_TYPE_TOTRASH:		$method = 'trash';		break;
			case tobcObjectChangeEvent::EVENT_TYPE_RESTORE:		$method = 'restore';	break;
			case tobcObjectChangeEvent::EVENT_TYPE_REMOVE:		$method = 'remove';		break;

			default: throw new Exception(
					'could not process change event with illegal event type ('.$objChangeEvent->getEventType().')'
			);
		}
		
		switch($objChangeEvent->getObjType() )
		{
			case 'cat':
				$method .= 'Category';
				break;
			case 'grp':
				$method .= 'Group';
				break;
			case 'fold':
				$method .= 'Folder';
				break;
			case 'htlm':
				$method .= 'Htlm';
				break;
			case 'file':
				$method .= 'File';
				break;
			case 'webr':
				$method .= 'WebResource';
				break;
			case 'sahs':
				$method .= 'Scorm';
				break;
            case 'root':
                $method .= 'Root';
                break;
			case 'lm':
				$method .= 'LearningModule';
				break;

			case 'blog'	:
				$method .= 'Blog';
				break;

			case 'itgr':
				$method .= 'ItemGroup';
				break;

			default:
				throw new Exception(
					'could not process change event with object type not supported ('.$objChangeEvent->getObjType().')'
				);
		}
		
		$method .= 'Object';
		
		$slaveClientObjAdm = sscoSlaveClientObjectAdministration::getInstance($slaveClientId);
		
		$objId = $objChangeEvent->getObjId();
		$refId = self::getFirstRefIdByObjId($objId, true);
		
		if($refId)
		{
			$logger->debug('Calling: ' . $method);
			return $slaveClientObjAdm->$method($objId, $refId);
		}
		// Allow only delete
		if(stristr($method, 'trash') || stristr($method, 'remove'))
		{
			return $slaveClientObjAdm->$method($objId, $refId);
		}
		$logger->info('Ignoring '.$method.' for deleted references.');
	}
	

	/**
	 * For every create event in the list add an artifical, non-persistent event
	 * to update all item groups containing the new item.
	 * This avoids having the item replicated but not its position in the item group.
	 *
	 * Implementation Note: we do not return a new event list, as their constructor
	 * is private and we would have to create a dummy one by doing a DB search
	 * which either is guaranteed to return no events, or remove the events found afterwards.
	 * This seems just a bit too silly.
	 *
	 * @param $objChangeEventList the list of events to be searched for create-events;
	 *   this list will also be updated in place by appending the artificial events.
	 * @return the updated event list, containing the events for containers to be updated
	 */
	private static function createItemGroupDummyEvents($objChangeEventList)
	{
		$obj_ids = array();
		foreach ($objChangeEventList as $event) {
			if ($event->getEventType() != tobcObjectChangeEvent::EVENT_TYPE_CREATE) {
				continue;
			}
			$obj_ids[] = $event->getObjId();
		}
		global $ilDB;

		$updatedItems = $ilDB->in('r.obj_id', $obj_ids, false, 'integer');
		$statement = 'SELECT distinct item_group_id ' .
			'FROM item_group_item i, object_reference r, object_data o ' .
			'WHERE r.ref_id = i.item_ref_id AND o.obj_id = item_group_id AND o.type=\'itgr\' AND ' .
			$updatedItems;

		// can be done in one query as
		// select distinct item_group_id from item_group_item i, object_reference r, object_data o
		//  where r.ref_id = i.item_ref_id and o.obj_id = item_group_id and r.obj_id in
		//   (select evt_obj_id from evnt_evhk_tobc_events where evt_event_type = 'CREATE');

		$resultSet = $ilDB->query($statement);
		while( $dataSet = $ilDB->fetchAssoc($resultSet) )
		{
			$objectChangeEvent = new tobcObjectChangeEvent();
			$objectChangeEvent->setObjId($dataSet['item_group_id'])
				->setObjType('itgr')
				->setEventType(tobcObjectChangeEvent::EVENT_TYPE_UPDATE)
				->setId(-1)
				;
			$objChangeEventList->addEvent($objectChangeEvent);
		}

		return $objChangeEventList;
	}

	/**
	 * @return string $soapLogin
	 */
	public function getSoapLogin()
	{
		return $this->soapLogin;
	}

	/**
	 * @param string $soapLogin 
	 */
	public function setSoapLogin($soapLogin)
	{
		$this->soapLogin = $soapLogin;
	}

	/**
	 * @return string $soapPass
	 */
	public function getSoapPass()
	{
		return $this->soapPass;
	}

	/**
	 * @param string $soapPass
	 */
	public function setSoapPass($soapPass)
	{
		$this->soapPass = $soapPass;
	}
	
	/**
	 * @static
	 * @global ilTree $tree
	 * @global ilObjectDataCache $ilObjDataCache
	 * @param integer $objId
	 * @param boolean $checkValidParent
	 * @return integer $firstRefId
	 */
	private static function getFirstRefIdByObjId($objId, $checkValidParent)
	{
		if( !isset(self::$firstRefIdByObjIdCache[$objId]) )
		{
			$refIds = ilObject::_getAllReferences($objId);
			
			if( $checkValidParent )
			{
				global $tree, $ilObjDataCache;
				
				$validRefId = null;
				
				foreach($refIds as $refId)
				{
					if(!$GLOBALS['tree']->isDeleted($refId))
					{
						self::$firstRefIdByObjIdCache[$objId] = $refId;
						return $refId;
					}
					/*
					$parentRefId = $tree->getParentId($refId);
					$parentObjId = $ilObjDataCache->lookupType($parentRefId);
					$parentObjType = $ilObjDataCache->lookupType($parentObjId);
					
					if( in_array($parentObjType, tobcObjectChangeEvent::getAllowedContainerTypes()) )
					{
						$validRefId = $refId;
						break;
					}
					 * 
					 */
				}
				self::$firstRefIdByObjIdCache[$objId] = $validRefId;
			}
			else
			{
				self::$firstRefIdByObjIdCache[$objId] = current($refIds);
			}
		}
		
		$firstRefId = self::$firstRefIdByObjIdCache[$objId];
		
		return $firstRefId;
	}
}

