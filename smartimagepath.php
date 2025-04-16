<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;
use Joomla\CMS\Language\Text;

class PlgSystemSmartimagepath extends CMSPlugin
{
    protected $app;
    protected $oldCatId = null;
    protected $autoloadLanguage = true;

    /**
     * Store the original category ID before an article is saved
     *
     * @param  string  $context  The context of the content
     * @param  object  $data     The article data
     * @param  bool    $isNew    Is this a new article
     * @return bool
     */
    public function onContentBeforeSave($context, $data, $isNew)
    {
        if ($context !== 'com_content.article') {
            return true;
        }

        // ถ้าไม่ต้องการใช้งานกับหมวดหมู่ปลายทาง
        if (!$isNew && isset($data->catid) && !$this->shouldProcessCategory($data->catid)) {
            // แจ้งเตือนผู้ใช้ว่าหมวดหมู่ใหม่ไม่ได้ใช้งานการจัดการรูปภาพอัตโนมัติ
            Factory::getApplication()->enqueueMessage(
                Text::_('PLG_SYSTEM_SMARTIMAGEPATH_CATEGORY_NOT_PROCESSED'), 
                'notice'
            );
        }

        // Store original category ID for existing articles
        if (!$isNew) {
            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select('catid')
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $data->id, ParameterType::INTEGER);
            $this->oldCatId = (int)$db->setQuery($query)->loadResult();
            
            // ถ้าหมวดหมู่เดิมไม่ได้ใช้งานกับ plugin แต่หมวดหมู่ใหม่ใช้ได้
            if (!$this->shouldProcessCategory($this->oldCatId) && $this->shouldProcessCategory($data->catid)) {
                // บันทึกล็อกเพื่อตรวจสอบ
                Log::add("Article moved from excluded category {$this->oldCatId} to included category {$data->catid}", 
                         Log::INFO, 'smartimagepath');
            }
            // ถ้าหมวดหมู่เดิมใช้งานกับ plugin แต่หมวดหมู่ใหม่ไม่ใช้งาน
            else if ($this->shouldProcessCategory($this->oldCatId) && !$this->shouldProcessCategory($data->catid)) {
                // บันทึกล็อกเพื่อตรวจสอบ
                Log::add("Article moved from included category {$this->oldCatId} to excluded category {$data->catid}", 
                         Log::INFO, 'smartimagepath');
            }
        }

