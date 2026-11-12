<?php
// Read-only balances of the user's OWN external wallets (MetaMask, Trust Wallet…) from public blockchains.
// Loyal never holds keys or funds here — it only reads public data by address.

// Public endpoints (no keys needed). Can be overridden in config.local.php with $CHAIN_RPC = [...].
function chain_rpc(): array {
    $d = [
        'eth'  => 'https://ethereum-rpc.publicnode.com',
        'bsc'  => 'https://bsc-rpc.publicnode.com',
        'base' => 'https://base-rpc.publicnode.com',
        'tron' => 'https://api.trongrid.io',
        'btc'  => 'https://mempool.space/api',
    ];
    return array_merge($d, is_array($GLOBALS['CHAIN_RPC'] ?? null) ? $GLOBALS['CHAIN_RPC'] : []);
}
// EVM networks and the tokens shown for them
const EVM_NETS = [
    'eth'  => ['name' => 'Ethereum',  'native' => ['sym' => 'ETH', 'name' => 'Ether', 'price' => 'ETH'],
               'tokens' => [['sym' => 'USDT', 'name' => 'Tether',   'addr' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'dec' => 6,  'price' => 'USDT'],
                            ['sym' => 'USDC', 'name' => 'USD Coin', 'addr' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'dec' => 6,  'price' => '$1']]],
    'bsc'  => ['name' => 'BNB Chain', 'native' => ['sym' => 'BNB', 'name' => 'BNB', 'price' => 'BNB'],
               'tokens' => [['sym' => 'USDT', 'name' => 'Tether',   'addr' => '0x55d398326f99059fF775485246999027B3197955', 'dec' => 18, 'price' => 'USDT'],
                            ['sym' => 'USDC', 'name' => 'USD Coin', 'addr' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 'dec' => 18, 'price' => '$1']]],
    'base' => ['name' => 'Base',      'native' => ['sym' => 'ETH', 'name' => 'Ether', 'price' => 'ETH'],
               'tokens' => [['sym' => 'USDC', 'name' => 'USD Coin', 'addr' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 'dec' => 6,  'price' => '$1']]],
];
const TRON_USDT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

function chain_detect(string $a): ?string {
    $a = trim($a);
    if (preg_match('~^0x[0-9a-fA-F]{40}$~', $a)) return 'evm';
    if (preg_match('~^T[1-9A-HJ-NP-Za-km-z]{33}$~', $a)) return 'tron';
    if (preg_match('~^(bc1[02-9ac-hj-np-z]{11,71}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$~', $a)) return 'btc';
    return null;
}

// big hex (wei) → float with decimals
function hex_amount(string $hex, int $dec): float {
    $hex = ltrim(preg_replace('~^0x~i', '', $hex), '0');
    if ($hex === '') return 0.0;
    $v = 0.0; foreach (str_split($hex) as $c) $v = $v * 16 + hexdec($c);
    return $v / (10 ** $dec);
}

