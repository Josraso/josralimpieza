<?php
/**
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_5($module)
{
    return $module->getUpdater()->registerHooks();
}
