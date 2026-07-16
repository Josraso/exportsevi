<?php
// DEBUG: Ver qué está pasando con stock_available
require_once(dirname(__FILE__).'/../../config/config.inc.php');

$id_shop = (int)Context::getContext()->shop->id;
$id_lang = (int)Context::getContext()->language->id;

echo "<h2>DEBUG INFO</h2>";
echo "<p><strong>Current Shop ID:</strong> $id_shop</p>";
echo "<p><strong>Current Lang ID:</strong> $id_lang</p>";

// Ver cuántos registros hay para un producto específico (MINIQUAD8)
$sql = "SELECT sa.id_stock_available, sa.id_product, sa.id_product_attribute, sa.id_shop, sa.id_shop_group, sa.quantity, p.reference
        FROM "._DB_PREFIX_."stock_available sa
        LEFT JOIN "._DB_PREFIX_."product p ON sa.id_product = p.id_product
        WHERE p.reference = 'MINIQUAD8'
        ORDER BY sa.id_product_attribute, sa.id_shop";

$results = Db::getInstance()->executeS($sql);

echo "<h3>Stock Available para MINIQUAD8:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>id_stock</th><th>id_product</th><th>id_attr</th><th>id_shop</th><th>id_shop_group</th><th>quantity</th></tr>";
foreach ($results as $row) {
    echo "<tr>";
    echo "<td>{$row['id_stock_available']}</td>";
    echo "<td>{$row['id_product']}</td>";
    echo "<td>{$row['id_product_attribute']}</td>";
    echo "<td>{$row['id_shop']}</td>";
    echo "<td>{$row['id_shop_group']}</td>";
    echo "<td>{$row['quantity']}</td>";
    echo "</tr>";
}
echo "</table>";

// Ver cuántas tiendas hay configuradas
$shops_sql = "SELECT id_shop, name FROM "._DB_PREFIX_."shop ORDER BY id_shop";
$shops = Db::getInstance()->executeS($shops_sql);

echo "<h3>Tiendas configuradas:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID Shop</th><th>Nombre</th></tr>";
foreach ($shops as $shop) {
    echo "<tr><td>{$shop['id_shop']}</td><td>{$shop['name']}</td></tr>";
}
echo "</table>";
