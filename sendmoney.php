<?php
session_start();

// Redirect if user not logged in
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit();
}

// DB Connection
$conn = new mysqli("localhost", "root", "", "wpl");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $senderPhone = $_SESSION['login'];
    $recipientPhone = trim($_POST['receiver_phone']);
    $amount = trim($_POST['amount']);

    // Validate inputs
    if (!preg_match('/^\d{10}$/', $recipientPhone)) {
        $error = "Invalid recipient phone number. Use a 10-digit number.";
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $error = "Invalid amount. Enter a positive number.";
    } elseif ($recipientPhone === $senderPhone) {
        $error = "You cannot send money to yourself.";
    } else {
        // Start transaction
        $conn->begin_transaction();

        try {
            // Get recipient info
            $stmt = $conn->prepare("SELECT id, balance FROM users WHERE phone = ?");
            $stmt->bind_param("s", $recipientPhone);
            $stmt->execute();
            $recipientResult = $stmt->get_result();
            if ($recipientResult->num_rows !== 1) {
                throw new Exception("Recipient not found.");
            }
            $recipientData = $recipientResult->fetch_assoc();
            $recipientID = $recipientData['id'];
            $recipientBalance = $recipientData['balance'];
            $stmt->close();

            // Get sender info
            $stmt = $conn->prepare("SELECT id, balance FROM users WHERE phone = ?");
            $stmt->bind_param("s", $senderPhone);
            $stmt->execute();
            $senderResult = $stmt->get_result();
            if ($senderResult->num_rows !== 1) {
                throw new Exception("Sender not found.");
            }
            $senderData = $senderResult->fetch_assoc();
            $senderID = $senderData['id'];
            $senderBalance = $senderData['balance'];
            $stmt->close();

            // Check sufficient funds
            if ($senderBalance < $amount) {
                throw new Exception("Insufficient balance.");
            }

            // Update sender balance
            $newSenderBalance = $senderBalance - $amount;
            $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $stmt->bind_param("di", $newSenderBalance, $senderID);
            $stmt->execute();
            $stmt->close();

            // Update recipient balance
            $newRecipientBalance = $recipientBalance + $amount;
            $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $stmt->bind_param("di", $newRecipientBalance, $recipientID);
            $stmt->execute();
            $stmt->close();

            // Record the transaction
            $stmt = $conn->prepare("INSERT INTO transactions (user_id, receiver_phone, amount, transaction_type) VALUES (?, ?, ?, 'send')");
            $stmt->bind_param("isd", $senderID, $recipientPhone, $amount);
            $stmt->execute();
            $stmt->close();

            // Commit everything
            $conn->commit();

            $success = "Transfer successful! Redirecting...";
            header("refresh:2; url=dashboard.php");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Transaction failed: " . $e->getMessage();
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Send Money</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card mx-auto shadow" style="max-width: 500px;">
        <div class="card-body">
            <h3 class="card-title text-center mb-4">Send Money</h3>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php elseif (!empty($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="sendmoney.php">
                <div class="mb-3">
                    <label for="receiver_phone" class="form-label">Recipient Phone Number</label>
                    <input type="tel" class="form-control" id="receiver_phone" name="receiver_phone" required placeholder="e.g. 9876543210">
                </div>

                <div class="mb-3">
                    <label for="amount" class="form-label">Amount (₹)</label>
                    <input type="number" class="form-control" id="amount" name="amount" step="0.01" required placeholder="e.g. 100.00">
                </div>

                <button type="submit" class="btn btn-primary w-100">Send</button>
                <a href="transactions.php" class="btn btn-secondary w-100 mt-2">Transaction Log</a>
            </form>
        </div>
    </div>
</div>
</body>
</html>
