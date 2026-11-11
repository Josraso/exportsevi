<?php
/**
 * 1.0.2: servidor de actualizaciones de josra.
 *
 * @author    Josra
 * @copyright Josra
 * @license   Uso privado
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_2($module)
{
    return $module->getUpdater()->registerHooks();
}
