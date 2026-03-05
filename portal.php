<?php
// portal.php
require_once 'config.php';

$hash = isset($_GET['h']) ? trim($_GET['h']) : '';
if (!$hash) {
    die("Secure Link Invalid or Expired.");
}

// Fetch Invoice Data
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE hash = ? LIMIT 1");
$stmt->execute([$hash]);
$inv = $stmt->fetch();

if (!$inv) {
    die("Document Not Found.");
}

// Read Receipt Trigger
if ($inv['viewedAt'] === null) {
    $pdo->prepare("UPDATE invoices SET viewedAt = NOW() WHERE id = ?")->execute([$inv['id']]);
}

// Fetch Global Settings
$settings = json_decode($pdo->query("SELECT payload FROM settings WHERE id = 1")->fetchColumn(), true);
$items = json_decode($inv['items'], true);

// Dynamic ATO GST Logic
$isGst = isset($settings['gstRegistered']) && $settings['gstRegistered'] == 1;
$docTitle = $inv['docType'] === 'quote' ? 'PROJECT QUOTE' : ($isGst ? 'TAX INVOICE' : 'INVOICE');

$baseAmount = (float)$inv['amount'];
$gstAmount = (float)$inv['gstAmount'];
$totalAmount = $baseAmount + $gstAmount;

