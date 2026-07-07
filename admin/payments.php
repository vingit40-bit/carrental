<?php
session_start();
require_once '../config/connect.php';

$admin_base = true;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        $conn = getConnection();
        
        // Generate unique payment number
        function generatePaymentNumber($conn) {
            $year = date('Y');
            $month = date('m');
            $prefix = "PAY-{$year}{$month}-";
            
            $sql = "SELECT COUNT(*) as count FROM payments WHERE payment_number LIKE '$prefix%'";
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            $count = $row['count'] + 1;
            
            return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
        }
        
        // Generate unique invoice number
        function generateInvoiceNumber($conn) {
            $year = date('Y');
            $month = date('m');
            $prefix = "INV-{$year}{$month}-";
            
            $sql = "SELECT COUNT(*) as count FROM invoices WHERE invoice_number LIKE '$prefix%'";
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            $count = $row['count'] + 1;
            
            return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
        }
        
        // Generate unique refund number
        function generateRefundNumber($conn) {
            $year = date('Y');
            $month = date('m');
            $prefix = "REF-{$year}{$month}-";
            
            $sql = "SELECT COUNT(*) as count FROM refunds WHERE refund_number LIKE '$prefix%'";
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            $count = $row['count'] + 1;
            
            return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
        }
        
        // Get Dashboard Statistics
        if ($_POST['action'] === 'get_stats') {
            // Collected Today
            $today = date('Y-m-d');
            $collected_today = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) = '$today' AND status = 'Completed'")->fetch_assoc()['total'];
            
            // Pending Payments
            $pending_payments = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE payment_status = 'Pending' AND status != 'Cancelled'")->fetch_assoc()['count'];
            
            // Security Deposits Held
            $deposits_held = $conn->query("SELECT COALESCE(SUM(deposit_amount), 0) as total FROM reservations WHERE deposit_amount > 0 AND deposit_refunded = 0")->fetch_assoc()['total'];
            
            // Refunds This Month
            $first_day = date('Y-m-01');
            $last_day = date('Y-m-t');
            $refunds_month = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM refunds WHERE DATE(refund_date) BETWEEN '$first_day' AND '$last_day' AND status = 'Completed'")->fetch_assoc()['total'];
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'collected_today' => $collected_today,
                    'pending_payments' => $pending_payments,
                    'deposits_held' => $deposits_held,
                    'refunds_month' => $refunds_month
                ]
            ]);
        }
        
        // Get Payment Records
        elseif ($_POST['action'] === 'get_payments') {
            $method = isset($_POST['method']) ? $conn->real_escape_string($_POST['method']) : 'all';
            $search = isset($_POST['search']) ? $conn->real_escape_string($_POST['search']) : '';
            
            $where = [];
            if ($method !== 'all') {
                $where[] = "p.payment_method = '$method'";
            }
            if (!empty($search)) {
                $where[] = "(p.payment_number LIKE '%$search%' OR c.first_name LIKE '%$search%' OR c.last_name LIKE '%$search%' OR i.invoice_number LIKE '%$search%')";
            }
            
            $where_clause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
            
            $sql = "SELECT p.*, 
                    c.first_name, c.last_name,
                    r.reservation_number,
                    i.invoice_number
                    FROM payments p
                    JOIN customers c ON p.customer_id = c.id
                    JOIN reservations r ON p.reservation_id = r.id
                    LEFT JOIN invoices i ON r.id = i.reservation_id
                    $where_clause
                    ORDER BY p.payment_date DESC
                    LIMIT 50";
            
            $result = $conn->query($sql);
            $payments = [];
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $payments[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'payments' => $payments]);
        }
        
        // Get Pending Payments (from reservations)
        elseif ($_POST['action'] === 'get_pending_payments') {
            $sql = "SELECT r.*, 
                    c.first_name, c.last_name, c.email, c.phone,
                    v.vehicle_name
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    WHERE r.payment_status IN ('Pending', 'Partial')
                    AND r.status != 'Cancelled'
                    ORDER BY 
                        CASE 
                            WHEN r.payment_status = 'Pending' THEN 1
                            ELSE 2
                        END,
                        r.pickup_date ASC";
            
            $result = $conn->query($sql);
            $pending = [];
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    // Calculate overdue status
                    $pickup = new DateTime($row['pickup_date']);
                    $today = new DateTime();
                    $row['is_overdue'] = $pickup < $today && $row['status'] == 'Confirmed';
                    $pending[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'pending' => $pending]);
        }
        
        // Get Invoices
        elseif ($_POST['action'] === 'get_invoices') {
            $status = isset($_POST['status']) ? $conn->real_escape_string($_POST['status']) : 'all';
            
            $where = [];
            if ($status !== 'all') {
                $where[] = "i.status = '$status'";
            }
            
            $where_clause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
            
            $sql = "SELECT i.*, 
                    c.first_name, c.last_name,
                    r.reservation_number,
                    v.vehicle_name
                    FROM invoices i
                    JOIN customers c ON i.customer_id = c.id
                    JOIN reservations r ON i.reservation_id = r.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    $where_clause
                    ORDER BY i.created_at DESC
                    LIMIT 20";
            
            $result = $conn->query($sql);
            $invoices = [];
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $invoices[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'invoices' => $invoices]);
        }
        
        // Get Refunds
        elseif ($_POST['action'] === 'get_refunds') {
            $sql = "SELECT rf.*, 
                    c.first_name, c.last_name,
                    r.reservation_number,
                    p.payment_number,
                    p.payment_method
                    FROM refunds rf
                    JOIN customers c ON rf.customer_id = c.id
                    JOIN reservations r ON rf.reservation_id = r.id
                    JOIN payments p ON rf.payment_id = p.id
                    ORDER BY rf.created_at DESC
                    LIMIT 20";
            
            $result = $conn->query($sql);
            $refunds = [];
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $refunds[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'refunds' => $refunds]);
        }
        
        // Get Payment Methods Distribution
        elseif ($_POST['action'] === 'get_payment_methods') {
            $sql = "SELECT payment_method, COUNT(*) as count, COALESCE(SUM(amount), 0) as total
                    FROM payments 
                    WHERE status = 'Completed'
                    GROUP BY payment_method
                    ORDER BY total DESC";
            
            $result = $conn->query($sql);
            $methods = [];
            $total_all = 0;
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $methods[] = $row;
                    $total_all += $row['total'];
                }
            }
            
            // Calculate percentages
            foreach ($methods as &$method) {
                $method['percentage'] = $total_all > 0 ? round(($method['total'] / $total_all) * 100) : 0;
            }
            
            echo json_encode(['success' => true, 'methods' => $methods, 'total' => $total_all]);
        }
        
        // Create Payment
        elseif ($_POST['action'] === 'create_payment') {
            $reservation_id = (int)$_POST['reservation_id'];
            $customer_id = (int)$_POST['customer_id'];
            $amount = (float)$_POST['amount'];
            $payment_method = $conn->real_escape_string($_POST['payment_method']);
            $payment_type = $conn->real_escape_string($_POST['payment_type']);
            $reference_number = isset($_POST['reference_number']) ? $conn->real_escape_string($_POST['reference_number']) : '';
            $notes = isset($_POST['notes']) ? $conn->real_escape_string($_POST['notes']) : '';
            
            $payment_number = generatePaymentNumber($conn);
            $payment_date = date('Y-m-d H:i:s');
            
            $conn->begin_transaction();
            
            try {
                // Insert payment record
                $sql = "INSERT INTO payments (
                        payment_number, reservation_id, customer_id, payment_date,
                        amount, payment_method, reference_number, payment_type, status, notes
                        ) VALUES (
                        '$payment_number', $reservation_id, $customer_id, '$payment_date',
                        $amount, '$payment_method', '$reference_number', '$payment_type', 'Completed', '$notes'
                        )";
                
                if (!$conn->query($sql)) {
                    throw new Exception("Failed to insert payment: " . $conn->error);
                }
                
                $payment_id = $conn->insert_id;
                
                // Update reservation total_paid
                $update_sql = "UPDATE reservations SET total_paid = total_paid + $amount 
                              WHERE id = $reservation_id";
                
                if (!$conn->query($update_sql)) {
                    throw new Exception("Failed to update reservation: " . $conn->error);
                }
                
                // Check if fully paid
                $check_sql = "SELECT total_amount, total_paid FROM reservations WHERE id = $reservation_id";
                $check_result = $conn->query($check_sql);
                $res = $check_result->fetch_assoc();
                
                if ($res['total_paid'] >= $res['total_amount']) {
                    $conn->query("UPDATE reservations SET payment_status = 'Paid' WHERE id = $reservation_id");
                } else {
                    $conn->query("UPDATE reservations SET payment_status = 'Partial' WHERE id = $reservation_id");
                }
                
                // Update or create invoice
                $invoice_sql = "SELECT id FROM invoices WHERE reservation_id = $reservation_id";
                $invoice_result = $conn->query($invoice_sql);
                
                if ($invoice_result->num_rows > 0) {
                    $invoice = $invoice_result->fetch_assoc();
                    $invoice_id = $invoice['id'];
                    
                    // Update invoice paid amount
                    $conn->query("UPDATE invoices SET paid_amount = paid_amount + $amount 
                                 WHERE id = $invoice_id");
                    
                    // Update invoice status
                    $inv_check = $conn->query("SELECT total_amount, paid_amount FROM invoices WHERE id = $invoice_id")->fetch_assoc();
                    if ($inv_check['paid_amount'] >= $inv_check['total_amount']) {
                        $conn->query("UPDATE invoices SET status = 'Paid' WHERE id = $invoice_id");
                    } else {
                        $conn->query("UPDATE invoices SET status = 'Partial' WHERE id = $invoice_id");
                    }
                } else {
                    // Create new invoice
                    $invoice_number = generateInvoiceNumber($conn);
                    
                    // Get reservation details
                    $res_sql = "SELECT * FROM reservations WHERE id = $reservation_id";
                    $res_result = $conn->query($res_sql);
                    $res_data = $res_result->fetch_assoc();
                    
                    $invoice_date = date('Y-m-d');
                    $due_date = date('Y-m-d', strtotime('+7 days'));
                    
                    $inv_insert = "INSERT INTO invoices (
                                   invoice_number, reservation_id, customer_id,
                                   invoice_date, due_date, subtotal, total_amount,
                                   paid_amount, status
                                   ) VALUES (
                                   '$invoice_number', $reservation_id, $customer_id,
                                   '$invoice_date', '$due_date', {$res_data['total_amount']}, {$res_data['total_amount']},
                                   $amount, 'Partial'
                                   )";
                    
                    if (!$conn->query($inv_insert)) {
                        throw new Exception("Failed to create invoice: " . $conn->error);
                    }
                }
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Payment recorded successfully', 'payment_number' => $payment_number]);
                
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        
        // Process Refund
        elseif ($_POST['action'] === 'process_refund') {
            $payment_id = (int)$_POST['payment_id'];
            $reservation_id = (int)$_POST['reservation_id'];
            $customer_id = (int)$_POST['customer_id'];
            $amount = (float)$_POST['amount'];
            $reason = $conn->real_escape_string($_POST['reason']);
            
            $refund_number = generateRefundNumber($conn);
            $refund_date = date('Y-m-d H:i:s');
            
            $conn->begin_transaction();
            
            try {
                // Insert refund record
                $sql = "INSERT INTO refunds (
                        refund_number, payment_id, reservation_id, customer_id,
                        refund_date, amount, reason, status
                        ) VALUES (
                        '$refund_number', $payment_id, $reservation_id, $customer_id,
                        '$refund_date', $amount, '$reason', 'Completed'
                        )";
                
                if (!$conn->query($sql)) {
                    throw new Exception("Failed to insert refund: " . $conn->error);
                }
                
                // Update payment status
                $conn->query("UPDATE payments SET status = 'Refunded' WHERE id = $payment_id");
                
                // Update reservation total_paid
                $conn->query("UPDATE reservations SET total_paid = total_paid - $amount 
                             WHERE id = $reservation_id");
                
                // Update payment status
                $check_sql = "SELECT total_amount, total_paid FROM reservations WHERE id = $reservation_id";
                $check_result = $conn->query($check_sql);
                $res = $check_result->fetch_assoc();
                
                if ($res['total_paid'] <= 0) {
                    $conn->query("UPDATE reservations SET payment_status = 'Pending' WHERE id = $reservation_id");
                } else {
                    $conn->query("UPDATE reservations SET payment_status = 'Partial' WHERE id = $reservation_id");
                }
                
                // Update invoice
                $invoice_sql = "SELECT id FROM invoices WHERE reservation_id = $reservation_id";
                $invoice_result = $conn->query($invoice_sql);
                
                if ($invoice_result->num_rows > 0) {
                    $invoice = $invoice_result->fetch_assoc();
                    $invoice_id = $invoice['id'];
                    
                    $conn->query("UPDATE invoices SET paid_amount = paid_amount - $amount 
                                 WHERE id = $invoice_id");
                    
                    $inv_check = $conn->query("SELECT total_amount, paid_amount FROM invoices WHERE id = $invoice_id")->fetch_assoc();
                    if ($inv_check['paid_amount'] <= 0) {
                        $conn->query("UPDATE invoices SET status = 'Draft' WHERE id = $invoice_id");
                    } else {
                        $conn->query("UPDATE invoices SET status = 'Partial' WHERE id = $invoice_id");
                    }
                }
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Refund processed successfully', 'refund_number' => $refund_number]);
                
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        
        // Update Payment Status
        elseif ($_POST['action'] === 'update_payment_status') {
            $id = (int)$_POST['id'];
            $status = $conn->real_escape_string($_POST['status']);
            
            $sql = "UPDATE payments SET status = '$status' WHERE id = $id";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Payment status updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Create Invoice
        elseif ($_POST['action'] === 'create_invoice') {
            $reservation_id = (int)$_POST['reservation_id'];
            $customer_id = (int)$_POST['customer_id'];
            $due_date = $conn->real_escape_string($_POST['due_date']);
            $discount = isset($_POST['discount']) ? (float)$_POST['discount'] : 0;
            $notes = isset($_POST['notes']) ? $conn->real_escape_string($_POST['notes']) : '';
            
            // Get reservation details
            $res_sql = "SELECT * FROM reservations WHERE id = $reservation_id";
            $res_result = $conn->query($res_sql);
            $res = $res_result->fetch_assoc();
            
            $invoice_number = generateInvoiceNumber($conn);
            $invoice_date = date('Y-m-d');
            $subtotal = $res['total_amount'];
            $total_amount = $subtotal - $discount;
            
            $sql = "INSERT INTO invoices (
                    invoice_number, reservation_id, customer_id,
                    invoice_date, due_date, subtotal, discount_amount,
                    total_amount, paid_amount, status, notes
                    ) VALUES (
                    '$invoice_number', $reservation_id, $customer_id,
                    '$invoice_date', '$due_date', $subtotal, $discount,
                    $total_amount, {$res['total_paid']}, 'Sent', '$notes'
                    )";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Invoice created successfully', 'invoice_number' => $invoice_number]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        $conn->close();
        exit;
    }
}

// Fetch initial statistics
$conn = getConnection();

$collected_today = 0;
$pending_payments = 0;
$deposits_held = 0;
$refunds_month = 0;

if ($conn) {
    $today = date('Y-m-d');
    $collected = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) = '$today' AND status = 'Completed'");
    $collected_today = $collected ? $collected->fetch_assoc()['total'] : 0;
    
    $pending = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE payment_status = 'Pending' AND status != 'Cancelled'");
    $pending_payments = $pending ? $pending->fetch_assoc()['count'] : 0;
    
    $deposits = $conn->query("SELECT COALESCE(SUM(deposit_amount), 0) as total FROM reservations WHERE deposit_amount > 0 AND deposit_refunded = 0");
    $deposits_held = $deposits ? $deposits->fetch_assoc()['total'] : 0;
    
    $first_day = date('Y-m-01');
    $last_day = date('Y-m-t');
    $refunds = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM refunds WHERE DATE(refund_date) BETWEEN '$first_day' AND '$last_day' AND status = 'Completed'");
    $refunds_month = $refunds ? $refunds->fetch_assoc()['total'] : 0;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments & Billing - Velocity Rentals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        html {
            font-size: 14px;
        }
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #1f2937;
        }
        ::-webkit-scrollbar-thumb {
            background: #dc2626;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #b91c1c;
        }

        .card-glow {
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.04),
                0 10px 30px rgba(0, 0, 0, 0.35),
                0 0 22px rgba(255, 255, 255, 0.05);
        }
        .card-glow:hover {
            box-shadow:
                0 0 0 1px rgba(220, 38, 38, 0.28),
                0 14px 34px rgba(0, 0, 0, 0.45),
                0 0 30px rgba(220, 38, 38, 0.18);
        }

        .header-glow {
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.05),
                0 8px 24px rgba(0, 0, 0, 0.4),
                0 0 24px rgba(255, 255, 255, 0.06);
        }

        .light-strip {
            position: relative;
        }
        .light-strip::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(220, 38, 38, 0.5), transparent);
        }

        /* Modal animations */
        .modal-enter {
            animation: modalFadeIn 0.3s ease-out;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Loading spinner */
        .spinner {
            border: 3px solid rgba(220, 38, 38, 0.1);
            border-top: 3px solid #dc2626;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Status badges */
        .status-badge {
            @apply px-2 py-1 text-xs font-medium rounded-full;
        }
        
        .status-paid {
            @apply bg-emerald-500/10 text-emerald-500;
        }
        
        .status-pending {
            @apply bg-amber-500/10 text-amber-500;
        }
        
        .status-partial {
            @apply bg-blue-500/10 text-blue-500;
        }
        
        .status-overdue {
            @apply bg-red-500/10 text-red-500;
        }
        
        .status-refunded {
            @apply bg-purple-500/10 text-purple-500;
        }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen">
    <?php include '../includes/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">
        <header class="header-glow bg-gray-900/90 backdrop-blur-md border-b border-gray-700/50 sticky top-0 z-30">
            <div class="flex items-center justify-between h-16 px-4 lg:px-6">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg hover:bg-gray-800 text-gray-400 hover:text-white transition-colors">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <div class="w-1 h-8 bg-gradient-to-b from-red-500 to-red-700 rounded-full"></div>
                        <div>
                            <h1 class="text-xl font-bold text-white">Payments & Billing</h1>
                            <p class="text-xs text-gray-500">Track transactions, invoices, and payment channels</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="hidden md:flex items-center bg-gray-800/80 rounded-lg px-3 py-2 border border-gray-600/30">
                        <i class="fas fa-search text-gray-500 text-sm"></i>
                        <input type="text" id="globalSearch" placeholder="Search payment..." onkeyup="searchPayments()" class="bg-transparent border-none outline-none text-sm text-gray-300 placeholder-gray-500 ml-2 w-52">
                    </div>
                    <button onclick="openInvoiceModal()" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white rounded-lg text-sm font-medium transition-all shadow-lg shadow-red-600/20">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Create Invoice</span>
                    </button>
                </div>
            </div>
        </header>

        <div class="p-4 lg:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-emerald-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-600/30 to-emerald-700/10 flex items-center justify-center border border-emerald-500/20">
                            <i class="fas fa-coins text-emerald-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Collected Today</h3>
                    <p class="text-2xl font-bold text-white" id="collectedToday">₱<?php echo number_format($collected_today, 2); ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-amber-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-600/30 to-amber-700/10 flex items-center justify-center border border-amber-500/20">
                            <i class="fas fa-clock text-amber-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Pending Payments</h3>
                    <p class="text-2xl font-bold text-white" id="pendingPayments"><?php echo $pending_payments; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-blue-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-600/30 to-blue-700/10 flex items-center justify-center border border-blue-500/20">
                            <i class="fas fa-shield-alt text-blue-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Security Deposits Held</h3>
                    <p class="text-2xl font-bold text-white" id="depositsHeld">₱<?php echo number_format($deposits_held, 2); ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-red-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-600/30 to-red-700/10 flex items-center justify-center border border-red-500/20">
                            <i class="fas fa-undo-alt text-red-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Refunds This Month</h3>
                    <p class="text-2xl font-bold text-white" id="refundsMonth">₱<?php echo number_format($refunds_month, 2); ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <button onclick="filterPayments('all')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-receipt text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">All Payments</span>
                </button>
                <button onclick="loadPendingPayments()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-amber-500/50 rounded-xl transition-all group">
                    <i class="fas fa-clock text-amber-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Pending</span>
                </button>
                <button onclick="loadInvoices()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-blue-500/50 rounded-xl transition-all group">
                    <i class="fas fa-file-invoice text-blue-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Invoices</span>
                </button>
                <button onclick="loadRefunds()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-purple-500/50 rounded-xl transition-all group">
                    <i class="fas fa-undo text-purple-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Refunds</span>
                </button>
                <button onclick="filterByMethod('Cash')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-emerald-500/50 rounded-xl transition-all group">
                    <i class="fas fa-money-bill-wave text-emerald-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Cash</span>
                </button>
                <button onclick="filterByMethod('GCash')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-blue-500/50 rounded-xl transition-all group">
                    <i class="fas fa-mobile-alt text-blue-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">GCash</span>
                </button>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
                <!-- Payment Records Table -->
                <div class="xl:col-span-2 card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Payment Records</h2>
                            <p class="text-sm text-gray-500">Recent completed transactions</p>
                        </div>
                        <select id="methodFilter" onchange="filterPayments()" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                            <option value="all">All Methods</option>
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Card">Card</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-700/30 bg-gray-800/30">
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Txn ID</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Customer</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Invoice</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Method</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Amount</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Status</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="paymentTableBody" class="divide-y divide-gray-700/30">
                                <tr>
                                    <td colspan="7" class="px-5 py-8 text-center text-gray-500">
                                        <div class="spinner"></div>
                                        <p class="mt-2">Loading payments...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pending Payments Panel -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Pending Payments</h2>
                        <p class="text-sm text-gray-500">Awaiting collection</p>
                    </div>
                    <div id="pendingList" class="p-4 space-y-3 max-h-96 overflow-y-auto">
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                    <div class="p-4 pt-0">
                        <button onclick="loadPendingPayments()" class="w-full py-2 text-sm text-red-500 hover:text-red-400 border border-red-500/20 rounded-lg hover:border-red-500/40 transition-colors">
                            Refresh Pending Queue
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Invoices / Receipts -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Invoices / Receipts</h2>
                        <p class="text-sm text-gray-500">Generated billing documents</p>
                    </div>
                    <div id="invoicesList" class="p-4 space-y-3 max-h-80 overflow-y-auto">
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                    <div class="p-4 pt-0">
                        <button onclick="openInvoiceModal()" class="w-full py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-plus mr-2"></i>Create New Invoice
                        </button>
                    </div>
                </div>

                <!-- Refunds & Deposits -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Refunds & Deposits</h2>
                        <p class="text-sm text-gray-500">Refund workflow and held deposits</p>
                    </div>
                    <div id="refundsList" class="p-4 space-y-3 max-h-80 overflow-y-auto">
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods Distribution -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Payment Methods</h2>
                        <p class="text-sm text-gray-500">Cash, GCash, Card split</p>
                    </div>
                    <div id="methodsList" class="p-4 space-y-3">
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Payment Modal -->
    <div id="paymentModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closePaymentModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-lg max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700 sticky top-0 bg-gray-900 z-10">
                    <h2 class="text-xl font-semibold text-white">Record Payment</h2>
                    <button onclick="closePaymentModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="paymentForm" class="p-5 space-y-4">
                    <input type="hidden" id="paymentReservationId">
                    <input type="hidden" id="paymentCustomerId">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Reservation #</label>
                        <input type="text" id="paymentReservationNumber" readonly class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-gray-300">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Customer</label>
                        <input type="text" id="paymentCustomerName" readonly class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-gray-300">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Amount Due</label>
                        <input type="text" id="paymentAmountDue" readonly class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-gray-300">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Payment Amount (₱)</label>
                        <input type="number" id="paymentAmount" step="0.01" min="1" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Payment Method</label>
                        <select id="paymentMethod" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Card">Card</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Payment Type</label>
                        <select id="paymentType" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                            <option value="Rental">Rental Payment</option>
                            <option value="Deposit">Security Deposit</option>
                            <option value="Extension">Extension Fee</option>
                            <option value="Late Fee">Late Fee</option>
                            <option value="Partial">Partial Payment</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Reference Number (Optional)</label>
                        <input type="text" id="paymentReference" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="GCash ref #, card last 4 digits">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Notes (Optional)</label>
                        <textarea id="paymentNotes" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Additional notes..."></textarea>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closePaymentModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-check mr-2"></i>Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Invoice Modal -->
    <div id="invoiceModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeInvoiceModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-lg max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700 sticky top-0 bg-gray-900 z-10">
                    <h2 class="text-xl font-semibold text-white">Create Invoice</h2>
                    <button onclick="closeInvoiceModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="invoiceForm" class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Select Reservation</label>
                        <select id="invoiceReservationId" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" onchange="loadReservationForInvoice()">
                            <option value="">Select a reservation...</option>
                        </select>
                    </div>
                    
                    <div id="invoiceReservationDetails" class="hidden bg-gray-800/50 p-3 rounded-lg">
                        <!-- Reservation details will appear here -->
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Due Date</label>
                        <input type="date" id="invoiceDueDate" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Discount (₱)</label>
                        <input type="number" id="invoiceDiscount" step="0.01" min="0" value="0" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Notes</label>
                        <textarea id="invoiceNotes" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500"></textarea>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closeInvoiceModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-file-invoice mr-2"></i>Create Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Refund Modal -->
    <div id="refundModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeRefundModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-lg max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700 sticky top-0 bg-gray-900 z-10">
                    <h2 class="text-xl font-semibold text-white">Process Refund</h2>
                    <button onclick="closeRefundModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="refundForm" class="p-5 space-y-4">
                    <input type="hidden" id="refundPaymentId">
                    <input type="hidden" id="refundReservationId">
                    <input type="hidden" id="refundCustomerId">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Payment #</label>
                        <input type="text" id="refundPaymentNumber" readonly class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-gray-300">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Customer</label>
                        <input type="text" id="refundCustomerName" readonly class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-gray-300">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Original Amount</label>
                        <input type="text" id="refundOriginalAmount" readonly class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-gray-300">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Refund Amount (₱)</label>
                        <input type="number" id="refundAmount" step="0.01" min="1" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Reason for Refund</label>
                        <textarea id="refundReason" rows="3" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Please provide reason..."></textarea>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closeRefundModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-undo-alt mr-2"></i>Process Refund
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeSuccessModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md max-h-[90vh] overflow-y-auto modal-enter">
                <div class="p-5 border-b border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-emerald-500/20 flex items-center justify-center">
                            <i class="fas fa-check-circle text-emerald-500 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Success</h3>
                    </div>
                </div>
                <div class="p-5">
                    <p id="successMessage" class="text-gray-300">Operation completed successfully!</p>
                </div>
                <div class="flex justify-end p-5 border-t border-gray-700">
                    <button onclick="closeSuccessModal()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeConfirmModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md max-h-[90vh] overflow-y-auto modal-enter">
                <div class="p-5 border-b border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-amber-500/20 flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-amber-500 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Confirm Action</h3>
                    </div>
                </div>
                <div class="p-5">
                    <p id="confirmMessage" class="text-gray-300">Are you sure you want to proceed?</p>
                </div>
                <div class="flex justify-end gap-3 p-5 border-t border-gray-700">
                    <button onclick="closeConfirmModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                    <button id="confirmActionBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            sidebar.classList.toggle('-translate-x-full');
            if (sidebar.classList.contains('-translate-x-full')) {
                overlay.classList.add('hidden');
            } else {
                overlay.classList.remove('hidden');
            }
        }

        // Close sidebar when clicking overlay on mobile
        document.getElementById('sidebar-overlay')?.addEventListener('click', toggleSidebar);

        // Modal functions
        function closeSuccessModal() {
            document.getElementById('successModal').classList.add('hidden');
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.add('hidden');
        }

        function showSuccess(message) {
            document.getElementById('successMessage').textContent = message;
            document.getElementById('successModal').classList.remove('hidden');
            setTimeout(() => {
                closeSuccessModal();
            }, 3000);
        }

        // Load initial data
        document.addEventListener('DOMContentLoaded', function() {
            loadPayments();
            loadPendingPayments();
            loadInvoices();
            loadRefunds();
            loadPaymentMethods();
            loadReservationSelect();
        });

        // Payment Functions
        function loadPayments() {
            const method = document.getElementById('methodFilter').value;
            const search = document.getElementById('globalSearch').value;
            
            const formData = new FormData();
            formData.append('action', 'get_payments');
            formData.append('method', method);
            if (search) {
                formData.append('search', search);
            }
            
            fetch('payments.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayPayments(data.payments);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function displayPayments(payments) {
            const tbody = document.getElementById('paymentTableBody');
            
            if (payments.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-gray-500">
                            <i class="fas fa-receipt text-4xl mb-2 opacity-50"></i>
                            <p>No payments found</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            let html = '';
            payments.forEach(p => {
                const statusClass = p.status === 'Completed' ? 'bg-emerald-500/10 text-emerald-500' :
                                   p.status === 'Pending' ? 'bg-amber-500/10 text-amber-500' :
                                   p.status === 'Failed' ? 'bg-red-500/10 text-red-500' :
                                   'bg-purple-500/10 text-purple-500';
                
                html += `
                    <tr class="hover:bg-gray-800/40 transition-colors">
                        <td class="px-5 py-4 text-sm font-mono text-gray-300">${p.payment_number}</td>
                        <td class="px-5 py-4 text-sm text-white">${p.first_name} ${p.last_name}</td>
                        <td class="px-5 py-4 text-sm text-gray-300">${p.invoice_number || '-'}</td>
                        <td class="px-5 py-4 text-sm text-gray-300">${p.payment_method}</td>
                        <td class="px-5 py-4 text-sm font-medium text-white">₱${parseFloat(p.amount).toFixed(2)}</td>
                        <td class="px-5 py-4">
                            <span class="px-2 py-1 text-xs font-medium ${statusClass} rounded-full">${p.status}</span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-2">
                                ${p.status === 'Completed' ? `
                                    <button onclick="openRefundModal(${p.id}, '${p.payment_number}', '${p.first_name} ${p.last_name}', ${p.amount}, ${p.reservation_id}, ${p.customer_id})" class="p-2 text-gray-400 hover:text-purple-500 hover:bg-gray-700 rounded-lg transition-colors" title="Refund">
                                        <i class="fas fa-undo-alt"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        function filterPayments() {
            loadPayments();
        }

        function filterByMethod(method) {
            document.getElementById('methodFilter').value = method;
            loadPayments();
        }

        function searchPayments() {
            loadPayments();
        }

        // Pending Payments
        function loadPendingPayments() {
            fetch('payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_pending_payments'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayPendingPayments(data.pending);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function displayPendingPayments(pending) {
            const container = document.getElementById('pendingList');
            
            if (pending.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No pending payments</p>';
                return;
            }
            
            let html = '';
            pending.forEach(p => {
                const overdueClass = p.is_overdue ? 'border-red-500/30 bg-red-500/5' : '';
                const statusText = p.payment_status === 'Pending' ? 'No payment yet' : 'Partially paid';
                
                html += `
                    <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/20 ${overdueClass} cursor-pointer hover:bg-amber-500/20 transition-colors" onclick="openPaymentModal(${p.id}, ${p.customer_id}, '${p.reservation_number}', '${p.first_name} ${p.last_name}', ${p.total_amount}, ${p.total_paid})">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-white">${p.reservation_number}</p>
                                <p class="text-xs text-gray-400">${p.first_name} ${p.last_name}</p>
                                <p class="text-xs text-gray-400">${p.vehicle_name} • Due ₱${parseFloat(p.total_amount).toFixed(2)}</p>
                                <p class="text-xs text-amber-500 mt-1">Paid: ₱${parseFloat(p.total_paid).toFixed(2)} • ${statusText}</p>
                                ${p.is_overdue ? '<p class="text-xs text-red-500 mt-1">⚠️ Overdue</p>' : ''}
                            </div>
                            <button class="text-xs bg-red-600 text-white px-2 py-1 rounded hover:bg-red-700">Pay</button>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Invoices
        function loadInvoices() {
            fetch('payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_invoices&status=all'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayInvoices(data.invoices);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function displayInvoices(invoices) {
            const container = document.getElementById('invoicesList');
            
            if (invoices.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No invoices found</p>';
                return;
            }
            
            let html = '';
            invoices.slice(0, 5).forEach(inv => {
                const statusClass = inv.status === 'Paid' ? 'bg-emerald-500/10 text-emerald-500' :
                                   inv.status === 'Sent' ? 'bg-blue-500/10 text-blue-500' :
                                   inv.status === 'Partial' ? 'bg-amber-500/10 text-amber-500' :
                                   inv.status === 'Overdue' ? 'bg-red-500/10 text-red-500' :
                                   'bg-gray-500/10 text-gray-500';
                
                html += `
                    <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-white">${inv.invoice_number}</p>
                                <p class="text-xs text-gray-400">${inv.first_name} ${inv.last_name}</p>
                                <p class="text-xs text-gray-400">${inv.vehicle_name}</p>
                                <p class="text-xs text-gray-500 mt-1">Due: ${new Date(inv.due_date).toLocaleDateString()}</p>
                            </div>
                            <span class="text-xs px-2 py-1 ${statusClass} rounded-full">${inv.status}</span>
                        </div>
                        <div class="mt-2 flex justify-between">
                            <span class="text-xs text-gray-400">Amount:</span>
                            <span class="text-sm font-bold text-white">₱${parseFloat(inv.total_amount).toFixed(2)}</span>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Refunds
        function loadRefunds() {
            fetch('payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_refunds'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayRefunds(data.refunds);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function displayRefunds(refunds) {
            const container = document.getElementById('refundsList');
            
            if (refunds.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No refunds found</p>';
                return;
            }
            
            let html = '';
            refunds.slice(0, 5).forEach(ref => {
                html += `
                    <div class="p-3 rounded-lg bg-red-500/10 border border-red-500/20">
                        <p class="text-sm font-medium text-white">${ref.refund_number}</p>
                        <p class="text-xs text-gray-400">${ref.first_name} ${ref.last_name}</p>
                        <p class="text-xs text-gray-400">${ref.reservation_number} • ₱${parseFloat(ref.amount).toFixed(2)}</p>
                        <p class="text-xs text-gray-500 mt-1">${ref.reason}</p>
                    </div>
                `;
            });
            
            // Add deposits
            html += `
                <div class="p-3 rounded-lg bg-blue-500/10 border border-blue-500/20 mt-3">
                    <p class="text-sm font-medium text-white">Security Deposits Held</p>
                    <p class="text-xs text-gray-400" id="depositsAmount">Loading...</p>
                </div>
            `;
            
            container.innerHTML = html;
        }

        // Payment Methods Distribution
        function loadPaymentMethods() {
            fetch('payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_payment_methods'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayPaymentMethods(data.methods);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function displayPaymentMethods(methods) {
            const container = document.getElementById('methodsList');
            
            if (methods.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No payment data</p>';
                return;
            }
            
            let html = '';
            methods.forEach(method => {
                html += `
                    <div class="flex items-center justify-between p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                        <span class="text-sm text-gray-300">${method.payment_method}</span>
                        <div class="text-right">
                            <span class="text-sm font-medium text-white">₱${parseFloat(method.total).toFixed(2)}</span>
                            <span class="text-xs text-gray-500 ml-2">${method.percentage}%</span>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Payment Modal
        function openPaymentModal(reservationId, customerId, reservationNumber, customerName, totalAmount, totalPaid) {
            document.getElementById('paymentReservationId').value = reservationId;
            document.getElementById('paymentCustomerId').value = customerId;
            document.getElementById('paymentReservationNumber').value = reservationNumber;
            document.getElementById('paymentCustomerName').value = customerName;
            
            const amountDue = totalAmount - totalPaid;
            document.getElementById('paymentAmountDue').value = '₱' + amountDue.toFixed(2);
            document.getElementById('paymentAmount').max = amountDue;
            document.getElementById('paymentAmount').value = amountDue;
            
            document.getElementById('paymentModal').classList.remove('hidden');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.add('hidden');
            document.getElementById('paymentForm').reset();
        }

        // Payment Form Submit
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'create_payment');
            formData.append('reservation_id', document.getElementById('paymentReservationId').value);
            formData.append('customer_id', document.getElementById('paymentCustomerId').value);
            formData.append('amount', document.getElementById('paymentAmount').value);
            formData.append('payment_method', document.getElementById('paymentMethod').value);
            formData.append('payment_type', document.getElementById('paymentType').value);
            formData.append('reference_number', document.getElementById('paymentReference').value);
            formData.append('notes', document.getElementById('paymentNotes').value);
            
            fetch('payments.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closePaymentModal();
                    showSuccess('Payment recorded successfully');
                    loadPayments();
                    loadPendingPayments();
                    loadInvoices();
                    loadPaymentMethods();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        });

        // Invoice Modal
        function openInvoiceModal() {
            document.getElementById('invoiceModal').classList.remove('hidden');
            
            // Set default due date (7 days from now)
            const dueDate = new Date();
            dueDate.setDate(dueDate.getDate() + 7);
            document.getElementById('invoiceDueDate').value = dueDate.toISOString().split('T')[0];
        }

        function closeInvoiceModal() {
            document.getElementById('invoiceModal').classList.add('hidden');
            document.getElementById('invoiceForm').reset();
            document.getElementById('invoiceReservationDetails').classList.add('hidden');
        }

        function loadReservationSelect() {
            fetch('payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_pending_payments'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const select = document.getElementById('invoiceReservationId');
                    select.innerHTML = '<option value="">Select a reservation...</option>';
                    
                    data.pending.forEach(r => {
                        select.innerHTML += `<option value="${r.id}" data-customer="${r.customer_id}" data-amount="${r.total_amount}" data-name="${r.first_name} ${r.last_name}" data-reservation="${r.reservation_number}">${r.reservation_number} - ${r.first_name} ${r.last_name} - ₱${parseFloat(r.total_amount).toFixed(2)}</option>`;
                    });
                }
            });
        }

        function loadReservationForInvoice() {
            const select = document.getElementById('invoiceReservationId');
            const selected = select.options[select.selectedIndex];
            
            if (select.value) {
                const details = document.getElementById('invoiceReservationDetails');
                details.innerHTML = `
                    <p class="text-xs text-gray-400">Reservation: ${selected.dataset.reservation}</p>
                    <p class="text-xs text-gray-400">Customer: ${selected.dataset.name}</p>
                    <p class="text-xs text-gray-400">Amount: ₱${parseFloat(selected.dataset.amount).toFixed(2)}</p>
                `;
                details.classList.remove('hidden');
            } else {
                document.getElementById('invoiceReservationDetails').classList.add('hidden');
            }
        }

        // Invoice Form Submit
        document.getElementById('invoiceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const select = document.getElementById('invoiceReservationId');
            const selected = select.options[select.selectedIndex];
            
            const formData = new FormData();
            formData.append('action', 'create_invoice');
            formData.append('reservation_id', select.value);
            formData.append('customer_id', selected.dataset.customer);
            formData.append('due_date', document.getElementById('invoiceDueDate').value);
            formData.append('discount', document.getElementById('invoiceDiscount').value);
            formData.append('notes', document.getElementById('invoiceNotes').value);
            
            fetch('payments.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeInvoiceModal();
                    showSuccess('Invoice created successfully');
                    loadInvoices();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        });

        // Refund Modal
        function openRefundModal(paymentId, paymentNumber, customerName, amount, reservationId, customerId) {
            document.getElementById('refundPaymentId').value = paymentId;
            document.getElementById('refundReservationId').value = reservationId;
            document.getElementById('refundCustomerId').value = customerId;
            document.getElementById('refundPaymentNumber').value = paymentNumber;
            document.getElementById('refundCustomerName').value = customerName;
            document.getElementById('refundOriginalAmount').value = '₱' + parseFloat(amount).toFixed(2);
            document.getElementById('refundAmount').max = amount;
            
            document.getElementById('refundModal').classList.remove('hidden');
        }

        function closeRefundModal() {
            document.getElementById('refundModal').classList.add('hidden');
            document.getElementById('refundForm').reset();
        }

        // Refund Form Submit
        document.getElementById('refundForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'process_refund');
            formData.append('payment_id', document.getElementById('refundPaymentId').value);
            formData.append('reservation_id', document.getElementById('refundReservationId').value);
            formData.append('customer_id', document.getElementById('refundCustomerId').value);
            formData.append('amount', document.getElementById('refundAmount').value);
            formData.append('reason', document.getElementById('refundReason').value);
            
            fetch('payments.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeRefundModal();
                    showSuccess('Refund processed successfully');
                    loadPayments();
                    loadRefunds();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePaymentModal();
                closeInvoiceModal();
                closeRefundModal();
                closeSuccessModal();
                closeConfirmModal();
            }
        });
    </script>
</body>
</html>