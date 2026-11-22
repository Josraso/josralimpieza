<?php
/**
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_3($module)
{
    return $module->getUpdater()->registerHooks();
}
