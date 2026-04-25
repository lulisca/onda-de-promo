<?php

declare(strict_types=1);

/*
 * Atualizador de ofertas para Hostinger (PHP + cron).
 *
 * Fluxo:
 * 1) Le os links em offers-sources.json
 * 2) Resolve redirecionamentos (amzn.to, meli.la etc)
 * 3) Tenta extrair titulo, imagem e preco via JSON-LD / Open Graph
 * 4) Regrava offers.json usado pelo frontend
 *
 * Observacao:
 * APIs oficiais de afiliado sao o caminho mais estavel a longo prazo.
 */

const SOURCE_FILE = __DIR__ . '/offers-sources.json';
const OUTPUT_FILE = __DIR__ . '/offers.json';
const REQUEST_TIMEOUT = 20;

function main(): void
{
    if (!file_exists(SOURCE_FILE)) {
        http_response_code(500);
        echo "Arquivo offers-sources.json nao encontrado.";
        return;
    }

    $sourcePayload = json_decode((string) file_get_contents(SOURCE_FILE), true);
    if (!is_array($sourcePayload) || !isset($sourcePayload['sources']) || !is_array($sourcePayload['sources'])) {
        http_response_code(500);
        echo "Formato invalido em offers-sources.json.";
        return;
    }

    $offers = [];

    foreach ($sourcePayload['sources'] as $source) {
        if (!is_array($source) || empty($source['id']) || empty($source['url'])) {
            continue;
        }

        $id = (string) $source['id'];
        $sourceUrl = (string) $source['url'];

        $page = fetchHtmlWithRedirects($sourceUrl);
        $finalUrl = $page['finalUrl'] ?? $sourceUrl;
        $store = detectStore($finalUrl);
        $badge = $store === 'amazon' ? 'AMZ' : 'ML';

        $extracted = [
            'title' => '',
            'image' => '',
            'price' => null,
            'listPrice' => null,
        ];

        if (!empty($page['html'])) {
            $extracted = extractProductData((string) $page['html']);
        }

        $title = trim((string) ($extracted['title'] ?? ''));
        if ($title === '') {
            $title = buildFallbackTitle($store, $sourceUrl);
        }

        $priceFinal = normalizePrice($extracted['price']);
        $priceOriginal = normalizePrice($extracted['listPrice']);

        if ($priceOriginal !== null && $priceFinal !== null && $priceOriginal < $priceFinal) {
            $priceOriginal = null;
        }

        $offers[] = [
            'id' => $id,
            'store' => $store,
            'badge' => $badge,
            'tag' => inferTag($title),
            'title' => $title,
            'image' => (string) ($extracted['image'] ?? ''),
            'affiliateUrl' => $sourceUrl,
            'url' => $finalUrl,
            'priceFinal' => $priceFinal,
            'priceFinalFormatted' => $priceFinal !== null ? formatMoneyBRL($priceFinal) : 'Consulte no site',
            'priceOriginal' => $priceOriginal,
            'priceOriginalFormatted' => $priceOriginal !== null ? formatMoneyBRL($priceOriginal) : '',
            'discountLabel' => buildDiscountLabel($priceOriginal, $priceFinal),
            'lastCheck' => gmdate('c'),
        ];
    }

    $output = [
        'updatedAt' => gmdate('c'),
        'offers' => $offers,
    ];

    $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        echo "Falha ao gerar JSON de saida.";
        return;
    }

    file_put_contents(OUTPUT_FILE, $json . PHP_EOL);

    header('Content-Type: application/json; charset=utf-8');
    echo $json;
}

function fetchHtmlWithRedirects(string $url): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['html' => '', 'finalUrl' => $url];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => REQUEST_TIMEOUT,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
        ],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ]);

    $html = curl_exec($ch);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    curl_close($ch);

    if (!is_string($html)) {
        $html = '';
    }

    return [
        'html' => $html,
        'finalUrl' => $finalUrl !== '' ? $finalUrl : $url,
    ];
}

function extractProductData(string $html): array
{
    $title = '';
    $image = '';
    $price = null;
    $listPrice = null;

    $jsonLdData = extractFromJsonLd($html);
    if ($jsonLdData !== null) {
        $title = (string) ($jsonLdData['title'] ?? '');
        $image = (string) ($jsonLdData['image'] ?? '');
        $price = $jsonLdData['price'] ?? null;
        $listPrice = $jsonLdData['listPrice'] ?? null;
    }

    if ($title === '' || $image === '') {
        $og = extractOpenGraph($html);
        if ($title === '') {
            $title = (string) ($og['title'] ?? '');
        }
        if ($image === '') {
            $image = (string) ($og['image'] ?? '');
        }
    }

    if ($price === null) {
        $price = extractPriceByRegex($html);
    }

    return [
        'title' => $title,
        'image' => $image,
        'price' => $price,
        'listPrice' => $listPrice,
    ];
}

