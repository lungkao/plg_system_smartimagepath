<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;

class PlgSystemSmartimagepath extends CMSPlugin
{
    protected $app;

    public function onContentAfterSave($context, $data, $isNew, $article)
    {
        if ($context !== 'com_content.article') {
            return;
        }

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

        if (!Folder::exists($newFolderAbs)) {
            Folder::create($newFolderAbs);
        }

        $changed = false;

        foreach (['image_intro', 'image_fulltext'] as $field) {
            if (empty($images->$field)) {
                continue;
            }

            $fullPathRaw = $images->$field;
            $imageParts = explode('#', $fullPathRaw);
            $currentPath = $imageParts[0];

            if (strpos($currentPath, 'images/') !== 0 || substr_count($currentPath, '/') > 1) {
                continue;
            }

            $fileName = basename($currentPath);
            $source = JPATH_ROOT . '/' . $currentPath;
            $target = $newFolderAbs . '/' . $fileName;

            if (File::exists($source)) {
                if (File::move($source, $target)) {
                    $images->$field = $newFolder . '/' . $fileName;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $object = (object)[
                'id' => $articleId,
                'images' => json_encode($images)
            ];
            $db->updateObject('#__content', $object, ['id']);
        }
    }
}
