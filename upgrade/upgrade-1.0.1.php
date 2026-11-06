<?php
/**
 * 1.0.1: se quita un hook que no se usaba.
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
    if ($module->isRegisteredInHook('actionAdminControllerSetMedia')) {
        $module->unregisterHook('actionAdminControllerSetMedia');
    }

    return true;
}
