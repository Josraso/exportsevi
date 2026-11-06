<?php
/**
 * Export Sevi - exportación por cron
 * URL: https://tienda.com/modules/exportsevi/export.php
 *
 * @author    Josra
 * @copyright Josra
 * @license   Uso privado
 */
$config = dirname(__FILE__) . '/../../config/config.inc.php';
if (!file_exists($config)) {
    http_response_code(500);
    die('No se encuentra config/config.inc.php de PrestaShop.');
}
require_once $config;

header('Content-Type: application/json; charset=utf-8');
$result = ['success' => false, 'message' => ''];
try {
    $module = Module::getInstanceByName('exportsevi');
    if (!$module || !Module::isInstalled('exportsevi') || !$module->active) {
        throw new Exception('el módulo exportsevi no está instalado o está desactivado.');
    }
    $result = $module->executeExport();
} catch (Throwable $e) {
    $result = ['success' => false, 'message' => $e->getMessage()];
}

http_response_code($result['success'] ? 200 : 500);
echo json_encode([
    'status' => $result['success'] ? 'success' : 'error',
    'message' => $result['message'],
    'timestamp' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (!$result['success']) {
    error_log('[exportsevi] Error en el cron: ' . $result['message']);
}
