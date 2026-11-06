<?php
/**
 * 1.0.1: creates the history table and the missing settings (security token included) when the module was
 * updated by uploading files over an older version.
 *
 * @author    Josra
 * @copyright Josra
 * @license   Uso privado
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    return $module->installTable() && $module->setDefaults();
}
