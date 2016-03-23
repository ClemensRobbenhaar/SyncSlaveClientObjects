<?php

include_once("./Services/UIComponent/classes/class.ilUserInterfaceHookPlugin.php");

/**
 * The ssco plugin class
 *
 * @author		Björn Heyser <bheyser@databay.de>
 * @version		$Id: class.ilSyncSlaveClientObjectsPlugin.php 36596 2012-08-28 17:38:15Z smeyer $
 *
 */
class ilSyncSlaveClientObjectsPlugin extends ilUserInterfaceHookPlugin
{
	function getPluginName()
	{
		return "SyncSlaveClientObjects";
	}
}

?>
