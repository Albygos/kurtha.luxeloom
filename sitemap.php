<?php
header("Content-Type: application/xml; charset=utf-8");

$domain = "https://kurta.luxeloom.co/";

// Load keywords
$keywordsFile = __DIR__ . '/keywords.txt';
$keywords = file_exists($keywordsFile)
    ? file($keywordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];

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

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

// Homepage
echo '  <url>' . PHP_EOL;
echo '    <loc>' . htmlspecialchars($domain, ENT_XML1, 'UTF-8') . '</loc>' . PHP_EOL;
echo '    <changefreq>daily</changefreq>' . PHP_EOL;
echo '    <priority>1.0</priority>' . PHP_EOL;
echo '  </url>' . PHP_EOL;

// Keyword Pages
foreach ($keywords as $kw) {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $kw), '-'));
    $loc = $domain . $slug . ".html";
    echo '  <url>' . PHP_EOL;
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>' . PHP_EOL;
    echo '    <changefreq>weekly</changefreq>' . PHP_EOL;
    echo '    <priority>0.8</priority>' . PHP_EOL;
    echo '  </url>' . PHP_EOL;
}

// Product Pages
foreach ($products as $pSlug) {
    $loc = $domain . "product/" . $pSlug . ".html";
    echo '  <url>' . PHP_EOL;
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>' . PHP_EOL;
    echo '    <changefreq>weekly</changefreq>' . PHP_EOL;
    echo '    <priority>0.7</priority>' . PHP_EOL;
    echo '  </url>' . PHP_EOL;
}

echo '</urlset>';
