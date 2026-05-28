<?php
header("Content-Type: application/xml; charset=utf-8");

$domain = "https://kurta.luxeloom.co/";

// Load products
$productsFile = __DIR__ . '/products.csv';
$products = [];
if (file_exists($productsFile) && ($handle = fopen($productsFile, "r")) !== FALSE) {
    $headers = fgetcsv($handle, 1000, ",");
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($data) !== count($headers)) continue;
        $p = array_combine($headers, $data);
        $pSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $p['Title']), '-'));
        $products[] = $pSlug;
    }
    fclose($handle);
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>" . PHP_EOL;
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">" . PHP_EOL;

foreach ($products as $pSlug) {
    $loc = $domain . "product/" . $pSlug . ".html";
    echo "  <url>" . PHP_EOL;
    echo "    <loc>" . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>" . PHP_EOL;
    echo "    <changefreq>weekly</changefreq>" . PHP_EOL;
    echo "    <priority>0.7</priority>" . PHP_EOL;
    echo "  </url>" . PHP_EOL;
}

echo "</urlset>";
?>
