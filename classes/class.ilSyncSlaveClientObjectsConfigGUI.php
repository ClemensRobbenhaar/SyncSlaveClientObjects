<?php

include_once("./Services/Component/classes/class.ilPluginConfigGUI.php");

use ILIAS\FileUpload\Location;
 
/**
 * GUI class for plugin configuration as well as
 * the slave client synchronization
 *
 * @author		Björn Heyser <bheyser@databay.de>
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 *
 */
class ilSyncSlaveClientObjectsConfigGUI extends ilPluginConfigGUI
{
	const CONFIG_FILE_PATH = 'ClientReplication/config.ini.php';
	
	const MIN_SLEEP = 0;
	const MAX_SLEEP = 900;
	
	const SYNC_OBJS = 1;
	const SYNC_DB = 2;
	const SYNC_RBAC = 3;
	const SYNC_HELP = 4;

	/**
	 * @var \ilLogger
	 */
	private $logger = null;

	
	/**
	* Handles all commmands, default is "configure"
	*/
	function performCommand($cmd)
	{
		global $DIC;

		$rbacsystem = $DIC->rbac()->system();
		$ilCtrl = $DIC->ctrl();
		$ilTabs = $DIC->tabs();
		$this->logger = $DIC->logger()->ssco();

		if( !$rbacsystem->checkAccess('read', ADMINISTRATION_SERVICES_PLUGINS_REF_ID) )
		{
			throw new ilException('permission denied!');
		}
		
		if($cmd == 'configure')
		{
			$cmd = 'showSyncForm';
		}
		
		$this->setTabs($ilTabs, $ilCtrl);
		
		switch ($cmd)
		{
			case 'showSettingsForm':
			case 'saveSettings':
				
				$ilTabs->activateTab('ssco_settings_tab');
				break;
			
			case 'showSyncForm':
			case 'performSync':
				
				$ilTabs->activateTab('ssco_sync_tab');
				break;
			
			default: throw new ilException("invalid command: $cmd");
		}
		
		$this->$cmd();
	}
	
	/**
	 * @param ilTabsGUI $tabs
	 * @param ilCtrl $ctrl 
	 */
	private function setTabs(ilTabsGUI $tabs, ilCtrl $ctrl)
	{
		$tabs->addTab('ssco_sync_tab',
				$this->getPluginObject()->txt('ssco_sync_tab'),
				$ctrl->getLinkTarget($this, 'showSyncForm')
		);
		
		$tabs->addTab('ssco_settings_tab',
				$this->getPluginObject()->txt('ssco_settings_tab'),
				$ctrl->getLinkTarget($this, 'showSettingsForm')
		);
	}
	
	private function showSettingsForm()
	{
		global $DIC;

		$tpl = $DIC->ui()->mainTemplate();
		$form = $this->initSettingsForm();
		$tpl->setContent( $form->getHTML() );
	}
	
	/**
	 * @return ilPropertyFormGUI $form
	 */
	private function initSettingsForm()
	{
		global $DIC;

		$lng = $DIC->language();
		$ilCtrl = $DIC->ctrl();
		
		$pl = $this->getPluginObject();
		
		$sscoSettings = $this->getSettings();
	
		include_once("Services/Form/classes/class.ilPropertyFormGUI.php");
		
		$form = new ilPropertyFormGUI();
	
			// soap user login
			$ti = new ilTextInputGUI($pl->txt("settings_form_soap_user_login_label"), "settings_form_soap_user_login");
			$ti->setInfo($pl->txt("settings_form_soap_user_login_info"));
			$ti->setRequired(true);
			$ti->setMaxLength(80);
			$ti->setSize(32);
			
			if( $ti->getValue() === null ) $ti->setValue( $sscoSettings->get('soap_user_login', '') );
		
		$form->addItem($ti);
	
			// soap user login
			$ti = new ilTextInputGUI($pl->txt("settings_form_soap_user_pass_label"), "settings_form_soap_user_pass");
			$ti->setInfo($pl->txt("settings_form_soap_user_pass_info"));
			$ti->setRequired(true);
			$ti->setMaxLength(80);
			$ti->setSize(32);
			
			if( $ti->getValue() === null ) $ti->setValue( $sscoSettings->get('soap_user_pass', '') );
	
		$form->addItem($ti);
	
		$form->addCommandButton("saveSettings", $lng->txt('save'));
	                
		$form->setTitle($pl->txt("client_sync_form_header_title"));
		$form->setFormAction($ilCtrl->getFormAction($this));
		
		return $form;
	}

