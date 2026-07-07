<?php
// Set timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');

session_start();
require_once '../config/connect.php';

$admin_base = true;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        $conn = getConnection();
        
        // Get Dashboard Statistics
        if ($_POST['action'] === 'get_stats') {
            $ongoing = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Ongoing'")->fetch_assoc()['count'];
            
            $today_checkins = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Confirmed' AND DATE(pickup_date) = CURDATE()")->fetch_assoc()['count'];
            
            $extensions = $conn->query("SELECT COUNT(*) as count FROM rental_extensions WHERE status = 'Pending'")->fetch_assoc()['count'];
            
            $late_returns = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Ongoing' AND CONCAT(return_date, ' ', return_time) < NOW()")->fetch_assoc()['count'];
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'ongoing' => $ongoing,
                    'today_checkins' => $today_checkins,
                    'extensions' => $extensions,
                    'late_returns' => $late_returns
                ]
            ]);
        }
        
        // Get Ongoing Rentals
        elseif ($_POST['action'] === 'get_ongoing_rentals') {
            $sql = "SELECT r.*, 
                    c.first_name, c.last_name, c.email, c.phone, c.license_number,
                    v.vehicle_name, v.category, v.license_plate, v.image_path, v.price_per_day,
                    re.id as extension_id, re.extension_days, re.status as extension_status
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    LEFT JOIN rental_extensions re ON r.id = re.reservation_id AND re.status = 'Pending'
                    WHERE r.status IN ('Confirmed', 'Ongoing')
                    ORDER BY 
                        CASE 
                            WHEN r.status = 'Ongoing' AND CONCAT(r.return_date, ' ', r.return_time) < NOW() THEN 1
                            WHEN r.status = 'Ongoing' THEN 2
                            ELSE 3
                        END,
                        r.return_date ASC";
            
            $result = $conn->query($sql);
            $rentals = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $rentals[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'rentals' => $rentals]);
        }
        
        // Get Today's Check-ins (Scheduled Releases)
        elseif ($_POST['action'] === 'get_today_checkins') {
            $sql = "SELECT r.*, 
                    c.first_name, c.last_name,
                    v.vehicle_name, v.license_plate
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    WHERE r.status = 'Confirmed' AND DATE(r.pickup_date) = CURDATE()
                    ORDER BY r.pickup_time ASC";
            
            $result = $conn->query($sql);
            $checkins = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $checkins[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'checkins' => $checkins]);
        }
        
        // Get Today's Returns
        elseif ($_POST['action'] === 'get_today_returns') {
            $sql = "SELECT r.*, 
                    c.first_name, c.last_name,
                    v.vehicle_name, v.license_plate
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    WHERE r.status = 'Ongoing' AND DATE(r.return_date) = CURDATE()
                    ORDER BY r.return_time ASC";
            
            $result = $conn->query($sql);
            $returns = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $returns[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'returns' => $returns]);
        }
        
        // Get Pending Extensions
        elseif ($_POST['action'] === 'get_pending_extensions') {
            $sql = "SELECT re.*, 
                    r.reservation_number,
                    c.first_name, c.last_name,
                    v.vehicle_name
                    FROM rental_extensions re
                    JOIN reservations r ON re.reservation_id = r.id
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    WHERE re.status = 'Pending'
                    ORDER BY re.requested_at DESC";
            
            $result = $conn->query($sql);
            $extensions = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $extensions[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'extensions' => $extensions]);
        }
        
        // Get Rental Details
        elseif ($_POST['action'] === 'get_rental_details') {
            $id = (int)$_POST['id'];
            
            $sql = "SELECT r.*, 
                    c.first_name, c.last_name, c.email, c.phone, c.address, c.license_number,
                    v.vehicle_name, v.category, v.license_plate, v.image_path, v.price_per_day, v.fuel_type, v.transmission,
                    rc.title as contract_title, rc.content as contract_content
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    LEFT JOIN rental_contracts rc ON r.contract_id = rc.id
                    WHERE r.id = $id";
            
            $result = $conn->query($sql);
            
            if ($result->num_rows > 0) {
                $rental = $result->fetch_assoc();
                
                // Calculate time remaining
                $return_datetime = new DateTime($rental['return_date'] . ' ' . $rental['return_time']);
                $now = new DateTime();
                
                if ($return_datetime > $now) {
                    $interval = $now->diff($return_datetime);
                    $rental['time_remaining'] = [
                        'days' => $interval->days,
                        'hours' => $interval->h,
                        'minutes' => $interval->i,
                        'total_hours' => ($interval->days * 24) + $interval->h
                    ];
                } else {
                    $rental['time_remaining'] = null;
                    
                    // Calculate late fee if applicable
                    if ($rental['status'] == 'Ongoing') {
                        $late_hours = ceil(($now->getTimestamp() - $return_datetime->getTimestamp()) / 3600);
                        if ($late_hours > 1) { // 1 hour grace period
                            $late_fee = $late_hours * 500; // ₱500 per hour after grace period
                            $rental['late_fee'] = $late_fee;
                        }
                    }
                }
                
                echo json_encode(['success' => true, 'rental' => $rental]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Rental not found']);
            }
        }
        
        // Check-in Vehicle (Start Rental)
        elseif ($_POST['action'] === 'check_in') {
            $id = (int)$_POST['id'];
            $notes = $conn->real_escape_string($_POST['notes']);
            $contract_signed = isset($_POST['contract_signed']) ? 1 : 0;
            
            $current_time = date('Y-m-d H:i:s');
            
            $sql = "UPDATE reservations SET 
                    status = 'Ongoing',
                    actual_pickup_time = '$current_time',
                    check_in_notes = '$notes',
                    contract_signed_at = " . ($contract_signed ? "'$current_time'" : "NULL") . "
                    WHERE id = $id AND status = 'Confirmed'";
            
            if ($conn->query($sql)) {
                // Update vehicle status to Rented
                $vehicle_sql = "SELECT vehicle_id FROM reservations WHERE id = $id";
                $vehicle_result = $conn->query($vehicle_sql);
                if ($vehicle_result->num_rows > 0) {
                    $vehicle_id = $vehicle_result->fetch_assoc()['vehicle_id'];
                    $conn->query("UPDATE vehicles SET status = 'Rented' WHERE id = $vehicle_id");
                }
                
                echo json_encode(['success' => true, 'message' => 'Vehicle checked in successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Check-out Vehicle (Return)
        elseif ($_POST['action'] === 'check_out') {
            $id = (int)$_POST['id'];
            $notes = $conn->real_escape_string($_POST['notes']);
            $late_fee = isset($_POST['late_fee']) ? (float)$_POST['late_fee'] : 0;
            $additional_charges = isset($_POST['additional_charges']) ? (float)$_POST['additional_charges'] : 0;
            
            $current_time = date('Y-m-d H:i:s');
            
            // Calculate total amount including extensions and fees
            $total_extensions = $conn->query("SELECT SUM(extension_fee) as total FROM rental_extensions WHERE reservation_id = $id AND status = 'Approved'")->fetch_assoc()['total'] ?? 0;
            
            $sql = "UPDATE reservations SET 
                    status = 'Completed',
                    actual_return_time = '$current_time',
                    check_out_notes = '$notes',
                    late_fee = $late_fee,
                    total_extension_fee = $total_extensions,
                    total_amount = total_amount + $late_fee + $total_extensions + $additional_charges
                    WHERE id = $id AND status = 'Ongoing'";
            
            if ($conn->query($sql)) {
                // Update vehicle status to Available
                $vehicle_sql = "SELECT vehicle_id FROM reservations WHERE id = $id";
                $vehicle_result = $conn->query($vehicle_sql);
                if ($vehicle_result->num_rows > 0) {
                    $vehicle_id = $vehicle_result->fetch_assoc()['vehicle_id'];
                    $conn->query("UPDATE vehicles SET status = 'Available' WHERE id = $vehicle_id");
                }
                
                echo json_encode(['success' => true, 'message' => 'Vehicle checked out successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Request Extension (Auto-approved)
        elseif ($_POST['action'] === 'request_extension') {
            $reservation_id = (int)$_POST['reservation_id'];
            $extension_days = (int)$_POST['extension_days'];
            $reason = $conn->real_escape_string($_POST['reason']);
            
            // Get daily rate and current return date
            $rate_sql = "SELECT v.price_per_day, r.return_date FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = $reservation_id";
            $rate_result = $conn->query($rate_sql);
            $data = $rate_result->fetch_assoc();
            $daily_rate = $data['price_per_day'];
            $current_return = $data['return_date'];
            
            $extension_fee = $daily_rate * $extension_days;
            
            // Insert extension record (auto-approved)
            $sql = "INSERT INTO rental_extensions (reservation_id, extension_days, extension_fee, reason, status, approved_at) 
                    VALUES ($reservation_id, $extension_days, $extension_fee, '$reason', 'Approved', NOW())";
            
            if ($conn->query($sql)) {
                // Update reservation return date and total amount
                $new_return = date('Y-m-d', strtotime($current_return . ' + ' . $extension_days . ' days'));
                $update_sql = "UPDATE reservations SET 
                              return_date = '$new_return',
                              total_amount = total_amount + $extension_fee
                              WHERE id = $reservation_id";
                $conn->query($update_sql);
                
                echo json_encode(['success' => true, 'message' => 'Extension approved successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Get Contract Templates
        elseif ($_POST['action'] === 'get_contracts') {
            $sql = "SELECT * FROM rental_contracts WHERE is_active = 1 ORDER BY version DESC";
            $result = $conn->query($sql);
            $contracts = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $contracts[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'contracts' => $contracts]);
        }
        
        // Get Contract Template
        elseif ($_POST['action'] === 'get_contract') {
            $id = (int)$_POST['id'];
            
            $sql = "SELECT * FROM rental_contracts WHERE id = $id";
            $result = $conn->query($sql);
            
            if ($result->num_rows > 0) {
                $contract = $result->fetch_assoc();
                echo json_encode(['success' => true, 'contract' => $contract]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Contract not found']);
            }
        }
        
        // Save Contract Template
        elseif ($_POST['action'] === 'save_contract') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $title = $conn->real_escape_string($_POST['title']);
            $content = $conn->real_escape_string($_POST['content']);
            
            if ($id > 0) {
                // Update existing contract
                $sql = "UPDATE rental_contracts SET title = '$title', content = '$content', version = version + 1 WHERE id = $id";
            } else {
                // Generate contract number
                $contract_number = 'CTR-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                // Insert new contract
                $sql = "INSERT INTO rental_contracts (contract_number, title, content) VALUES ('$contract_number', '$title', '$content')";
            }
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Contract saved successfully']);
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
$ongoing = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Ongoing'")->fetch_assoc()['count'];
$today_checkins = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Confirmed' AND DATE(pickup_date) = CURDATE()")->fetch_assoc()['count'];
$extensions = $conn->query("SELECT COUNT(*) as count FROM rental_extensions WHERE status = 'Pending'")->fetch_assoc()['count'];
$late_returns = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Ongoing' AND CONCAT(return_date, ' ', return_time) < NOW()")->fetch_assoc()['count'];

// Fetch ongoing rentals for display
$ongoing_sql = "SELECT r.*, 
                c.first_name, c.last_name,
                v.vehicle_name,
                re.id as extension_id,
                re.status as extension_status
                FROM reservations r
                JOIN customers c ON r.customer_id = c.id
                JOIN vehicles v ON r.vehicle_id = v.id
                LEFT JOIN rental_extensions re ON r.id = re.reservation_id AND re.status = 'Pending'
                WHERE r.status IN ('Confirmed', 'Ongoing')
                ORDER BY 
                    CASE 
                        WHEN r.status = 'Ongoing' AND CONCAT(r.return_date, ' ', r.return_time) < NOW() THEN 1
                        WHEN r.status = 'Ongoing' THEN 2
                        ELSE 3
                    END,
                    r.return_date ASC
                LIMIT 10";
$ongoing_result = $conn->query($ongoing_sql);

// Fetch today's check-ins
$checkins_sql = "SELECT r.*, 
                 c.first_name, c.last_name,
                 v.vehicle_name
                 FROM reservations r
                 JOIN customers c ON r.customer_id = c.id
                 JOIN vehicles v ON r.vehicle_id = v.id
                 WHERE r.status = 'Confirmed' AND DATE(r.pickup_date) = CURDATE()
                 ORDER BY r.pickup_time ASC
                 LIMIT 5";
$checkins_result = $conn->query($checkins_sql);

// Fetch pending extensions count for display
$extensions_count = $conn->query("SELECT COUNT(*) as count FROM rental_extensions WHERE status = 'Pending'")->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rental Management - Velocity Rentals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Quill.js for Rich Text Editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
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

        /* Progress tracker */
        .progress-tracker {
            background: #1f2937;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .progress-bar {
            height: 8px;
            background: #374151;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #dc2626, #ef4444);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .countdown-timer {
            font-size: 2rem;
            font-weight: bold;
            text-align: center;
            color: #dc2626;
            margin: 1rem 0;
        }
        
        .countdown-unit {
            font-size: 0.875rem;
            color: #9ca3af;
            margin: 0 0.25rem;
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

        /* Quill editor customization */
        .ql-container {
            background: #1f2937;
            color: #fff;
            border-color: #374151 !important;
            min-height: 300px;
        }
        
        .ql-toolbar {
            background: #111827;
            border-color: #374151 !important;
        }
        
        .ql-toolbar button {
            color: #9ca3af !important;
        }
        
        .ql-toolbar button:hover {
            color: #dc2626 !important;
        }
        
        .ql-editor {
            min-height: 300px;
        }
        
        .ql-editor.ql-blank::before {
            color: #6b7280;
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
                            <h1 class="text-xl font-bold text-white">Rental Management</h1>
                            <p class="text-xs text-gray-500">Monitor active rentals and contract lifecycle</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="hidden md:flex items-center bg-gray-800/80 rounded-lg px-3 py-2 border border-gray-600/30">
                        <i class="fas fa-search text-gray-500 text-sm"></i>
                        <input type="text" id="searchRental" placeholder="Search rental..." onkeyup="searchRentals()" class="bg-transparent border-none outline-none text-sm text-gray-300 placeholder-gray-500 ml-2 w-52">
                    </div>
                    <button onclick="openContractModal()" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white rounded-lg text-sm font-medium transition-all shadow-lg shadow-red-600/20">
                        <i class="fas fa-file-signature"></i>
                        <span>New Rental Contract</span>
                    </button>
                </div>
            </div>
        </header>

        <div class="p-4 lg:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-blue-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-600/30 to-blue-700/10 flex items-center justify-center border border-blue-500/20">
                            <i class="fas fa-road text-blue-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Ongoing Rentals</h3>
                    <p class="text-2xl font-bold text-white" id="ongoingStats"><?php echo $ongoing; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-emerald-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-600/30 to-emerald-700/10 flex items-center justify-center border border-emerald-500/20">
                            <i class="fas fa-calendar-check text-emerald-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Today Check-Ins</h3>
                    <p class="text-2xl font-bold text-white" id="checkinsStats"><?php echo $today_checkins; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-amber-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-600/30 to-amber-700/10 flex items-center justify-center border border-amber-500/20">
                            <i class="fas fa-clock text-amber-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Extensions Requested</h3>
                    <p class="text-2xl font-bold text-white" id="extensionsStats"><?php echo $extensions; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-red-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-600/30 to-red-700/10 flex items-center justify-center border border-red-500/20">
                            <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Late Returns</h3>
                    <p class="text-2xl font-bold text-white" id="lateStats"><?php echo $late_returns; ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <button onclick="filterRentals('all')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-blue-500/50 rounded-xl transition-all group">
                    <i class="fas fa-list text-blue-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">All Rentals</span>
                </button>
                <button onclick="filterRentals('confirmed')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-emerald-500/50 rounded-xl transition-all group">
                    <i class="fas fa-key text-emerald-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Ready for Pickup</span>
                </button>
                <button onclick="filterRentals('ongoing')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-blue-500/50 rounded-xl transition-all group">
                    <i class="fas fa-road text-blue-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Ongoing</span>
                </button>
                <button onclick="filterRentals('extension')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-amber-500/50 rounded-xl transition-all group">
                    <i class="fas fa-calendar-plus text-amber-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Extended</span>
                </button>
                <button onclick="filterRentals('late')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Late Returns</span>
                </button>
                <button onclick="filterRentals('completed')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-purple-500/50 rounded-xl transition-all group">
                    <i class="fas fa-check-double text-purple-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Completed</span>
                </button>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
                <!-- Ongoing Rentals Table -->
                <div class="xl:col-span-2 card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Rental List</h2>
                            <p class="text-sm text-gray-500">Live tracking of all rentals</p>
                        </div>
                        <select id="statusFilter" onchange="filterTableByStatus(this.value)" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                            <option value="all">All Status</option>
                            <option value="confirmed">Ready for Pickup</option>
                            <option value="ongoing">Ongoing</option>
                            <option value="extended">Extended</option>
                            <option value="late">Late Return</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-700/30 bg-gray-800/30">
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Renter</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Vehicle</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Release</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Due Return</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Status</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="rentalTableBody" class="divide-y divide-gray-700/30">
                                <?php if ($ongoing_result && $ongoing_result->num_rows > 0): ?>
                                    <?php while($rental = $ongoing_result->fetch_assoc()): ?>
                                        <?php 
                                            $isLate = ($rental['status'] == 'Ongoing' && strtotime($rental['return_date'] . ' ' . $rental['return_time']) < time());
                                            $displayStatus = '';
                                            $statusClass = '';
                                            
                                            if ($rental['status'] == 'Confirmed') {
                                                $displayStatus = 'Ready for Pickup';
                                                $statusClass = 'bg-emerald-500/10 text-emerald-500';
                                            } elseif ($rental['status'] == 'Ongoing' && $isLate) {
                                                $displayStatus = 'Late Return';
                                                $statusClass = 'bg-red-500/10 text-red-500';
                                            } elseif ($rental['status'] == 'Ongoing' && $rental['extension_id']) {
                                                $displayStatus = 'Extended';
                                                $statusClass = 'bg-amber-500/10 text-amber-500';
                                            } elseif ($rental['status'] == 'Ongoing') {
                                                $displayStatus = 'Ongoing';
                                                $statusClass = 'bg-blue-500/10 text-blue-500';
                                            } elseif ($rental['status'] == 'Completed') {
                                                $displayStatus = 'Completed';
                                                $statusClass = 'bg-purple-500/10 text-purple-500';
                                            }
                                        ?>
                                        <tr class="hover:bg-gray-800/40 transition-colors rental-row" 
                                            data-status="<?php 
                                                if ($rental['status'] == 'Confirmed') echo 'confirmed';
                                                elseif ($isLate) echo 'late';
                                                elseif ($rental['extension_id']) echo 'extended';
                                                elseif ($rental['status'] == 'Ongoing') echo 'ongoing';
                                                elseif ($rental['status'] == 'Completed') echo 'completed';
                                            ?>"
                                            data-id="<?php echo $rental['id']; ?>">
                                            <td class="px-5 py-4 text-sm text-white"><?php echo $rental['first_name'] . ' ' . $rental['last_name']; ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-300"><?php echo $rental['vehicle_name']; ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-300"><?php echo date('M d, Y', strtotime($rental['pickup_date'])); ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-300"><?php echo date('M d, Y', strtotime($rental['return_date'])); ?></td>
                                            <td class="px-5 py-4">
                                                <span class="px-2 py-1 text-xs font-medium <?php echo $statusClass; ?> rounded-full">
                                                    <?php echo $displayStatus; ?>
                                                </span>
                                            </td>
                                            <td class="px-5 py-4">
                                                <button onclick="manageRental(<?php echo $rental['id']; ?>)" class="text-xs text-red-500 hover:text-red-400 bg-red-500/10 px-3 py-1 rounded-lg">
                                                    Manage
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-5 py-8 text-center text-gray-500">
                                            <i class="fas fa-car text-4xl mb-2 opacity-50"></i>
                                            <p>No rentals found</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Check-In / Vehicle Release Panel -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Check-In / Vehicle Release</h2>
                        <p class="text-sm text-gray-500">Today scheduled releases</p>
                    </div>
                    <div id="checkinsList" class="p-4 space-y-3">
                        <?php if ($checkins_result && $checkins_result->num_rows > 0): ?>
                            <?php while($checkin = $checkins_result->fetch_assoc()): ?>
                                <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 cursor-pointer hover:bg-emerald-500/20 transition-colors" onclick="manageRental(<?php echo $checkin['id']; ?>)">
                                    <p class="text-sm font-medium text-white"><?php echo $checkin['reservation_number']; ?> • <?php echo $checkin['vehicle_name']; ?></p>
                                    <p class="text-xs text-gray-400"><?php echo $checkin['first_name'] . ' ' . $checkin['last_name']; ?> • Pickup <?php echo date('h:i A', strtotime($checkin['pickup_time'])); ?></p>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-sm text-gray-400 text-center py-4">No scheduled releases today</p>
                        <?php endif; ?>
                    </div>
                    <div class="p-4 pt-0">
                        <button onclick="filterRentals('confirmed')" class="w-full py-2 text-sm text-red-500 hover:text-red-400 border border-red-500/20 rounded-lg hover:border-red-500/40 transition-colors">
                            View All Ready for Pickup
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Check-Out / Return Vehicle -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Check-Out / Return Vehicle</h2>
                        <p class="text-sm text-gray-500">Returns due and inspection flow</p>
                    </div>
                    <div id="returnsList" class="p-4 space-y-3">
                        <!-- Will be loaded via AJAX -->
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>

                <!-- Rental Extensions -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Rental Extensions</h2>
                        <p class="text-sm text-gray-500">Recently extended rentals</p>
                    </div>
                    <div id="extensionsList" class="p-4 space-y-3">
                        <!-- Will be loaded via AJAX -->
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>

                <!-- Rental Agreements / Contracts -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Rental Agreements / Contracts</h2>
                        <p class="text-sm text-gray-500">Latest generated documents</p>
                    </div>
                    <div id="contractsList" class="p-4 space-y-3">
                        <!-- Will be loaded via AJAX -->
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Contract Editor Modal -->
    <div id="contractModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeContractModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-4xl max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700 sticky top-0 bg-gray-900 z-10">
                    <h2 class="text-xl font-semibold text-white" id="contractModalTitle">Contract Editor</h2>
                    <button onclick="closeContractModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="p-5">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Contract Title</label>
                        <input type="text" id="contractTitle" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" placeholder="e.g., Standard Rental Agreement">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Contract Templates</label>
                        <select id="contractTemplateSelect" onchange="loadContractTemplate()" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                            <option value="">Select a template...</option>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Contract Content</label>
                        <div id="contractEditor" style="height: 400px;"></div>
                    </div>
                    
                    <div class="flex justify-end gap-3">
                        <button onclick="closeContractModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                        <button onclick="printContract()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-print mr-2"></i>Print
                        </button>
                        <button onclick="saveContract()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-save mr-2"></i>Save Contract
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rental Management Modal -->
    <div id="rentalModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeRentalModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-3xl max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700 sticky top-0 bg-gray-900 z-10">
                    <h2 class="text-xl font-semibold text-white" id="rentalModalTitle">Manage Rental</h2>
                    <button onclick="closeRentalModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="rentalModalContent" class="p-5">
                    <!-- Content will be loaded dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Extension Request Modal -->
    <div id="extensionRequestModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeExtensionRequestModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 class="text-xl font-semibold text-white">Request Extension</h2>
                    <button onclick="closeExtensionRequestModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="p-5">
                    <input type="hidden" id="extensionReservationId">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Extension Days</label>
                        <input type="number" id="extensionDays" min="1" max="30" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" onchange="calculateExtensionFee()">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Daily Rate</label>
                        <input type="text" id="dailyRate" readonly class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-gray-300">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Extension Fee</label>
                        <input type="text" id="extensionFee" readonly class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-red-500 font-bold">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Reason for Extension</label>
                        <textarea id="extensionReason" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Please provide reason..."></textarea>
                    </div>
                    
                    <div class="flex justify-end gap-3">
                        <button onclick="closeExtensionRequestModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                        <button onclick="confirmExtensionRequest()" class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors">
                            <i class="fas fa-calendar-plus mr-2"></i>Request Extension
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeConfirmModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md modal-enter">
                <div class="p-5 border-b border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-amber-500/20 flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-amber-500 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white" id="confirmModalTitle">Confirm Action</h3>
                    </div>
                </div>
                <div class="p-5">
                    <p id="confirmMessage" class="text-gray-300">Are you sure you want to proceed?</p>
                </div>
                <div class="flex justify-end gap-3 p-5 border-t border-gray-700">
                    <button onclick="closeConfirmModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                    <button id="confirmActionBtn" onclick="executeConfirmAction()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeSuccessModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md modal-enter">
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

    <script>
        // Global variables
        let quill;
        let currentRentalId = null;
        let currentAction = null;
        
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

        // Confirmation Modal
        function showConfirm(title, message, action) {
            document.getElementById('confirmModalTitle').textContent = title;
            document.getElementById('confirmMessage').textContent = message;
            document.getElementById('confirmModal').classList.remove('hidden');
            currentAction = action;
        }
        
        function executeConfirmAction() {
            if (typeof currentAction === 'function') {
                currentAction();
            }
            closeConfirmModal();
        }

        // Contract Editor
        function openContractModal() {
            document.getElementById('contractModal').classList.remove('hidden');
            document.getElementById('contractModalTitle').textContent = 'Contract Editor';
            
            // Initialize Quill editor if not already initialized
            if (!quill) {
                quill = new Quill('#contractEditor', {
                    theme: 'snow',
                    placeholder: 'Write your contract content here...',
                    modules: {
                        toolbar: [
                            [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                            [{ 'align': [] }],
                            ['link'],
                            ['clean']
                        ]
                    }
                });
            }
            
            // Load contract templates
            loadContractTemplates();
        }

        function closeContractModal() {
            document.getElementById('contractModal').classList.add('hidden');
        }

        function loadContractTemplates() {
            fetch('rentals.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_contracts'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const select = document.getElementById('contractTemplateSelect');
                    select.innerHTML = '<option value="">Select a template...</option>';
                    
                    data.contracts.forEach(contract => {
                        select.innerHTML += `<option value="${contract.id}">${contract.title} (v${contract.version})</option>`;
                    });
                }
            });
        }

        function loadContractTemplate() {
            const id = document.getElementById('contractTemplateSelect').value;
            if (!id) return;
            
            fetch('rentals.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_contract&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('contractTitle').value = data.contract.title;
                    quill.root.innerHTML = data.contract.content;
                }
            });
        }

        function saveContract() {
            const title = document.getElementById('contractTitle').value;
            const content = quill.root.innerHTML;
            const id = document.getElementById('contractTemplateSelect').value;
            
            if (!title) {
                alert('Please enter a contract title');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'save_contract');
            formData.append('title', title);
            formData.append('content', content);
            if (id) {
                formData.append('id', id);
            }
            
            fetch('rentals.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeContractModal();
                    showSuccess('Contract saved successfully');
                    loadContractsList();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        function printContract() {
            const title = document.getElementById('contractTitle').value;
            const content = quill.root.innerHTML;
            
            if (!title || !content || content === '<p><br></p>') {
                alert('Please create or load a contract first');
                return;
            }
            
            // Create print-friendly window
            const printWindow = window.open('', '_blank', 'width=800,height=600');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Print Contract</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            padding: 20px;
                            max-width: 800px;
                            margin: 0 auto;
                            font-size: 12px;
                            line-height: 1.4;
                        }
                        .contract-content {
                            white-space: pre-wrap;
                        }
                        @media print {
                            body { padding: 10px; }
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="contract-content">${content}</div>
                    <div style="text-align: center; margin-top: 20px;">
                        <button onclick="window.print()" class="no-print" style="padding: 8px 16px; background: #dc2626; color: white; border: none; cursor: pointer; border-radius: 4px;">Print Contract</button>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
        }

        // Load Contracts List
        function loadContractsList() {
            fetch('rentals.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_contracts'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayContractsList(data.contracts);
                }
            });
        }

        function displayContractsList(contracts) {
            const container = document.getElementById('contractsList');
            
            if (contracts.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No contracts found</p>';
                return;
            }
            
            let html = '';
            contracts.slice(0, 5).forEach(contract => {
                html += `
                    <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30 cursor-pointer hover:bg-gray-700/40 transition-colors" onclick="editContract(${contract.id})">
                        <p class="text-sm font-medium text-white">${contract.contract_number}</p>
                        <p class="text-xs text-gray-400">${contract.title} • v${contract.version}</p>
                        <p class="text-xs text-gray-500">${new Date(contract.created_at).toLocaleDateString()}</p>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        function editContract(id) {
            openContractModal();
            document.getElementById('contractTemplateSelect').value = id;
            loadContractTemplate();
        }

        // Rental Management
        function manageRental(id) {
            currentRentalId = id;
            
            fetch('rentals.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_rental_details&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayRentalManagement(data.rental);
                }
            });
        }

        function displayRentalManagement(rental) {
            const content = document.getElementById('rentalModalContent');
            
            const vehicleImage = rental.image_path !== 'default-car.png' ? 
                `../uploads/vehicles/${rental.image_path}` : 
                'https://via.placeholder.com/300x200/1f2937/ffffff?text=No+Image';
            
            let actionButtons = '';
            let progressTracker = '';
            
            if (rental.status === 'Confirmed') {
                // Ready for Check-in
                actionButtons = `
                    <div class="mt-6">
                        <h3 class="text-lg font-medium text-white mb-4">Check-In Vehicle</h3>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Check-In Notes</label>
                            <textarea id="checkInNotes" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Vehicle condition, odometer reading, etc."></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" id="contractSigned" class="rounded bg-gray-800 border-gray-700 text-red-600 focus:ring-red-500">
                                <span class="text-sm text-gray-300">Contract signed and acknowledged</span>
                            </label>
                        </div>
                        <button onclick="confirmCheckIn(${rental.id})" class="w-full px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors">
                            <i class="fas fa-key mr-2"></i>Check-In Vehicle
                        </button>
                    </div>
                `;
            } else if (rental.status === 'Ongoing') {
                // Progress Tracker
                const isLate = new Date(rental.return_date + ' ' + rental.return_time) < new Date();
                
                if (rental.time_remaining && !isLate) {
                    const totalHours = rental.time_remaining.total_hours;
                    const totalRentalHours = (new Date(rental.return_date + ' ' + rental.return_time) - new Date(rental.pickup_date + ' ' + rental.pickup_time)) / (1000 * 60 * 60);
                    const progress = ((totalRentalHours - totalHours) / totalRentalHours) * 100;
                    
                    progressTracker = `
                        <div class="progress-tracker">
                            <h3 class="text-lg font-medium text-white mb-4">Rental Progress</h3>
                            <div class="countdown-timer">
                                <span id="days">${rental.time_remaining.days}</span><span class="countdown-unit">d</span>
                                <span id="hours">${rental.time_remaining.hours}</span><span class="countdown-unit">h</span>
                                <span id="minutes">${rental.time_remaining.minutes}</span><span class="countdown-unit">m</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: ${progress}%"></div>
                            </div>
                            <p class="text-sm text-gray-400 text-center mt-2">Time remaining until scheduled return</p>
                        </div>
                    `;
                }
                
                if (isLate) {
                    // Late return - show check-out button with late fee
                    const lateFee = parseFloat(rental.late_fee) || 500;
                    actionButtons = `
                        <div class="mt-6">
                            <h3 class="text-lg font-medium text-white mb-4 text-red-500">⚠️ Late Return</h3>
                            <div class="bg-red-500/10 p-3 rounded-lg mb-4">
                                <p class="text-sm text-gray-400">Vehicle is past due return time</p>
                                <p class="text-lg font-bold text-red-500">Late Fee: ₱${lateFee.toFixed(2)}</p>
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-400 mb-2">Check-Out Notes</label>
                                <textarea id="checkOutNotes" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Vehicle condition, odometer reading, damages, etc."></textarea>
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-400 mb-2">Additional Charges (if any)</label>
                                <input type="number" id="additionalCharges" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" value="0" step="0.01">
                            </div>
                            <button onclick="confirmCheckOut(${rental.id}, ${lateFee})" class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                                <i class="fas fa-undo-alt mr-2"></i>Process Return
                            </button>
                        </div>
                    `;
                } else {
                    // Normal ongoing - show extension and check-out options
                    actionButtons = `
                        <div class="mt-6 grid grid-cols-2 gap-3">
                            <button onclick="openExtensionRequestModal(${rental.id}, ${rental.price_per_day})" class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors">
                                <i class="fas fa-calendar-plus mr-2"></i>Request Extension
                            </button>
                            <button onclick="confirmEarlyCheckOut(${rental.id})" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                <i class="fas fa-undo-alt mr-2"></i>Early Check-Out
                            </button>
                        </div>
                    `;
                }
            }
            
            content.innerHTML = `
                <div class="space-y-4">
                    <!-- Header -->
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm text-gray-400">Reservation #${rental.reservation_number}</p>
                            <p class="text-lg font-bold text-white">${rental.first_name} ${rental.last_name}</p>
                        </div>
                        <span class="px-3 py-1 text-sm font-medium 
                            ${rental.status === 'Ongoing' ? 'bg-blue-500/10 text-blue-500' : 
                              rental.status === 'Confirmed' ? 'bg-emerald-500/10 text-emerald-500' : 
                              'bg-gray-500/10 text-gray-500'} rounded-full">
                            ${rental.status}
                        </span>
                    </div>
                    
                    <!-- Customer Info -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs mb-1">Contact</p>
                            <p class="text-sm text-white">${rental.email}</p>
                            <p class="text-sm text-white">${rental.phone}</p>
                        </div>
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs mb-1">License</p>
                            <p class="text-sm text-white">${rental.license_number}</p>
                        </div>
                    </div>
                    
                    <!-- Vehicle Info -->
                    <div class="bg-gray-800/50 p-3 rounded-lg">
                        <div class="flex gap-3">
                            <img src="${vehicleImage}" alt="${rental.vehicle_name}" class="w-20 h-20 object-cover rounded-lg" onerror="this.src='https://via.placeholder.com/300x200/1f2937/ffffff?text=No+Image'">
                            <div>
                                <p class="text-white font-medium">${rental.vehicle_name}</p>
                                <p class="text-xs text-gray-400">${rental.category} • ${rental.license_plate}</p>
                                <p class="text-xs text-gray-400">${rental.fuel_type} • ${rental.transmission}</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Rental Dates -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs">Pickup</p>
                            <p class="text-sm text-white">${new Date(rental.pickup_date).toLocaleDateString()}</p>
                            <p class="text-xs text-gray-400">${rental.pickup_time}</p>
                            ${rental.actual_pickup_time ? `<p class="text-xs text-emerald-500 mt-1">Actual: ${new Date(rental.actual_pickup_time).toLocaleString()}</p>` : ''}
                        </div>
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs">Return</p>
                            <p class="text-sm text-white">${new Date(rental.return_date).toLocaleDateString()}</p>
                            <p class="text-xs text-gray-400">${rental.return_time}</p>
                        </div>
                    </div>
                    
                    <!-- Payment Summary -->
                    <div class="bg-gray-800/50 p-3 rounded-lg">
                        <p class="text-gray-400 text-sm mb-2">Payment Summary</p>
                        <div class="space-y-1">
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-400">Daily Rate:</span>
                                <span class="text-xs text-white">₱${parseFloat(rental.daily_rate).toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-400">Total Days:</span>
                                <span class="text-xs text-white">${rental.total_days}</span>
                            </div>
                            <div class="flex justify-between pt-2 border-t border-gray-700">
                                <span class="text-sm text-gray-400">Total Amount:</span>
                                <span class="text-lg font-bold text-red-500">₱${parseFloat(rental.total_amount).toFixed(2)}</span>
                            </div>
                        </div>
                    </div>
                    
                    ${progressTracker}
                    ${actionButtons}
                    
                    ${rental.check_in_notes ? `
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs mb-1">Check-In Notes</p>
                            <p class="text-sm text-white">${rental.check_in_notes}</p>
                        </div>
                    ` : ''}
                </div>
            `;
            
            document.getElementById('rentalModal').classList.remove('hidden');
            
            // Start countdown timer if applicable
            if (rental.time_remaining && !isLate) {
                startCountdown(rental.time_remaining);
            }
        }

        function closeRentalModal() {
            document.getElementById('rentalModal').classList.add('hidden');
        }

        // Countdown Timer
        function startCountdown(timeRemaining) {
            let days = timeRemaining.days;
            let hours = timeRemaining.hours;
            let minutes = timeRemaining.minutes;
            
            const timer = setInterval(() => {
                if (minutes > 0) {
                    minutes--;
                } else {
                    if (hours > 0) {
                        hours--;
                        minutes = 59;
                    } else if (days > 0) {
                        days--;
                        hours = 23;
                        minutes = 59;
                    } else {
                        clearInterval(timer);
                        location.reload(); // Refresh to show late status
                        return;
                    }
                }
                
                document.getElementById('days').textContent = days;
                document.getElementById('hours').textContent = hours;
                document.getElementById('minutes').textContent = minutes;
            }, 60000);
        }

        // Check-In with Confirmation
        function confirmCheckIn(id) {
            showConfirm(
                'Confirm Check-In',
                'Are you sure you want to check-in this vehicle? This will start the rental period.',
                function() {
                    processCheckIn(id);
                }
            );
        }

        function processCheckIn(id) {
            const notes = document.getElementById('checkInNotes').value;
            const contractSigned = document.getElementById('contractSigned').checked;
            
            const formData = new FormData();
            formData.append('action', 'check_in');
            formData.append('id', id);
            formData.append('notes', notes);
            formData.append('contract_signed', contractSigned);
            
            fetch('rentals.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeConfirmModal();
                if (data.success) {
                    closeRentalModal();
                    showSuccess('Vehicle checked in successfully');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // Check-Out with Confirmation
        function confirmCheckOut(id, lateFee) {
            showConfirm(
                'Confirm Check-Out',
                'Are you sure you want to process this return? This will complete the rental.',
                function() {
                    processCheckOut(id, lateFee);
                }
            );
        }

        function confirmEarlyCheckOut(id) {
            showConfirm(
                'Confirm Early Check-Out',
                'Are you sure you want to process early return?',
                function() {
                    const notes = prompt('Enter check-out notes (vehicle condition, odometer, etc.):');
                    if (notes) {
                        processCheckOut(id, 0);
                    }
                }
            );
        }

        function processCheckOut(id, lateFee) {
            const notes = document.getElementById('checkOutNotes')?.value || '';
            const additionalCharges = parseFloat(document.getElementById('additionalCharges')?.value) || 0;
            
            const formData = new FormData();
            formData.append('action', 'check_out');
            formData.append('id', id);
            formData.append('notes', notes);
            formData.append('late_fee', lateFee);
            formData.append('additional_charges', additionalCharges);
            
            fetch('rentals.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeConfirmModal();
                if (data.success) {
                    closeRentalModal();
                    showSuccess('Vehicle returned successfully');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // Extension Request with Confirmation
        function openExtensionRequestModal(reservationId, dailyRate) {
            document.getElementById('extensionReservationId').value = reservationId;
            document.getElementById('dailyRate').value = '₱' + parseFloat(dailyRate).toFixed(2);
            document.getElementById('extensionFee').value = '₱0.00';
            document.getElementById('extensionDays').value = '';
            document.getElementById('extensionReason').value = '';
            document.getElementById('extensionRequestModal').classList.remove('hidden');
        }

        function closeExtensionRequestModal() {
            document.getElementById('extensionRequestModal').classList.add('hidden');
        }

        function calculateExtensionFee() {
            const days = parseInt(document.getElementById('extensionDays').value) || 0;
            const dailyRateText = document.getElementById('dailyRate').value;
            const dailyRate = parseFloat(dailyRateText.replace('₱', ''));
            
            const fee = days * dailyRate;
            document.getElementById('extensionFee').value = '₱' + fee.toFixed(2);
        }

        function confirmExtensionRequest() {
            const days = document.getElementById('extensionDays').value;
            const reason = document.getElementById('extensionReason').value;
            
            if (!days || days < 1) {
                alert('Please enter valid number of days');
                return;
            }
            
            if (!reason) {
                alert('Please provide a reason for extension');
                return;
            }
            
            showConfirm(
                'Confirm Extension',
                'Are you sure you want to request an extension? This will automatically update the rental period.',
                function() {
                    submitExtensionRequest();
                }
            );
        }

        function submitExtensionRequest() {
            const reservationId = document.getElementById('extensionReservationId').value;
            const days = document.getElementById('extensionDays').value;
            const reason = document.getElementById('extensionReason').value;
            
            const formData = new FormData();
            formData.append('action', 'request_extension');
            formData.append('reservation_id', reservationId);
            formData.append('extension_days', days);
            formData.append('reason', reason);
            
            fetch('rentals.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeConfirmModal();
                closeExtensionRequestModal();
                if (data.success) {
                    showSuccess('Extension approved successfully');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // Load Returns List
        function loadReturnsList() {
            fetch('rentals.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_today_returns'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayReturnsList(data.returns);
                }
            });
        }

        function displayReturnsList(returns) {
            const container = document.getElementById('returnsList');
            
            if (returns.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No returns scheduled today</p>';
                return;
            }
            
            let html = '';
            returns.forEach(ret => {
                html += `
                    <div class="p-3 rounded-lg bg-blue-500/10 border border-blue-500/20 cursor-pointer hover:bg-blue-500/20 transition-colors" onclick="manageRental(${ret.id})">
                        <p class="text-sm font-medium text-white">${ret.reservation_number} • ${ret.vehicle_name}</p>
                        <p class="text-xs text-gray-400">${ret.first_name} ${ret.last_name} • Due ${new Date(ret.return_date).toLocaleDateString()} ${ret.return_time}</p>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Load Extensions List (recent extensions)
        function loadExtensionsList() {
            fetch('rentals.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_pending_extensions'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayExtensionsList(data.extensions);
                }
            })
            .catch(error => {
                console.error('Error loading extensions:', error);
                document.getElementById('extensionsList').innerHTML = '<p class="text-sm text-gray-400 text-center py-4">Error loading extensions</p>';
            });
        }

        function displayExtensionsList(extensions) {
            const container = document.getElementById('extensionsList');
            
            if (extensions.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No recent extensions</p>';
                return;
            }
            
            let html = '';
            extensions.slice(0, 5).forEach(ext => {
                html += `
                    <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/20">
                        <p class="text-sm font-medium text-white">${ext.first_name} ${ext.last_name}</p>
                        <p class="text-xs text-gray-400">${ext.vehicle_name} • +${ext.extension_days} days</p>
                        <p class="text-xs text-gray-500">Fee: ₱${parseFloat(ext.extension_fee).toFixed(2)}</p>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Filter Functions
        function filterRentals(type) {
            document.getElementById('statusFilter').value = type;
            filterTableByStatus(type);
        }

        function filterTableByStatus(status) {
            const rows = document.querySelectorAll('.rental-row');
            
            rows.forEach(row => {
                if (status === 'all') {
                    row.style.display = '';
                } else {
                    const rowStatus = row.dataset.status;
                    if (rowStatus === status) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
        }

        function searchRentals() {
            const search = document.getElementById('searchRental').value.toLowerCase();
            const rows = document.querySelectorAll('.rental-row');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(search)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadReturnsList();
            loadExtensionsList();
            loadContractsList();
            
            // Refresh data every 60 seconds
            setInterval(() => {
                loadReturnsList();
                loadExtensionsList();
            }, 60000);
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeContractModal();
                closeRentalModal();
                closeExtensionRequestModal();
                closeConfirmModal();
                closeSuccessModal();
            }
        });
    </script>
</body>
</html>