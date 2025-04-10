<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Log\Log;

class PlgSystemSmartimagepath extends CMSPlugin
{
    protected $app;
    protected $oldCatId = null;

    public function onContentBeforeSave($context, $data, $isNew)
    {
        if ($context !== 'com_content.article') {
            return true;
        }

        // เก็บ category ID เดิมไว้
        if (!$isNew) {
            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select('catid')
                ->from('#__content')
                ->where('id = ' . (int)$data->id);
            $this->oldCatId = (int)$db->setQuery($query)->loadResult();
        }

        return true;
    }

    public function onContentAfterSave($context, $data, $isNew, $article)
    {
        if ($context !== 'com_content.article') {
            return;
        }

        // ตรวจสอบว่ามีการเปลี่ยน category หรือไม่
        $categoryChanged = !$isNew && $this->oldCatId && $this->oldCatId !== (int)$data->catid;

        if (empty($data->images)) {
            return;
        }

        $images = json_decode($data->images);
        if (!$images) {
            return;
        }

        $catId = (int) ($data->catid ?? 0);
        $articleId = (int) ($data->id ?? 0);
        if (!$catId || !$articleId) {
            return;
        }

        $db = Factory::getDbo();
        $db->setQuery("SELECT alias FROM #__categories WHERE id = " . $db->quote($catId));
        $categoryAlias = $db->loadResult();
        if (!$categoryAlias) {
            return;
        }

        $year = Factory::getDate()->format('Y');
        $newFolder = 'images/articles/' . $categoryAlias . '/' . $year . '/' . $articleId;
        $newFolderAbs = JPATH_ROOT . '/' . $newFolder;

        if ($categoryChanged) {
            // สร้าง path สำหรับ category เก่า
            $db->setQuery("SELECT alias FROM #__categories WHERE id = " . $db->quote($this->oldCatId));
            $oldCategoryAlias = $db->loadResult();
            $oldFolder = 'images/articles/' . $oldCategoryAlias . '/' . $year . '/' . $articleId;
            $oldFolderAbs = JPATH_ROOT . '/' . $oldFolder;

            // ย้ายรูปจากโฟลเดอร์เก่าไปใหม่ถ้ามีอยู่
            if (Folder::exists($oldFolderAbs)) {
                if (!Folder::exists($newFolderAbs)) {
                    Folder::create($newFolderAbs);
                }
                
                $files = Folder::files($oldFolderAbs);
                foreach ($files as $file) {
                    $source = $oldFolderAbs . '/' . $file;
                    $target = $newFolderAbs . '/' . $file;
                    if (File::exists($source)) {
                        if (File::move($source, $target)) {
                            Log::add("Moved {$file} from {$oldFolder} to {$newFolder}", Log::INFO, 'smartimagepath');
                        }
                    }
                }
                
                // ลบโฟลเดอร์เก่าถ้าว่างเปล่า
                if (count(Folder::files($oldFolderAbs)) === 0) {
                    Folder::delete($oldFolderAbs);
                    Log::add("Removed empty folder: {$oldFolder}", Log::INFO, 'smartimagepath');
                }
            }
        }

        if (!Folder::exists($newFolderAbs)) {
            Folder::create($newFolderAbs);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $changed = false;

        Log::addLogger(
            ['text_file' => 'smartimagepath.log'],
            Log::ALL,
            ['smartimagepath']
        );

        foreach (['image_intro', 'image_fulltext'] as $field) {
            if (empty($images->$field)) {
                continue;
            }

            $fullPathRaw = $images->$field;
            $imageParts = explode('#', $fullPathRaw);
            $currentPath = $imageParts[0];

            if (strpos($currentPath, 'images/') !== 0 || substr_count($currentPath, '/') > 1) {
                Log::add("Skipped path $currentPath - outside image root", Log::INFO, 'smartimagepath');
                continue;
            }

            $fileName = basename($currentPath);
            $source = JPATH_ROOT . '/' . $currentPath;
            $target = $newFolderAbs . '/' . $fileName;

            if (File::exists($source)) {
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
            $object = (object)[
                'id' => $articleId,
                'images' => json_encode($images)
            ];
            $db->updateObject('#__content', $object, ['id']);
            Log::add("Updated database for article ID {$articleId}", Log::INFO, 'smartimagepath');
        }
    }
}