	/**
	 * Save settings
	 */
	private function saveSettings()
	{
		global $DIC;

		$tpl = $DIC->ui()->mainTemplate();
		$ilCtrl = $DIC->ctrl();
		
		$pl = $this->getPluginObject();

		$form = $this->initSettingsForm();
		
		if(!$form->checkInput())
		{
			$form->setValuesByPost();
			$tpl->setContent($form->getHtml());
			return;
		}
		
		$sscoSettings = $this->getSettings();

		$soapLogin = $form->getInput('settings_form_soap_user_login');
		$sscoSettings->set('soap_user_login', $soapLogin);
		
		$soapPass = $form->getInput('settings_form_soap_user_pass');
		$sscoSettings->set('soap_user_pass', $soapPass);
		
		ilUtil::sendSuccess($pl->txt('settings_form_saved_successfully'), true);
		$ilCtrl->redirect($this, 'showSettingsForm');
	}

	/**
	 * show sync form
	 */
	private function showSyncForm()
	{
		global $DIC;

		$tpl = $DIC->ui()->mainTemplate();
		$form = $this->initSyncForm();
		$tpl->setContent( $form->getHTML() );
	}
	
	/**
	 * @return \ilPropertyFormGUI $form
	 */
	private function initSyncForm()
	{
		global $DIC;

		$lng = $DIC->language();
		$ilCtrl = $DIC->ctrl();
		
		$pl = $this->getPluginObject();
	
		include_once("Services/Form/classes/class.ilPropertyFormGUI.php");
		
		$form = new ilPropertyFormGUI();
		
		$type = new ilRadioGroupInputGUI($pl->txt('sync_type'),'sync_type');
		
		
		$sync_db = new ilRadioOption($pl->txt('sync_db'));
		$sync_db->setValue(self::SYNC_DB);
		$type->addOption($sync_db);
		
		$sync_obj = new ilRadioOption($pl->txt('sync_objs'));
		$sync_obj->setValue(self::SYNC_OBJS);
		$type->addOption($sync_obj);

		$sync_rbac = new ilRadioOption($pl->txt('sync_rbac'));
		$sync_rbac->setValue(self::SYNC_RBAC);
		$sync_rbac->setDisabled(false);
		$type->addOption($sync_rbac);

		$sync_help = new ilRadioOption($pl->txt('sync_help'));
		$sync_help->setValue(self::SYNC_HELP);
		$sync_help->setDisabled(false);
		$type->addOption($sync_help);

		// add help file
		$help_file = new ilFileInputGUI($pl->txt('help_file'), 'help_file');
		$help_file->setSuffixes(['zip']);
		$help_file->setRequired(true);
		$sync_help->addSubItem($help_file);

		
		$type->setValue(self::SYNC_OBJS);
		$type->setRequired(true);
		
		$form->addItem($type);

		// setting 2 (text)
		$ti = new ilTextInputGUI($pl->txt("sleep_between_soap_requests_label"), "sleep_between_soap_requests");
		$ti->setInfo($pl->txt("sleep_between_soap_requests_info"));
		$ti->setRequired(true);
		$ti->setMaxLength(10);
		$ti->setSize(10);

		if ($ti->getValue() === null) {
			$ti->setValue(3);
		}
		$form->addItem($ti);
	
		$form->addCommandButton("performSync", $pl->txt("client_sync_form_start_process"));
	                
		$form->setTitle($pl->txt("client_sync_form_header_title"));
		$form->setFormAction($ilCtrl->getFormAction($this));
		
		return $form;
	}
	