// Formatters
function cur($num) { return '$' . number_format((float)$num, 2); }
function dte($str) { return $str ? date('d M Y', strtotime($str)) : ''; }
function esc($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($docTitle) ?> #<?= esc($inv['id']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s; }
        .tab-btn.active { border-bottom: 2px solid #4f46e5; color: #312e81; font-weight: bold; background-color: white; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @media print { 
            body { background: white !important; }
            .print-hide { display: none !important; } 
            .print-shadow-none { box-shadow: none !important; border: none !important; } 
        }
    </style>
</head>
<body class="bg-gray-100 font-sans p-4 md:p-8 text-slate-800 print:bg-white print:p-0">
    
    <div class="max-w-4xl mx-auto bg-white p-8 md:p-12 rounded-xl shadow-xl border-t-8 border-indigo-600 print-shadow-none">
        
        <div class="flex flex-col md:flex-row justify-between items-start border-b-2 border-gray-100 pb-8 mb-8">
            <div>
                <h1 class="text-4xl font-extrabold text-slate-900"><?= esc($settings['bName']) ?></h1>
                <p class="text-slate-500 mt-2 text-sm font-bold">ABN: <?= esc($settings['bAbn']) ?></p>
                <?php if (!empty($settings['bAddr'])): ?>
                    <p class="text-slate-500 text-sm mt-1 whitespace-pre-line"><?= esc($settings['bAddr']) ?></p>
                <?php endif; ?>
                <p class="text-slate-500 text-sm mt-1"><?= esc($settings['bEmail']) ?></p>
            </div>
            <div class="text-left md:text-right mt-6 md:mt-0">
                <h2 class="text-3xl font-black text-slate-300 tracking-widest uppercase"><?= esc($docTitle) ?></h2>
                <p class="text-slate-800 font-bold mt-2 text-lg">#<?= esc($inv['id']) ?></p>
                <p class="text-slate-500 text-sm mt-1">Issue Date: <?= esc(dte($inv['issueDate'])) ?></p>
                <?php if($inv['docType'] !== 'quote' && $inv['dueDate']): ?>
                    <p class="text-slate-500 text-sm">Due Date: <span class="font-bold text-slate-700"><?= esc(dte($inv['dueDate'])) ?></span></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="mb-10">
            <h3 class="font-bold text-indigo-500 uppercase text-xs mb-2">Billed To</h3>
            <p class="text-xl font-bold text-slate-900"><?= esc($inv['clientName']) ?></p>
            <p class="text-slate-500 text-sm"><?= esc($inv['clientEmail']) ?></p>
            <?php if (!empty($inv['clientAddress'])): ?>
                <p class="text-slate-500 text-sm mt-1 whitespace-pre-line"><?= esc($inv['clientAddress']) ?></p>
            <?php endif; ?>
        </div>

        <table class="w-full text-left border-collapse mb-8">
            <tr class="bg-gray-50 text-slate-500 text-xs uppercase tracking-wider border-b-2 border-slate-800">
                <th class="p-4 font-bold w-1/2">Service Details</th>
                <th class="p-4 font-bold text-center">Qty</th>
                <th class="p-4 font-bold text-right">Unit Price</th>
                <th class="p-4 font-bold text-right">Total</th>
            </tr>
            <?php foreach($items as $item): ?>
            <tr class="border-b border-gray-100">
                <td class="p-4 text-slate-800 font-semibold align-top">
                    <?= esc($item['name']) ?>
                    <?php if (!empty($item['desc'])): ?>
                        <br><span class="text-xs text-slate-500 font-normal mt-1 block whitespace-pre-line"><?= esc($item['desc']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="p-4 text-center text-slate-600 align-top"><?= esc($item['qty']) ?></td>
                <td class="p-4 text-right text-slate-600 align-top font-mono text-sm"><?= cur($item['price']) ?></td>
                <td class="p-4 text-right font-mono text-slate-900 font-bold align-top"><?= cur($item['qty'] * $item['price']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="flex flex-col md:flex-row justify-between items-start mb-12 break-inside-avoid">
            <div class="w-full md:w-1/2 pr-0 md:pr-8 mb-6 md:mb-0">
                <?php if(!empty($inv['notes'])): ?>
                    <h3 class="font-bold text-indigo-400 uppercase text-xs mb-2">Notes & Terms</h3>
                    <p class="text-sm text-slate-600 whitespace-pre-line leading-relaxed"><?= esc($inv['notes']) ?></p>
                <?php endif; ?>
            </div>
            <div class="w-full md:w-5/12 bg-gray-50 p-6 rounded-xl print-shadow-none border border-gray-200">
                <div class="flex justify-between text-sm text-gray-600 mb-2">
                    <span>Subtotal (Ex GST):</span>
                    <span class="font-mono"><?= cur($baseAmount) ?></span>
                </div>
                <?php if($isGst): ?>
                <div class="flex justify-between text-sm text-gray-600 mb-4">
                    <span>GST (10%):</span>
                    <span class="font-mono"><?= cur($gstAmount) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between text-2xl font-black text-indigo-900 pt-4 border-t border-gray-300">
                    <span>Total Due:</span>
                    <span class="font-mono" id="js-total" data-aud="<?= esc($totalAmount) ?>"><?= cur($totalAmount) ?></span>
                </div>
            </div>
        </div>

        <?php if ($inv['status'] === 'paid'): ?>
            <div class="bg-green-50 border border-green-200 text-green-800 p-8 rounded-2xl text-center shadow-inner">
                <svg class="w-16 h-16 text-green-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <h3 class="text-3xl font-black mb-2">PAID IN FULL</h3>
                <p>Thank you for partnering with <?= esc($settings['bName']) ?>.</p>
            </div>
        <?php else: ?>
            <div class="print-hide border border-gray-200 rounded-xl overflow-hidden shadow-sm bg-white break-inside-avoid">
                
                <div class="flex bg-gray-50 border-b border-gray-200">
                    <button class="tab-btn active flex-1 py-4 text-sm font-bold text-gray-500 hover:bg-gray-100 transition focus:outline-none" onclick="openTab('tab-bank', this)">Bank Transfer</button>
                    <button class="tab-btn flex-1 py-4 text-sm font-bold text-gray-500 hover:bg-gray-100 transition focus:outline-none" onclick="openTab('tab-stripe', this)">Credit Card</button>
                    <button class="tab-btn flex-1 py-4 text-sm font-bold text-gray-500 hover:bg-gray-100 transition focus:outline-none" onclick="openTab('tab-crypto', this)">Crypto</button>
                </div>
                
                <div class="p-6 md:p-8">
                    
                    <div id="tab-bank" class="tab-content active">
                        <h4 class="font-bold text-slate-800 mb-4 text-lg">Electronic Funds Transfer</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 font-mono text-sm">
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                <p class="text-xs text-indigo-500 uppercase font-sans font-bold mb-1">PayID (Instant)</p>
                                <p class="text-slate-800 font-bold select-all text-lg"><?= esc($settings['payid']) ?></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                <p class="text-xs text-gray-500 uppercase font-sans font-bold mb-1">Account Name</p>
                                <p class="text-slate-800 font-bold select-all text-lg"><?= esc($settings['bName']) ?></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                <p class="text-xs text-gray-500 uppercase font-sans font-bold mb-1">BSB</p>
                                <p class="text-slate-800 font-bold select-all text-lg"><?= esc($settings['bsb']) ?></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                <p class="text-xs text-gray-500 uppercase font-sans font-bold mb-1">Account Number</p>
                                <p class="text-slate-800 font-bold select-all text-lg"><?= esc($settings['acc']) ?></p>
                            </div>
                        </div>
                        <div class="mt-6 p-4 bg-orange-50 border border-orange-200 rounded-lg">
                            <p class="text-sm text-orange-800 font-bold flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                Important: Please use <span class="font-mono bg-white px-2 py-1 rounded border border-orange-200 ml-2 select-all">INV-<?= esc($inv['id']) ?></span> as your payment reference.
                            </p>
                        </div>
                    </div>

                    <div id="tab-stripe" class="tab-content text-center py-6">
                        <svg class="w-12 h-12 text-indigo-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                        <h4 class="font-bold text-slate-800 mb-2 text-xl">Pay securely via Stripe</h4>
                        <p class="text-sm text-gray-500 mb-8 max-w-md mx-auto">We accept Visa, Mastercard, Apple Pay, and Google Pay through our secure portal.</p>
                        <?php if(!empty($settings['stripeUrl'])): ?>
                            <a href="<?= esc($settings['stripeUrl']) ?>" target="_blank" class="inline-block bg-indigo-600 text-white font-bold py-4 px-10 rounded-lg shadow-lg hover:bg-indigo-700 transition transform hover:-translate-y-1">
                                Pay <?= cur($totalAmount) ?> Now
                            </a>
                        <?php else: ?>
                            <p class="text-red-500 text-sm font-bold bg-red-50 p-4 rounded border border-red-200 inline-block">Payment link not currently configured.</p>
                        <?php endif; ?>
                    </div>

                    <div id="tab-crypto" class="tab-content">
                        <h4 class="font-bold text-slate-800 mb-4 text-lg">Cryptocurrency Transfer</h4>
                        <p class="text-sm text-gray-500 mb-6">Rates calculated live via the Binance API. Please transfer the exact amount listed below.</p>
                        <div class="space-y-4">
                            <div class="bg-gray-50 p-5 rounded-lg border border-gray-200 flex flex-col md:flex-row justify-between items-center md:items-start gap-4">
                                <div class="w-full md:w-2/3">
                                    <p class="text-xs text-gray-500 font-bold uppercase tracking-wider mb-1">Bitcoin (BTC) Wallet</p>
                                    <p class="font-mono text-sm text-slate-700 select-all break-all bg-white p-2 rounded border"><?= esc($settings['btc']) ?></p>
                                </div>
                                <div class="text-center md:text-right w-full md:w-1/3">
                                    <p class="text-2xl font-black text-orange-500 font-mono" id="js-btc-val">Loading...</p>
                                    <p class="text-xs text-gray-400 font-bold">BTC Due</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 p-5 rounded-lg border border-gray-200 flex flex-col md:flex-row justify-between items-center md:items-start gap-4">
                                <div class="w-full md:w-2/3">
                                    <p class="text-xs text-gray-500 font-bold uppercase tracking-wider mb-1">Ethereum (ETH) Wallet</p>
                                    <p class="font-mono text-sm text-slate-700 select-all break-all bg-white p-2 rounded border"><?= esc($settings['eth']) ?></p>
                                </div>
                                <div class="text-center md:text-right w-full md:w-1/3">
                                    <p class="text-2xl font-black text-blue-500 font-mono" id="js-eth-val">Loading...</p>
                                    <p class="text-xs text-gray-400 font-bold">ETH Due</p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            
            <div class="mt-8 flex justify-center print-hide">
                <button onclick="window.print()" class="text-gray-500 hover:text-slate-800 font-bold text-sm flex items-center transition px-4 py-2 bg-white rounded-lg shadow-sm border">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                    Print / Save PDF
                </button>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function openTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
            
            // Fetch live API rates only when Crypto tab is clicked to save bandwidth
            if (tabId === 'tab-crypto' && document.getElementById('js-btc-val').innerText === 'Loading...') {
                fetchCryptoRates();
            }
        }

        async function fetchCryptoRates() {
            const audTotal = parseFloat(document.getElementById('js-total').dataset.aud);
            try {
                const btcRes = await fetch('https://api.binance.com/api/v3/ticker/price?symbol=BTCAUD');
                const btcData = await btcRes.json();
                document.getElementById('js-btc-val').innerText = (audTotal / parseFloat(btcData.price)).toFixed(6);

                const ethRes = await fetch('https://api.binance.com/api/v3/ticker/price?symbol=ETHAUD');
                const ethData = await ethRes.json();
                document.getElementById('js-eth-val').innerText = (audTotal / parseFloat(ethData.price)).toFixed(4);
            } catch (e) {
                console.error("Crypto Fetch Error:", e);
                document.getElementById('js-btc-val').innerText = 'API Error';
                document.getElementById('js-eth-val').innerText = 'API Error';
            }
        }
    </script>
</body>
</html>