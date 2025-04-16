<?php
// filepath: /Users/pisanchueachatchai/Documents/project web/plg_system_smartimagepath/plg_system_smartimagepath/script.php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.SmartImagePath
 *
 * @copyright   (C) 2025 Pisanchu Eachatchai. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access to this file
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

/**
 * Script file for the SmartImagePath plugin
 *
 * @since  1.0.0
 */
class PlgSystemSmartimagepathInstallerScript
{
    /**
     * Constructor
     *
     * @param   InstallerAdapter  $adapter  The object responsible for running this script
     */
    public function __construct(InstallerAdapter $adapter = null)
    {
        // Constructor can be called without the adapter in some Joomla versions
    }

    /**
     * Called before any type of action
     *
     * @param   string  $route   Which action is happening (install|uninstall|discover_install|update)
     * @param   InstallerAdapter  $adapter  The object responsible for running this script
     *
     * @return  boolean  True on success
     */
    public function preflight($route, $adapter)
    {
        if ($route == 'update') {
            // Clean cache on update
            $this->cleanCache();
        }

        return true;
    }

    /**
     * Called after any type of action
     *
     * @param   string  $route   Which action is happening (install|uninstall|discover_install|update)
     * @param   InstallerAdapter  $adapter  The object responsible for running this script
     *
     * @return  boolean  True on success
     */
    public function postflight($route, $adapter)
    {
        if ($route == 'install' || $route == 'update') {
            $this->enablePlugin();
        }

        // ตรวจสอบโครงสร้างโฟลเดอร์
        $rootFolder = 'images/articles';
        $rootFolderAbs = JPATH_ROOT . '/' . $rootFolder;
        
        if (!Folder::exists($rootFolderAbs)) {
            Folder::create($rootFolderAbs);
            
            // ใช้ Factory::getApplication()->enqueueMessage แทน adapter message
            Factory::getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_SMARTIMAGEPATH_FOLDER_CREATED'));
        }

        return true;
    }

    /**
     * Clean cache after update - แก้ไขเมธอดนี้ให้ทำงานกับทุกเวอร์ชันของ Joomla
     *
     * @return  void
     */
    private function cleanCache()
    {
        try {
            // วิธีที่ 1: ใช้ Cache::clean
            $cache = Factory::getCache('com_content');
            if (method_exists($cache, 'clean')) {
                $cache->clean();
            }

            // วิธีที่ 2: ใช้ Cache::clean สำหรับแคชระบบ
            $systemCache = Factory::getCache('_system');
            if (method_exists($systemCache, 'clean')) {
                $systemCache->clean();
            }

            // วิธีที่ 3: ล้างแฟ้มแคชโดยตรง (เป็นทางเลือกสุดท้าย)
            $cacheDir = JPATH_SITE . '/cache/com_content';
            if (Folder::exists($cacheDir)) {
                Folder::delete($cacheDir);
            }
        } catch (Exception $e) {
            // บันทึกข้อผิดพลาดแต่ไม่หยุดการติดตั้ง
            Log::add('Error cleaning cache: ' . $e->getMessage(), Log::WARNING, 'smartimagepath');
        }
    }

    /**
     * Enable the plugin after installation
     *
     * @return  void
     */
    private function enablePlugin()
    {
        try {
            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('smartimagepath'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'));
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            // บันทึกข้อผิดพลาดแต่ไม่หยุดการติดตั้ง
            Log::add('Error enabling plugin: ' . $e->getMessage(), Log::WARNING, 'smartimagepath');
        }
    }
}