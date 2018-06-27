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
		
		$objChangeEventList = tobcObjectChangeEventList::getListByObjTypesAndEventTypes( array('cat','grp','fold'), $eventTypes );

		$this->logger->info('Starting update of containers ["cat","grp","fold"]...');
		self::processEventList($objChangeEventList, $slaveClients);
		
		// process all remove events regarding to 'cat','grp' objects
		
		$eventTypes = array(
			tobcObjectChangeEvent::EVENT_TYPE_REMOVE
		);
		
		$objChangeEventList = tobcObjectChangeEventList::getListByObjTypesAndEventTypes( array('cat','grp','fold'), $eventTypes );
		
		$GLOBALS['ilLog']->write('Handling delete containers: '. memory_get_peak_usage());
		self::processEventList($objChangeEventList, $slaveClients);
		
		// process all events regarding to 'htlm' or 'file' objects
				
		$objChangeEventList = tobcObjectChangeEventList::getListByObjTypes( array('htlm', 'file', 'webr') );
		
		$GLOBALS['ilLog']->write('Handling html/file/webr objects: '. memory_get_peak_usage());
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
	
	
	public function performRbacSync($clients)
	{
		$GLOBALS['ilLog']->write(__METHOD__.': Starting rbac sync...');
		
		$GLOBALS['ilLog']->write(__METHOD__.': Syncing rbac role import ids');
		foreach($clients as $client)
		{
			$slaveClientObjAdm = sscoSlaveClientObjectAdministration::getInstance($client);
			$GLOBALS['ilLog']->write(__METHOD__.$slaveClientObjAdm->getSoapSid());
			$slaveClientObjAdm->updateRbacImportIds();
		}
		
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
			return $slaveClientObjAdm->$method($objId, $refId);
		}
		// Allow only delete
		if(stristr($method, 'trash') || stristr($method, 'remove'))
		{
			return $slaveClientObjAdm->$method($objId, $refId);
		}
		$GLOBALS['ilLog']->write(__METHOD__.': Ignoring '.$method.' for deleted references.');
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