function extractOpenGraph(string $html): array
{
    $result = ['title' => '', 'image' => ''];

    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $result['title'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $result['image'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $result;
}

function extractFromJsonLd(string $html): ?array
{
    if (!preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
        return null;
    }

    foreach ($matches[1] as $jsonText) {
        $decoded = json_decode(trim((string) $jsonText), true);
        if ($decoded === null) {
            continue;
        }

        $product = findProductNode($decoded);
        if ($product === null) {
            continue;
        }

        $image = '';
        if (isset($product['image'])) {
            if (is_string($product['image'])) {
                $image = $product['image'];
            } elseif (is_array($product['image']) && isset($product['image'][0]) && is_string($product['image'][0])) {
                $image = $product['image'][0];
            }
        }

        $offers = $product['offers'] ?? null;
        $price = null;
        $listPrice = null;

        if (is_array($offers)) {
            if (array_is_list($offers)) {
                $offers = $offers[0] ?? null;
            }

            if (is_array($offers)) {
                $price = $offers['price'] ?? ($offers['lowPrice'] ?? null);
                $listPrice = $offers['highPrice'] ?? null;

                if ($listPrice === null && isset($offers['priceSpecification']) && is_array($offers['priceSpecification'])) {
                    $spec = $offers['priceSpecification'];
                    if (isset($spec['price']) && is_numeric($spec['price'])) {
                        $listPrice = (float) $spec['price'];
                    }
                }
            }
        }

        return [
            'title' => (string) ($product['name'] ?? ''),
            'image' => (string) $image,
            'price' => $price,
            'listPrice' => $listPrice,
        ];
    }

    return null;
}

function findProductNode($node): ?array
{
    if (!is_array($node)) {
        return null;
    }

    if (isset($node['@type'])) {
        $type = $node['@type'];
        if ((is_string($type) && stripos($type, 'Product') !== false)
            || (is_array($type) && in_array('Product', $type, true))) {
            return $node;
        }
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            $found = findProductNode($value);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

function extractPriceByRegex(string $html): ?float
{
    if (preg_match('/R\$\s*([0-9\.]+,[0-9]{2})/', $html, $m)) {
        return normalizePrice($m[1]);
    }

    return null;
}

function normalizePrice($raw): ?float
{
    if ($raw === null) {
        return null;
    }

    if (is_int($raw) || is_float($raw)) {
        return (float) $raw;
    }

    $text = trim((string) $raw);
    if ($text === '') {
        return null;
    }

    $text = preg_replace('/[^0-9,\.]/', '', $text) ?? '';
    if ($text === '') {
        return null;
    }

    if (str_contains($text, ',') && str_contains($text, '.')) {
        $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);
    } elseif (str_contains($text, ',')) {
        $text = str_replace(',', '.', $text);
    }

    if (!is_numeric($text)) {
        return null;
    }

    return (float) $text;
}

function formatMoneyBRL(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function buildDiscountLabel(?float $original, ?float $final): string
{
    if ($original === null || $final === null || $original <= 0 || $final >= $original) {
        return '';
    }

    $discount = (int) round((1 - ($final / $original)) * 100);
    return '-' . $discount . '%';
}

function detectStore(string $url): string
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    $host = strtolower($host);

    if (str_contains($host, 'amazon.')) {
        return 'amazon';
    }

    return 'mercadolivre';
}

function inferTag(string $title): string
{
    $value = mb_strtolower($title, 'UTF-8');

    if (str_contains($value, 'notebook') || str_contains($value, 'laptop')) {
        return 'Notebook';
    }
    if (str_contains($value, 'celular') || str_contains($value, 'smartphone') || str_contains($value, 'galaxy')) {
        return 'Celular';
    }
    if (str_contains($value, 'ssd')) {
        return 'Armazenamento';
    }

    return 'Oferta';
}

function buildFallbackTitle(string $store, string $sourceUrl): string
{
    return $store === 'amazon'
        ? 'Oferta Amazon (' . $sourceUrl . ')'
        : 'Oferta Mercado Livre (' . $sourceUrl . ')';
}

main();
