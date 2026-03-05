<?php
// setup.php
require_once 'config.php';

try {
    // 1. Invoices Ledger
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clientName VARCHAR(255) NOT NULL,
        clientEmail VARCHAR(255) NOT NULL,
        clientAddress TEXT,
        issueDate DATE NOT NULL,
        dueDate DATE,
        items JSON NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        gstAmount DECIMAL(10,2) DEFAULT 0.00,
        notes TEXT,
        status ENUM('unpaid', 'paid') DEFAULT 'unpaid',
        docType ENUM('invoice', 'quote') DEFAULT 'invoice',
        hash VARCHAR(64) NOT NULL UNIQUE,
        viewedAt DATETIME NULL,
        lateFeeApplied TINYINT(1) DEFAULT 0,
        createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Expense Ledger (For P&L and BAS reporting)
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL,
        cat VARCHAR(100) NOT NULL,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        includesGst TINYINT(1) DEFAULT 1,
        file VARCHAR(255) NULL,
        createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 3. Subscriptions (For SEO & Hosting Retainers)
    $pdo->exec("CREATE TABLE IF NOT EXISTS retainers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clientName VARCHAR(255) NOT NULL,
        clientEmail VARCHAR(255) NOT NULL,
        service VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        freq ENUM('Weekly', 'Monthly', 'Quarterly', 'Annually') DEFAULT 'Monthly',
        nextDate DATE NOT NULL,
        status ENUM('active', 'paused') DEFAULT 'active'
    )");

    // 4. Master Settings Store
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT PRIMARY KEY,
        payload JSON NOT NULL
    )");

    // Seed default Perth-based Web Design settings if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
    if ($stmt->fetchColumn() == 0) {
        $defaultSettings = json_encode([
            'bName' => 'JP Websites',
            'bAbn' => '12 345 678 901',
            'bEmail' => 'hello@jpwebsites.com.au',
            'bPhone' => '0467 020 224',
            'bAddr' => "Sorrento, Perth WA 6020",
            'gstRegistered' => true,
            'payid' => 'hello@jpwebsites.com.au',
            'bsb' => '123-456',
            'acc' => '12345678',
            'stripeUrl' => 'https://buy.stripe.com/your_test_link',
            'btc' => 'bc1q_your_wallet_here',
            'eth' => '0x_your_wallet_here',
            'dunEnabled' => true,
            'dunPercent' => 5,
            'emailSubjInv' => 'Tax Invoice #{id} from {business}',
            'emailBodyInv' => "Hi {client},\n\nPlease find attached your document #{id} for {amount}.\n\nView and pay securely here:\n{link}\n\nWe appreciate your prompt payment.\n\nRegards,\n{business}",
            'emailSubjRem' => 'Reminder: Tax Invoice #{id} is due',
            'emailBodyRem' => "Hi {client},\n\nJust a friendly reminder that invoice #{id} for {amount} is currently due.\n\nPlease arrange payment at your earliest convenience via the portal:\n{link}\n\nRegards,\n{business}",
            'emailSubjOverdue' => 'Overdue Notice: Tax Invoice #{id}',
            'emailBodyOverdue' => "Hi {client},\n\nYour invoice #{id} is now overdue. Please clear this balance immediately to avoid service interruption or late administration fees:\n{link}\n\nRegards,\n{business}"
        ]);
        $pdo->prepare("INSERT INTO settings (id, payload) VALUES (1, ?)")->execute([$defaultSettings]);
    }

    echo "<div style='font-family: sans-serif; padding: 40px; text-align: center; background-color: #f8fafc; border-radius: 8px; max-width: 600px; margin: 40px auto; border: 1px solid #e2e8f0;'>";
    echo "<h2 style='color: #16a34a; margin-bottom: 16px;'>Database Provisioned Successfully.</h2>";
    echo "<p style='color: #475569; margin-bottom: 24px; line-height: 1.5;'>The core tables and default ATO settings have been successfully injected into your MySQL instance.</p>";
    echo "<div style='background-color: #fee2e2; border-left: 4px solid #ef4444; padding: 16px; border-radius: 4px;'>";
    echo "<p style='color: #991b1b; font-weight: bold; margin: 0;'>Security Action Required:</p>";
    echo "<p style='color: #7f1d1d; margin: 8px 0 0 0;'>Please delete <strong>setup.php</strong> from your cPanel file manager immediately to secure your environment.</p>";
    echo "</div>";
    echo "</div>";

} catch (PDOException $e) {
    die("<div style='font-family: sans-serif; padding: 40px; text-align: center; color: #dc2626;'><h3>Setup Error</h3><p>" . htmlspecialchars($e->getMessage()) . "</p></div>");
}