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
            Configuration::updateValue('EXPORTSEVI_SECURITY_TOKEN', Tools::passwdGen(32)) &&
            Configuration::updateValue('EXPORTSEVI_EMAIL_NOTIFY', 0) &&
            Configuration::updateValue('EXPORTSEVI_EMAIL_ADDRESS', Configuration::get('PS_SHOP_EMAIL')) &&
            Configuration::updateValue('EXPORTSEVI_CSV_DELIMITER', ';') &&
            Configuration::updateValue('EXPORTSEVI_CSV_ENCODING', 'UTF-8') &&
            Configuration::updateValue('EXPORTSEVI_FILTER_CATEGORY', '') &&
            Configuration::updateValue('EXPORTSEVI_FILTER_MANUFACTURER', '') &&
            Configuration::updateValue('EXPORTSEVI_FILTER_PRICE_MIN', '') &&
            Configuration::updateValue('EXPORTSEVI_FILTER_PRICE_MAX', '') &&
            Configuration::updateValue('EXPORTSEVI_LOG_AUTO_DELETE', 30); // 30 days
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
            Configuration::deleteByName('EXPORTSEVI_SECURITY_TOKEN') &&
            Configuration::deleteByName('EXPORTSEVI_EMAIL_NOTIFY') &&
            Configuration::deleteByName('EXPORTSEVI_EMAIL_ADDRESS') &&
            Configuration::deleteByName('EXPORTSEVI_CSV_DELIMITER') &&
            Configuration::deleteByName('EXPORTSEVI_CSV_ENCODING') &&
            Configuration::deleteByName('EXPORTSEVI_FILTER_CATEGORY') &&
            Configuration::deleteByName('EXPORTSEVI_FILTER_MANUFACTURER') &&
            Configuration::deleteByName('EXPORTSEVI_FILTER_PRICE_MIN') &&
            Configuration::deleteByName('EXPORTSEVI_FILTER_PRICE_MAX') &&
            Configuration::deleteByName('EXPORTSEVI_LOG_AUTO_DELETE');
    }

    public function getContent()
    {
        $output = null;

        // Handle file download
        if (Tools::isSubmit('download_export') && Tools::getValue('file')) {
            $this->downloadExportFile(Tools::getValue('file'));
            exit;
        }

        // Handle preview request (AJAX)
        if (Tools::isSubmit('preview_export')) {
            $this->previewExport();
            exit;
        }

        // Handle delete logs
        if (Tools::isSubmit('delete_logs')) {
            $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'exportsevi_log`';
            if (Db::getInstance()->execute($sql)) {
                $output .= $this->displayConfirmation($this->l('All logs deleted successfully'));
            } else {
                $output .= $this->displayError($this->l('Error deleting logs'));
            }
        }

        if (Tools::isSubmit('submit' . $this->name)) {
            $folder = strval(Tools::getValue('EXPORTSEVI_FOLDER'));
            $filename = strval(Tools::getValue('EXPORTSEVI_FILENAME'));
            $product_status = strval(Tools::getValue('EXPORTSEVI_PRODUCT_STATUS'));
            $batch_size = (int)Tools::getValue('EXPORTSEVI_BATCH_SIZE');
            $email_notify = (int)Tools::getValue('EXPORTSEVI_EMAIL_NOTIFY');
            $email_address = strval(Tools::getValue('EXPORTSEVI_EMAIL_ADDRESS'));
            $csv_delimiter = strval(Tools::getValue('EXPORTSEVI_CSV_DELIMITER'));
            $csv_encoding = strval(Tools::getValue('EXPORTSEVI_CSV_ENCODING'));

            // Get multiple categories and manufacturers as arrays
            $filter_categories = Tools::getValue('EXPORTSEVI_FILTER_CATEGORY');
            $filter_manufacturers = Tools::getValue('EXPORTSEVI_FILTER_MANUFACTURER');

            // Convert to comma-separated string for storage
            $filter_category = is_array($filter_categories) ? implode(',', array_filter($filter_categories)) : '';
            $filter_manufacturer = is_array($filter_manufacturers) ? implode(',', array_filter($filter_manufacturers)) : '';

            $filter_price_min = strval(Tools::getValue('EXPORTSEVI_FILTER_PRICE_MIN'));
            $filter_price_max = strval(Tools::getValue('EXPORTSEVI_FILTER_PRICE_MAX'));
            $log_auto_delete = (int)Tools::getValue('EXPORTSEVI_LOG_AUTO_DELETE');

            if (!$folder || empty($folder) || !$filename || empty($filename)) {
                $output .= $this->displayError($this->l('Invalid Configuration value'));
            } elseif ($batch_size < 100 || $batch_size > 5000) {
                $output .= $this->displayError($this->l('Batch size must be between 100 and 5000'));
            } elseif ($email_notify && (!$email_address || !Validate::isEmail($email_address))) {
                $output .= $this->displayError($this->l('Invalid email address'));
            } else {
                Configuration::updateValue('EXPORTSEVI_FOLDER', $folder);
                Configuration::updateValue('EXPORTSEVI_FILENAME', $filename);
                Configuration::updateValue('EXPORTSEVI_PRODUCT_STATUS', $product_status);
                Configuration::updateValue('EXPORTSEVI_BATCH_SIZE', $batch_size);
                Configuration::updateValue('EXPORTSEVI_EMAIL_NOTIFY', $email_notify);
                Configuration::updateValue('EXPORTSEVI_EMAIL_ADDRESS', $email_address);
                Configuration::updateValue('EXPORTSEVI_CSV_DELIMITER', $csv_delimiter);
                Configuration::updateValue('EXPORTSEVI_CSV_ENCODING', $csv_encoding);
                Configuration::updateValue('EXPORTSEVI_FILTER_CATEGORY', $filter_category);
                Configuration::updateValue('EXPORTSEVI_FILTER_MANUFACTURER', $filter_manufacturer);
                Configuration::updateValue('EXPORTSEVI_FILTER_PRICE_MIN', $filter_price_min);
                Configuration::updateValue('EXPORTSEVI_FILTER_PRICE_MAX', $filter_price_max);
                Configuration::updateValue('EXPORTSEVI_LOG_AUTO_DELETE', $log_auto_delete);
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

    private function previewExport()
    {
        header('Content-Type: application/json');

        try {
            // Get first 10 products for preview
            $products = $this->getProductsData(0, 10);

            if (empty($products)) {
                echo json_encode([
                    'success' => false,
                    'message' => $this->l('No products found with current filters')
                ]);
                return;
            }

            // Get total count (estimate)
            $all_products = $this->getProductsData(0, 1);
            $total_estimate = count($this->getProductsData(0, 100)); // Quick estimate

            $headers = [
                $this->l('Referencias Completas'),
                $this->l('Referencias Filtradas'),
                $this->l('Nombre'),
                $this->l('Stock')
            ];

            $rows = [];
            foreach ($products as $product) {
                $rows[] = [
                    htmlspecialchars($product['ref_completa']),
                    htmlspecialchars($product['ref_filtrada']),
                    htmlspecialchars($product['nombre']),
                    $product['stock']
                ];
            }

            echo json_encode([
                'success' => true,
                'headers' => $headers,
                'rows' => $rows,
                'total' => $total_estimate . '+'
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
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

    private function sendNotificationEmail($success, $products_count, $filepath, $execution_time, $message = '')
    {
        if (!(int)Configuration::get('EXPORTSEVI_EMAIL_NOTIFY')) {
            return; // Email notifications disabled
        }

        $email_address = Configuration::get('EXPORTSEVI_EMAIL_ADDRESS');
        if (!$email_address || !Validate::isEmail($email_address)) {
            return; // Invalid email
        }

        $subject = '[ExportSevi] ' . ($success ? 'Export Completed' : 'Export Failed');

        $message_body = '
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family: Arial, sans-serif; padding: 20px;">
            <h2 style="color: ' . ($success ? '#4CAF50' : '#f44336') . ';">Export ' . ($success ? 'Completed' : 'Failed') . '</h2>
            <table style="border-collapse: collapse; width: 100%; max-width: 600px;">
                <tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Shop:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . Configuration::get('PS_SHOP_NAME') . '</td></tr>
                <tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Date:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . date('Y-m-d H:i:s') . '</td></tr>
                <tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Status:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd; color: ' . ($success ? '#4CAF50' : '#f44336') . ';"><strong>' . ($success ? 'SUCCESS' : 'FAILED') . '</strong></td></tr>
                <tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Products:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . $products_count . '</td></tr>
                <tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>File:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($filepath) . '</td></tr>
                <tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Time:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . $execution_time . ' seconds</td></tr>
                ' . ($message ? '<tr><td style="padding: 8px;"><strong>Message:</strong></td><td style="padding: 8px;">' . htmlspecialchars($message) . '</td></tr>' : '') . '
            </table>
        </body>
        </html>';

        // Use PHP mail() directly for better reliability
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= 'From: ' . Configuration::get('PS_SHOP_NAME') . ' <' . Configuration::get('PS_SHOP_EMAIL') . '>' . "\r\n";

        @mail($email_address, $subject, $message_body, $headers);
    }

    private function getCategoriesTree($id_lang, $id_parent = 2, $level = 0, $max_level = 10)
    {
        $categories = [];

        if ($level > $max_level) {
            return $categories;
        }

        // Get categories for this parent
        $sql = 'SELECT c.id_category, cl.name
                FROM ' . _DB_PREFIX_ . 'category c
                LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON (c.id_category = cl.id_category AND cl.id_lang = ' . (int)$id_lang . ')
                WHERE c.id_parent = ' . (int)$id_parent . ' AND c.active = 1
                ORDER BY cl.name ASC';

        $results = Db::getInstance()->executeS($sql);

        if ($results) {
            foreach ($results as $cat) {
                // Add indentation for hierarchy visualization
                $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
                $prefix = $level > 0 ? $indent . '└─ ' : '';

                $categories[] = [
                    'id' => $cat['id_category'],
                    'name' => $prefix . $cat['name']
                ];

                // Recursively get children
                $children = $this->getCategoriesTree($id_lang, $cat['id_category'], $level + 1, $max_level);
                $categories = array_merge($categories, $children);
            }
        }

        return $categories;
    }

    public function displayForm()
    {
        // Get form values
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Get categories tree for selects
        $categories_list = [['id' => '', 'name' => $this->l('-- All Categories --')]];
        $categories_list = array_merge($categories_list, $this->getCategoriesTree($default_lang));

        // Get manufacturers using direct SQL query
        $manufacturers_list = [['id_manufacturer' => '', 'name' => $this->l('-- All Manufacturers --')]];

        $sql_manufacturers = 'SELECT m.id_manufacturer, m.name
                             FROM ' . _DB_PREFIX_ . 'manufacturer m
                             WHERE m.active = 1
                             ORDER BY m.name ASC';
        $manufacturers_data = Db::getInstance()->executeS($sql_manufacturers);

        if ($manufacturers_data) {
            foreach ($manufacturers_data as $man) {
                $manufacturers_list[] = [
                    'id_manufacturer' => $man['id_manufacturer'],
                    'name' => $man['name']
                ];
            }
        }

        // Calculate CSV URL
        $folder = Configuration::get('EXPORTSEVI_FOLDER');
        $filename = Configuration::get('EXPORTSEVI_FILENAME');
        $csv_url = '';

        if ($folder && $filename) {
            $relative_path = str_replace(_PS_ROOT_DIR_, '', $folder);
            $csv_url = _PS_BASE_URL_ . __PS_BASE_URI__ . ltrim($relative_path, '/') . '/' . $filename;
        }

        // Single unified form with all configuration options
        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('Export Configuration'),
                'icon' => 'icon-cogs'
            ],
            'input' => [
                // === BASIC SETTINGS ===
                [
                    'type' => 'html',
                    'name' => '',
                    'html_content' => '<h4 style="border-bottom: 2px solid #00aff0; padding-bottom: 5px; margin-top: 0;"><i class="icon-wrench"></i> ' . $this->l('Basic Settings') . '</h4>'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Destination Folder'),
                    'name' => 'EXPORTSEVI_FOLDER',
                    'required' => true,
                    'desc' => $this->l('Full server path (e.g., /var/www/exports/)') .
                              ($csv_url ? '<br><strong>' . $this->l('CSV URL:') . '</strong> <a href="' . htmlspecialchars($csv_url) . '" target="_blank">' . htmlspecialchars($csv_url) . '</a>' : ''),
                    'class' => 'folder-input-wide'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('File Name'),
                    'name' => 'EXPORTSEVI_FILENAME',
                    'required' => true,
                    'desc' => $this->l('Include .csv extension (e.g., productos.csv)'),
                    'class' => 'fixed-width-lg'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Batch Size'),
                    'name' => 'EXPORTSEVI_BATCH_SIZE',
                    'required' => true,
                    'desc' => $this->l('Products per batch (100-5000). Lower = less memory.'),
                    'class' => 'fixed-width-sm',
                    'suffix' => 'products'
                ],

                // === CSV FORMAT ===
                [
                    'type' => 'html',
                    'name' => '',
                    'html_content' => '<h4 style="border-bottom: 2px solid #00aff0; padding-bottom: 5px; margin-top: 20px;"><i class="icon-file-text"></i> ' . $this->l('CSV Format Options') . '</h4>'
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('CSV Delimiter'),
                    'name' => 'EXPORTSEVI_CSV_DELIMITER',
                    'options' => [
                        'query' => [
                            ['id' => ';', 'name' => $this->l('Semicolon') . ' (;)'],
                            ['id' => ',', 'name' => $this->l('Comma') . ' (,)'],
                            ['id' => "\t", 'name' => $this->l('Tab')],
                            ['id' => '|', 'name' => $this->l('Pipe') . ' (|)']
                        ],
                        'id' => 'id',
                        'name' => 'name'
                    ]
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Encoding'),
                    'name' => 'EXPORTSEVI_CSV_ENCODING',
                    'options' => [
                        'query' => [
                            ['id' => 'UTF-8', 'name' => 'UTF-8'],
                            ['id' => 'ISO-8859-1', 'name' => 'ISO-8859-1 (Latin-1)'],
                            ['id' => 'Windows-1252', 'name' => 'Windows-1252']
                        ],
                        'id' => 'id',
                        'name' => 'name'
                    ],
                    'desc' => $this->l('UTF-8 recommended for international characters')
                ],

                // === PRODUCT FILTERS ===
                [
                    'type' => 'html',
                    'name' => '',
                    'html_content' => '<h4 style="border-bottom: 2px solid #00aff0; padding-bottom: 5px; margin-top: 20px;"><i class="icon-filter"></i> ' . $this->l('Product Filters') . ' <small>(' . $this->l('optional') . ')</small></h4>'
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Product Status'),
                    'name' => 'EXPORTSEVI_PRODUCT_STATUS',
                    'options' => [
                        'query' => [
                            ['id' => 'all', 'name' => $this->l('All Products')],
                            ['id' => 'active', 'name' => $this->l('Only Active')],
                            ['id' => 'inactive', 'name' => $this->l('Only Inactive')]
                        ],
                        'id' => 'id',
                        'name' => 'name'
                    ]
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Categories'),
                    'name' => 'EXPORTSEVI_FILTER_CATEGORY[]',
                    'multiple' => true,
                    'size' => 15,
                    'options' => [
                        'query' => $categories_list,
                        'id' => 'id',
                        'name' => 'name'
                    ],
                    'desc' => $this->l('Hold Ctrl/Cmd to select multiple')
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Manufacturers'),
                    'name' => 'EXPORTSEVI_FILTER_MANUFACTURER[]',
                    'multiple' => true,
                    'size' => 15,
                    'options' => [
                        'query' => $manufacturers_list,
                        'id' => 'id_manufacturer',
                        'name' => 'name'
                    ],
                    'desc' => $this->l('Hold Ctrl/Cmd to select multiple')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Price Range'),
                    'name' => 'EXPORTSEVI_FILTER_PRICE_MIN',
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Min price (optional)'),
                    'suffix' => '€'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l(''),
                    'name' => 'EXPORTSEVI_FILTER_PRICE_MAX',
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Max price (optional)'),
                    'suffix' => '€'
                ],

                // === EMAIL NOTIFICATIONS ===
                [
                    'type' => 'html',
                    'name' => '',
                    'html_content' => '<h4 style="border-bottom: 2px solid #00aff0; padding-bottom: 5px; margin-top: 20px;"><i class="icon-envelope"></i> ' . $this->l('Email Notifications') . '</h4>'
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Enable Email Notifications'),
                    'name' => 'EXPORTSEVI_EMAIL_NOTIFY',
                    'is_bool' => true,
                    'values' => [
                        ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')],
                        ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')]
                    ],
                    'desc' => $this->l('Send email after each export (manual or cron)')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Email Address'),
                    'name' => 'EXPORTSEVI_EMAIL_ADDRESS',
                    'desc' => $this->l('Email address to receive notifications'),
                    'class' => 'fixed-width-lg'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Auto-delete logs after'),
                    'name' => 'EXPORTSEVI_LOG_AUTO_DELETE',
                    'suffix' => 'days',
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Set 0 to disable automatic deletion')
                ],

                // === FOLDER BROWSER (SUB-ACCORDION) ===
                [
                    'type' => 'html',
                    'name' => '',
                    'html_content' => '
                        <div style="margin-top: 20px; border: 1px solid #ddd; border-radius: 4px;">
                            <div id="folder-browser-toggle" style="background: #f8f8f8; padding: 12px; cursor: pointer; border-radius: 4px;">
                                <i class="icon-folder-open"></i> <strong>' . $this->l('Choose a different folder') . '</strong>
                                <span class="pull-right"><i class="icon-chevron-right"></i></span>
                            </div>
                            <div id="folder-browser-content" style="display: none; padding: 15px; background: #fff; border-top: 1px solid #ddd;">
                                <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f8f8f8;">
                                    ' . $this->getFolderBrowser() . '
                                </div>
                                <p class="help-block">' . $this->l('Click on [+] to expand folders, click on folder names to select') . '</p>
                            </div>
                        </div>
                    '
                ]
            ],
            'submit' => [
                'title' => $this->l('Save Configuration'),
                'class' => 'btn btn-primary pull-right',
                'icon' => 'process-icon-save'
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
        $helper->fields_value['EXPORTSEVI_CSV_DELIMITER'] = Configuration::get('EXPORTSEVI_CSV_DELIMITER') ?: ';';
        $helper->fields_value['EXPORTSEVI_CSV_ENCODING'] = Configuration::get('EXPORTSEVI_CSV_ENCODING') ?: 'UTF-8';

        // Convert comma-separated strings to arrays for multiselect
        $filter_categories = Configuration::get('EXPORTSEVI_FILTER_CATEGORY');
        $helper->fields_value['EXPORTSEVI_FILTER_CATEGORY[]'] = $filter_categories ? explode(',', $filter_categories) : [];

        $filter_manufacturers = Configuration::get('EXPORTSEVI_FILTER_MANUFACTURER');
        $helper->fields_value['EXPORTSEVI_FILTER_MANUFACTURER[]'] = $filter_manufacturers ? explode(',', $filter_manufacturers) : [];

        $helper->fields_value['EXPORTSEVI_FILTER_PRICE_MIN'] = Configuration::get('EXPORTSEVI_FILTER_PRICE_MIN');
        $helper->fields_value['EXPORTSEVI_FILTER_PRICE_MAX'] = Configuration::get('EXPORTSEVI_FILTER_PRICE_MAX');
        $helper->fields_value['EXPORTSEVI_EMAIL_NOTIFY'] = Configuration::get('EXPORTSEVI_EMAIL_NOTIFY');
        $helper->fields_value['EXPORTSEVI_EMAIL_ADDRESS'] = Configuration::get('EXPORTSEVI_EMAIL_ADDRESS') ?: Configuration::get('PS_SHOP_EMAIL');
        $helper->fields_value['EXPORTSEVI_LOG_AUTO_DELETE'] = Configuration::get('EXPORTSEVI_LOG_AUTO_DELETE') ?: 30;

        $form = $helper->generateForm($fields_form);

        // Check if this is first configuration (no folder set yet)
        $is_first_config = !Configuration::get('EXPORTSEVI_FOLDER');

        // Add CSS for field sizing and UI enhancements
        $form .= '<style>
        /* Field size adjustments */
        input[name="EXPORTSEVI_FOLDER"].folder-input-wide {
            min-width: 600px !important;
            max-width: 100% !important;
        }
        input[name="EXPORTSEVI_FILENAME"].fixed-width-lg,
        input[name="EXPORTSEVI_EMAIL_ADDRESS"].fixed-width-lg {
            width: 350px !important;
        }
        input[name="EXPORTSEVI_BATCH_SIZE"].fixed-width-sm,
        input[name="EXPORTSEVI_LOG_AUTO_DELETE"].fixed-width-sm {
            width: 100px !important;
        }

        /* Make multiselect boxes larger and more readable */
        select[name="EXPORTSEVI_FILTER_CATEGORY[]"],
        select[name="EXPORTSEVI_FILTER_MANUFACTURER[]"] {
            min-height: 350px !important;
            font-size: 13px;
            line-height: 1.6;
        }
        select[name="EXPORTSEVI_FILTER_CATEGORY[]"] option,
        select[name="EXPORTSEVI_FILTER_MANUFACTURER[]"] option {
            padding: 4px 8px;
        }

        /* Main configuration panel styling */
        .panel legend {
            cursor: pointer;
            user-select: none;
            transition: background 0.2s;
        }
        .panel legend:hover {
            background: #f8f8f8;
        }
        .panel legend .pull-right {
            margin-right: 10px;
            transition: transform 0.2s;
        }

        /* Folder browser sub-accordion */
        #folder-browser-toggle {
            transition: background 0.2s;
        }
        #folder-browser-toggle:hover {
            background: #e8e8e8 !important;
        }
        #folder-browser-toggle .pull-right {
            transition: transform 0.2s;
        }

        /* Required field indicator */
        .form-group.required label:after {
            content: " *";
            color: #e74c3c;
            font-weight: bold;
        }

        /* Section headers within form */
        .form-wrapper h4 {
            font-weight: 600;
            color: #363a41;
            margin-bottom: 15px;
        }

        /* Better button spacing */
        .btn-lg {
            margin-right: 8px;
            margin-bottom: 8px;
        }

        /* Tooltip styling */
        [title] {
            cursor: help;
        }

        /* Alert styling */
        .alert {
            border-left: 4px solid;
        }
        .alert-info {
            border-left-color: #00aff0;
        }
        </style>';

        // Add JavaScript for accordion behavior
        $form .= '<script>
        document.addEventListener("DOMContentLoaded", function() {
            // Main configuration panel - make collapsible
            var configPanel = document.querySelector(".panel legend");
            if (configPanel) {
                var panel = configPanel.closest(".panel");
                var panelBody = panel.querySelector(".form-wrapper");

                // Add chevron icon
                configPanel.innerHTML = \'<span class="pull-right"><i class="icon-chevron-' . ($is_first_config ? 'down' : 'right') . '"></i></span>\' + configPanel.innerHTML;

                // Toggle on click
                configPanel.addEventListener("click", function() {
                    var icon = this.querySelector("i");
                    if (panelBody.style.display === "none") {
                        panelBody.style.display = "block";
                        icon.className = "icon-chevron-down";
                    } else {
                        panelBody.style.display = "none";
                        icon.className = "icon-chevron-right";
                    }
                });

                // Collapse on load if not first config
                ' . ($is_first_config ? '' : 'panelBody.style.display = "none";') . '
            }

            // Folder browser sub-accordion
            var folderToggle = document.getElementById("folder-browser-toggle");
            var folderContent = document.getElementById("folder-browser-content");
            if (folderToggle && folderContent) {
                folderToggle.addEventListener("click", function() {
                    var icon = this.querySelector("i");
                    if (folderContent.style.display === "none") {
                        folderContent.style.display = "block";
                        icon.className = "icon-chevron-down";
                    } else {
                        folderContent.style.display = "none";
                        icon.className = "icon-chevron-right";
                    }
                });
            }
        });
        </script>';

        // Export Actions section (always visible)
        $security_token = Configuration::get('EXPORTSEVI_SECURITY_TOKEN');
        $cron_url = _PS_BASE_URL_ . __PS_BASE_URI__ . 'modules/' . $this->name . '/export.php?token=' . $security_token;
        $current_file = Configuration::get('EXPORTSEVI_FILENAME');

        $form .= '<div class="panel">';
        $form .= '<div class="panel-heading"><i class="icon-play-circle"></i> ' . $this->l('Export Actions') . '</div>';
        $form .= '<div class="form-wrapper">';

        // Get last successful export info
        $last_export_sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'exportsevi_log` WHERE status = \'success\' ORDER BY export_date DESC LIMIT 1';
        $last_export = Db::getInstance()->getRow($last_export_sql);

        if ($last_export) {
            $form .= '<div class="alert alert-info" style="margin-bottom: 15px;">';
            $form .= '<strong><i class="icon-clock-o"></i> ' . $this->l('Last successful export:') . '</strong> ';
            $form .= htmlspecialchars($last_export['export_date']) . ' - ';
            $form .= '<strong>' . (int)$last_export['products_count'] . '</strong> ' . $this->l('products');
            $form .= ' <span class="badge badge-' . ($last_export['export_type'] === 'manual' ? 'info' : 'default') . '">' . htmlspecialchars($last_export['export_type']) . '</span>';
            $form .= '</div>';
        }

        $form .= '<form method="post" action="' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '">';
        $form .= '<div class="form-group">';
        $form .= '<button type="button" id="preview-btn" class="btn btn-info btn-lg" onclick="showPreview()" title="' . $this->l('Preview first 10 products before exporting') . '">';
        $form .= '<i class="icon-eye"></i> ' . $this->l('Preview') . '</button> ';
        $form .= '<button type="submit" name="export_now" class="btn btn-primary btn-lg" title="' . $this->l('Run export now with current configuration') . '">';
        $form .= '<i class="icon-download"></i> ' . $this->l('Export Now') . '</button> ';

        // Download button if file exists
        $folder = Configuration::get('EXPORTSEVI_FOLDER');
        $filepath = rtrim($folder, '/') . '/' . $current_file;
        if (file_exists($filepath)) {
            $file_size = round(filesize($filepath) / 1024, 2);
            $file_date = date('Y-m-d H:i', filemtime($filepath));
            $form .= '<a href="' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&download_export=1&file=' . urlencode($current_file) . '" class="btn btn-success btn-lg" title="' . $this->l('Size:') . ' ' . $file_size . 'KB - ' . $this->l('Modified:') . ' ' . $file_date . '">';
            $form .= '<i class="icon-cloud-download"></i> ' . $this->l('Download CSV') . '</a>';
        }

        $form .= '</div>';
        $form .= '<div id="preview-container" style="display:none; margin-top:15px;"></div>';
        $form .= '</form>';

        $form .= '<hr style="margin: 25px 0;">';

        $form .= '<h4 style="margin-top: 0;"><i class="icon-time"></i> ' . $this->l('Automated Exports (Cron)') . '</h4>';

        $form .= '<div class="form-group">';
        $form .= '<label><i class="icon-key"></i> ' . $this->l('Security Token') . '</label>';
        $form .= '<div class="input-group">';
        $form .= '<input type="text" class="form-control" value="' . htmlspecialchars($security_token) . '" readonly onclick="this.select()" title="' . $this->l('Click to select and copy') . '">';
        $form .= '<span class="input-group-btn">';
        $form .= '<form method="post" action="' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '" style="display:inline;">';
        $form .= '<button type="submit" name="regenerate_token" class="btn btn-warning" onclick="return confirm(\'' . $this->l('This will invalidate the current cron URL. Continue?') . '\')" title="' . $this->l('Generate a new security token') . '">';
        $form .= '<i class="icon-refresh"></i> ' . $this->l('Regenerate') . '</button>';
        $form .= '</form>';
        $form .= '</span>';
        $form .= '</div>';
        $form .= '<p class="help-block"><i class="icon-info-circle"></i> ' . $this->l('Keep this token secret. It protects your export from unauthorized access.') . '</p>';
        $form .= '</div>';

        $form .= '<div class="form-group">';
        $form .= '<label><i class="icon-link"></i> ' . $this->l('Cron URL') . '</label>';
        $form .= '<input type="text" class="form-control" value="' . htmlspecialchars($cron_url) . '" readonly onclick="this.select()" title="' . $this->l('Click to select and copy') . '">';
        $form .= '<p class="help-block"><i class="icon-info-circle"></i> ' . $this->l('Use this URL in your server cron job. Example (runs daily at 2 AM):') . '<br><code style="background: #f5f5f5; padding: 8px; display: block; margin-top: 5px; border-radius: 3px;">0 2 * * * curl -s "' . htmlspecialchars($cron_url) . '"</code></p>';
        $form .= '</div>';
        $form .= '</div></div>';

        // Export history
        $form .= $this->displayExportHistory();

        // Add JavaScript and CSS for collapsible folder browser (PrestaShop style)
        $form .= '
        <style>
        .folder-tree { font-family: monospace; line-height: 24px; }
        .folder-line { display: block; padding: 2px 0; }
        .folder-toggle {
            display: inline-block;
            width: 20px;
            text-align: center;
            cursor: pointer;
            font-weight: bold;
            color: #555;
            user-select: none;
        }
        .folder-toggle:hover { color: #000; }
        .folder-name {
            cursor: pointer;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
            transition: all 0.2s;
        }
        .folder-name:hover { background: #f0f8ff; }
        .folder-name.selected { background: #e3f2fd; font-weight: bold; }
        .folder-children {
            margin-left: 20px;
            display: none;
        }
        .folder-children.open { display: block; }
        .folder-writable { color: #4CAF50; }
        .folder-not-writable { color: #f44336; }
        </style>

        <script>
        function selectFolder(path, element) {
            document.getElementsByName("EXPORTSEVI_FOLDER")[0].value = path;

            // Remove previous selection
            var allFolders = document.querySelectorAll(".folder-name");
            allFolders.forEach(function(f) {
                f.classList.remove("selected");
            });

            // Highlight selected
            element.classList.add("selected");
        }

        function toggleFolder(toggleBtn) {
            var folderLine = toggleBtn.closest(".folder-line");
            var children = folderLine.querySelector(".folder-children");

            if (!children) return;

            if (children.classList.contains("open")) {
                // Close
                children.classList.remove("open");
                toggleBtn.textContent = "+";
            } else {
                // Open
                children.classList.add("open");
                toggleBtn.textContent = "-";
            }
        }

        // Initialize - set all toggles to + and close all except root
        document.addEventListener("DOMContentLoaded", function() {
            var allToggles = document.querySelectorAll(".folder-toggle");
            allToggles.forEach(function(toggle, index) {
                if (index > 0) { // Keep first (root) open
                    toggle.textContent = "+";
                } else {
                    toggle.textContent = "-";
                    var folderLine = toggle.closest(".folder-line");
                    var children = folderLine.querySelector(".folder-children");
                    if (children) children.classList.add("open");
                }
            });
        });

        // Preview functionality
        function showPreview() {
            var previewBtn = document.getElementById("preview-btn");
            var previewContainer = document.getElementById("preview-container");

            previewBtn.disabled = true;
            previewBtn.innerHTML = \'<i class="icon-spinner icon-spin"></i> Loading...\';

            var url = "' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&preview_export=1";

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        var html = \'<div class="alert alert-success"><strong>Preview:</strong> Showing first 10 rows of export</div>\';
                        html += \'<div style="overflow-x:auto;"><table class="table table-bordered table-striped">\';
                        html += \'<thead><tr>\';
                        data.headers.forEach(function(header) {
                            html += \'<th>\' + header + \'</th>\';
                        });
                        html += \'</tr></thead><tbody>\';
                        data.rows.forEach(function(row) {
                            html += \'<tr>\';
                            row.forEach(function(cell) {
                                html += \'<td>\' + cell + \'</td>\';
                            });
                            html += \'</tr>\';
                        });
                        html += \'</tbody></table></div>\';
                        html += \'<p class="help-block">Total products to export: \' + data.total + \'</p>\';

                        previewContainer.innerHTML = html;
                        previewContainer.style.display = "block";
                    } else {
                        previewContainer.innerHTML = \'<div class="alert alert-danger">\' + data.message + \'</div>\';
                        previewContainer.style.display = "block";
                    }
                })
                .catch(error => {
                    previewContainer.innerHTML = \'<div class="alert alert-danger">Error loading preview</div>\';
                    previewContainer.style.display = "block";
                })
                .finally(() => {
                    previewBtn.disabled = false;
                    previewBtn.innerHTML = \'<i class="icon-eye"></i> ' . $this->l('Preview (First 10 rows)') . '\';
                });
        }
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

        // Get total count
        $total_sql = 'SELECT COUNT(*) as total FROM `' . _DB_PREFIX_ . 'exportsevi_log`';
        $total_result = Db::getInstance()->getRow($total_sql);
        $total_logs = $total_result ? $total_result['total'] : 0;

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

            // Add delete logs button
            $html .= '<div class="form-group">';
            $html .= '<p class="help-block">' . sprintf($this->l('Showing last 10 of %d total logs'), $total_logs) . '</p>';
            $html .= '<form method="post" action="' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '" onsubmit="return confirm(\'' . $this->l('Are you sure you want to delete all export logs?') . '\')">';
            $html .= '<button type="submit" name="delete_logs" class="btn btn-danger">';
            $html .= '<i class="icon-trash"></i> ' . $this->l('Delete All Logs');
            $html .= '</button>';
            $html .= '</form>';
            $html .= '</div>';
        } else {
            $html .= '<p class="alert alert-info">' . $this->l('No exports yet.') . '</p>';
        }

        $html .= '</div></div>';

        return $html;
    }

    private function getFolderBrowser()
    {
        $html = '<div class="alert alert-info">';
        $html .= '<i class="icon-info-circle"></i> ';
        $html .= '<strong>' . $this->l('How to use:') . '</strong><br>';
        $html .= $this->l('Click [+] to expand folders, click on folder names to select as destination');
        $html .= '</div>';

        $html .= '<div class="folder-tree">';
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
        $folder_name = $level === 0 ? 'PrestaShop Root' : basename(rtrim($path, '/'));
        $writable = is_writable($path);
        $writable_class = $writable ? 'folder-writable' : 'folder-not-writable';
        $writable_icon = $writable ? '✓' : '✗';

        // Get subdirectories
        $dirs = [];
        if (is_readable($path)) {
            if ($handle = opendir($path)) {
                while (($file = readdir($handle)) !== false) {
                    if ($file != '.' && $file != '..' && is_dir($path . $file) &&
                        !in_array($file, ['.git', '.svn', 'node_modules', '.idea', '__pycache__'])) {
                        $dirs[] = $file;
                    }
                }
                closedir($handle);
            }
        }

        // Priority directories
        $priority_dirs = ['modules', 'upload', 'download', 'img', 'var', 'cache', 'log', 'exports', 'files'];
        $sorted_dirs = [];
        foreach ($priority_dirs as $priority) {
            if (in_array($priority, $dirs)) {
                $sorted_dirs[] = $priority;
                $dirs = array_diff($dirs, [$priority]);
            }
        }
        sort($dirs);
        $dirs = array_merge($sorted_dirs, $dirs);

        $has_subdirs = count($dirs) > 0;

        // Start folder line
        $html .= '<div class="folder-line">';

        // Toggle button (+ or -)
        if ($has_subdirs) {
            $html .= '<span class="folder-toggle" onclick="toggleFolder(this)">+</span>';
        } else {
            $html .= '<span style="display:inline-block;width:20px;"></span>';
        }

        // Folder name (clickable to select)
        $html .= '<span class="folder-name ' . $writable_class . '" onclick="selectFolder(\'' . addslashes($path) . '\', this)">';
        $html .= '<i class="icon-folder"></i> ' . htmlspecialchars($folder_name) . ' ' . $writable_icon;
        $html .= '</span>';

        // Subdirectories
        if ($has_subdirs) {
            $html .= '<div class="folder-children">';
            foreach ($dirs as $dir) {
                $html .= $this->listPSDirectories($path . $dir, $level + 1, $max_level);
            }
            $html .= '</div>';
        }

        $html .= '</div>'; // End folder-line

        return $html;
    }

    public function executeExport($export_type = 'manual')
    {
        $start_time = microtime(true);

        // Auto-delete old logs
        $auto_delete_days = (int)Configuration::get('EXPORTSEVI_LOG_AUTO_DELETE');
        if ($auto_delete_days > 0) {
            $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'exportsevi_log`
                    WHERE export_date < DATE_SUB(NOW(), INTERVAL ' . (int)$auto_delete_days . ' DAY)';
            Db::getInstance()->execute($sql);
        }

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

            // Note: .htaccess NOT created - CSV files need to be accessible via URL for stock updates

            $filepath = rtrim($folder, '/') . '/' . $filename;

            // Get CSV settings
            $csv_delimiter = Configuration::get('EXPORTSEVI_CSV_DELIMITER') ?: ';';
            $csv_encoding = Configuration::get('EXPORTSEVI_CSV_ENCODING') ?: 'UTF-8';

            // Open file for writing
            $file = fopen($filepath, 'w');
            if (!$file) {
                $this->logExport(0, $filepath, 'error', 'Cannot create file: ' . $filepath, $export_type);
                $this->sendNotificationEmail(false, 0, $filepath, 0, 'Cannot create file');
                return ['success' => false, 'message' => 'Cannot create file: ' . $filepath];
            }

            // Add BOM for UTF-8 Excel compatibility
            if ($csv_encoding === 'UTF-8') {
                fwrite($file, "\xEF\xBB\xBF");
            }

            // CSV Headers
            $headers = [
                'Referencias Completas',
                'Referencias Filtradas',
                'Nombre',
                'Stock'
            ];

            // Convert encoding if needed
            if ($csv_encoding !== 'UTF-8') {
                $headers = array_map(function($h) use ($csv_encoding) {
                    return mb_convert_encoding($h, $csv_encoding, 'UTF-8');
                }, $headers);
            }

            fputcsv($file, $headers, $csv_delimiter);

            // Get products data with batch processing
            $total_count = 0;
            $batch_size = (int)Configuration::get('EXPORTSEVI_BATCH_SIZE') ?: 500;
            $offset = 0;

            do {
                $products = $this->getProductsData($offset, $batch_size);

                foreach ($products as $product) {
                    $row = [
                        $product['ref_completa'],
                        $product['ref_filtrada'],
                        $product['nombre'],
                        $product['stock']
                    ];

                    // Convert encoding if needed
                    if ($csv_encoding !== 'UTF-8') {
                        $row = array_map(function($cell) use ($csv_encoding) {
                            return mb_convert_encoding($cell, $csv_encoding, 'UTF-8');
                        }, $row);
                    }

                    fputcsv($file, $row, $csv_delimiter);
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

            // Send email notification
            $this->sendNotificationEmail(true, $total_count, $filepath, $execution_time, $message);

            return [
                'success' => true,
                'message' => $message
            ];

        } catch (Exception $e) {
            $execution_time = round(microtime(true) - $start_time, 2);
            $this->logExport(0, $folder . '/' . $filename, 'error', $e->getMessage(), $export_type);
            $this->sendNotificationEmail(false, 0, $folder . '/' . $filename, $execution_time, $e->getMessage());
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

        // Additional filters - support multiple categories and manufacturers
        $filter_categories = Configuration::get('EXPORTSEVI_FILTER_CATEGORY');
        $filter_manufacturers = Configuration::get('EXPORTSEVI_FILTER_MANUFACTURER');
        $filter_price_min = (float)Configuration::get('EXPORTSEVI_FILTER_PRICE_MIN');
        $filter_price_max = (float)Configuration::get('EXPORTSEVI_FILTER_PRICE_MAX');

        $filters_sql = '';

        // Category filter (multiple)
        if ($filter_categories) {
            $category_ids = array_filter(array_map('intval', explode(',', $filter_categories)));
            if (!empty($category_ids)) {
                $filters_sql .= ' AND EXISTS (
                    SELECT 1 FROM ' . _DB_PREFIX_ . 'category_product cp
                    WHERE cp.id_product = p.id_product AND cp.id_category IN (' . implode(',', $category_ids) . ')
                )';
            }
        }

        // Manufacturer filter (multiple)
        if ($filter_manufacturers) {
            $manufacturer_ids = array_filter(array_map('intval', explode(',', $filter_manufacturers)));
            if (!empty($manufacturer_ids)) {
                $filters_sql .= ' AND p.id_manufacturer IN (' . implode(',', $manufacturer_ids) . ')';
            }
        }

        // Price filters
        if ($filter_price_min > 0) {
            $filters_sql .= ' AND p.price >= ' . (float)$filter_price_min;
        }
        if ($filter_price_max > 0) {
            $filters_sql .= ' AND p.price <= ' . (float)$filter_price_max;
        }

        // Increase GROUP_CONCAT limit to avoid truncation
        Db::getInstance()->execute('SET SESSION group_concat_max_len = 10000');

        // Calculate items already added to manage offset properly
        $items_added = 0;
        $items_to_skip = $offset;
        $items_to_return = $limit;

        // First: Get simple products (without combinations)
        $simple_products = 'SELECT
                    p.id_product,
                    p.reference as product_reference,
                    pl.name as product_name,
                    sa.quantity as stock
                FROM ' . _DB_PREFIX_ . 'product p
                LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = ' . (int)$id_lang . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0)
                WHERE 1=1 ' . $status_where . $filters_sql . '
                AND NOT EXISTS (
                    SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute pa
                    WHERE pa.id_product = p.id_product
                )
                ORDER BY p.id_product';

        $simple_data = Db::getInstance()->executeS($simple_products);

        if ($simple_data) {
            foreach ($simple_data as $row) {
                // Skip items until we reach offset
                if ($items_to_skip > 0) {
                    $items_to_skip--;
                    continue;
                }

                // Stop if we've reached the limit
                if ($items_added >= $items_to_return) {
                    break;
                }

                $results[] = [
                    'ref_completa' => $row['product_reference'],
                    'ref_filtrada' => $row['product_reference'],
                    'nombre' => $row['product_name'],
                    'stock' => (int)$row['stock']
                ];
                $items_added++;
            }
        }

        // If we still need more items, get products with combinations
        if ($items_added < $items_to_return) {
            $products_with_combinations = 'SELECT DISTINCT
                        p.id_product,
                        p.reference as product_reference,
                        pl.name as product_name,
                        sa.quantity as stock
                    FROM ' . _DB_PREFIX_ . 'product p
                    LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = ' . (int)$id_lang . ')
                    LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0)
                    WHERE 1=1 ' . $status_where . $filters_sql . '
                    AND EXISTS (
                        SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute pa
                        WHERE pa.id_product = p.id_product
                    )
                    ORDER BY p.id_product';

            $parent_data = Db::getInstance()->executeS($products_with_combinations);

            if ($parent_data) {
                foreach ($parent_data as $row) {
                    // Stop if we've reached the limit
                    if ($items_added >= $items_to_return) {
                        break;
                    }

                    // Get combinations for this product
                    $combinations_sql = 'SELECT
                                pa.reference as combination_reference,
                                sa.quantity as stock,
                                GROUP_CONCAT(CONCAT(agl.name, ": ", al.name) ORDER BY a.id_attribute_group, a.position SEPARATOR " - ") as attributes
                            FROM ' . _DB_PREFIX_ . 'product_attribute pa
                            LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (pa.id_product = sa.id_product AND pa.id_product_attribute = sa.id_product_attribute)
                            LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac ON pa.id_product_attribute = pac.id_product_attribute
                            LEFT JOIN ' . _DB_PREFIX_ . 'attribute a ON pac.id_attribute = a.id_attribute
                            LEFT JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON (a.id_attribute = al.id_attribute AND al.id_lang = ' . (int)$id_lang . ')
                            LEFT JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl ON (a.id_attribute_group = agl.id_attribute_group AND agl.id_lang = ' . (int)$id_lang . ')
                            WHERE pa.id_product = ' . (int)$row['id_product'] . '
                            GROUP BY pa.id_product_attribute
                            ORDER BY pa.id_product_attribute';

                    $combinations_data = Db::getInstance()->executeS($combinations_sql);

                    if ($combinations_data) {
                        foreach ($combinations_data as $comb) {
                            // Skip items until we reach offset
                            if ($items_to_skip > 0) {
                                $items_to_skip--;
                                continue;
                            }

                            // Stop if we've reached the limit
                            if ($items_added >= $items_to_return) {
                                break 2; // Break both foreach loops
                            }

                            $combination_name = $row['product_name'];
                            if (!empty($comb['attributes'])) {
                                $combination_name .= ' - ' . $comb['attributes'];
                            }

                            // SOLO las combinaciones: referencia padre en columna 1, combinación en columna 2
                            $results[] = [
                                'ref_completa' => $row['product_reference'], // Referencia padre repetida
                                'ref_filtrada' => $comb['combination_reference'], // Referencia de combinación
                                'nombre' => $combination_name,
                                'stock' => (int)$comb['stock']
                            ];
                            $items_added++;
                        }
                    }
                }
            }
        }

        return $results;
    }
}