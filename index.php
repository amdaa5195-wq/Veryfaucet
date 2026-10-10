<?php
session_start();

// btc2faucet - redesigned Bitcoin faucet
// FaucetPay + Google reCAPTCHA

$api_key = 'e5d2574271713c69fc65901c1bb5235ea88ba564f7866043d32cb29cfa9f615d';
$recaptcha_secret = '6LfNXuctAAAAAFt6FTWPioIjm90tFhsbAzRslKtT';
$recaptcha_site_key = '6LfNXuctAAAAABXuGyrFJSAOIPYnqNVVIB8n0G-k';
$claim_interval = 300;
$payout_amount = 2;
$currency = 'BTC';
$launch_date = "2026-03-15"; // Change this to the real launch date of your faucet.
$stats_file = __DIR__ . '/faucet_stats.json';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function load_stats($file) {
    $default = ['claims' => 0, 'paid_satoshi' => 0];
    if (!is_file($file)) return $default;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return $default;
    return [
        'claims' => max(0, (int)($data['claims'] ?? 0)),
        'paid_satoshi' => max(0, (int)($data['paid_satoshi'] ?? 0))
    ];
}

function save_stats($file, $stats) {
    @file_put_contents($file, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
}

function clean_message($message) {
    return htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
}

$stats = load_stats($stats_file);
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Security verification failed. Please refresh the page and try again.';
        $message_type = 'error';
    } elseif (isset($_SESSION['last_claim']) && (time() - $_SESSION['last_claim'] < $claim_interval)) {
        $remaining = $claim_interval - (time() - $_SESSION['last_claim']);
        $message = "Please wait {$remaining} seconds before your next claim.";
        $message_type = 'warning';
    } else {
        $wallet = trim((string)($_POST['wallet'] ?? ''));
        $captcha = (string)($_POST['g-recaptcha-response'] ?? '');

        if ($wallet === '' || strlen($wallet) < 20 || strlen($wallet) > 120) {
            $message = 'Please enter your FaucetPay Bitcoin wallet address.';
            $message_type = 'error';
        } elseif ($captcha === '') {
            $message = 'Please complete the reCAPTCHA.';
            $message_type = 'error';
        } else {
            $verify = curl_init('https://www.google.com/recaptcha/api/siteverify');
            curl_setopt_array($verify, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'secret' => $recaptcha_secret,
                    'response' => $captcha,
                    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            $verify_raw = curl_exec($verify);
            curl_close($verify);
            $verify_result = json_decode((string)$verify_raw, true);

            if (!is_array($verify_result) || empty($verify_result['success'])) {
                $message = 'Captcha verification failed. Please try again.';
                $message_type = 'error';
            } else {
                $api = curl_init('https://faucetpay.io/api/v1/send');
                curl_setopt_array($api, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'api_key' => $api_key,
                        'to' => $wallet,
                        'amount' => $payout_amount,
                        'currency' => $currency
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $api_raw = curl_exec($api);
                $api_error = curl_error($api);
                curl_close($api);
                $result = json_decode((string)$api_raw, true);

                if ($api_error !== '') {
                    $message = 'Payment service is temporarily unavailable. Please try again.';
                    $message_type = 'error';
                } elseif (is_array($result) && (int)($result['status'] ?? 0) === 200) {
                    $_SESSION['last_claim'] = time();
                    $stats['claims']++;
                    $stats['paid_satoshi'] += $payout_amount;
                    save_stats($stats_file, $stats);
                    $message = "Success! {$payout_amount} Satoshi {$currency} has been sent to your FaucetPay account.";
                    $message_type = 'success';
                } else {
                    $api_message = is_array($result) ? ($result['message'] ?? 'The payment was not completed.') : 'The payment was not completed.';
                    $message = 'Payment failed: ' . $api_message;
                    $message_type = 'error';
                }
            }
        }
    }
}

$launch = DateTime::createFromFormat('!Y-m-d', $launch_date);
$today = new DateTime('today');
$days_online = 99; // Display configuration; use verified operating history for public claims.
$paid_ltc = $stats['paid_satoshi'] / 100000000;
$remaining_time = 0;
if (isset($_SESSION['last_claim'])) {
    $remaining_time = max(0, $claim_interval - (time() - $_SESSION['last_claim']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#071a2b">
    <meta name="description" content="btc2faucet — claim 2 satoshi every 5 minutes.">
    <title>btc2faucet — Bitcoin Faucet</title>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        :root{--bg:#071a2b;--panel:#0d263b;--panel2:#12334a;--text:#f2f8fc;--muted:#a7c0d1;--accent:#31e6c2;--accent2:#62a9ff;--border:rgba(157,205,228,.18);--shadow:0 24px 70px rgba(0,0,0,.24)}
        *{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:radial-gradient(ellipse at 20% 0%,#123c56 0%,transparent 42%),linear-gradient(160deg,#061421,#0a2134 58%,#071a2b);color:var(--text);min-height:100vh}
        a{color:var(--accent)}.topbar{padding:19px 22px;border-bottom:1px solid var(--border);background:rgba(5,20,33,.86);backdrop-filter:blur(14px);position:sticky;top:0;z-index:20}
        .nav{max-width:1120px;margin:auto;display:flex;align-items:center;justify-content:space-between;gap:15px}.brand{font-weight:950;font-size:25px;letter-spacing:-1px}.brand span{color:var(--accent)}.status{font-size:12px;color:#8fffe6;background:rgba(49,230,194,.09);border:1px solid rgba(49,230,194,.3);padding:8px 12px;border-radius:999px;font-weight:800}.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--accent);margin-right:7px;box-shadow:0 0 12px var(--accent)}
        .hero{max-width:1120px;margin:58px auto 26px;padding:0 22px;text-align:center}.eyebrow{display:inline-block;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--accent);padding:8px 13px;border-radius:999px;font-size:12px;font-weight:800;letter-spacing:1.3px;text-transform:uppercase}.hero h1{font-size:clamp(38px,7vw,68px);line-height:1.04;margin:20px 0 15px;letter-spacing:-2.5px}.hero h1 span{color:var(--accent)}.hero p{margin:0 auto;color:var(--muted);font-size:17px;max-width:650px;line-height:1.7}
        .stats{max-width:1120px;margin:32px auto;padding:0 22px;display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.stat{background:linear-gradient(145deg,rgba(18,51,74,.94),rgba(11,35,53,.94));border:1px solid var(--border);border-radius:18px;padding:22px 16px;text-align:left;box-shadow:var(--shadow)}.stat small{color:var(--muted);font-weight:700;font-size:12px}.stat strong{display:block;font-size:clamp(20px,2.4vw,28px);margin-top:9px;letter-spacing:-.5px}.stat .unit{color:var(--accent);font-size:13px}
        .layout{max-width:820px;margin:26px auto 55px;padding:0 22px;display:grid;grid-template-columns:minmax(0,1fr);gap:20px;align-items:start}.card{background:linear-gradient(150deg,rgba(15,42,62,.98),rgba(9,30,46,.98));border:1px solid var(--border);border-radius:24px;box-shadow:var(--shadow);padding:28px}.card h2{margin:0 0 8px;font-size:27px}.sub{color:var(--muted);margin:0 0 20px;line-height:1.6}.reward{background:linear-gradient(115deg,#16bda5,#227fd0);color:#041a27;border-radius:18px;padding:22px;margin:22px 0;display:flex;align-items:center;justify-content:space-between;gap:15px}.reward b{font-size:35px;letter-spacing:-1px}.reward small{opacity:.85;display:block;font-weight:750}.field{margin:18px 0}.field label{display:block;font-size:13px;font-weight:850;margin-bottom:9px}.field input{width:100%;padding:15px 16px;background:#071a2b;border:1px solid #25475e;color:#f2f8fc;border-radius:12px;font-size:15px;outline:none;transition:.2s}.field input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(49,230,194,.1)}.captcha{margin:20px 0;display:flex;justify-content:center}.btn{width:100%;padding:16px;border:0;border-radius:12px;background:var(--accent);color:#052033;font-size:16px;font-weight:950;cursor:pointer;transition:transform .2s,filter .2s}.btn:hover{filter:brightness(1.06);transform:translateY(-1px)}.btn:disabled{opacity:.6;cursor:not-allowed}.message{padding:14px 16px;border-radius:12px;margin-bottom:18px;font-weight:700}.success{background:#073d36;color:#a8ffdf;border:1px solid #147e6b}.error{background:#4b1c2a;color:#ffd0da;border:1px solid #8c334c}.warning{background:#49391a;color:#ffe7a4;border:1px solid #876820}.adbox{padding:12px;overflow:hidden}.adbox+.adbox{margin-top:16px}.section{max-width:1120px;margin:0 auto 42px;padding:0 22px}.activity-head{display:flex;justify-content:space-between;align-items:end;gap:12px;margin-bottom:16px}.activity-head h2{margin:0;font-size:25px}.activity-head p{margin:5px 0 0;color:var(--muted);font-size:13px}.demo-tag{font-size:11px;font-weight:900;letter-spacing:.8px;color:#ffe5a2;background:rgba(255,196,61,.1);border:1px solid rgba(255,196,61,.3);padding:7px 10px;border-radius:999px}.activity{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.activity-row{display:flex;align-items:center;gap:11px;padding:14px;background:rgba(13,38,59,.8);border:1px solid var(--border);border-radius:14px;min-width:0}.coin{width:37px;height:37px;flex:0 0 37px;border-radius:50%;display:grid;place-items:center;background:rgba(49,230,194,.12);color:var(--accent);font-weight:950}.wallet-info{min-width:0;flex:1}.wallet-info strong{display:block;font-size:13px;overflow-wrap:anywhere}.wallet-info small{display:block;color:var(--muted);font-size:11px;margin-top:4px}.amount{font-size:12px;font-weight:900;color:var(--accent);white-space:nowrap}.demo-note{color:#ffe5a2;font-size:12px;line-height:1.6;margin:13px 0 0}.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px}.info{background:rgba(13,38,59,.8);border:1px solid var(--border);border-radius:18px;padding:21px}.info h3{margin:0 0 9px;font-size:17px}.info p{margin:0;color:var(--muted);line-height:1.7;font-size:14px}.footer{padding:30px 20px;text-align:center;color:var(--muted);border-top:1px solid var(--border);background:rgba(5,20,33,.8)}.footer strong{color:var(--text)}
        @media(max-width:850px){.stats{grid-template-columns:repeat(2,1fr)}.layout{grid-template-columns:1fr}.hero{margin-top:38px}.info-grid{grid-template-columns:1fr}.activity{grid-template-columns:repeat(2,1fr)}.topbar{position:static}}
        @media(max-width:520px){.stats{gap:9px}.stat{padding:16px 12px}.card{padding:20px}.reward{align-items:flex-start;flex-direction:column}.activity{grid-template-columns:1fr}.activity-head{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<header class="topbar"><div class="nav"><div class="brand">btc<span>2faucet</span></div><div class="status"><i class="dot"></i>BTC FAUCET</div></div></header>

<section class="hero">
    <span class="eyebrow">Bitcoin micro-rewards</span>
    <h1>Small claims.<br><span>Real Bitcoin potential.</span></h1>
    <p>Claim 2 satoshi every 5 minutes. Complete the verification and enter your FaucetPay Bitcoin address to request a reward.</p>
</section>

<section class="stats">
    <div class="stat"><small>REWARD PER CLAIM</small><strong>2 <span class="unit">SAT</span></strong></div>
    <div class="stat"><small>CLAIMS RECORDED</small><strong><?= number_format($stats['claims']) ?></strong></div>
    <div class="stat"><small>TOTAL WITHDRAWN <span class="unit">DEMO</span></small><strong>0.64582318 <span class="unit">SAT</span></strong></div>
    <div class="stat"><small>TIME ONLINE <span class="unit">CONFIGURED</span></small><strong>99 <span class="unit">days</span></strong></div>
</section>
<div style="width:728px; margin:0 auto; text-align:center;">
<iframe src="//ads.coinserom.com/pub?adsunit=31303530&size=728x90" style="width:728px;height:90px;border:0px;padding:0;background-color: transparent;overflow: auto;">
</iframe>
<a style="display: block;text-align:right;font-size:12px;width:728px;" href="https://coinserom.com/?affiliate=3631363737" target="_blank">Advertise here</a>
</div>

<main class="layout">
    <section class="card">
        <h2>Claim your BTC reward</h2>
        <p class="sub">Enter your FaucetPay Bitcoin address and complete the verification below.</p>
        <?php if ($message !== ''): ?><div class="message <?= clean_message($message_type) ?>"><?= clean_message($message) ?></div><?php endif; ?>

        <div class="reward"><div><small>Current reward</small><b><?= number_format($payout_amount) ?> SAT</b></div><div><strong>BTC</strong><small>FaucetPay payout</small></div></div>
<div style="width:300px; margin:0 auto; text-align:center;">
<iframe src="//ads.coinserom.com/pub?adsunit=31303531&size=300x250" style="width:300px;height:250px;border:0px;padding:0;background-color: transparent;overflow: auto;">
</iframe>
<a style="display: block;text-align:right;font-size:12px;width:300px;" href="https://coinserom.com/?affiliate=3631363737" target="_blank">Advertise here</a>
</div>

        <form method="POST" id="claimForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= clean_message($_SESSION['csrf_token']) ?>">
            <div class="field"><label for="wallet">FaucetPay Bitcoin wallet</label><input id="wallet" type="text" name="wallet" placeholder="Enter your BTC address" maxlength="120" required></div>
            <div class="captcha"><div class="g-recaptcha" data-sitekey="<?= clean_message($recaptcha_site_key) ?>"></div></div>
            <button class="btn" type="submit" <?= $remaining_time > 0 ? 'disabled' : '' ?>><?= $remaining_time > 0 ? 'Wait ' . $remaining_time . 's' : 'Claim 2 SAT' ?></button>
        </form>
    </section>
</main>
<div style="width:320px; margin:0 auto; text-align:center;">
<iframe src="//ads.coinserom.com/pub?adsunit=31303439&size=320x100" style="width:320px;height:100px;border:0px;padding:0;background-color: transparent;overflow: auto;">
</iframe>
<a style="display: block;text-align:right;font-size:12px;width:320px;" href="https://coinserom.com/?affiliate=3631363737" target="_blank">Ads by coinserom</a>
</div>

<section class="section">
    <div class="info-grid">
        <div class="info"><h3>⚡ Simple rewards</h3><p>Request 2 satoshi per claim, subject to successful verification and payout service availability.</p></div>
        <div class="info"><h3>🛡️ Protected claims</h3><p>reCAPTCHA, cooldown and CSRF protection help reduce automated and forged requests.</p></div>
        <div class="info"><h3>📅 Faucet details</h3><p>The interface is configured to display <strong>99 days</strong> online. Update this figure to match verified operating history before publishing.</p></div>
    </div>
</section>

<footer class="footer"><strong>btc2faucet</strong> · Bitcoin Faucet · Payouts via FaucetPay</footer>

</body>
</html>
