<?php
// ================================
// CONFIG
// ================================
$domain = "https://kurta.luxeloom.co/";
$brand  = "LuxeLoom";

// Determine base path for URLs (handles subdirectories on localhost)
$base_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if (substr($base_path, -1) !== '/') {
    $base_path .= '/';
}

// Parse request URI for clean URLs (fail-safe routing)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '';
// Strip base path prefix if present
if ($base_path !== '/' && strpos($requestPath, $base_path) === 0) {
    $requestPath = substr($requestPath, strlen($base_path));
} else {
    $requestPath = ltrim($requestPath, '/');
}

// Extract slug or product parameter if not already provided via $_GET
if (empty($_GET['product']) && empty($_GET['slug'])) {
    if (preg_match('/^product\/([a-zA-Z0-9\-]+)\.html$/i', $requestPath, $matches)) {
        $_GET['product'] = $matches[1];
    } elseif (preg_match('/^([a-zA-Z0-9\-]+)\.html$/i', $requestPath, $matches)) {
        $_GET['slug'] = $matches[1];
    }
}

// ================================
// LOAD KEYWORDS
// ================================
$keywordsFile = __DIR__ . '/keywords.txt';
$keywords = file_exists($keywordsFile)
    ? file($keywordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];

// Convert keywords to slugs
$keywordMap = [];
foreach ($keywords as $kw) {
    $slug_val = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $kw), '-'));
    $keywordMap[$slug_val] = trim($kw);
}

// ================================
// LOAD PRODUCTS
// ================================
$productsFile = __DIR__ . '/products.csv';
$products = [];
$selectedProduct = null;

if (file_exists($productsFile) && ($handle = fopen($productsFile, "r")) !== FALSE) {
    $headers = fgetcsv($handle, 1000, ",");
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($data) !== count($headers)) continue;
        $p = array_combine($headers, $data);
        
        $pSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $p['Title']), '-'));
        $p['slug'] = $pSlug;
        
        // Clean prices
        $sale = trim($p["Sale Price"] ?? '');
        $regular = trim($p["Regular Price"] ?? '');
        if ($sale === "") {
            $sale = $regular;
            $regular = "";
        }
        $p['sale_clean'] = $sale;
        $p['regular_clean'] = $regular;
        
        $products[$pSlug] = $p;
    }
    fclose($handle);
}

// Helper to clean price for schema validation
function clean_price_for_schema($priceStr) {
    $priceStr = trim($priceStr);

    // Remove everything except numbers and decimal point
    $priceStr = preg_replace('/[^0-9.]/', '', $priceStr);

    // Remove leading dots
    $priceStr = ltrim($priceStr, '.');

    // Convert to valid decimal
    return number_format((float)$priceStr, 2, '.', '');
}

// Detect if viewing a product
$productSlug = $_GET['product'] ?? '';
if ($productSlug !== '' && isset($products[$productSlug])) {
    $selectedProduct = $products[$productSlug];
}

// ================================
// DETECT PAGE
// ================================
$slug = $_GET['slug'] ?? '';

if ($selectedProduct) {
    // Product Page Meta
    $isHomepage = false;
    $isProductPage = true;
    $keyword = $selectedProduct['Title'];
    $canonical = $domain . "product/" . $selectedProduct['slug'] . ".html";
    $current_slug = $selectedProduct['slug'];
    $title = $selectedProduct['Title'] . " - Buy Online | " . $brand;
    $description = "Buy " . $selectedProduct['Title'] . " online in India at best price. Price: " . $selectedProduct['sale_clean'] . ". Premium quality, fast delivery.";
    
    $schemaProductName = $selectedProduct['Title'];
    $schemaProductImage = $selectedProduct['Image'];
$schemaProductPrice = (string)((float)clean_price_for_schema($selectedProduct['sale_clean']));
} else {
    // Keyword Page Meta / Homepage
    $isProductPage = false;
    
    // Pick the first product as a representative product for keyword schema fallbacks
    $repProduct = reset($products);
    
    if ($slug === '' || !isset($keywordMap[$slug])) {
        // HOMEPAGE
        $isHomepage = true;
        $keyword = "Premium Ethnic Wear & Kurtas";
        $current_slug = "";
        $canonical = $domain;
        $title = "LuxeLoom - Premium Ethnic Wear & Designer Kurtas Online";
        $description = "Explore LuxeLoom's premium collection of cotton kurtas, rayon co-ord sets, and designer ethnic wear. Buy online in India with fast delivery.";
        
        $schemaProductName = "LuxeLoom Premium Ethnic Wear Collection";
        $schemaProductImage = $repProduct ? $repProduct['Image'] : "https://images.unsplash.com/photo-1610030469983-98e550d6193c?w=800&auto=format&fit=crop&q=80";
        $schemaProductPrice = $repProduct ? clean_price_for_schema($repProduct['sale_clean']) : "1594";
    } else {
        // KEYWORD LANDING PAGE
        $isHomepage = false;
        $keyword = $keywordMap[$slug];
        $canonical = $domain . $slug . ".html";
        $current_slug = $slug;
        $title = "Buy " . $keyword . " Online - Best Prices | " . $brand;
        $description = "Get the best deals on " . strtolower($keyword) . " online. Premium quality fabrics, handcrafted designs, and fast shipping across India.";
        
        $schemaProductName = $keyword;
        $schemaProductImage = $repProduct ? $repProduct['Image'] : "https://images.unsplash.com/photo-1610030469983-98e550d6193c?w=800&auto=format&fit=crop&q=80";
        $schemaProductPrice = $repProduct ? clean_price_for_schema($repProduct['sale_clean']) : "1594";
    }
}

