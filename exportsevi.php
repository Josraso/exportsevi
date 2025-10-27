<?php
/**
 * ExportSevi Module
 * Export products with references and stock to CSV
 * 
 * @author Tu Nombre
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ExportSevi extends Module
{
    public function __construct()
    {
        $this->name = 'exportsevi';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Tu Nombre';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.6',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Export Sevi');
        $this->description = $this->l('Export product references and stock to CSV file with cron support');
    }

    public function install()
    {
        // Create export logs table
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'exportsevi_log` (
            `id_export` int(11) NOT NULL AUTO_INCREMENT,
            `export_date` datetime NOT NULL,
            `products_count` int(11) NOT NULL,
            `file_path` varchar(255) NOT NULL,
            `status` enum("success","error") NOT NULL,
            `message` text,
            `export_type` enum("manual","cron") NOT NULL DEFAULT "manual",
            PRIMARY KEY (`id_export`),
            KEY `export_date` (`export_date`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        return parent::install() &&
            Db::getInstance()->execute($sql) &&
            Configuration::updateValue('EXPORTSEVI_FOLDER', _PS_ROOT_DIR_ . '/exports/') &&
            Configuration::updateValue('EXPORTSEVI_FILENAME', 'productos_stock.csv') &&
            Configuration::updateValue('EXPORTSEVI_PRODUCT_STATUS', 'active') &&
            Configuration::updateValue('EXPORTSEVI_BATCH_SIZE', 500) &&
            Configuration::updateValue('EXPORTSEVI_SECURITY_TOKEN', Tools::passwdGen(32));
    }

    public function uninstall()
    {
        // Drop logs table
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'exportsevi_log`';

        return parent::uninstall() &&
            Db::getInstance()->execute($sql) &&
            Configuration::deleteByName('EXPORTSEVI_FOLDER') &&
            Configuration::deleteByName('EXPORTSEVI_FILENAME') &&
            Configuration::deleteByName('EXPORTSEVI_PRODUCT_STATUS') &&
            Configuration::deleteByName('EXPORTSEVI_BATCH_SIZE') &&
            Configuration::deleteByName('EXPORTSEVI_SECURITY_TOKEN');
    }

    public function getContent()
    {
        $output = null;

        // Handle file download
        if (Tools::isSubmit('download_export') && Tools::getValue('file')) {
            $this->downloadExportFile(Tools::getValue('file'));
            exit;
        }

        if (Tools::isSubmit('submit' . $this->name)) {
            $folder = strval(Tools::getValue('EXPORTSEVI_FOLDER'));
            $filename = strval(Tools::getValue('EXPORTSEVI_FILENAME'));
            $product_status = strval(Tools::getValue('EXPORTSEVI_PRODUCT_STATUS'));
            $batch_size = (int)Tools::getValue('EXPORTSEVI_BATCH_SIZE');

            if (!$folder || empty($folder) || !$filename || empty($filename)) {
                $output .= $this->displayError($this->l('Invalid Configuration value'));
            } elseif ($batch_size < 100 || $batch_size > 5000) {
                $output .= $this->displayError($this->l('Batch size must be between 100 and 5000'));
            } else {
                Configuration::updateValue('EXPORTSEVI_FOLDER', $folder);
                Configuration::updateValue('EXPORTSEVI_FILENAME', $filename);
                Configuration::updateValue('EXPORTSEVI_PRODUCT_STATUS', $product_status);
                Configuration::updateValue('EXPORTSEVI_BATCH_SIZE', $batch_size);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        // Manual export button
        if (Tools::isSubmit('export_now')) {
            $result = $this->executeExport('manual');
            if ($result['success']) {
                $output .= $this->displayConfirmation($this->l('Export completed: ') . $result['message']);
            } else {
                $output .= $this->displayError($this->l('Export failed: ') . $result['message']);
            }
        }

        // Regenerate security token
        if (Tools::isSubmit('regenerate_token')) {
            Configuration::updateValue('EXPORTSEVI_SECURITY_TOKEN', Tools::passwdGen(32));
            $output .= $this->displayConfirmation($this->l('Security token regenerated successfully'));
        }

        return $output . $this->displayForm();
    }

    private function downloadExportFile($filename)
    {
        $folder = Configuration::get('EXPORTSEVI_FOLDER');
        $filepath = rtrim($folder, '/') . '/' . basename($filename); // basename prevents directory traversal

        if (!file_exists($filepath)) {
            die('File not found');
        }

        // Security check - ensure file is within exports folder
        $real_folder = realpath($folder);
        $real_file = realpath($filepath);

        if (strpos($real_file, $real_folder) !== 0) {
            die('Access denied');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');

        readfile($filepath);
    }

    public function displayForm()
    {
        // Get form values
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Destination Folder'),
                    'name' => 'EXPORTSEVI_FOLDER',
                    'required' => true,
                    'desc' => $this->l('Full server path (e.g., /var/www/exports/ or /home/user/exports/)'),
                    'class' => 'fixed-width-xxl'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('File Name'),
                    'name' => 'EXPORTSEVI_FILENAME',
                    'required' => true,
                    'desc' => $this->l('Include .csv extension (e.g., productos.csv)')
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Product Status'),
                    'name' => 'EXPORTSEVI_PRODUCT_STATUS',
                    'options' => [
                        'query' => [
                            ['id' => 'all', 'name' => $this->l('All Products')],
                            ['id' => 'active', 'name' => $this->l('Only Active Products')],
                            ['id' => 'inactive', 'name' => $this->l('Only Inactive Products')]
                        ],
                        'id' => 'id',
                        'name' => 'name'
                    ]
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Batch Size'),
                    'name' => 'EXPORTSEVI_BATCH_SIZE',
                    'required' => true,
                    'desc' => $this->l('Number of products to process per batch (100-5000). Lower values use less memory.')
                ]
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;

        $helper->fields_value['EXPORTSEVI_FOLDER'] = Configuration::get('EXPORTSEVI_FOLDER');
        $helper->fields_value['EXPORTSEVI_FILENAME'] = Configuration::get('EXPORTSEVI_FILENAME');
        $helper->fields_value['EXPORTSEVI_PRODUCT_STATUS'] = Configuration::get('EXPORTSEVI_PRODUCT_STATUS') ?: 'active';
        $helper->fields_value['EXPORTSEVI_BATCH_SIZE'] = Configuration::get('EXPORTSEVI_BATCH_SIZE') ?: 500;

        $form = $helper->generateForm($fields_form);

        // Add folder browser and manual export section
        $security_token = Configuration::get('EXPORTSEVI_SECURITY_TOKEN');
        $cron_url = _PS_BASE_URL_ . __PS_BASE_URI__ . 'modules/' . $this->name . '/export.php?token=' . $security_token;
        $current_file = Configuration::get('EXPORTSEVI_FILENAME');

        $form .= '<div class="panel">';
        $form .= '<div class="panel-heading"><i class="icon-folder"></i> ' . $this->l('Folder Browser') . '</div>';
        $form .= '<div class="form-wrapper">';
        $form .= '<div id="folder-browser" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f8f8f8;">';
        $form .= $this->getFolderBrowser();
        $form .= '</div>';
        $form .= '<p class="help-block">' . $this->l('Click on [+] to expand folders, click on folder names to select') . '</p>';
        $form .= '</div></div>';

        $form .= '<div class="panel">';
        $form .= '<div class="panel-heading"><i class="icon-cogs"></i> ' . $this->l('Export Actions') . '</div>';
        $form .= '<div class="form-wrapper">';
        $form .= '<form method="post" action="' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '">';
        $form .= '<div class="form-group">';
        $form .= '<button type="submit" name="export_now" class="btn btn-primary btn-lg">';
        $form .= '<i class="icon-download"></i> ' . $this->l('Export Now') . '</button> ';

        // Download button if file exists
        $folder = Configuration::get('EXPORTSEVI_FOLDER');
        $filepath = rtrim($folder, '/') . '/' . $current_file;
        if (file_exists($filepath)) {
            $form .= '<a href="' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&download_export=1&file=' . urlencode($current_file) . '" class="btn btn-success btn-lg">';
            $form .= '<i class="icon-cloud-download"></i> ' . $this->l('Download Last Export') . '</a>';
        }

        $form .= '</div>';
        $form .= '</form>';

        $form .= '<hr>';

        $form .= '<div class="form-group">';
        $form .= '<label>' . $this->l('Security Token:') . '</label>';
        $form .= '<div class="input-group">';
        $form .= '<input type="text" class="form-control" value="' . htmlspecialchars($security_token) . '" readonly onclick="this.select()">';
        $form .= '<span class="input-group-btn">';
        $form .= '<form method="post" action="' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '" style="display:inline;">';
        $form .= '<button type="submit" name="regenerate_token" class="btn btn-warning" onclick="return confirm(\'' . $this->l('This will invalidate the current cron URL. Continue?') . '\')">';
        $form .= '<i class="icon-refresh"></i> ' . $this->l('Regenerate') . '</button>';
        $form .= '</form>';
        $form .= '</span>';
        $form .= '</div>';
        $form .= '<p class="help-block">' . $this->l('Keep this token secret. It\'s required for cron access.') . '</p>';
        $form .= '</div>';

        $form .= '<div class="form-group">';
        $form .= '<label>' . $this->l('Cron URL:') . '</label>';
        $form .= '<input type="text" class="form-control" value="' . htmlspecialchars($cron_url) . '" readonly onclick="this.select()">';
        $form .= '<p class="help-block">' . $this->l('Use this URL in your cron job for automatic exports. Example:') . '<br><code>0 2 * * * curl -s "' . htmlspecialchars($cron_url) . '"</code></p>';
        $form .= '</div>';
        $form .= '</div></div>';

        // Export history
        $form .= $this->displayExportHistory();

        // Add JavaScript for collapsible folder browser
        $form .= '<script>
        function selectFolder(path) {
            document.getElementsByName("EXPORTSEVI_FOLDER")[0].value = path;
            // Highlight selected folder
            var folders = document.querySelectorAll(".folder-name");
            folders.forEach(function(f) { f.style.backgroundColor = ""; f.style.fontWeight = ""; });
            event.target.style.backgroundColor = "#e3f2fd";
            event.target.style.fontWeight = "bold";
        }

        function toggleFolder(element) {
            var content = element.nextElementSibling;
            var icon = element.querySelector(".folder-icon");
            
            if (content.style.display === "none") {
                content.style.display = "block";
                icon.className = "folder-icon icon-folder-open";
                element.innerHTML = element.innerHTML.replace("[+]", "[-]");
            } else {
                content.style.display = "none";
                icon.className = "folder-icon icon-folder";
                element.innerHTML = element.innerHTML.replace("[-]", "[+]");
            }
        }

        // Initialize - close all folders except root
        document.addEventListener("DOMContentLoaded", function() {
            var folders = document.querySelectorAll(".folder-content");
            folders.forEach(function(folder, index) {
                if (index > 0) { // Keep root open
                    folder.style.display = "none";
                }
            });
        });
        </script>';

        return $form;
    }

    private function displayExportHistory()
    {
        $html = '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-list"></i> ' . $this->l('Export History') . '</div>';
        $html .= '<div class="form-wrapper">';

        // Get last 10 exports
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'exportsevi_log` ORDER BY export_date DESC LIMIT 10';
        $logs = Db::getInstance()->executeS($sql);

        if ($logs && count($logs) > 0) {
            $html .= '<table class="table">';
            $html .= '<thead><tr>';
            $html .= '<th>' . $this->l('Date') . '</th>';
            $html .= '<th>' . $this->l('Products') . '</th>';
            $html .= '<th>' . $this->l('Type') . '</th>';
            $html .= '<th>' . $this->l('Status') . '</th>';
            $html .= '<th>' . $this->l('Message') . '</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($logs as $log) {
                $status_class = $log['status'] === 'success' ? 'success' : 'danger';
                $status_icon = $log['status'] === 'success' ? 'check' : 'times';
                $type_badge = $log['export_type'] === 'manual' ? 'info' : 'default';

                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($log['export_date']) . '</td>';
                $html .= '<td>' . (int)$log['products_count'] . '</td>';
                $html .= '<td><span class="badge badge-' . $type_badge . '">' . htmlspecialchars($log['export_type']) . '</span></td>';
                $html .= '<td><span class="label label-' . $status_class . '"><i class="icon-' . $status_icon . '"></i> ' . htmlspecialchars($log['status']) . '</span></td>';
                $html .= '<td>' . htmlspecialchars($log['message']) . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        } else {
            $html .= '<p class="alert alert-info">' . $this->l('No exports yet.') . '</p>';
        }

        $html .= '</div></div>';

        return $html;
    }

    private function getFolderBrowser()
    {
        $html = '<div style="font-family: monospace;">';
        $html .= '<div style="margin-bottom: 15px; padding: 10px; background: #e8f4f8; border-left: 4px solid #2196F3;">';
        $html .= '<strong><i class="icon-info"></i> PrestaShop Directory Structure</strong><br>';
        $html .= '<small>Click on [+] to expand folders, click on folder names to select as destination</small>';
        $html .= '</div>';

        $base_path = _PS_ROOT_DIR_;
        $html .= $this->listPSDirectories($base_path, 0);
        $html .= '</div>';

        return $html;
    }

    private function listPSDirectories($path, $level = 0, $max_level = 3)
    {
        $html = '';
        if ($level > $max_level) return $html;

        // Security: Validate path is within PS_ROOT_DIR
        $real_root = realpath(_PS_ROOT_DIR_);
        $real_path = realpath($path);

        if (!$real_path || strpos($real_path, $real_root) !== 0) {
            return $html; // Invalid path, return empty
        }

        $path = rtrim($path, '/') . '/';
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
        
        // Current directory
        $writable = is_writable($path) ? '✓' : '✗';
        $style = is_writable($path) ? 'color: #4CAF50;' : 'color: #f44336;';
        $rel_path = str_replace(_PS_ROOT_DIR_, '', $path);
        $display_path = $rel_path ?: '/';
        $folder_name = $level === 0 ? 'PrestaShop Root' : basename(rtrim($path, '/'));

        // Check if has subdirectories
        $has_subdirs = false;
        if (is_readable($path)) {
            if ($handle = opendir($path)) {
                while (($file = readdir($handle)) !== false) {
                    if ($file != '.' && $file != '..' && is_dir($path . $file) && 
                        !in_array($file, ['.git', '.svn', 'node_modules', '.idea', '__pycache__'])) {
                        $has_subdirs = true;
                        break;
                    }
                }
                closedir($handle);
            }
        }

        $html .= '<div class="folder-item">';
        
        // Folder toggle and name
        if ($has_subdirs) {
            $html .= '<span style="cursor: pointer; padding: 2px 5px; border-radius: 3px; background: #ddd; margin-right: 5px;" onclick="toggleFolder(this)">';
            $html .= '<i class="folder-icon icon-folder"></i> [+]</span>';
        } else {
            $html .= '<span style="margin-right: 25px;"></span>';
        }
        
        $html .= $indent . '<span class="folder-name" style="cursor: pointer; padding: 2px 8px; ' . $style . ' border-radius: 3px;" 
                  onclick="selectFolder(\'' . addslashes($path) . '\')" 
                  onmouseover="this.style.backgroundColor=\'#f0f8ff\'" 
                  onmouseout="if(this.style.fontWeight!=\'bold\') this.style.backgroundColor=\'\'">';
        
        $html .= '<i class="icon-folder"></i> ' . $folder_name . ' ' . $writable;
        $html .= '</span><br>';

        // Subdirectories content (initially hidden)
        if ($has_subdirs) {
            $html .= '<div class="folder-content">';
            
            $dirs = [];
            if ($handle = opendir($path)) {
                while (($file = readdir($handle)) !== false) {
                    if ($file != '.' && $file != '..' && is_dir($path . $file) && 
                        !in_array($file, ['.git', '.svn', 'node_modules', '.idea', '__pycache__'])) {
                        $dirs[] = $file;
                    }
                }
                closedir($handle);
                sort($dirs);
                
                // Show common/useful directories first
                $priority_dirs = ['modules', 'upload', 'download', 'img', 'var', 'cache', 'log', 'exports', 'files'];
                $sorted_dirs = [];
                
                foreach ($priority_dirs as $priority) {
                    if (in_array($priority, $dirs)) {
                        $sorted_dirs[] = $priority;
                        $dirs = array_diff($dirs, [$priority]);
                    }
                }
                $dirs = array_merge($sorted_dirs, $dirs);
                
                foreach ($dirs as $dir) {
                    $html .= $this->listPSDirectories($path . $dir, $level + 1, $max_level);
                }
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>';

        return $html;
    }

    public function executeExport($export_type = 'manual')
    {
        $start_time = microtime(true);

        try {
            $folder = Configuration::get('EXPORTSEVI_FOLDER');
            $filename = Configuration::get('EXPORTSEVI_FILENAME');

            // Create folder if it doesn't exist
            if (!is_dir($folder)) {
                if (!mkdir($folder, 0755, true)) {
                    $this->logExport(0, $folder . '/' . $filename, 'error', 'Cannot create folder: ' . $folder, $export_type);
                    return ['success' => false, 'message' => 'Cannot create folder: ' . $folder];
                }
            }

            // Create .htaccess to protect exports folder
            $htaccess_path = rtrim($folder, '/') . '/.htaccess';
            if (!file_exists($htaccess_path)) {
                $htaccess_content = "# Deny web access to export files\n";
                $htaccess_content .= "Order deny,allow\n";
                $htaccess_content .= "Deny from all\n";
                file_put_contents($htaccess_path, $htaccess_content);
            }

            // Create index.php to prevent directory listing
            $index_path = rtrim($folder, '/') . '/index.php';
            if (!file_exists($index_path)) {
                file_put_contents($index_path, "<?php\nheader('HTTP/1.0 403 Forbidden');\nexit('Access forbidden');\n");
            }

            $filepath = rtrim($folder, '/') . '/' . $filename;

            // Open file for writing
            $file = fopen($filepath, 'w');
            if (!$file) {
                $this->logExport(0, $filepath, 'error', 'Cannot create file: ' . $filepath, $export_type);
                return ['success' => false, 'message' => 'Cannot create file: ' . $filepath];
            }

            // Add BOM for Excel compatibility
            fwrite($file, "\xEF\xBB\xBF");

            // CSV Headers
            fputcsv($file, [
                'Referencias Completas',
                'Referencias Filtradas',
                'Nombre',
                'Stock'
            ], ';');

            // Get products data with batch processing
            $total_count = 0;
            $batch_size = (int)Configuration::get('EXPORTSEVI_BATCH_SIZE') ?: 500;
            $offset = 0;

            do {
                $products = $this->getProductsData($offset, $batch_size);

                foreach ($products as $product) {
                    fputcsv($file, [
                        $product['ref_completa'],
                        $product['ref_filtrada'],
                        $product['nombre'],
                        $product['stock']
                    ], ';');
                    $total_count++;
                }

                $offset += $batch_size;

                // Free memory
                unset($products);

            } while (count($products ?? []) === $batch_size);

            fclose($file);

            $execution_time = round(microtime(true) - $start_time, 2);
            $message = $total_count . ' products exported in ' . $execution_time . 's';

            // Log success
            $this->logExport($total_count, $filepath, 'success', $message, $export_type);

            return [
                'success' => true,
                'message' => $message
            ];

        } catch (Exception $e) {
            $this->logExport(0, $folder . '/' . $filename, 'error', $e->getMessage(), $export_type);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function logExport($products_count, $file_path, $status, $message, $export_type = 'manual')
    {
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'exportsevi_log`
                (export_date, products_count, file_path, status, message, export_type)
                VALUES (NOW(), ' . (int)$products_count . ', "' . pSQL($file_path) . '",
                "' . pSQL($status) . '", "' . pSQL($message) . '", "' . pSQL($export_type) . '")';

        Db::getInstance()->execute($sql);
    }

    private function getProductsData($offset = 0, $limit = 500)
    {
        $results = [];
        $context = Context::getContext();
        $id_lang = $context->language->id;
        $product_status = Configuration::get('EXPORTSEVI_PRODUCT_STATUS') ?: 'active';

        // Build WHERE clause for product status
        $status_where = '';
        switch ($product_status) {
            case 'active':
                $status_where = 'AND p.active = 1';
                break;
            case 'inactive':
                $status_where = 'AND p.active = 0';
                break;
            case 'all':
            default:
                $status_where = ''; // No filter
                break;
        }

        // Increase GROUP_CONCAT limit to avoid truncation
        Db::getInstance()->execute('SET SESSION group_concat_max_len = 10000');

        // Optimized query with UNION: Simple products + Combinations in one query
        $sql = '
        (
            SELECT
                p.reference as ref_completa,
                p.reference as ref_filtrada,
                pl.name as nombre,
                IFNULL(sa.quantity, 0) as stock,
                p.id_product,
                0 as id_product_attribute
            FROM ' . _DB_PREFIX_ . 'product p
            LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = ' . (int)$id_lang . ')
            LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0)
            WHERE 1=1 ' . $status_where . '
            AND NOT EXISTS (
                SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute pa
                WHERE pa.id_product = p.id_product
            )
        )
        UNION ALL
        (
            SELECT
                p.reference as ref_completa,
                pa.reference as ref_filtrada,
                CONCAT(pl.name, " - ",
                    GROUP_CONCAT(
                        CONCAT(agl.name, ": ", al.name)
                        ORDER BY a.id_attribute_group, a.position
                        SEPARATOR " - "
                    )
                ) as nombre,
                IFNULL(sa.quantity, 0) as stock,
                p.id_product,
                pa.id_product_attribute
            FROM ' . _DB_PREFIX_ . 'product p
            LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = ' . (int)$id_lang . ')
            INNER JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON p.id_product = pa.id_product
            LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (pa.id_product = sa.id_product AND pa.id_product_attribute = sa.id_product_attribute)
            LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac ON pa.id_product_attribute = pac.id_product_attribute
            LEFT JOIN ' . _DB_PREFIX_ . 'attribute a ON pac.id_attribute = a.id_attribute
            LEFT JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON (a.id_attribute = al.id_attribute AND al.id_lang = ' . (int)$id_lang . ')
            LEFT JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl ON (a.id_attribute_group = agl.id_attribute_group AND agl.id_lang = ' . (int)$id_lang . ')
            WHERE 1=1 ' . $status_where . '
            GROUP BY pa.id_product_attribute
        )
        ORDER BY id_product, id_product_attribute
        LIMIT ' . (int)$offset . ', ' . (int)$limit;

        $data = Db::getInstance()->executeS($sql);

        if ($data) {
            foreach ($data as $row) {
                $results[] = [
                    'ref_completa' => $row['ref_completa'] ?: '',
                    'ref_filtrada' => $row['ref_filtrada'] ?: '',
                    'nombre' => $row['nombre'] ?: '',
                    'stock' => (int)$row['stock']
                ];
            }
        }

        return $results;
    }
}