// several HTTP requests in parallel; each: [url, postBody|null]
function chain_http(array $reqs, int $timeout = 7): array {
    $mh = curl_multi_init(); $hs = [];
    foreach ($reqs as $k => [$url, $body]) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Loyal/1.0)', CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        curl_multi_add_handle($mh, $ch); $hs[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $k => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $b = curl_multi_getcontent($ch);
        $out[$k] = ($code >= 200 && $code < 300 && $b) ? json_decode($b, true) : null;
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// → ['ok'=>bool, 'assets'=>[[sym,name,net,amount,usd]], 'total'=>usd]
function chain_balances(string $chain, string $addr): array {
    $cf = __DIR__ . '/../data/wb_' . md5($chain . $addr) . '.json';
    $c = is_file($cf) ? json_decode((string)file_get_contents($cf), true) : null;
    if ($c && time() - ($c['t'] ?? 0) < 60) return $c['d'];

    $rpc = chain_rpc(); $p = function_exists('prices') ? prices() : [];
    $price = fn($k) => $k === '$1' ? 1.0 : (float)($p[$k]['usd'] ?? 0);
    $assets = []; $ok = false;

    if ($chain === 'evm') {
        $reqs = [];
        foreach (EVM_NETS as $net => $n) {
            $batch = [['jsonrpc' => '2.0', 'id' => 0, 'method' => 'eth_getBalance', 'params' => [$addr, 'latest']]];
            foreach ($n['tokens'] as $i => $t)
                $batch[] = ['jsonrpc' => '2.0', 'id' => $i + 1, 'method' => 'eth_call', 'params' => [['to' => $t['addr'], 'data' => '0x70a08231' . str_pad(strtolower(substr($addr, 2)), 64, '0', STR_PAD_LEFT)], 'latest']];
            $reqs[$net] = [$rpc[$net], json_encode($batch)];
        }
        foreach (chain_http($reqs) as $net => $res) {
            if (!is_array($res)) continue;
            $ok = true; $by = [];
            foreach ($res as $r) if (isset($r['id'], $r['result'])) $by[$r['id']] = $r['result'];
            $n = EVM_NETS[$net];
            $list = [[$n['native'], 18, $by[0] ?? null]];
            foreach ($n['tokens'] as $i => $t) $list[] = [$t, $t['dec'], $by[$i + 1] ?? null];
            foreach ($list as [$t, $dec, $hex]) {
                if (!is_string($hex)) continue;
                $amt = hex_amount($hex, $dec);
                if ($amt <= 0) continue;
                $assets[] = ['sym' => $t['sym'], 'name' => $t['name'], 'net' => $n['name'], 'key' => $net, 'contract' => $t['addr'] ?? null, 'dec' => $dec,
                             'amount' => $amt, 'usd' => $amt * $price($t['price'])];
            }
        }
    }
    elseif ($chain === 'tron') {
        $r = chain_http(['a' => [$rpc['tron'] . '/v1/accounts/' . rawurlencode($addr), null]])['a'];
        if (is_array($r) && isset($r['data'])) {
            $ok = true; $acc = $r['data'][0] ?? [];
            $trx = ($acc['balance'] ?? 0) / 1e6;
            if ($trx > 0) $assets[] = ['sym' => 'TRX', 'name' => 'Tron', 'net' => 'Tron', 'key' => 'tron', 'contract' => null, 'dec' => 6, 'amount' => $trx, 'usd' => $trx * $price('TRX')];
            foreach ($acc['trc20'] ?? [] as $tk) foreach ($tk as $contract => $raw)
                if ($contract === TRON_USDT && ($u = (float)$raw / 1e6) > 0) $assets[] = ['sym' => 'USDT', 'name' => 'Tether', 'net' => 'Tron', 'key' => 'tron', 'contract' => TRON_USDT, 'dec' => 6, 'amount' => $u, 'usd' => $u * $price('USDT')];
        }
    }
    elseif ($chain === 'btc') {
        $r = chain_http(['a' => [$rpc['btc'] . '/address/' . rawurlencode($addr), null]])['a'];
        if (is_array($r) && isset($r['chain_stats'])) {
            $ok = true;
            $sat = ($r['chain_stats']['funded_txo_sum'] - $r['chain_stats']['spent_txo_sum']) + (($r['mempool_stats']['funded_txo_sum'] ?? 0) - ($r['mempool_stats']['spent_txo_sum'] ?? 0));
            $btc = $sat / 1e8;
            if ($btc > 0) $assets[] = ['sym' => 'BTC', 'name' => 'Bitcoin', 'net' => 'Bitcoin', 'key' => 'btc', 'contract' => null, 'dec' => 8, 'amount' => $btc, 'usd' => $btc * $price('BTC')];
        }
    }
    usort($assets, fn($a, $b) => $b['usd'] <=> $a['usd']);
    $d = ['ok' => $ok, 'assets' => $assets, 'total' => array_sum(array_column($assets, 'usd'))];
    if ($ok) @file_put_contents($cf, json_encode(['t' => time(), 'd' => $d]));
    elseif ($c) return $c['d'] + ['stale' => true];   // network problem — show the last known numbers
    return $d;
}