// ================================
// RELEVANCE ENGINE: SORT PRODUCTS BY KEYWORD MATCH
// ================================
$orderedProducts = $products; // default
if (!$isProductPage && !$isHomepage && !empty($keyword)) {
    $keywordTerms = explode(' ', strtolower(preg_replace('/[^a-z0-9\s]/i', '', $keyword)));
    $stopWords = ['and', 'for', 'in', 'with', 'the', 'best', 'buy', 'online', 'of', 'at', 'to', 'under', 'sets', 'set', 'design', 'latest', 'cheap', 'price', 'brands', 'brand'];
    $searchTerms = array_filter($keywordTerms, function($term) use ($stopWords) {
        return strlen($term) > 2 && !in_array($term, $stopWords);
    });

    if (!empty($searchTerms)) {
        $scoredProducts = [];
        foreach ($products as $pKey => $p) {
            $score = 0;
            $pTitle = strtolower($p['Title']);
            foreach ($searchTerms as $term) {
                if (strpos($pTitle, $term) !== false) {
                    $score += 10;
                    if (preg_match('/\b' . preg_quote($term, '/') . '\b/', $pTitle)) {
                        $score += 5;
                    }
                }
            }
            if ($score > 0) {
                $p['relevance_score'] = $score;
                $scoredProducts[] = $p;
            }
        }

        if (!empty($scoredProducts)) {
            // Sort by score descending
            usort($scoredProducts, function($a, $b) {
                return $b['relevance_score'] <=> $a['relevance_score'];
            });
            
            // Collect non-matching products
            $nonMatching = array_filter($products, function($p) use ($scoredProducts) {
                foreach ($scoredProducts as $sp) {
                    if ($sp['slug'] === $p['slug']) return false;
                }
                return true;
            });
            
            $orderedProducts = array_merge($scoredProducts, $nonMatching);
        }
    }
}

// ================================
// SEEDED RANDOM CRAWL CONTENT (REVIEWS & FAQs VARIATIONS)
// ================================
if ($current_slug !== '') {
    srand(crc32($current_slug));
} else {
    srand(crc32('homepage'));
}

$reviewPool = [
    [
        "author" => "Ananya Sharma",
        "body" => "Best ethnic wear I bought online. The fabric details are exquisite, perfect for weddings.",
        "rating" => 5
    ],
    [
        "author" => "Meera Sengupta",
        "body" => "The stitching quality is highly premium. Fits perfectly and looks very elegant.",
        "rating" => 5
    ],
    [
        "author" => "Aarav Kapoor",
        "body" => "Super soft fabric, extremely comfortable. Fast shipping to Mumbai.",
        "rating" => 5
    ],
    [
        "author" => "Priya Nair",
        "body" => "Beautiful colors and elegant designs. Perfect fit as per the size chart.",
        "rating" => 4
    ],
    [
        "author" => "Rohan Verma",
        "body" => "Excellent value for money. Handcrafted feel and rich textures.",
        "rating" => 5
    ],
    [
        "author" => "Divya Iyer",
        "body" => "Stunning embroidery and gorgeous fall. Got so many compliments!",
        "rating" => 5
    ]
];

// Pick 2 reviews randomly based on seed
$selectedReviewKeys = (array) array_rand($reviewPool, 2);
$pageReviews = [];
foreach ($selectedReviewKeys as $rk) {
    $pageReviews[] = $reviewPool[$rk];
}

// Seeded FAQ pool
$faqItems = [
    [
        "question" => "Is " . strtolower($keyword) . " suitable for festive occasions?",
        "answer" => "Yes, our " . strtolower($keyword) . " collection features premium hand-crafted detailing perfect for weddings, festivals, and special traditional celebrations."
    ],
    [
        "question" => "What is the delivery time within India?",
        "answer" => "We deliver across India within 2 to 5 business days with free express shipping on all orders."
    ],
    [
        "question" => "Do you offer cash on delivery (COD) or easy returns?",
        "answer" => "Yes, we support secure online payments, cash on delivery (COD) across India, and offer hassle-free 7-day returns if the fit isn't perfect."
    ]
];

// Reset random seed to default
srand();