	/**
	 * Save form input (currently does not save anything to db)
	 *
	 */
	private function performSync()
	{
		global $DIC;

		$tpl = $DIC->ui()->mainTemplate();
		$lng = $DIC->language();
		$ilCtrl = $DIC->ctrl();
	
		$pl = $this->getPluginObject();
		
		$form = $this->initSyncForm();
		
		if(!$form->checkInput())
		{
			$form->setValuesByPost();
			$tpl->setContent($form->getHtml());
			return;
		}
		
		$sleepTime = (int)$form->getInput("sleep_between_soap_requests");
		
		if( $sleepTime < self::MIN_SLEEP || $sleepTime > self::MAX_SLEEP )
		{
			ilUtil::sendFailure(
				sprintf($pl->txt("perform_sync_started_failure_sleeptime"), self::MIN_SLEEP, self::MAX_SLEEP),
				true
			);
			
			$form->setValuesByPost();
			$tpl->setContent($form->getHtml());
			return;
		}

		try
		{
			$currentSyncDate = new ilDateTime(time(), IL_CAL_UNIX);
			
			$sscoSettings = $this->getSettings();
			$slaveClients = $this->getSlaveClients();
			
			$this->getPluginObject()->includeClass('class.sscoSlaveClientSynchronization.php');
			
			$slaveClientSync = new sscoSlaveClientSynchronization($sscoSettings);

			$this->logger->debug('Starting synchronisation');

			switch($form->getInput('sync_type'))
			{
				case self::SYNC_OBJS:
					$this->logger->info('Starting update of objects');
					$slaveClientSync->performConnectionCheck($slaveClients);
					$this->logger->debug('Connection check passed.');
					$slaveClientSync->perform($slaveClients);
					$this->setLastSyncDate($currentSyncDate);
					break;
					
				case self::SYNC_DB:
					$this->logger->info('Starting update of database.');
					$slaveClientSync->performConnectionCheck($slaveClients);
					$slaveClientSync->performDBUpdate($slaveClients);
					break;
				
				case self::SYNC_RBAC:
					$this->logger->info('Starting update of access control.');
					$slaveClientSync->performConnectionCheck($slaveClients);
					$slaveClientSync->performRbacSync($slaveClients);
					break;

				case self::SYNC_HELP:
					$this->logger->info('Starting help package synchronisation.');

					$tmp_file = $this->handleHelpModuleUpload();

					$slaveClientSync->performConnectionCheck($slaveClients);
					$slaveClientSync->performHelpSync($slaveClients, $tmp_file);
					break;
			}
			
			// Perform a soap connection check
			
		}
		catch(Exception $e)
		{

			$this->logger->warning(get_class($e));
			$this->logger->warning($e->getMessage());
			$this->logger->logStack();
			ilUtil::sendFailure(
				sprintf($pl->txt("perform_sync_started_exception"), $e->getMessage()),
				true
			);
			
			$form->setValuesByPost();
			$tpl->setContent($form->getHtml());
			return;
		}

		ilUtil::sendSuccess($pl->txt("perform_sync_started_success"), true);
		$ilCtrl->redirect($this, "showSyncForm");
	}

	/**
	 * Handle help module upload
	 */
	protected function handleHelpModuleUpload()
	{
		global $DIC;

		$uploader = $DIC->upload();
		$filename = '';
		if(!$uploader->hasBeenProcessed())
		{
			$uploader->process();

			foreach($uploader->getResults() as $result)
			{
				$uploader->moveOneFileTo(
					$result,
					'',
					Location::TEMPORARY,
					$result->getName()
				);
				$filename = $result->getName();
			}
		}
		$this->logger->debug('Moved file to: ' . ilUtil::getDataDir().'/temp/'.$filename);
		return ilUtil::getDataDir().'/temp/'.$filename;
	}

	/**
	 * @return ilDateTime $lastSyncDate
	 */
	private function getLastSyncDate()
	{
		$sscoSettings = $this->getSettings();
		
		$lastSyncDatetime = $sscoSettings->get('last_sync_datetime', null);
		
		if( !is_null($lastSyncDatetime) )
		{
			$lastSyncDate = new ilDateTime($lastSyncDatetime, IL_CAL_DATETIME);
		}
		else
		{
			$lastSyncDate = new ilDateTime(0, IL_CAL_UNIX);
		}
		
		return $lastSyncDate;
	}
	
	/**
	 * @param ilDateTime $currentSyncDate
	 */
	private function setLastSyncDate(ilDateTime $currentSyncDate)
	{
		$sscoSettings = $this->getSettings();
		
		$sscoSettings->set('last_sync_datetime', $currentSyncDate->get(IL_CAL_DATETIME));
	}
	
	/**
	 * @return array $slaveClients
	 */
	private function getSlaveClients()
	{
		$slaveClients = array();
		
		$ini = new ilIniFile(self::CONFIG_FILE_PATH);

		$ini->read();
		
		if( $err = $ini->getError() )
		{
			throw new ilException('Error in config file "config.ini.php": '.$err);
		}
		
		$i = 0;

		while( $ini->groupExists($section = 'CLIENT_'.++$i) )
		{
			$clientId = $ini->readVariable($section, 'CLIENT_ID');

			if($clientId == CLIENT_ID)
			{
				continue;
			}
			
			if( $err = $ini->getError() )
			{
				throw new ilException('Error in config file "config.ini.php": '.$err);
			}
			
			$slaveClients[] = $clientId;
		}
		return $slaveClients;
	}
	
	/**
	 * @return ilSetting $sscoSettings
	 */
	private function getSettings()
	{
		$sscoSettings = new ilSetting('ssco_plugin_settings');
		
		return $sscoSettings;
	}
}