        return true;
    }

    /**
     * Handle image paths after an article is saved
     *
     * @param  string  $context  The context of the content
     * @param  object  $data     The article data
     * @param  bool    $isNew    Is this a new article
     * @param  bool    $article  The full article object
     * @return void
     */
    public function onContentAfterSave($context, $data, $isNew, $article)
    {
        if ($context !== 'com_content.article' || empty($data->images)) {
            return;
        }

        // Setup logging
        Log::addLogger(
            ['text_file' => 'smartimagepath.log'],
            Log::ALL,
            ['smartimagepath']
        );

        $images = json_decode($data->images);
        if (!$images) {
            return;
        }

        $catId = (int)($data->catid ?? 0);
        $articleId = (int)($data->id ?? 0);
        if (!$catId || !$articleId) {
            return;
        }
        
        // ตรวจสอบว่าควรประมวลผลหมวดหมู่นี้หรือไม่
        if (!$this->shouldProcessCategory($catId)) {
            Log::add("Category ID {$catId} excluded from processing", Log::INFO, 'smartimagepath');
            return;
        }

        // Get category alias
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('alias'))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $catId, ParameterType::INTEGER);
        $categoryAlias = $db->setQuery($query)->loadResult();
        
        if (!$categoryAlias) {
            return;
        }

        $year = Factory::getDate()->format('Y');
        $params = $this->params;
        $folderStructure = $params->get('folder_structure', 'category/year/article');
        $rootFolder = $params->get('root_folder', 'images/articles');

        // ตาม folder_structure ที่กำหนด
        switch ($folderStructure) {
            case 'year/category/article':
                $newFolder = $rootFolder . '/' . $year . '/' . $categoryAlias . '/' . $articleId;
                break;
            case 'category/article':
                $newFolder = $rootFolder . '/' . $categoryAlias . '/' . $articleId;
                break;
            default: // category/year/article
                $newFolder = $rootFolder . '/' . $categoryAlias . '/' . $year . '/' . $articleId;
        }
        $newFolderAbs = JPATH_ROOT . '/' . $newFolder;

        // Check if category has changed
        $categoryChanged = !$isNew && $this->oldCatId && $this->oldCatId !== $catId;

        if ($categoryChanged) {
            $this->handleCategoryChange($db, $images, $this->oldCatId, $categoryAlias, $year, $articleId, $newFolder, $newFolderAbs);
        }

        // Handle moving images from root to the structured folder
        $this->moveImagesToStructuredFolder($images, $newFolderAbs, $newFolder, $articleId);
    }

    /**
     * Handle moving images when category changes
     *
     * @param  object  $db              Database object
     * @param  object  $images          Images object
     * @param  int     $oldCatId        Original category ID
     * @param  string  $newCategoryAlias New category alias
     * @param  string  $year            Current year
     * @param  int     $articleId       Article ID
     * @param  string  $newFolder       New folder path
     * @param  string  $newFolderAbs    Absolute path to new folder
     * @return void
     */
    private function handleCategoryChange($db, $images, $oldCatId, $newCategoryAlias, $year, $articleId, $newFolder, $newFolderAbs)
    {
        // Get old category alias
        $query = $db->getQuery(true)
            ->select($db->quoteName('alias'))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $oldCatId, ParameterType::INTEGER);
        $oldCategoryAlias = $db->setQuery($query)->loadResult();
        
        if (!$oldCategoryAlias) {
            return;
        }
        
        $oldFolder = 'images/articles/' . $oldCategoryAlias . '/' . $year . '/' . $articleId;
        $oldFolderAbs = JPATH_ROOT . '/' . $oldFolder;

        Log::add(Text::sprintf('PLG_SYSTEM_SMARTIMAGEPATH_CATEGORY_CHANGED', $oldCategoryAlias, $newCategoryAlias), Log::INFO, 'smartimagepath');

        // Update image paths in the database
        $changed = $this->updateImagePaths($images, $oldFolder, $newFolder);

        // Move files from old folder to new folder
        if (Folder::exists($oldFolderAbs)) {
            $this->moveFilesAndCleanFolders($oldFolderAbs, $newFolderAbs, $oldFolder, $newFolder);
        }

        // Update database with changed paths
        if ($changed) {
            $this->updateDatabase($articleId, $images);
        }
    }

    /**
     * Update image paths when category changes
     * 
     * @param  object  $images     Images object
     * @param  string  $oldFolder  Old folder path
     * @param  string  $newFolder  New folder path
     * @return bool    Whether paths were changed
     */
    private function updateImagePaths($images, $oldFolder, $newFolder)
    {
        $changed = false;
        foreach (['image_intro', 'image_fulltext'] as $field) {
            if (empty($images->$field)) {
                continue;
            }

            $currentImage = $images->$field;
            if (strpos($currentImage, $oldFolder) === 0) {
                $images->$field = str_replace($oldFolder, $newFolder, $currentImage);
                $changed = true;
                Log::add("Updated image path from {$currentImage} to {$images->$field}", Log::INFO, 'smartimagepath');
            }
        }
        return $changed;
    }

    /**
     * Move files from old folder to new folder and clean up empty folders
     *
     * @param  string  $oldFolderAbs  Absolute path to old folder
     * @param  string  $newFolderAbs  Absolute path to new folder
     * @param  string  $oldFolder     Relative path to old folder
     * @param  string  $newFolder     Relative path to new folder
     * @return void
     */
    private function moveFilesAndCleanFolders($oldFolderAbs, $newFolderAbs, $oldFolder, $newFolder)
    {
        // เพิ่มการบันทึกล็อก
        Log::add("Attempting to move files from {$oldFolder} to {$newFolder}", Log::INFO, 'smartimagepath');
        Log::add("Old folder absolute path: {$oldFolderAbs}", Log::DEBUG, 'smartimagepath');
        Log::add("New folder absolute path: {$newFolderAbs}", Log::DEBUG, 'smartimagepath');
        
        // ตรวจสอบว่าโฟลเดอร์ต้นทางมีอยู่จริง
        if (!Folder::exists($oldFolderAbs)) {
            Log::add("Source folder does not exist: {$oldFolderAbs}", Log::WARNING, 'smartimagepath');
            return;
        }
        
        // สร้างโฟลเดอร์ปลายทางถ้ายังไม่มี
        if (!Folder::exists($newFolderAbs)) {
            if (!Folder::create($newFolderAbs)) {
                Log::add("Failed to create destination folder: {$newFolderAbs}", Log::ERROR, 'smartimagepath');
                return;
            }
            Log::add("Created new folder: {$newFolder}", Log::INFO, 'smartimagepath');
        }
        
        // ดึงรายการไฟล์จากโฟลเดอร์ต้นทาง
        $files = Folder::files($oldFolderAbs);
        Log::add("Found " . count($files) . " files in source folder", Log::INFO, 'smartimagepath');
        
        // ตรวจสอบว่ามีสิทธิ์เขียนโฟลเดอร์ปลายทาง
        if (!is_writable($newFolderAbs)) {
            Log::add("Destination folder is not writable: {$newFolderAbs}", Log::ERROR, 'smartimagepath');
            return;
        }
        
        $movedFiles = 0;
        foreach ($files as $file) {
            $source = $oldFolderAbs . '/' . $file;
            $target = $newFolderAbs . '/' . $file;
            
            // ตรวจสอบว่าไฟล์ต้นทางมีอยู่จริง
            if (!File::exists($source)) {
                Log::add("Source file does not exist: {$source}", Log::WARNING, 'smartimagepath');
                continue;
            }
            
            // ตรวจสอบว่ามีสิทธิ์อ่านไฟล์ต้นทาง
            if (!is_readable($source)) {
                Log::add("Source file is not readable: {$source}", Log::WARNING, 'smartimagepath');
                continue;
            }
            
            // ย้ายไฟล์
            if (File::copy($source, $target)) {
                // ลบไฟล์ต้นทางหลังจากคัดลอกสำเร็จ
                if (File::delete($source)) {
                    $movedFiles++;
                    Log::add("Moved {$file} from {$oldFolder} to {$newFolder}", Log::INFO, 'smartimagepath');
                } else {
                    Log::add("Copied file but failed to delete source: {$source}", Log::WARNING, 'smartimagepath');
                }
            } else {
                Log::add("Failed to copy file: {$source} to {$target}", Log::ERROR, 'smartimagepath');
            }
        }
        
        Log::add("Successfully moved {$movedFiles} out of " . count($files) . " files", Log::INFO, 'smartimagepath');
        
        // ถ้าไม่มีไฟล์เหลืออยู่ในโฟลเดอร์ต้นทาง ให้ลบโฟลเดอร์
        if (Folder::exists($oldFolderAbs) && count(Folder::files($oldFolderAbs)) === 0) {
            if (Folder::delete($oldFolderAbs)) {
                Log::add("Removed empty folder: {$oldFolder}", Log::INFO, 'smartimagepath');
            } else {
                Log::add("Failed to remove empty folder: {$oldFolder}", Log::WARNING, 'smartimagepath');
            }
            
            // ตรวจสอบและลบโฟลเดอร์ year ถ้าว่างเปล่า...
            // (ส่วนที่เหลือของโค้ดเดิม)
        }
    }

    /**
     * Clean up empty folders after files are moved
     *
     * @param  string  $folderAbs  Absolute path to folder
     * @param  string  $folder     Relative path to folder
     * @return void
     */
    private function cleanEmptyFolders($folderAbs, $folder)
    {
        if (!Folder::exists($folderAbs) || count(Folder::files($folderAbs)) > 0) {
            return;
        }
        
        Folder::delete($folderAbs);
        Log::add("Removed empty folder: {$folder}", Log::INFO, 'smartimagepath');
        
        // Check and remove empty year folder
        $yearFolder = dirname($folderAbs);
        if (Folder::exists($yearFolder) && 
            count(Folder::folders($yearFolder)) === 0 && 
            count(Folder::files($yearFolder)) === 0) {
            
            Folder::delete($yearFolder);
            Log::add("Removed empty year folder: " . dirname($folder), Log::INFO, 'smartimagepath');
            
            // Check and remove empty category folder
            $catFolder = dirname($yearFolder);
            if (Folder::exists($catFolder) &&
                count(Folder::folders($catFolder)) === 0 && 
                count(Folder::files($catFolder)) === 0) {
                
                Folder::delete($catFolder);
                Log::add("Removed empty category folder: " . dirname(dirname($folder)), Log::INFO, 'smartimagepath');
            }
        }
    }

    /**
     * Move images from root to structured folder
     *
     * @param  object  $images       Images object
     * @param  string  $newFolderAbs Absolute path to new folder
     * @param  string  $newFolder    Relative path to new folder
     * @param  int     $articleId    Article ID
     * @return void
     */
    private function moveImagesToStructuredFolder($images, $newFolderAbs, $newFolder, $articleId)
    {
        if (!Folder::exists($newFolderAbs)) {
            Folder::create($newFolderAbs);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $changed = false;

        foreach (['image_intro', 'image_fulltext'] as $field) {
            if (empty($images->$field)) {
                continue;
            }

            $fullPathRaw = $images->$field;
            $imageParts = explode('#', $fullPathRaw);
            $currentPath = $imageParts[0];

            // Security check: Only process images in the root images folder
            if (strpos($currentPath, 'images/') !== 0 || substr_count($currentPath, '/') > 1) {
                Log::add("Skipped path {$currentPath} - outside image root", Log::INFO, 'smartimagepath');
                continue;
            }

            $fileName = basename($currentPath);
            $source = JPATH_ROOT . '/' . $currentPath;
            $target = $newFolderAbs . '/' . $fileName;

            if (File::exists($source)) {
                // Security check: Validate file is an actual image
                $mimeType = mime_content_type($source);
                if (!in_array($mimeType, $allowedTypes)) {
                    Factory::getApplication()->enqueueMessage("SmartImagePath: Blocked file {$fileName} (MIME: {$mimeType})", 'warning');
                    Log::add("Blocked file {$fileName} - MIME: {$mimeType}", Log::WARNING, 'smartimagepath');
                    continue;
                }

                if (File::move($source, $target)) {
                    $images->$field = $newFolder . '/' . $fileName;
                    $changed = true;
                    Log::add("Moved {$fileName} to {$newFolder}", Log::INFO, 'smartimagepath');
                } else {
                    Log::add("Failed to move {$fileName}", Log::ERROR, 'smartimagepath');
                }
            } else {
                Log::add("File not found: {$source}", Log::ERROR, 'smartimagepath');
            }
        }

        if ($changed) {
            $this->updateDatabase($articleId, $images);
        }
    }

    /**
     * Update database with new image paths
     *
     * @param  int     $articleId  Article ID
     * @param  object  $images     Images object
     * @return void
     */
    private function updateDatabase($articleId, $images)
    {
        $db = Factory::getDbo();
        $object = (object)[
            'id' => $articleId,
            'images' => json_encode($images)
        ];
        
        try {
            $db->updateObject('#__content', $object, ['id']);
            Log::add("Updated database for article ID {$articleId}", Log::INFO, 'smartimagepath');
        } catch (Exception $e) {
            Log::add("Failed to update database: " . $e->getMessage(), Log::ERROR, 'smartimagepath');
        }
    }

    /**
     * Process content when it's displayed
     *
     * @param string $context The context
     * @param object &$row The article object
     * @param mixed &$params The article params
     * @param integer $page The 'page' number
     * @return boolean
     */
    public function onContentPrepare($context, &$row, &$params, $page = 0)
    {
        // ตรวจสอบว่าเป็นบทความหรือไม่
        if ($context !== 'com_content.article' && $context !== 'com_content.category') {
            return true;
        }

        // ดึงเนื้อหา
        if (!isset($row->text) || empty($row->text)) {
            return true;
        }

        // แก้ไข path ของรูปภาพที่อยู่ในเนื้อหา
        // สามารถใช้ regex เพื่อค้นหา src="images/..." และอัพเดตเป็น path ใหม่
        // ...

        return true;
    }

    // เพิ่มฟังก์ชันนี้เพื่อตรวจสอบว่าหมวดหมู่ควรถูกประมวลผลหรือไม่
    private function shouldProcessCategory($categoryId)
    {
        $params = $this->params;
        $categoryMode = $params->get('category_mode', 'all');
        
        // ถ้าตั้งค่าให้ทำงานกับทุกหมวดหมู่
        if ($categoryMode === 'all') {
            return true;
        }
        
        // ดึงรายการหมวดหมู่ที่เลือกไว้
        $categories = $params->get('categories', []);
        
        // แปลงเป็น array ถ้าได้รับค่าเป็น string
        if (!is_array($categories) && !empty($categories)) {
            $categories = explode(',', $categories);
        }
        
        // ถ้าไม่ได้เลือกหมวดหมู่ใดไว้ ให้ใช้ค่าเริ่มต้น
        if (empty($categories)) {
            return $categoryMode === 'exclude'; // กรณี exclude แต่ไม่ได้เลือกหมวดหมู่ = ใช้ได้ทุกหมวดหมู่
        }
        
        // ตรวจสอบว่าหมวดหมู่อยู่ในรายการหรือไม่
        $inList = in_array($categoryId, $categories);
        
        // ถ้าเป็นโหมด include ต้องอยู่ในรายการ, ถ้าเป็นโหมด exclude ต้องไม่อยู่ในรายการ
        return ($categoryMode === 'include') ? $inList : !$inList;
    }
}