// Escape output
function e($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// ================================
// DYNAMIC SCHEMA GENERATION
// ================================
$schemaGraph = [];

// 1. WebSite Schema (with SearchBox)
$schemaGraph[] = [
    "@type" => "WebSite",
    "@id" => $domain . "#website",
    "url" => $domain,
    "name" => $brand,
    "description" => "Premium designer ethnic wear & kurtas online",
    "potentialAction" => [
        "@type" => "SearchAction",
        "target" => [
            "@type" => "EntryPoint",
            "urlTemplate" => $domain . "?search={search_term_string}"
        ],
        "query-input" => "required name=search_term_string"
    ]
];

// 2. Organization Schema
$schemaGraph[] = [
    "@type" => "Organization",
    "@id" => $domain . "#organization",
    "name" => $brand,
    "url" => $domain,
    "logo" => [
        "@type" => "ImageObject",
        "@id" => $domain . "#logo",
        "url" => "https://images.unsplash.com/photo-1610030469983-98e550d6193c?w=120&auto=format&fit=crop&q=80",
        "caption" => $brand
    ],
    "contactPoint" => [
        "@type" => "ContactPoint",
        "telephone" => "+91-9999999999",
        "contactType" => "customer service",
        "areaServed" => "IN",
        "availableLanguage" => ["en", "Hindi"]
    ]
];

// 3. BreadcrumbList Schema
$breadcrumbItems = [];
$breadcrumbItems[] = [
    "@type" => "ListItem",
    "position" => 1,
    "name" => "Home",
    "item" => rtrim($domain, "/")
];

if (!$isHomepage) {
    $breadcrumbItems[] = [
        "@type" => "ListItem",
        "position" => 2,
        "name" => "Kurtas",
        "item" => rtrim($domain, "/") . "/kurtas"
    ];
    $breadcrumbItems[] = [
        "@type" => "ListItem",
        "position" => 3,
        "name" => $keyword,
        "item" => $canonical
    ];
}

$schemaGraph[] = [
    "@type" => "BreadcrumbList",
    "@id" => $canonical . "#breadcrumb",
    "itemListElement" => $breadcrumbItems
];

// 4. Product Schema (To get Rich Star rating snippets in Google)
$skuCode = $current_slug ?: "luxeloom-ethnic-wear";
$productOffer = [
    "@type" => "Offer",
    "url" => $canonical,
    "priceCurrency" => "INR",
    "price" => $schemaProductPrice,
    "priceValidUntil" => "2027-12-31",
    "availability" => "https://schema.org/InStock",
    "itemCondition" => "https://schema.org/NewCondition",
    "seller" => [
        "@type" => "Organization",
        "name" => $brand
    ],
    "shippingDetails" => [
        "@type" => "OfferShippingDetails",
        "shippingRate" => [
            "@type" => "MonetaryAmount",
            "value" => "0",
            "currency" => "INR"
        ],
        "shippingDestination" => [
            "@type" => "DefinedRegion",
            "addressCountry" => "IN"
        ],
        "deliveryTime" => [
            "@type" => "ShippingDeliveryTime",
            "handlingTime" => [
                "@type" => "QuantitativeValue",
                "minValue" => 0,
                "maxValue" => 1,
                "unitCode" => "DAY"
            ],
            "transitTime" => [
                "@type" => "ShippingDeliveryTime",
                "minValue" => 2,
                "maxValue" => 5,
                "unitCode" => "DAY"
            ]
        ]
    ],
    "hasMerchantReturnPolicy" => [
        "@type" => "MerchantReturnPolicy",
        "applicableCountry" => "IN",
        "returnPolicyCategory" => "https://schema.org/MerchantReturnFiniteReturnPeriod",
        "merchantReturnDays" => 7,
        "returnMethod" => "https://schema.org/ReturnByMail",
        "returnFees" => "https://schema.org/FreeReturn"
    ]
];

$schemaGraph[] = [
    "@type" => "Product",
    "@id" => $canonical . "#product",
    "name" => $schemaProductName,
    "image" => [$schemaProductImage],
    "description" => $description,
    "sku" => $skuCode,
    "mpn" => $skuCode,
    "brand" => [
        "@type" => "Brand",
        "name" => $brand
    ],
    "aggregateRating" => [
        "@type" => "AggregateRating",
        "ratingValue" => 4.8,
        "reviewCount" => 1240
    ],
    "offers" => $productOffer,
    "review" => [
        [
            "@type" => "Review",
            "author" => [
                "@type" => "Person",
                "name" => $pageReviews[0]['author']
            ],
            "reviewRating" => [
                "@type" => "Rating",
                "ratingValue" => $pageReviews[0]['rating'],
                "bestRating" => 5
            ],
            "reviewBody" => $pageReviews[0]['body']
        ],
        [
            "@type" => "Review",
            "author" => [
                "@type" => "Person",
                "name" => $pageReviews[1]['author']
            ],
            "reviewRating" => [
                "@type" => "Rating",
                "ratingValue" => $pageReviews[1]['rating'],
                "bestRating" => 5
            ],
            "reviewBody" => $pageReviews[1]['body']
        ]
    ]
];

// 5. FAQ Page Schema (For Rich FAQ dropdowns in search results)
$schemaGraph[] = [
    "@type" => "FAQPage",
    "@id" => $canonical . "#faq",
    "mainEntity" => [
        [
            "@type" => "Question",
            "name" => $faqItems[0]['question'],
            "acceptedAnswer" => [
                "@type" => "Answer",
                "text" => $faqItems[0]['answer']
            ]
        ],
        [
            "@type" => "Question",
            "name" => $faqItems[1]['question'],
            "acceptedAnswer" => [
                "@type" => "Answer",
                "text" => $faqItems[1]['answer']
            ]
        ],
        [
            "@type" => "Question",
            "name" => $faqItems[2]['question'],
            "acceptedAnswer" => [
                "@type" => "Answer",
                "text" => $faqItems[2]['answer']
            ]
        ]
    ]
];

$fullSchemaJSON = json_encode([
    "@context" => "https://schema.org",
    "@graph" => $schemaGraph
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
<!DOCTYPE html>

<html class="light" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= e($title) ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Noto+Serif:ital,wght@0,400;0,700;1,400&amp;family=Manrope:wght@400;500;600;700&amp;display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
        rel="stylesheet" />
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "on-tertiary-container": "#b7a689",
                        "on-surface-variant": "#584141",
                        "primary-fixed-dim": "#ffb3b5",
                        "tertiary": "#302611",
                        "surface-container": "#efeeea",
                        "inverse-on-surface": "#f2f0ed",
                        "background": "#fbf9f5",
                        "secondary-fixed-dim": "#e9c176",
                        "primary": "#570013",
                        "surface-tint": "#af2b3e",
                        "error": "#ba1a1a",
                        "on-error-container": "#93000a",
                        "surface": "#fbf9f5",
                        "outline-variant": "#e0bfbf",
                        "inverse-primary": "#ffb3b5",
                        "surface-container-lowest": "#ffffff",
                        "on-secondary-fixed": "#261900",
                        "on-tertiary": "#ffffff",
                        "surface-dim": "#dbdad6",
                        "on-surface": "#1b1c1a",
                        "secondary-fixed": "#ffdea5",
                        "surface-variant": "#e4e2de",
                        "on-primary-container": "#ff828a",
                        "on-secondary": "#ffffff",
                        "primary-fixed": "#ffdada",
                        "on-error": "#ffffff",
                        "on-primary-fixed-variant": "#8e0f28",
                        "on-background": "#1b1c1a",
                        "on-primary": "#ffffff",
                        "surface-bright": "#fbf9f5",
                        "surface-container-low": "#f5f3ef",
                        "outline": "#8c7071",
                        "on-tertiary-fixed-variant": "#51452d",
                        "inverse-surface": "#30312e",
                        "on-secondary-fixed-variant": "#5d4201",
                        "tertiary-fixed": "#f3e0c0",
                        "primary-container": "#800020",
                        "surface-container-highest": "#e4e2de",
                        "secondary-container": "#fed488",
                        "on-primary-fixed": "#40000b",
                        "error-container": "#ffdad6",
                        "secondary": "#775a19",
                        "tertiary-container": "#473c25",
                        "on-secondary-container": "#785a1a",
                        "on-tertiary-fixed": "#231a06",
                        "tertiary-fixed-dim": "#d6c4a5",
                        "surface-container-high": "#eae8e4"
                    },
                    "borderRadius": {
                        "DEFAULT": "0.125rem",
                        "lg": "0.25rem",
                        "xl": "0.5rem",
                        "full": "0.75rem"
                    },
                    "fontFamily": {
                        "headline": ["Noto Serif"],
                        "body": ["Manrope"],
                        "label": ["Manrope"]
                    }
                },
            },
        }
    </script>
    <style>
        body {
            font-family: 'Manrope', sans-serif;
            background-color: #fbf9f5;
            color: #1b1c1a;
        }

        .font-serif {
            font-family: 'Noto Serif', serif;
        }

        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 300, 'GRAD' 0, 'opsz' 24;
        }

        .gradient-primary {
            background: linear-gradient(45deg, #570013, #800020);
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
    </style>
    <link rel="canonical" href="<?= e($canonical) ?>" />
    <meta name="description" content="<?= e($description) ?>">
    <script type="application/ld+json">
<?= $fullSchemaJSON ?>
    </script>
</head>

<body class="bg-surface text-on-surface">
    <!-- TopAppBar -->
    <nav class="fixed top-0 w-full z-50 bg-stone-50/80 backdrop-blur-md shadow-sm">
        <div class="flex items-center justify-between px-6 h-16 w-full max-w-7xl mx-auto">
            <div class="flex items-center gap-4">
                <a href="<?= $base_path ?>" class="text-2xl font-bold tracking-tighter text-rose-900 font-serif">luxeloom</a>
            </div>
            <div class="flex items-center gap-4">
                <span class="material-symbols-outlined text-rose-900 cursor-pointer">search</span>
            </div>
        </div>
    </nav>
    <main class="pt-16 pb-24">
        <!-- Product Details or Product Grid -->

        <?php if ($isProductPage): ?>
        <!-- 🛍️ Product Detail Section -->
        <section class="max-w-7xl mx-auto px-4 py-8">
            <!-- Breadcrumbs -->
            <nav class="text-xs text-gray-500 mb-6 flex items-center gap-1 flex-wrap">
                <a href="<?= $base_path ?>" class="hover:text-black">Home</a>
                <span>›</span>
                <a href="<?= $base_path ?>" class="hover:text-black">Kurtas</a>
                <span>›</span>
                <span class="text-gray-700 font-medium"><?= e($selectedProduct['Title']) ?></span>
            </nav>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 md:gap-16">
                <!-- Product Image -->
                <div class="aspect-[3/4] bg-surface-container-low rounded-xl overflow-hidden shadow-sm">
                    <img alt="<?= e($selectedProduct['Title']) ?>" 
                         src="<?= e($selectedProduct['Image']) ?>" 
                         class="w-full h-full object-cover">
                </div>

                <!-- Product Details -->
                <div class="flex flex-col justify-center">
                    <span class="inline-block bg-rose-50 text-rose-800 text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded w-fit mb-4">
                        Premium Quality
                    </span>
                    <h1 class="text-2xl md:text-4xl font-serif font-bold text-gray-900 leading-tight mb-3">
                        <?= e($selectedProduct['Title']) ?>
                    </h1>

                    <!-- ⭐ Rating -->
                    <div class="flex items-center gap-2 mb-6">
                        <div class="flex text-secondary">
                            <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
                            <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
                            <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
                            <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
                            <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
                        </div>
                        <span class="text-xs font-semibold text-gray-800">4.9</span>
                        <span class="text-xs text-gray-400">|</span>
                        <span class="text-xs text-gray-500 hover:underline cursor-pointer">1,240 verified reviews</span>
                    </div>

                    <!-- 💰 Price -->
                    <div class="flex items-baseline gap-3 mb-6">
                        <span class="text-3xl font-bold text-primary"><?= e($selectedProduct['sale_clean']) ?></span>
                        <?php if ($selectedProduct['regular_clean']): ?>
                            <span class="text-lg text-stone-400 line-through"><?= e($selectedProduct['regular_clean']) ?></span>
                            <span class="text-xs font-bold text-green-700 bg-green-50 px-2 py-0.5 rounded">
                                SAVE NOW
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Description -->
                    <div class="space-y-4 text-stone-600 text-sm leading-relaxed mb-8">
                        <p>
                            Experience unmatched comfort and style with the <?= e($selectedProduct['Title']) ?>. Made from premium, lightweight fabric, it's designed to keep you feeling fresh and elegant all day long.
                        </p>
                        <p>
                            Perfect for casual wear, festive gatherings, or workspace styling. Pair it with your favorite accessories to complete the look.
                        </p>
                    </div>

                    <!-- CTA Buttons -->
                    <div class="flex flex-col sm:flex-row gap-4 mb-8">
                        <a href="<?= e($selectedProduct['Product Link']) ?>" target="_blank" 
                           class="flex-1 bg-primary text-white text-center py-4 px-8 font-bold uppercase text-xs tracking-widest hover:bg-primary-container transition shadow-md rounded-lg">
                            Buy From Official Store
                        </a>

                    </div>

                    <!-- Features Info -->
                    <div class="grid grid-cols-2 gap-4 pt-6 border-t border-stone-100">
                        <div class="flex items-center gap-2.5 text-xs text-stone-600">
                            <span class="material-symbols-outlined text-rose-900">verified</span>
                            <span>100% Authentic Fabric</span>
                        </div>
                        <div class="flex items-center gap-2.5 text-xs text-stone-600">
                            <span class="material-symbols-outlined text-rose-900">local_shipping</span>
                            <span>Fast Delivery India</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 🔄 You May Also Like Section (Random Products) -->
        <section class="max-w-7xl mx-auto px-4 py-16 border-t border-stone-100">
            <h2 class="text-2xl font-serif text-gray-900 mb-8 font-bold">You May Also Like</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <?php
                $related = array_filter($products, function($p) use ($selectedProduct) {
                    return $p['slug'] !== $selectedProduct['slug'];
                });
                if (!empty($related)) {
                    $keys = array_rand($related, min(4, count($related)));
                    if (!is_array($keys)) $keys = [$keys];
                    foreach ($keys as $k) {
                        $rp = $related[$k];
                        $rpSale = htmlspecialchars($rp['sale_clean'], ENT_QUOTES, 'UTF-8');
                        $rpReg = htmlspecialchars($rp['regular_clean'], ENT_QUOTES, 'UTF-8');
                        $rpRegHtml = $rpReg ? '<span class="text-xs text-stone-400 line-through">' . $rpReg . '</span>' : '';
                        $rpTitle = htmlspecialchars($rp['Title'], ENT_QUOTES, 'UTF-8');
                        $rpImage = htmlspecialchars($rp['Image'], ENT_QUOTES, 'UTF-8');
                        $rpLink = $base_path . "product/" . $rp['slug'] . ".html";
                        echo <<<HTML
<a href="{$rpLink}" class="group flex flex-col">
  <div class="aspect-[3/4] bg-surface-container-low rounded-lg overflow-hidden relative mb-3">
    <img alt="{$rpTitle}" src="{$rpImage}" loading="lazy" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
  </div>
  <div class="px-1">
    <h3 class="text-sm font-serif text-on-surface mb-0.5 truncate">{$rpTitle}</h3>
    <div class="flex items-center gap-1 mb-1.5">
      <div class="flex text-secondary scale-75 origin-left">
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
      </div>
      <span class="text-[9px] text-stone-500">4.9</span>
    </div>
    <div class="flex justify-between items-center">
      <div class="flex items-baseline gap-2">
        <span class="text-lg font-bold text-primary">{$rpSale}</span>
        {$rpRegHtml}
      </div>
    </div>
  </div>
</a>
HTML;
                    }
                }
                ?>
            </div>
        </section>

        <?php else: ?>
        <!-- 🏠 Standard Keyword/Homepage Section -->
        <section class="max-w-7xl mx-auto px-4 py-6">
            <!-- 🔗 Breadcrumb -->
            <?php if (!$isHomepage): ?>
            <nav class="text-xs text-gray-500 mb-2 flex items-center gap-1 flex-wrap">
                <a href="<?= $base_path ?>" class="hover:text-black">Home</a>
                <span>›</span>
                <a href="<?= $base_path ?>" class="hover:text-black">Kurtas</a>
                <span>›</span>
                <span class="text-gray-700 font-medium"><?= e($keyword) ?></span>
            </nav>
            <?php endif; ?>
            <!-- 🧵 Title -->
            <h1 class="text-xl md:text-2xl font-semibold text-gray-900 leading-snug">
                <?= e($keyword) ?>
            </h1>
        </section>

        <section class="max-w-7xl mx-auto px-4 py-8">
            <div id="productGrid" class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
                <?php
                if (!empty($orderedProducts)) {
                    foreach ($orderedProducts as $p) {
                        $sale = htmlspecialchars($p['sale_clean'], ENT_QUOTES, 'UTF-8');
                        $regular = htmlspecialchars($p['regular_clean'], ENT_QUOTES, 'UTF-8');
                        $regularHtml = $regular ? '<span class="text-xs text-stone-400 line-through">' . $regular . '</span>' : '';
                        $title = htmlspecialchars($p['Title'], ENT_QUOTES, 'UTF-8');
                        $image = htmlspecialchars($p['Image'], ENT_QUOTES, 'UTF-8');
                        $link = $base_path . "product/" . $p['slug'] . ".html";
                        
                        // Dynamic badge generation to avoid duplicate layout penalties
                        $badgeHtml = '';
                        if (!$isHomepage && isset($p['relevance_score']) && $p['relevance_score'] > 0) {
                            $badgeHtml = '<span class="absolute top-2 left-2 bg-primary text-white text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded shadow-sm z-10">' . e($keyword) . ' Pick</span>';
                        }
                        
                        echo <<<HTML
<a href="{$link}" class="group flex flex-col">
  <div class="aspect-[3/4] bg-surface-container-low rounded-lg overflow-hidden relative mb-3">
    {$badgeHtml}
    <img alt="{$title}" src="{$image}" loading="lazy" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
  </div>
  <div class="px-1">
    <h3 class="text-sm font-serif text-on-surface mb-0.5 truncate">{$title}</h3>
    <!-- ⭐ Rating -->
    <div class="flex items-center gap-1 mb-1.5">
      <div class="flex text-secondary scale-75 origin-left">
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1;">star</span>
      </div>
      <span class="text-[9px] text-stone-500">4.9</span>
    </div>
    <!-- 💰 Price -->
    <div class="flex justify-between items-center">
      <div class="flex items-baseline gap-2">
        <span class="text-lg font-bold text-primary">{$sale}</span>
        {$regularHtml}
      </div>
    </div>
  </div>
</a>
HTML;
                    }
                }
                ?>
            </div>
        </section>
        
        <?php if ($isHomepage): ?>
        <!-- 📂 Popular Collections Directory (For SEO Crawling & Direct Traffic) -->
        <section class="max-w-7xl mx-auto px-4 py-8 border-t border-stone-100">
            <h2 class="text-xl md:text-2xl font-serif text-gray-900 mb-6 font-bold">Shop by Popular Styles</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php
                $dirCount = 0;
                foreach ($keywordMap as $kSlug => $kName) {
                    $kLink = $base_path . $kSlug . ".html";
                    $kEscaped = htmlspecialchars($kName, ENT_QUOTES, 'UTF-8');
                    // Find a product from the CSV that matches some words of this keyword to display a nice thumbnail, or use a default
                    $thumbImage = "https://images.unsplash.com/photo-1610030469983-98e550d6193c?w=400&auto=format&fit=crop&q=60";
                    foreach ($products as $p) {
                        // Check if any product title matches keyword terms
                        $terms = explode(' ', strtolower($kName));
                        $matched = true;
                        foreach ($terms as $t) {
                            if (strlen($t) > 3 && strpos(strtolower($p['Title']), $t) === false) {
                                $matched = false;
                                break;
                            }
                        }
                        if ($matched) {
                            $thumbImage = $p['Image'];
                            break;
                        }
                    }
                    
                    echo <<<HTML
<a href="{$kLink}" class="group relative flex items-center justify-center h-28 rounded-lg overflow-hidden bg-stone-900 shadow-sm">
  <img src="{$thumbImage}" alt="{$kEscaped}" loading="lazy" class="absolute inset-0 w-full h-full object-cover opacity-60 group-hover:scale-105 transition-transform duration-500">
  <div class="absolute inset-0 bg-gradient-to-t from-stone-950/80 via-stone-900/30 to-transparent"></div>
  <span class="relative z-10 text-white font-bold text-xs uppercase tracking-wider text-center px-3 font-serif">{$kEscaped}</span>
</a>
HTML;
                    $dirCount++;
                    if ($dirCount >= 12) break; // Display top 12 keywords on homepage
                }
                ?>
            </div>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <!-- 1. Customer Ratings & Reviews -->
        <section class="bg-surface-container-low py-16 px-6">
            <div class="max-w-7xl mx-auto">
                <h2 class="text-3xl font-serif text-primary mb-8 text-center">Customer Love</h2>
                <div class="flex flex-col items-center mb-12">
                    <span class="text-6xl font-bold text-primary">4.9</span>
                    <div class="flex text-secondary mt-2">
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">star</span>
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">star</span>
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">star</span>
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">star</span>
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">star</span>
                    </div>
                    <p class="text-stone-500 text-xs mt-2">Based on 1,240 verified reviews</p>
                </div>
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-stone-100">
                        <p class="text-on-surface-variant italic mb-4 leading-relaxed text-sm">"<?= e($pageReviews[0]['body']) ?>"</p>
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-stone-100 rounded-full flex items-center justify-center text-primary font-bold text-xs">
                                <?= e(substr($pageReviews[0]['author'], 0, 1)) ?>
                            </div>
                            <div>
                                <h4 class="font-bold text-xs"><?= e($pageReviews[0]['author']) ?></h4>
                                <p class="text-[10px] text-stone-400 uppercase tracking-tighter">Verified Buyer</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-stone-100">
                        <p class="text-on-surface-variant italic mb-4 leading-relaxed text-sm">"<?= e($pageReviews[1]['body']) ?>"</p>
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-stone-100 rounded-full flex items-center justify-center text-primary font-bold text-xs">
                                <?= e(substr($pageReviews[1]['author'], 0, 1)) ?>
                            </div>
                            <div>
                                <h4 class="font-bold text-xs"><?= e($pageReviews[1]['author']) ?></h4>
                                <p class="text-[10px] text-stone-400 uppercase tracking-tighter">Verified Buyer</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- 2. Frequently Asked Questions (FAQ) -->
        <section class="max-w-3xl mx-auto px-6 py-16">
            <h2 class="text-3xl font-serif text-center text-primary mb-10">Frequent Questions</h2>
            <div class="space-y-3">
                <?php foreach ($faqItems as $item): ?>
                <details
                    class="group bg-white p-5 rounded-lg border border-stone-100 open:border-primary/20 transition-all">
                    <summary
                        class="flex justify-between items-center cursor-pointer list-none font-bold text-on-surface text-sm">
                        <?= e($item['question']) ?>
                        <span
                            class="material-symbols-outlined text-primary group-open:rotate-180 transition-transform">expand_more</span>
                    </summary>
                    <p class="mt-4 text-on-surface-variant leading-relaxed text-xs">
                        <?= e($item['answer']) ?>
                    </p>
                </details>
                <?php endforeach; ?>
            </div>
        </section>
        <!-- 3. Long-form SEO Content Block -->

        <section class="bg-stone-900 text-stone-200 py-16 px-6">
            <div class="max-w-7xl mx-auto">

                <h2 class="text-3xl font-serif text-white mb-6">
                    Premium <?= e($keyword) ?> Collection in India
                </h2>

                <div class="space-y-4 text-stone-400 text-sm leading-relaxed">

                    <p>
                        Discover our exclusive range of <?= e(strtolower($keyword)) ?> designed for modern Indian fashion.
                        Each <?= e(strtolower($keyword)) ?> blends tradition with contemporary style.
                    </p>

                    <div>
                        <h3 class="text-white font-serif text-lg mb-2">Exquisite Fabrics</h3>
                        <p>
                            Our <?= e(strtolower($keyword)) ?> collection features Giza Cotton ensuring comfort and luxury feel.
                        </p>
                    </div>

                    <div>
                        <h3 class="text-white font-serif text-lg mb-2">Master Craftsmanship</h3>
                        <p>
                            Every <?= e(strtolower($keyword)) ?> includes Embroidery crafted by skilled artisans.
                        </p>
                    </div>

                    <!-- 🔥 INTERLINK GRID -->
                    <div class="mt-6">
                        <h3 class="text-white mb-3">Explore More Styles</h3>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            $linkCount = 0;
                            foreach ($keywordMap as $kSlug => $kName) {
                                // Don't link to the current page itself
                                if ($kSlug === $current_slug) continue;
                                
                                $kLink = $base_path . $kSlug . ".html";
                                $kEscaped = htmlspecialchars($kName, ENT_QUOTES, 'UTF-8');
                                echo '<a href="' . $kLink . '" class="px-3 py-1 bg-white/10 rounded-full text-xs hover:bg-white/20 transition">' . $kEscaped . '</a>';
                                
                                $linkCount++;
                                if ($linkCount >= 20) break; // Limit to 20 links for clean layout & SEO ratio
                            }
                            ?>
                        </div>
                    </div>

                    <p>
                        Buy <?= e(strtolower($keyword)) ?> online in India at best price with fast delivery.
                    </p>

                </div>
            </div>
        </section>

    </main>
    <!-- 4. Detailed Footer -->
    <footer class="bg-stone-100 w-full py-12 px-8 flex flex-col gap-8 justify-between items-start mb-16">
        <div class="max-w-xs">
            <span class="text-xl font-serif italic text-rose-950 mb-4 block">luxeloom</span>
            <p class="text-stone-600 text-xs mb-6 leading-relaxed">Modern kurthas for the Contemporary Woman. All rights
                reserved.</p>
            <div class="flex gap-4">
                <span
                    class="material-symbols-outlined text-stone-600 cursor-pointer hover:text-primary transition-colors">brand_awareness</span>
                <span
                    class="material-symbols-outlined text-stone-600 cursor-pointer hover:text-primary transition-colors">public</span>
                <span
                    class="material-symbols-outlined text-stone-600 cursor-pointer hover:text-primary transition-colors">group</span>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-12 w-full">
            <div>
                <h5 class="text-rose-950 font-bold text-[10px] uppercase tracking-widest mb-4">Discovery</h5>
                <ul class="space-y-3">
                    <li><a class="text-stone-600 hover:text-primary transition-colors text-xs" href="#">Heritage
                            Story</a></li>
                    <li><a class="text-stone-600 hover:text-primary transition-colors text-xs"
                            href="#">Sustainability</a></li>
                    <li><a class="text-stone-600 hover:text-primary transition-colors text-xs" href="#">Lookbook</a>
                    </li>
                    <li><a class="text-stone-600 hover:text-primary transition-colors text-xs underline decoration-primary/30 underline-offset-4"
                            href="#">Size Guide</a></li>
                </ul>
            </div>
            <div>
                <h5 class="text-rose-950 font-bold text-[10px] uppercase tracking-widest mb-4">Concierge</h5>
                <ul class="space-y-3">
                    <li><a class="text-stone-600 hover:text-primary transition-colors text-xs" href="#">Shipping &amp;
                            Returns</a></li>
                    <li><a class="text-stone-600 hover:text-primary transition-colors text-xs" href="#">Privacy
                            Policy</a></li>
                    <li><a class="text-stone-600 hover:text-primary transition-colors text-xs" href="#">Contact Us</a>
                    </li>
                    <li><a class="text-stone-600 hover:text-primary transition-colors text-xs" href="#">FAQs</a></li>
                </ul>
            </div>
        </div>
    </footer>
    
    <!-- 🛒 Sticky Bottom CTA Bar for Mobile Users (Mobile Conversion Booster) -->
    <?php if ($isProductPage): ?>
    <div id="stickyCtaBar" class="fixed bottom-0 left-0 right-0 z-40 bg-white/95 backdrop-blur-md border-t border-stone-200 p-3 flex gap-3 shadow-lg md:hidden transition-transform duration-300 translate-y-full">
        <a href="<?= e($selectedProduct['Product Link']) ?>" target="_blank" 
           class="flex-1 bg-primary text-white text-center py-3 font-bold uppercase text-[10px] tracking-widest rounded-lg flex items-center justify-center">
            Buy Now
        </a>

    </div>
    <script>
        window.addEventListener('scroll', function() {
            var ctaBar = document.getElementById('stickyCtaBar');
            if (ctaBar) {
                if (window.scrollY > 450) {
                    ctaBar.classList.remove('translate-y-full');
                } else {
                    ctaBar.classList.add('translate-y-full');
                }
            }
        });
    </script>
    <?php endif; ?>
</body>

</html>
