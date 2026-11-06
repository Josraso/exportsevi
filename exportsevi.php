<?php
/**
 * Export Sevi
 * Exporta referencias y stock de los productos a un CSV, a mano o por cron.
 *
 * @author    Josra
 * @copyright Josra
 * @license   Uso privado
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
        $this->version = '1.0.1';
        $this->author = 'Josra';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.6',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Export Sevi');
        $this->description = $this->l('Exporta referencias y stock de los productos a un CSV, a mano o por cron.');
    }

    public function install()
    {
        return parent::install() &&
            Configuration::updateValue('EXPORTSEVI_FOLDER', _PS_ROOT_DIR_ . '/exports/') &&
            Configuration::updateValue('EXPORTSEVI_FILENAME', 'productos_stock.csv') &&
            Configuration::updateValue('EXPORTSEVI_PRODUCT_STATUS', 'active');
    }

    public function uninstall()
    {
        Configuration::deleteByName('EXPORTSEVI_FOLDER');
        Configuration::deleteByName('EXPORTSEVI_FILENAME');
        Configuration::deleteByName('EXPORTSEVI_PRODUCT_STATUS');

        return parent::uninstall();
    }

    /**
     * URL completa de la configuración del módulo. En PrestaShop 8/9 la configuración va por ruta Symfony
     * (index.php/improve/modules/...): una dirección relativa como AdminController::$currentIndex se resuelve
     * contra esa ruta y el servidor (nginx de Plesk) devuelve «Not Found» al guardar.
     */
    protected function baseUrl()
    {
        $params = ['configure' => $this->name, 'tab_module' => $this->tab, 'module_name' => $this->name];
        if (version_compare(_PS_VERSION_, '1.7.5.0', '>=')) {
            $url = $this->context->link->getAdminLink('AdminModules', true, [], $params);
        } else {
            $url = $this->context->link->getAdminLink('AdminModules', true);
        }
        if (strpos($url, 'configure=') === false && strpos($url, '/configure/') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        return $url;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $folder = strval(Tools::getValue('EXPORTSEVI_FOLDER'));
            $filename = strval(Tools::getValue('EXPORTSEVI_FILENAME'));
            $product_status = strval(Tools::getValue('EXPORTSEVI_PRODUCT_STATUS'));

            if (trim($folder) === '' || trim($filename) === '') {
                $output .= $this->displayError($this->l('No se ha guardado: la carpeta y el nombre del archivo son obligatorios.'));
            } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
                $output .= $this->displayError($this->l('No se ha guardado: el nombre del archivo solo puede llevar letras, números, puntos, guiones y guiones bajos (sin carpetas).'));
            } else {
                Configuration::updateValue('EXPORTSEVI_FOLDER', $folder);
                Configuration::updateValue('EXPORTSEVI_FILENAME', $filename);
                Configuration::updateValue('EXPORTSEVI_PRODUCT_STATUS', $product_status);
                $output .= $this->displayConfirmation($this->l('Configuración guardada.'));
            }
        }

        // Manual export button
        if (Tools::isSubmit('export_now')) {
            $result = $this->executeExport();
            if ($result['success']) {
                $output .= $this->displayConfirmation($this->l('Exportación hecha: ') . $result['message']);
            } else {
                $output .= $this->displayError($this->l('No se ha podido exportar: ') . $result['message']);
            }
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        // Get form values
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('Configuración'),
                'icon' => 'icon-cogs',
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Carpeta de destino'),
                    'name' => 'EXPORTSEVI_FOLDER',
                    'required' => true,
                    'desc' => $this->l('Ruta completa en el servidor (p. ej. /var/www/vhosts/tienda.com/exports/). Se puede elegir abajo, en «Carpetas».'),
                    'class' => 'fixed-width-xxl'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Nombre del archivo'),
                    'name' => 'EXPORTSEVI_FILENAME',
                    'required' => true,
                    'desc' => $this->l('Con la extensión .csv (p. ej. productos_stock.csv). Se sobrescribe en cada exportación.')
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Productos'),
                    'name' => 'EXPORTSEVI_PRODUCT_STATUS',
                    'options' => [
                        'query' => [
                            ['id' => 'all', 'name' => $this->l('Todos')],
                            ['id' => 'active', 'name' => $this->l('Solo activos')],
                            ['id' => 'inactive', 'name' => $this->l('Solo desactivados')]
                        ],
                        'id' => 'id',
                        'name' => 'name'
                    ]
                ]
            ],
            'submit' => [
                'title' => $this->l('Guardar'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->baseUrl(); // completa: la relativa da «Not Found» en PS 8/9
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->submit_action = 'submit' . $this->name;

        $helper->fields_value['EXPORTSEVI_FOLDER'] = Configuration::get('EXPORTSEVI_FOLDER');
        $helper->fields_value['EXPORTSEVI_FILENAME'] = Configuration::get('EXPORTSEVI_FILENAME');
        $helper->fields_value['EXPORTSEVI_PRODUCT_STATUS'] = Configuration::get('EXPORTSEVI_PRODUCT_STATUS') ?: 'active';

        $form = $helper->generateForm($fields_form);

        // Add folder browser and manual export section
        $cron_url = _PS_BASE_URL_ . __PS_BASE_URI__ . 'modules/' . $this->name . '/export.php';
        
        $form .= '<div class="panel">';
        $form .= '<div class="panel-heading"><i class="icon-folder"></i> ' . $this->l('Carpetas') . '</div>';
        $form .= '<div class="form-wrapper">';
        $form .= '<div id="folder-browser" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f8f8f8;">';
        $form .= $this->getFolderBrowser();
        $form .= '</div>';
        $form .= '<p class="help-block">' . $this->l('[+] abre la carpeta; pulsando el nombre se elige como destino. ✓ = se puede escribir, ✗ = no.') . '</p>';
        $form .= '</div></div>';
        
        $form .= '<div class="panel">';
        $form .= '<div class="panel-heading"><i class="icon-cogs"></i> ' . $this->l('Exportar') . '</div>';
        $form .= '<div class="form-wrapper">';
        $form .= '<form method="post" action="' . htmlspecialchars($this->baseUrl(), ENT_QUOTES, 'UTF-8') . '">';
        $form .= '<div class="form-group">';
        $form .= '<button type="submit" name="export_now" class="btn btn-primary btn-lg">';
        $form .= '<i class="icon-download"></i> ' . $this->l('Exportar ahora') . '</button>';
        $form .= '</div>';
        $form .= '</form>';
        $form .= '<div class="form-group">';
        $form .= '<label>' . $this->l('URL para el cron') . '</label>';
        $form .= '<input type="text" class="form-control" value="' . htmlspecialchars($cron_url, ENT_QUOTES, 'UTF-8') . '" readonly onclick="this.select()">';
        $form .= '<p class="help-block">' . $this->l('En Plesk > Tareas programadas, «Obtener una URL» con esta dirección cada vez que quiera el archivo al día.') . '</p>';
        $form .= '</div>';
        $form .= '</div></div>';

        // Add JavaScript for collapsible folder browser
        $form .= '<script>
        function selectFolder(path, el) {
            document.getElementsByName("EXPORTSEVI_FOLDER")[0].value = path;
            var folders = document.querySelectorAll(".folder-name");
            folders.forEach(function(f) { f.style.backgroundColor = ""; f.style.fontWeight = ""; });
            if (el) {
                el.style.backgroundColor = "#e3f2fd";
                el.style.fontWeight = "bold";
            }
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

    private function getFolderBrowser()
    {
        $html = '<div style="font-family: monospace;">';
        $html .= '<div style="margin-bottom: 15px; padding: 10px; background: #e8f4f8; border-left: 4px solid #2196F3;">';
        $html .= '<strong><i class="icon-info"></i> ' . $this->l('Carpetas de la tienda') . '</strong>';
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

        $path = rtrim($path, '/') . '/';
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
        
        // Current directory
        $writable = is_writable($path) ? '✓' : '✗';
        $style = is_writable($path) ? 'color: #4CAF50;' : 'color: #f44336;';
        $rel_path = str_replace(_PS_ROOT_DIR_, '', $path);
        $display_path = $rel_path ?: '/';
        $folder_name = $level === 0 ? $this->l('Raíz de la tienda') : htmlspecialchars(basename(rtrim($path, '/')), ENT_QUOTES, 'UTF-8');

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
                  onclick="selectFolder(\'' . htmlspecialchars(addslashes($path), ENT_QUOTES, 'UTF-8') . '\', this)" 
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

    public function executeExport()
    {
        try {
            $folder = Configuration::get('EXPORTSEVI_FOLDER');
            $filename = Configuration::get('EXPORTSEVI_FILENAME');
            
            // Create folder if it doesn't exist
            if (!is_dir($folder)) {
                if (!@mkdir($folder, 0755, true)) {
                    return ['success' => false, 'message' => 'no se puede crear la carpeta ' . $folder . ' (no existe y el usuario del servidor no tiene permiso para crearla).'];
                }
            }

            $filepath = rtrim($folder, '/') . '/' . $filename;

            // Open file for writing
            $file = @fopen($filepath, 'w');
            if (!$file) {
                return ['success' => false, 'message' => 'no se puede escribir ' . $filepath . ' (revise los permisos de la carpeta o si el archivo está abierto o bloqueado).'];
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

            // Get products data
            $products = $this->getProductsData();
            
            foreach ($products as $product) {
                fputcsv($file, [
                    $product['ref_completa'],
                    $product['ref_filtrada'],
                    $product['nombre'],
                    $product['stock']
                ], ';');
            }

            fclose($file);
            
            return [
                'success' => true, 
                'message' => count($products) . ' filas en ' . $filepath
            ];

        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getProductsData()
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
        
        // First: Get simple products (without combinations)
        $simple_products = 'SELECT 
                    p.id_product,
                    p.reference as product_reference,
                    pl.name as product_name,
                    sa.quantity as stock
                FROM ' . _DB_PREFIX_ . 'product p
                LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = ' . (int)$id_lang . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0)
                WHERE 1=1 ' . $status_where . '
                AND NOT EXISTS (
                    SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute pa 
                    WHERE pa.id_product = p.id_product
                )
                ORDER BY p.id_product';

        $simple_data = Db::getInstance()->executeS($simple_products);
        
        foreach ($simple_data as $row) {
            $results[] = [
                'ref_completa' => $row['product_reference'],
                'ref_filtrada' => $row['product_reference'],
                'nombre' => $row['product_name'],
                'stock' => (int)$row['stock']
            ];
        }

        // Second: Get products with combinations
        $products_with_combinations = 'SELECT DISTINCT
                    p.id_product,
                    p.reference as product_reference,
                    pl.name as product_name,
                    sa.quantity as stock
                FROM ' . _DB_PREFIX_ . 'product p
                LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = ' . (int)$id_lang . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0)
                WHERE 1=1 ' . $status_where . '
                AND EXISTS (
                    SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute pa 
                    WHERE pa.id_product = p.id_product
                )
                ORDER BY p.id_product';

        $parent_data = Db::getInstance()->executeS($products_with_combinations);
        
        foreach ($parent_data as $row) {
            // NO añadir la fila del producto padre - ir directo a las combinaciones

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
            
            foreach ($combinations_data as $comb) {
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
            }
        }

        return $results;
    }
}