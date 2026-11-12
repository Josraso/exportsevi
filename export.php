<?php
/**
 * ExportSevi - Cron Export Script
 * URL: /modules/exportsevi/export.php?token=YOUR_TOKEN
 *
 * Called by cron jobs to run automatic exports. Requires the security token shown in the module configuration.
 *
 * @author    Josra
 * @copyright Josra
 * @license   Uso privado
 */
$config = dirname(__FILE__) . '/../../config/config.inc.php';
if (!file_exists($config)) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'PrestaShop configuration not found']));
}
require_once $config;

header('Content-Type: application/json; charset=utf-8');

$respond = function ($code, $status, $message) {
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($status !== 'success') {
        error_log('[ExportSevi] Cron ' . date('Y-m-d H:i:s') . ': ' . $message);
    }
    exit;
};

// Security check - only allow execution if module is installed and enabled
// (desactivado, por ejemplo por no tener licencia, no exporta)
if (!Module::isInstalled('exportsevi') || !Module::isEnabled('exportsevi')) {
    $respond(403, 'error', 'Module not installed or disabled');
}

// Security check - validate token
$provided_token = (string) Tools::getValue('token');
$valid_token = (string) Configuration::get('EXPORTSEVI_SECURITY_TOKEN');
if ($valid_token === '' || $provided_token === '' || !hash_equals($valid_token, $provided_token)) {
    $respond(403, 'error', 'Invalid or missing security token');
}

try {
    $module = Module::getInstanceByName('exportsevi');
    if (!$module || !$module->active) {
        throw new Exception('Module not found or not active');
    }
    $result = $module->executeExport('cron');
} catch (Throwable $e) {
    $respond(500, 'error', $e->getMessage());
}

$respond($result['success'] ? 200 : 500, $result['success'] ? 'success' : 'error', $result['message']);
