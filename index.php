<?php
// index.php
session_start();
require_once 'config.php';

// --- HYBRID API ROUTER ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['api'];
    $input = json_decode(file_get_contents('php://input'), true);

    // Unauthenticated Routes
    if ($action === 'login') {
        if ($input['user'] === ADMIN_USER && $input['pass'] === ADMIN_PASS) {
            $_SESSION['admin_logged_in'] = true;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Invalid credentials']);
        }
        exit;
    }

    // Auth Barrier
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['error' => 'Unauthorized access.']);
        exit;
    }

    try {
        if ($action === 'logout') {
            session_destroy();
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'get_data') {
            $invoices = $pdo->query("SELECT * FROM invoices ORDER BY id DESC")->fetchAll();
            foreach($invoices as &$inv) { 
                $inv['items'] = json_decode($inv['items'], true); 
            }
            
            $expenses = $pdo->query("SELECT * FROM expenses ORDER BY date DESC")->fetchAll();
            $retainers = $pdo->query("SELECT * FROM retainers ORDER BY nextDate ASC")->fetchAll();
            $settings = json_decode($pdo->query("SELECT payload FROM settings WHERE id = 1")->fetchColumn(), true);
            
            echo json_encode(compact('invoices', 'expenses', 'retainers', 'settings'));
            exit;
        }

        if ($action === 'save_invoice') {
            $hash = bin2hex(random_bytes(16));
            $amount = (float)$input['amount'];
            $settings = json_decode($pdo->query("SELECT payload FROM settings WHERE id = 1")->fetchColumn(), true);
            
            // ATO GST Logic
            $gstAmount = 0;
            if (isset($settings['gstRegistered']) && $settings['gstRegistered']) {
                $gstAmount = $amount * 0.10;
            }

            // Strict Sanitisation for Production
            $sanitizedItems = [];
            if (is_array($input['items'])) {
                foreach ($input['items'] as $item) {
                    $sanitizedItems[] = [
                        'name' => htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'desc' => htmlspecialchars($item['desc'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'qty' => (float)($item['qty'] ?? 0),
                        'price' => (float)($item['price'] ?? 0)
                    ];
                }
            }
            
            $docType = in_array($input['docType'], ['invoice', 'quote']) ? $input['docType'] : 'invoice';
            $issueDate = preg_replace('/[^0-9\-]/', '', $input['issueDate']);
            $dueDate = preg_replace('/[^0-9\-]/', '', $input['dueDate']);
            
            $stmt = $pdo->prepare("INSERT INTO invoices (clientName, clientEmail, clientAddress, issueDate, dueDate, items, amount, gstAmount, notes, docType, hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                htmlspecialchars($input['clientName'], ENT_QUOTES, 'UTF-8'),
                filter_var($input['clientEmail'], FILTER_SANITIZE_EMAIL),
                htmlspecialchars($input['clientAddress'], ENT_QUOTES, 'UTF-8'),
                $issueDate,
                $dueDate,
                json_encode($sanitizedItems),
                $amount,
                $gstAmount,
                htmlspecialchars($input['notes'], ENT_QUOTES, 'UTF-8'),
                $docType,
                $hash
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'hash' => $hash]);
            exit;
        }

        if ($action === 'mark_paid') {
            $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = ?")->execute([(int)$input['id']]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'save_expense') {
            $stmt = $pdo->prepare("INSERT INTO expenses (date, cat, description, amount, includesGst, file) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                preg_replace('/[^0-9\-]/', '', $input['date']), 
                htmlspecialchars($input['cat'], ENT_QUOTES, 'UTF-8'), 
                htmlspecialchars($input['desc'], ENT_QUOTES, 'UTF-8'), 
                (float)$input['amount'], 
                (int)$input['includesGst'],
                htmlspecialchars($input['file'] ?? '', ENT_QUOTES, 'UTF-8')
            ]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'save_settings') {
            $pdo->prepare("UPDATE settings SET payload = ? WHERE id = 1")->execute([json_encode($input)]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'send_email') {
            $id = (int)$input['id'];
            $type = preg_replace('/[^a-z]/i', '', $input['type']); 

            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $inv = $stmt->fetch();

            if (!$inv) {
                echo json_encode(['error' => 'Document not found']);
                exit;
            }

            $settings = json_decode($pdo->query("SELECT payload FROM settings WHERE id = 1")->fetchColumn(), true);
            
            $subjKey = 'emailSubj' . ucfirst($type);
            $bodyKey = 'emailBody' . ucfirst($type);

            $subjTemplate = $settings[$subjKey] ?? $settings['emailSubjInv'];
            $bodyTemplate = $settings[$bodyKey] ?? $settings['emailBodyInv'];

            $link = APP_URL . "/portal.php?h=" . $inv['hash'];
            $totalAmount = $inv['amount'] + $inv['gstAmount'];
            $formattedAmount = '$' . number_format($totalAmount, 2);

            $search = ['{client}', '{amount}', '{id}', '{business}', '{link}'];
            $replace = [$inv['clientName'], $formattedAmount, $inv['id'], $settings['bName'], $link];

            $subject = str_replace($search, $replace, $subjTemplate);
            $body = str_replace($search, $replace, $bodyTemplate);

            $headers = "From: " . $settings['bName'] . " <" . $settings['bEmail'] . ">\r\n";
            $headers .= "Reply-To: " . $settings['bEmail'] . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

            if(mail($inv['clientEmail'], $subject, $body, $headers)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Failed to dispatch email. Check cPanel native mail routing.']);
            }
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// --- FRONTEND SHELL ---
$isLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JP Websites | Agency OS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { slate: { 850: '#1e293b', 900: '#0f172a' } } } } }
    </script>
    <style>
        .hidden { display: none !important; }
        .toast-enter { animation: slideIn 0.3s ease-out forwards; }
        @keyframes slideIn { from { transform: translateY(-100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @media print {
            body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .print-hide { display: none !important; }
            .print-shadow-none { box-shadow: none !important; border: none !important; }
            .print-only { display: block !important; }
        }
        .print-only { display: none; }
        .custom-scroll::-webkit-scrollbar { height: 8px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>
</head>
<body class="bg-gray-50 text-slate-800 font-sans min-h-screen">

    <div id="toast" class="fixed top-5 right-5 bg-slate-800 text-white px-6 py-4 rounded-lg shadow-2xl hidden z-50 flex items-center border-l-4 border-indigo-400 print-hide">
        <span id="toast-msg" class="font-medium text-sm">Notification</span>
    </div>

    <div id="login-view" class="flex items-center justify-center min-h-screen p-4 bg-gradient-to-br from-indigo-50 to-slate-100 <?= $isLoggedIn ? 'hidden' : '' ?> print-hide">
        <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-sm border-t-4 border-indigo-600">
            <h1 class="text-3xl font-extrabold mb-2 text-center text-indigo-900 tracking-tight">JP Websites</h1>
            <p class="text-sm text-gray-500 text-center mb-8 font-medium">Agency Operating System</p>
            
            <div id="login-error" class="bg-red-50 border-l-4 border-red-500 text-red-700 p-3 mb-6 text-sm hidden rounded-r">
                Authentication failed. Invalid credentials.
            </div>

            <form onsubmit="handleLogin(event)">
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Username</label>
                    <input type="text" id="l-user" required class="w-full border-gray-300 border rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-500 bg-gray-50 outline-none">
                </div>
                <div class="mb-8">
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Password</label>
                    <input type="password" id="l-pass" required class="w-full border-gray-300 border rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-500 bg-gray-50 outline-none">
                </div>
                <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3 px-4 rounded-lg shadow-lg hover:bg-indigo-700 transition">Access Dashboard</button>
            </form>
        </div>
    </div>

    <div id="app-shell" class="min-h-screen flex flex-col <?= $isLoggedIn ? '' : 'hidden' ?> print-hide">
        
        <nav class="bg-slate-900 text-white shadow-lg z-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center gap-8">
                        <span class="font-extrabold text-xl tracking-tight bg-clip-text text-transparent bg-gradient-to-r from-indigo-400 to-cyan-300">JP Websites</span>
                        <div class="hidden md:block">
                            <div class="flex items-baseline space-x-2">
                                <button onclick="navTo('ledger')" id="nav-ledger" class="nav-btn bg-slate-800 text-white px-4 py-2 rounded-md text-sm font-bold transition-all">Ledger</button>
                                <button onclick="navTo('mrr')" id="nav-mrr" class="nav-btn text-slate-300 hover:bg-slate-800 hover:text-white px-4 py-2 rounded-md text-sm font-medium transition-all">Retainers</button>
                                <button onclick="navTo('pnl')" id="nav-pnl" class="nav-btn text-slate-300 hover:bg-slate-800 hover:text-white px-4 py-2 rounded-md text-sm font-medium transition-all">Tax & P&L</button>
                                <button onclick="navTo('settings')" id="nav-settings" class="nav-btn text-slate-300 hover:bg-slate-800 hover:text-white px-4 py-2 rounded-md text-sm font-medium transition-all">Settings</button>
                            </div>
                        </div>
                    </div>
                    <button onclick="logout()" class="text-slate-300 hover:text-white font-bold text-sm border border-slate-700 px-3 py-1 rounded hover:bg-slate-800 transition">Sign Out</button>
                </div>
            </div>
        </nav>

        <main class="flex-grow p-4 md:p-8">
            <div class="max-w-7xl mx-auto">
                
                <div id="view-ledger" class="app-view">
                    <div class="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
                        <h2 class="text-3xl font-bold text-slate-800">Financial Ledger</h2>
                        <button onclick="openInvoiceModal()" class="bg-indigo-600 text-white font-bold py-2.5 px-5 rounded-lg shadow-md hover:bg-indigo-700 transition text-sm">
                            + Draft Document
                        </button>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                        <div class="overflow-x-auto custom-scroll">
                            <table class="w-full text-left border-collapse whitespace-nowrap">
                                <thead>
                                    <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-200">
                                        <th class="p-4 font-bold">ID</th>
                                        <th class="p-4 font-bold">Client Details</th>
                                        <th class="p-4 font-bold">Date Issued</th>
                                        <th class="p-4 font-bold text-right">Total (inc GST)</th>
                                        <th class="p-4 font-bold text-center">Status</th>
                                        <th class="p-4 font-bold text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="ledger-body" class="text-sm divide-y divide-gray-100"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="view-mrr" class="app-view hidden">
                    <h2 class="text-3xl font-bold text-slate-800 mb-8">Retainer Engine</h2>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden p-12 text-center text-gray-500">
                        <p class="text-lg font-bold text-slate-700 mb-2">Recurring Billing Module</p>
                        <p>This module requires active Stripe Billing integration to automatically process vaulted cards. Configure your Stripe Secret Key in settings to activate.</p>
                    </div>
                </div>

                <div id="view-pnl" class="app-view hidden">
                    <div class="flex justify-between items-center mb-2">
                        <h2 class="text-3xl font-bold text-slate-800">ATO Tax & P&L Dashboard</h2>
                        <div class="flex gap-2">
                            <select id="fy-filter" onchange="renderPnl()" class="border border-slate-300 rounded shadow-sm text-sm font-bold bg-white text-slate-700 px-3 py-2 focus:ring-2 focus:ring-slate-500 outline-none cursor-pointer">
                                <option value="all">All Financial Years</option>
                                <option value="2026">FY 2025/2026</option>
                                <option value="2025">FY 2024/2025</option>
                                <option value="2024">FY 2023/2024</option>
                            </select>
                            <button onclick="prepareEOFYPrint()" class="bg-slate-800 text-white font-bold py-2 px-4 rounded shadow hover:bg-slate-900 transition text-sm">Export Tax PDF</button>
                        </div>
                    </div>
                    <p class="text-slate-500 mb-8 text-sm">Data formatted for precise Perth, WA Business Activity Statements (BAS) compliance.</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                            <p class="text-xs text-gray-400 uppercase font-bold tracking-wider mb-2">Total Sales (Ex GST)</p>
                            <p class="text-2xl font-bold text-slate-800" id="bas-sales">$0.00</p>
                        </div>
                        <div class="bg-green-50 p-6 rounded-xl shadow-sm border border-green-200">
                            <p class="text-xs text-green-600 uppercase font-bold tracking-wider mb-2">GST Collected</p>
                            <p class="text-2xl font-bold text-green-700" id="bas-gst-col">$0.00</p>
                        </div>
                        <div class="bg-red-50 p-6 rounded-xl shadow-sm border border-red-200">
                            <p class="text-xs text-red-600 uppercase font-bold tracking-wider mb-2">GST on Expenses</p>
                            <p class="text-2xl font-bold text-red-700" id="bas-gst-paid">$0.00</p>
                        </div>
                        <div class="bg-slate-900 p-6 rounded-xl shadow-lg border border-slate-700">
                            <p class="text-xs text-slate-400 uppercase font-bold tracking-wider mb-2">Net ATO Liability</p>
                            <p class="text-3xl font-black text-white" id="bas-net-ato">$0.00</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <div class="lg:col-span-1 bg-white p-6 rounded-xl shadow-sm border border-gray-200 h-fit">
                            <h3 class="font-bold text-slate-800 mb-6 border-b pb-3">Log Expense</h3>
                            <form onsubmit="handleAddExpense(event)">
                                <div class="mb-4"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Date</label><input type="date" id="e-date" required class="w-full border-gray-300 border rounded-lg p-2.5 text-sm"></div>
                                <div class="mb-4"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Category</label><select id="e-cat" class="w-full border-gray-300 border rounded-lg p-2.5 text-sm bg-white"><option value="Software">Software & SaaS</option><option value="Contractors">Contractors</option><option value="Hosting">Hosting</option><option value="Marketing">Advertising</option></select></div>
                                <div class="mb-4"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Description</label><input type="text" id="e-desc" required class="w-full border-gray-300 border rounded-lg p-2.5 text-sm"></div>
                                <div class="mb-4"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Total Amount (AUD)</label><input type="number" step="0.01" id="e-amount" required class="w-full border-gray-300 border rounded-lg p-2.5 text-sm"></div>
                                <div class="mb-6"><label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" id="e-gst" checked class="rounded text-indigo-600"><span class="text-sm font-bold text-gray-700">Includes 10% GST</span></label></div>
                                <button type="submit" class="w-full bg-slate-800 text-white font-bold py-3 rounded-lg hover:bg-slate-900 transition">Save Expense</button>
                            </form>
                        </div>
                        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                            <div class="overflow-x-auto custom-scroll">
                                <table class="w-full text-left border-collapse whitespace-nowrap">
                                    <thead>
                                        <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-200">
                                            <th class="p-4 font-bold">Date</th><th class="p-4 font-bold">Category</th><th class="p-4 font-bold">Description</th><th class="p-4 font-bold text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody id="expenses-body" class="text-sm divide-y divide-gray-100"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="view-settings" class="app-view hidden">
                    <h2 class="text-3xl font-bold text-slate-800 mb-6">System Configuration</h2>
                    <form onsubmit="handleSaveSettings(event)" class="space-y-6">
                        
                        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                            <h3 class="font-bold text-lg text-slate-800 mb-4 border-b pb-2">Agency & ATO Profile</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Business Name</label><input type="text" id="s-name" class="w-full border rounded-lg p-2.5 text-sm bg-gray-50"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">ABN</label><input type="text" id="s-abn" class="w-full border rounded-lg p-2.5 text-sm bg-gray-50"></div>
                            </div>
                            <label class="flex items-center space-x-2 mt-4 mb-4 p-4 border rounded-lg bg-indigo-50 border-indigo-100 cursor-pointer">
                                <input type="checkbox" id="s-gst" class="w-5 h-5 rounded text-indigo-600 focus:ring-indigo-500">
                                <div>
                                    <span class="block text-sm font-bold text-indigo-900">Registered for GST</span>
                                    <span class="block text-xs text-indigo-700">Applies 10% tax and formats documents as "Tax Invoice" for ATO compliance.</span>
                                </div>
                            </label>
                        </div>

                        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                            <h3 class="font-bold text-lg text-slate-800 mb-4 border-b pb-2">Payment Gateways</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PayID</label><input type="text" id="s-payid" class="w-full border rounded-lg p-2.5 text-sm bg-gray-50"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">BSB</label><input type="text" id="s-bsb" class="w-full border rounded-lg p-2.5 text-sm bg-gray-50"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Account</label><input type="text" id="s-acc" class="w-full border rounded-lg p-2.5 text-sm bg-gray-50"></div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div><label class="block text-xs font-bold text-indigo-500 uppercase mb-1">Stripe Payment Link URL</label><input type="url" id="s-stripe" class="w-full border rounded-lg p-2.5 text-sm bg-gray-50" placeholder="https://buy.stripe.com/..."></div>
                                <div><label class="block text-xs font-bold text-orange-500 uppercase mb-1">BTC Wallet</label><input type="text" id="s-btc" class="w-full border rounded-lg p-2.5 text-sm font-mono bg-gray-50"></div>
                                <div><label class="block text-xs font-bold text-blue-500 uppercase mb-1">ETH Wallet</label><input type="text" id="s-eth" class="w-full border rounded-lg p-2.5 text-sm font-mono bg-gray-50"></div>
                            </div>
                        </div>

                        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                            <h3 class="font-bold text-lg text-slate-800 mb-4 border-b pb-2">Email Templates</h3>
                            <p class="text-xs text-gray-500 mb-4">Variables: <code>{client}</code>, <code>{amount}</code>, <code>{id}</code>, <code>{business}</code>, <code>{link}</code></p>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-indigo-600 uppercase mb-1">New Invoice Subject</label>
                                    <input type="text" id="s-email-inv-subj" class="w-full border rounded-lg p-2.5 text-sm mb-2 font-mono bg-gray-50">
                                    <textarea id="s-email-inv-body" rows="6" class="w-full border rounded-lg p-2.5 text-xs font-mono bg-gray-50"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-orange-600 uppercase mb-1">Reminder Subject</label>
                                    <input type="text" id="s-email-rem-subj" class="w-full border rounded-lg p-2.5 text-sm mb-2 font-mono bg-gray-50">
                                    <textarea id="s-email-rem-body" rows="6" class="w-full border rounded-lg p-2.5 text-xs font-mono bg-gray-50"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-red-600 uppercase mb-1">Overdue Subject</label>
                                    <input type="text" id="s-email-overdue-subj" class="w-full border rounded-lg p-2.5 text-sm mb-2 font-mono bg-gray-50">
                                    <textarea id="s-email-overdue-body" rows="6" class="w-full border rounded-lg p-2.5 text-xs font-mono bg-gray-50"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="bg-indigo-600 text-white font-bold py-3 px-8 rounded-lg shadow hover:bg-indigo-700">Save Configuration</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <div id="invoice-modal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900 bg-opacity-75 hidden backdrop-blur-sm print-hide">
        <div class="min-h-screen flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl overflow-hidden">
                <div class="bg-white px-6 py-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-extrabold text-slate-800">Draft Document</h2>
                    <button type="button" onclick="closeInvoiceModal()" class="text-gray-400 hover:text-gray-600 text-xl font-bold">&times;</button>
                </div>
                <form onsubmit="handleCreate(event)">
                    <div class="p-6 md:p-8 space-y-6 max-h-[70vh] overflow-y-auto custom-scroll">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Type</label><select id="f-doc-type" class="w-full border bg-gray-50 rounded p-2"><option value="quote">Quote</option><option value="invoice" selected>Invoice</option></select></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Issue Date</label><input type="date" id="f-issue-date" class="w-full border bg-gray-50 rounded p-2" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Due Date</label><input type="date" id="f-due-date" class="w-full border bg-gray-50 rounded p-2" required></div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 border-t border-b py-6 border-gray-100">
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Client Name</label><input type="text" id="f-client-name" class="w-full border bg-gray-50 rounded p-2" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Client Email</label><input type="email" id="f-client-email" class="w-full border bg-gray-50 rounded p-2" required></div>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-slate-800 mb-2">Line Items (Ex GST)</h3>
                            <table class="w-full text-left border-collapse">
                                <thead><tr class="text-xs text-gray-400 uppercase border-b"><th class="py-2 w-1/2">Description</th><th class="py-2 w-20 text-center">Qty</th><th class="py-2 w-32 text-right">Price</th></tr></thead>
                                <tbody id="line-items-body"></tbody>
                            </table>
                            <button type="button" onclick="addLineItem()" class="mt-3 text-indigo-600 text-sm font-bold">+ Add Row</button>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-6 py-4 border-t flex justify-between items-center">
                        <div class="text-xl font-bold text-slate-800">Total: <span id="modal-total" class="font-mono text-indigo-600">$0.00</span> <span class="text-xs text-gray-500 font-normal" id="modal-gst-label"></span></div>
                        <div class="space-x-2">
                            <button type="button" onclick="closeInvoiceModal()" class="px-4 py-2 bg-white border text-gray-700 rounded-lg font-bold shadow-sm">Cancel</button>
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg font-bold hover:bg-indigo-700 shadow">Generate</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="eofy-print-view" class="print-only max-w-4xl mx-auto p-8 font-sans">
        <div class="border-b-2 border-gray-800 pb-4 mb-8 flex justify-between items-end">
            <div>
                <h1 class="text-3xl font-black text-gray-900">End of Financial Year Report</h1>
                <p class="text-sm font-bold text-gray-500 mt-1" id="eofy-bname">JP Websites</p>
            </div>
            <div class="text-right">
                <p class="text-sm font-bold text-slate-600" id="eofy-period">All Records</p>
            </div>
        </div>
        <div class="flex justify-between mb-8">
            <div class="w-1/3"><p class="text-xs uppercase font-bold border-b pb-1 mb-2">Revenue (Inc GST)</p><p class="text-2xl font-bold" id="eofy-rev">$0.00</p></div>
            <div class="w-1/3"><p class="text-xs uppercase font-bold border-b pb-1 mb-2">Expenses</p><p class="text-2xl font-bold" id="eofy-exp">$0.00</p></div>
            <div class="w-1/3 text-right"><p class="text-xs uppercase font-bold border-b pb-1 mb-2">Operating Profit</p><p class="text-2xl font-black" id="eofy-prof">$0.00</p></div>
        </div>
        <h3 class="font-bold mb-2 border-b pb-1">Expense Ledger</h3>
        <table class="w-full text-left text-sm border-collapse"><tbody id="eofy-table-body"></tbody></table>
    </div>

    <script>
        let appState = { invoices: [], expenses: [], settings: {} };
        const todayStr = new Date().toISOString().split('T')[0];
        
        function formatCurrency(num) { return '$' + parseFloat(num).toLocaleString('en-AU', { minimumFractionDigits: 2 }); }
        function formatDate(str) { return str ? new Date(str).toLocaleDateString('en-AU', { day:'numeric', month:'short', year:'numeric'}) : ''; }
        function showToast(msg) { 
            const t = document.getElementById('toast'); 
            document.getElementById('toast-msg').innerText = msg;
            t.classList.remove('hidden'); t.classList.add('toast-enter');
            setTimeout(() => { t.classList.add('hidden'); t.classList.remove('toast-enter'); }, 3000);
        }

        function getFinancialYear(dateString) {
            if (!dateString) return null;
            const d = new Date(dateString);
            const year = d.getFullYear();
            const month = d.getMonth() + 1;
            return month >= 7 ? year + 1 : year;
        }

        async function api(action, payload = {}) {
            try {
                const res = await fetch(`index.php?api=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
                const data = await res.json();
                if(data.error) throw new Error(data.error);
                return data;
            } catch (err) { alert(err.message); return false; }
        }

        async function initApp() {
            const data = await api('get_data');
            if(data) { appState = data; renderLedger(); renderPnl(); }
        }

        async function handleLogin(e) {
            e.preventDefault();
            const res = await api('login', { user: document.getElementById('l-user').value, pass: document.getElementById('l-pass').value });
            if (res && res.success) { window.location.reload(); } else { document.getElementById('login-error').classList.remove('hidden'); }
        }

        async function logout() { await api('logout'); window.location.reload(); }

        function navTo(viewId) {
            document.querySelectorAll('.app-view').forEach(el => el.classList.add('hidden'));
            document.getElementById(`view-${viewId}`).classList.remove('hidden');
            document.querySelectorAll('.nav-btn').forEach(el => el.classList.remove('bg-slate-800', 'text-white'));
            document.getElementById(`nav-${viewId}`).classList.add('bg-slate-800', 'text-white');
            if(viewId === 'settings') initSettingsForm();
        }

        function openInvoiceModal() {
            document.getElementById('create-form')?.reset();
            document.getElementById('f-issue-date').value = todayStr;
            document.getElementById('line-items-body').innerHTML = ''; addLineItem();
            document.getElementById('invoice-modal').classList.remove('hidden');
        }
        function closeInvoiceModal() { document.getElementById('invoice-modal').classList.add('hidden'); }

        function addLineItem() {
            const tr = document.createElement('tr'); tr.className = 'item-row';
            tr.innerHTML = `
                <td class="py-2 pr-2"><input type="text" class="w-full border rounded p-2 text-sm i-name focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Service Item" required></td>
                <td class="py-2 px-2"><input type="number" class="w-full border rounded p-2 text-sm text-center i-qty focus:ring-2 focus:ring-indigo-500 outline-none" value="1" oninput="calcTotals()" required></td>
                <td class="py-2 pl-2"><input type="number" step="0.01" class="w-full border rounded p-2 text-sm text-right i-price focus:ring-2 focus:ring-indigo-500 outline-none" value="0" oninput="calcTotals()" required></td>
            `;
            document.getElementById('line-items-body').appendChild(tr); calcTotals();
        }

        function calcTotals() {
            let total = 0;
            document.querySelectorAll('.item-row').forEach(row => {
                total += (parseFloat(row.querySelector('.i-qty').value) || 0) * (parseFloat(row.querySelector('.i-price').value) || 0);
            });
            
            let gstLabel = "";
            if (appState.settings.gstRegistered) {
                const gst = total * 0.10;
                total += gst;
                gstLabel = `(Inc. ${formatCurrency(gst)} GST)`;
            }
            
            document.getElementById('modal-total').innerText = formatCurrency(total);
            document.getElementById('modal-gst-label').innerText = gstLabel;
        }

        async function handleCreate(e) {
            e.preventDefault();
            let items = []; let amt = 0;
            document.querySelectorAll('.item-row').forEach(row => {
                const q = parseFloat(row.querySelector('.i-qty').value);
                const p = parseFloat(row.querySelector('.i-price').value);
                items.push({ name: row.querySelector('.i-name').value, desc: '', qty: q, price: p });
                amt += (q * p);
            });
            const payload = {
                clientName: document.getElementById('f-client-name').value, clientEmail: document.getElementById('f-client-email').value,
                clientAddress: '', issueDate: document.getElementById('f-issue-date').value, dueDate: document.getElementById('f-due-date').value,
                items: items, amount: amt, notes: document.getElementById('f-notes')?.value || '', docType: document.getElementById('f-doc-type').value
            };
            const res = await api('save_invoice', payload);
            if(res && res.success) { closeInvoiceModal(); await initApp(); showToast("Document drafted."); }
        }

        async function dispatchEmail(id, type) {
            showToast(`Dispatching ${type}...`);
            const res = await api('send_email', { id: id, type: type });
            if (res && res.success) showToast(`Email successfully sent.`);
        }

        function renderLedger() {
            let h = '';
            appState.invoices.forEach(inv => {
                const total = parseFloat(inv.amount) + parseFloat(inv.gstAmount);
                const badge = inv.status === 'paid' ? '<span class="text-green-600 font-bold bg-green-50 px-2 py-1 rounded text-xs">PAID</span>' : '<span class="text-red-600 font-bold bg-red-50 px-2 py-1 rounded text-xs">UNPAID</span>';
                
                let actions = `<a href="portal.php?h=${inv.hash}" target="_blank" class="text-slate-600 hover:text-indigo-600 font-bold text-xs border px-2 py-1 rounded shadow-sm mr-1 transition">View Portal</a>`;
                
                if(inv.status === 'unpaid') {
                    actions += `
                    <button onclick="dispatchEmail(${inv.id}, 'inv')" class="text-indigo-600 font-bold text-xs border border-indigo-200 bg-indigo-50 px-2 py-1 rounded mr-1 hover:bg-indigo-100 transition">Email</button>
                    <button onclick="dispatchEmail(${inv.id}, 'rem')" class="text-orange-600 font-bold text-xs border border-orange-200 bg-orange-50 px-2 py-1 rounded mr-1 hover:bg-orange-100 transition">Remind</button>
                    <button onclick="dispatchEmail(${inv.id}, 'overdue')" class="text-red-600 font-bold text-xs border border-red-200 bg-red-50 px-2 py-1 rounded mr-1 hover:bg-red-100 transition">Late</button>
                    <button onclick="markPaid(${inv.id})" class="text-green-600 font-bold text-xs border border-green-200 bg-green-50 px-2 py-1 rounded hover:bg-green-100 transition">Mark Paid</button>
                    `;
                }

                h += `<tr class="border-b hover:bg-slate-50 transition"><td class="p-4 text-xs font-mono text-gray-500">#${inv.id}</td><td class="p-4 font-bold">${inv.clientName}</td><td class="p-4 text-sm text-gray-600">${formatDate(inv.issueDate)}</td><td class="p-4 text-right font-mono font-bold">${formatCurrency(total)}</td><td class="p-4 text-center">${badge}</td><td class="p-4 text-right whitespace-nowrap">${actions}</td></tr>`;
            });
            document.getElementById('ledger-body').innerHTML = h || '<tr><td colspan="6" class="text-center p-8 text-gray-500">No documents found in ledger.</td></tr>';
        }

        async function markPaid(id) {
            const res = await api('mark_paid', { id });
            if(res && res.success) { await initApp(); showToast("Marked Paid."); }
        }

        function renderPnl() {
            const fySelect = document.getElementById('fy-filter');
            const selectedFY = fySelect ? fySelect.value : 'all';

            let salesExGst = 0; let gstCol = 0; let expTotal = 0; let gstPaid = 0; let h = ''; let eh = '';
            
            appState.invoices.forEach(i => {
                const invFY = getFinancialYear(i.issueDate);
                if (selectedFY !== 'all' && invFY != selectedFY) return;

                if(i.status === 'paid') {
                    salesExGst += parseFloat(i.amount);
                    gstCol += parseFloat(i.gstAmount);
                }
            });

            appState.expenses.forEach(ex => {
                const exFY = getFinancialYear(ex.date);
                if (selectedFY !== 'all' && exFY != selectedFY) return;

                let amount = parseFloat(ex.amount);
                expTotal += amount;
                if(ex.includesGst == 1) { gstPaid += (amount / 11); }
                h += `<tr class="border-b hover:bg-slate-50 transition"><td class="p-4 text-sm text-gray-600">${formatDate(ex.date)}</td><td class="p-4 text-sm"><span class="bg-gray-100 border text-gray-600 px-2 py-1 rounded text-xs font-bold">${ex.cat}</span></td><td class="p-4 text-sm">${ex.description}</td><td class="p-4 text-right text-red-500 font-mono font-bold">${formatCurrency(amount)}</td></tr>`;
                eh += `<tr class="border-b"><td class="p-2">${formatDate(ex.date)}</td><td class="p-2">${ex.cat}</td><td class="p-2">${ex.description}</td><td class="p-2 text-right">${formatCurrency(amount)}</td></tr>`;
            });
            
            document.getElementById('expenses-body').innerHTML = h || '<tr><td colspan="4" class="text-center p-8 text-gray-500">No expenses logged for this period.</td></tr>';
            document.getElementById('eofy-table-body').innerHTML = eh;
            
            document.getElementById('bas-sales').innerText = formatCurrency(salesExGst);
            document.getElementById('bas-gst-col').innerText = formatCurrency(gstCol);
            document.getElementById('bas-gst-paid').innerText = formatCurrency(gstPaid);
            document.getElementById('bas-net-ato').innerText = formatCurrency(gstCol - gstPaid);

            document.getElementById('eofy-rev').innerText = formatCurrency(salesExGst + gstCol);
            document.getElementById('eofy-exp').innerText = formatCurrency(expTotal);
            document.getElementById('eofy-prof').innerText = formatCurrency((salesExGst + gstCol) - expTotal);
            document.getElementById('eofy-bname').innerText = appState.settings.bName || 'JP Websites';
            
            const periodText = selectedFY === 'all' ? 'All Records' : `Financial Year Ending June 30, ${selectedFY}`;
            document.getElementById('eofy-period').innerText = periodText;
        }

        async function handleAddExpense(e) {
            e.preventDefault();
            const payload = { 
                date: document.getElementById('e-date').value, 
                cat: document.getElementById('e-cat').value, 
                desc: document.getElementById('e-desc').value, 
                amount: document.getElementById('e-amount').value,
                includesGst: document.getElementById('e-gst').checked ? 1 : 0
            };
            const res = await api('save_expense', payload);
            if(res && res.success) { e.target.reset(); await initApp(); showToast("Expense logged."); }
        }

        function prepareEOFYPrint() {
            document.getElementById('app-shell').classList.add('print-hide');
            document.getElementById('eofy-print-view').classList.remove('print-only');
            window.print();
            document.getElementById('app-shell').classList.remove('print-hide');
            document.getElementById('eofy-print-view').classList.add('print-only');
        }

        function initSettingsForm() {
            const s = appState.settings;
            document.getElementById('s-name').value = s.bName || ''; document.getElementById('s-abn').value = s.bAbn || '';
            document.getElementById('s-gst').checked = s.gstRegistered == 1;
            document.getElementById('s-payid').value = s.payid || ''; document.getElementById('s-bsb').value = s.bsb || ''; document.getElementById('s-acc').value = s.acc || '';
            document.getElementById('s-stripe').value = s.stripeUrl || ''; document.getElementById('s-btc').value = s.btc || ''; document.getElementById('s-eth').value = s.eth || '';
            
            document.getElementById('s-email-inv-subj').value = s.emailSubjInv || ''; document.getElementById('s-email-inv-body').value = s.emailBodyInv || '';
            document.getElementById('s-email-rem-subj').value = s.emailSubjRem || ''; document.getElementById('s-email-rem-body').value = s.emailBodyRem || '';
            document.getElementById('s-email-overdue-subj').value = s.emailSubjOverdue || ''; document.getElementById('s-email-overdue-body').value = s.emailBodyOverdue || '';
        }

        async function handleSaveSettings(e) {
            e.preventDefault();
            const payload = {
                bName: document.getElementById('s-name').value, bAbn: document.getElementById('s-abn').value, gstRegistered: document.getElementById('s-gst').checked,
                payid: document.getElementById('s-payid').value, bsb: document.getElementById('s-bsb').value, acc: document.getElementById('s-acc').value,
                stripeUrl: document.getElementById('s-stripe').value, btc: document.getElementById('s-btc').value, eth: document.getElementById('s-eth').value,
                emailSubjInv: document.getElementById('s-email-inv-subj').value, emailBodyInv: document.getElementById('s-email-inv-body').value,
                emailSubjRem: document.getElementById('s-email-rem-subj').value, emailBodyRem: document.getElementById('s-email-rem-body').value,
                emailSubjOverdue: document.getElementById('s-email-overdue-subj').value, emailBodyOverdue: document.getElementById('s-email-overdue-body').value
            };
            const res = await api('save_settings', payload);
            if(res && res.success) { await initApp(); showToast("Configurations updated."); }
        }

        <?php if ($isLoggedIn): ?> initApp(); <?php endif; ?>
    </script>
</body>
</html>