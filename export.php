<?php
/**
 * ExportSevi - Cron Export Script
 * URL: /modules/exportsevi/export.php
 * 
 * This file is called by cron jobs to execute automatic exports
 */

// Include PrestaShop configuration
$depth = '../..';
if (file_exists($depth.'/config/config.inc.php')) {
    require_once($depth.'/config/config.inc.php');
} else {
    // Try different path depth
    $depth = '../../..';
    if (file_exists($depth.'/config/config.inc.php')) {
        require_once($depth.'/config/config.inc.php');
    } else {
        die('PrestaShop configuration not found');
    }
}

// Security check - only allow execution if module is installed
if (!Module::isInstalled('exportsevi')) {
    die('Module not installed');
}

try {
    // Load the module
    $module = Module::getInstanceByName('exportsevi');
    
    if (!$module || !$module->active) {
        throw new Exception('Module not found or not active');
    }
    
    // Execute export
    $result = $module->executeExport();
    
    // Return JSON response for logging
    header('Content-Type: application/json');
    
    if ($result['success']) {
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => $result['message'],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $result['message'],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

// Log the execution (optional)
error_log('[ExportSevi] Cron executed at ' . date('Y-m-d H:i:s') . ' - Result: ' . ($result['success'] ? 'Success' : 'Error'));
?>