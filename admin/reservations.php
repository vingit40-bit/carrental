<?php
session_start();
require_once '../config/connect.php';

$admin_base = true;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        $conn = getConnection();
        
        // Generate unique reservation number
        function generateReservationNumber($conn) {
            $year = date('Y');
            $month = date('m');
            $prefix = "RES-{$year}{$month}-";
            
            $sql = "SELECT COUNT(*) as count FROM reservations WHERE reservation_number LIKE '$prefix%'";
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            $count = $row['count'] + 1;
            
            return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
        }
        
        // Search Customers (for step 1)
        if ($_POST['action'] === 'search_customers') {
            $search = $conn->real_escape_string($_POST['search']);
            
            $sql = "SELECT id, first_name, last_name, email, phone, license_number, verification_status 
                    FROM customers 
                    WHERE (first_name LIKE '%$search%' 
                    OR last_name LIKE '%$search%' 
                    OR email LIKE '%$search%'
                    OR license_number LIKE '%$search%')
                    AND verification_status = 'Verified'
                    ORDER BY first_name ASC 
                    LIMIT 10";
            
            $result = $conn->query($sql);
            $customers = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $customers[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'customers' => $customers]);
        }
        
        // Get Available Vehicles (for step 2)
        elseif ($_POST['action'] === 'get_available_vehicles') {
            $pickup_date = $conn->real_escape_string($_POST['pickup_date']);
            $return_date = $conn->real_escape_string($_POST['return_date']);
            
            // Find vehicles that are not reserved for the selected dates
            $sql = "SELECT v.* FROM vehicles v 
                    WHERE v.status = 'Available'
                    AND v.id NOT IN (
                        SELECT vehicle_id FROM reservations 
                        WHERE status IN ('Confirmed', 'Ongoing')
                        AND (
                            (pickup_date <= '$return_date' AND return_date >= '$pickup_date')
                        )
                    )
                    ORDER BY v.vehicle_name ASC";
            
            $result = $conn->query($sql);
            $vehicles = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $vehicles[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'vehicles' => $vehicles]);
        }
        
        // Create Reservation (step 3) - Updated with new columns
        elseif ($_POST['action'] === 'create_reservation') {
            $customer_id = (int)$_POST['customer_id'];
            $vehicle_id = (int)$_POST['vehicle_id'];
            $pickup_date = $conn->real_escape_string($_POST['pickup_date']);
            $return_date = $conn->real_escape_string($_POST['return_date']);
            $pickup_time = $conn->real_escape_string($_POST['pickup_time']);
            $return_time = $conn->real_escape_string($_POST['return_time']);
            $total_days = (int)$_POST['total_days'];
            $daily_rate = (float)$_POST['daily_rate'];
            $total_amount = (float)$_POST['total_amount'];
            $special_requests = $conn->real_escape_string($_POST['special_requests']);
            
            // Check if vehicle is still available for these dates
            $check_sql = "SELECT id FROM reservations 
                         WHERE vehicle_id = $vehicle_id 
                         AND status IN ('Confirmed', 'Ongoing')
                         AND (
                             (pickup_date <= '$return_date' AND return_date >= '$pickup_date')
                         )";
            
            $check_result = $conn->query($check_sql);
            if ($check_result->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Vehicle is no longer available for the selected dates']);
                exit;
            }
            
            $reservation_number = generateReservationNumber($conn);
            
            // Updated INSERT with all new columns (set to NULL/default values)
            $sql = "INSERT INTO reservations (
                    reservation_number, customer_id, vehicle_id, 
                    pickup_date, return_date, pickup_time, return_time,
                    total_days, daily_rate, total_amount, special_requests,
                    status, payment_status,
                    actual_pickup_time, actual_return_time, check_in_notes, check_out_notes,
                    late_fee, total_extension_fee, contract_id, contract_signed_at
                    ) VALUES (
                    '$reservation_number', $customer_id, $vehicle_id,
                    '$pickup_date', '$return_date', '$pickup_time', '$return_time',
                    $total_days, $daily_rate, $total_amount, '$special_requests',
                    'Pending', 'Pending',
                    NULL, NULL, NULL, NULL,
                    0.00, 0.00, NULL, NULL
                    )";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Reservation created successfully', 'reservation_number' => $reservation_number]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Get Reservation Details - Updated to include all columns
        elseif ($_POST['action'] === 'get_reservation') {
            $id = (int)$_POST['id'];
            
            $sql = "SELECT r.*, 
                    c.first_name, c.last_name, c.email, c.phone, c.license_number,
                    v.vehicle_name, v.category, v.license_plate, v.image_path,
                    rc.title as contract_title, rc.content as contract_content
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    LEFT JOIN rental_contracts rc ON r.contract_id = rc.id
                    WHERE r.id = $id";
            
            $result = $conn->query($sql);
            
            if ($result->num_rows > 0) {
                $reservation = $result->fetch_assoc();
                echo json_encode(['success' => true, 'reservation' => $reservation]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Reservation not found']);
            }
        }
        
        // Get All Reservations with filters
        elseif ($_POST['action'] === 'get_reservations') {
            $status = isset($_POST['status']) ? $conn->real_escape_string($_POST['status']) : 'all';
            $search = isset($_POST['search']) ? $conn->real_escape_string($_POST['search']) : '';
            
            $where = [];
            if ($status !== 'all') {
                $where[] = "r.status = '$status'";
            }
            if (!empty($search)) {
                $where[] = "(c.first_name LIKE '%$search%' OR c.last_name LIKE '%$search%' OR v.vehicle_name LIKE '%$search%' OR r.reservation_number LIKE '%$search%')";
            }
            
            $where_clause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
            
            $sql = "SELECT r.*, 
                    c.first_name, c.last_name,
                    v.vehicle_name, v.category
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    $where_clause
                    ORDER BY r.created_at DESC";
            
            $result = $conn->query($sql);
            $reservations = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $reservations[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'reservations' => $reservations]);
        }
        
        // Get Calendar Data
        elseif ($_POST['action'] === 'get_calendar_data') {
            $year = (int)$_POST['year'];
            $month = (int)$_POST['month'];
            
            $start_date = "$year-$month-01";
            $end_date = date('Y-m-t', strtotime($start_date));
            
            $sql = "SELECT r.pickup_date, r.return_date, r.status, 
                    c.first_name, c.last_name, v.vehicle_name
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    WHERE (r.pickup_date BETWEEN '$start_date' AND '$end_date')
                    OR (r.return_date BETWEEN '$start_date' AND '$end_date')
                    ORDER BY r.pickup_date ASC";
            
            $result = $conn->query($sql);
            $reservations = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $reservations[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'reservations' => $reservations]);
        }
        
        // Get Recent Activity
        elseif ($_POST['action'] === 'get_recent_activity') {
            $sql = "SELECT r.id, r.reservation_number, r.status, r.created_at,
                    c.first_name, c.last_name,
                    v.vehicle_name
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    ORDER BY r.created_at DESC
                    LIMIT 10";
            
            $result = $conn->query($sql);
            $activities = [];
            
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $activities[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'activities' => $activities]);
        }
        
        // Update Reservation Status (Confirm/Cancel)
        elseif ($_POST['action'] === 'update_status') {
            $id = (int)$_POST['id'];
            $status = $conn->real_escape_string($_POST['status']);
            $reason = isset($_POST['reason']) ? $conn->real_escape_string($_POST['reason']) : '';
            
            // Get the vehicle_id from this reservation first
            $get_vehicle = "SELECT vehicle_id FROM reservations WHERE id = $id";
            $vehicle_result = $conn->query($get_vehicle);
            
            if ($vehicle_result->num_rows > 0) {
                $vehicle_row = $vehicle_result->fetch_assoc();
                $vehicle_id = $vehicle_row['vehicle_id'];
                
                $sql = "UPDATE reservations SET status = '$status'";
                
                if ($status === 'Cancelled' && !empty($reason)) {
                    $sql .= ", cancellation_reason = '$reason'";
                }
                
                $sql .= " WHERE id = $id";
                
                if ($conn->query($sql)) {
                    // Update vehicle status based on reservation status
                    if ($status === 'Confirmed') {
                        // Update vehicle status to Rented when reservation is confirmed
                        $update_vehicle = "UPDATE vehicles SET status = 'Rented' WHERE id = $vehicle_id";
                        $conn->query($update_vehicle);
                    } elseif ($status === 'Cancelled') {
                        // Update vehicle status to Available when reservation is cancelled
                        $update_vehicle = "UPDATE vehicles SET status = 'Available' WHERE id = $vehicle_id";
                        $conn->query($update_vehicle);
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Reservation status updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Reservation not found']);
            }
        }
        
        // Get Dashboard Statistics
        elseif ($_POST['action'] === 'get_stats') {
            $total = $conn->query("SELECT COUNT(*) as count FROM reservations")->fetch_assoc()['count'];
            $pending = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Pending'")->fetch_assoc()['count'];
            $this_week = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE WEEK(pickup_date) = WEEK(CURDATE()) AND YEAR(pickup_date) = YEAR(CURDATE())")->fetch_assoc()['count'];
            $cancelled = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Cancelled'")->fetch_assoc()['count'];
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'total' => $total,
                    'pending' => $pending,
                    'this_week' => $this_week,
                    'cancelled' => $cancelled
                ]
            ]);
        }
        
        $conn->close();
        exit;
    }
}

// Fetch initial statistics
$conn = getConnection();
$total_bookings = $conn->query("SELECT COUNT(*) as count FROM reservations")->fetch_assoc()['count'];
$pending_approvals = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Pending'")->fetch_assoc()['count'];
$this_week = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE WEEK(pickup_date) = WEEK(CURDATE()) AND YEAR(pickup_date) = YEAR(CURDATE())")->fetch_assoc()['count'];
$cancelled = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Cancelled'")->fetch_assoc()['count'];

// Fetch recent reservations for display
$recent_sql = "SELECT r.*, 
               c.first_name, c.last_name,
               v.vehicle_name
               FROM reservations r
               JOIN customers c ON r.customer_id = c.id
               JOIN vehicles v ON r.vehicle_id = v.id
               ORDER BY r.created_at DESC
               LIMIT 10";
$recent_result = $conn->query($recent_sql);

// Fetch pending approvals
$pending_sql = "SELECT r.*, 
                c.first_name, c.last_name,
                v.vehicle_name
                FROM reservations r
                JOIN customers c ON r.customer_id = c.id
                JOIN vehicles v ON r.vehicle_id = v.id
                WHERE r.status = 'Pending'
                ORDER BY r.pickup_date ASC
                LIMIT 5";
$pending_result = $conn->query($pending_sql);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservations / Bookings - Velocity Rentals</title>
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

        /* Step indicators */
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: #374151;
            transform: translateY(-50%);
            z-index: 1;
        }
        
        .step-item {
            position: relative;
            z-index: 2;
            background: #1f2937;
            padding: 0 1rem;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin: 0 auto 0.5rem;
            transition: all 0.3s;
        }
        
        .step-item.active .step-circle {
            background: #dc2626;
            color: white;
        }
        
        .step-item.completed .step-circle {
            background: #10b981;
            color: white;
        }

        /* Vehicle card */
        .vehicle-card {
            background: #1f2937;
            border: 1px solid #374151;
            border-radius: 0.75rem;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .vehicle-card:hover {
            border-color: #dc2626;
            transform: translateY(-2px);
        }
        
        .vehicle-card.selected {
            border-color: #dc2626;
            background: rgba(220, 38, 38, 0.1);
        }
        
        .vehicle-image {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 0.5rem;
            margin-bottom: 0.75rem;
        }

        /* Customer search result */
        .customer-result {
            background: #1f2937;
            border: 1px solid #374151;
            border-radius: 0.5rem;
            padding: 0.75rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .customer-result:hover {
            border-color: #dc2626;
        }
        
        .customer-result.selected {
            border-color: #dc2626;
            background: rgba(220, 38, 38, 0.1);
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

        /* Calendar styles */
        .calendar-day {
            position: relative;
            min-height: 80px;
            padding: 0.5rem;
            border-radius: 0.5rem;
            background: #1f2937;
            border: 1px solid #374151;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
        }
        
        .calendar-day:hover {
            border-color: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
        }
        
        .calendar-day-number {
            font-size: 1rem;
            font-weight: 700;
            color: #e5e7eb;
            text-align: center;
            margin-bottom: 0.25rem;
        }
        
        .calendar-cars {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            justify-content: center;
            margin-top: auto;
        }
        
        .car-icon {
            font-size: 0.65rem;
            padding: 1px 2px;
            border-radius: 2px;
            line-height: 1;
        }
        
        .car-icon.pending { color: #f59e0b; }
        .car-icon.confirmed { color: #10b981; }
        .car-icon.cancelled { color: #ef4444; }
        .car-icon.ongoing { color: #3b82f6; }
        .car-icon.completed { color: #6b7280; }
        
        /* Tooltip */
        .calendar-tooltip {
            position: fixed;
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            border: 1px solid #374151;
            border-radius: 0.75rem;
            padding: 1rem;
            z-index: 100;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.6), 0 0 20px rgba(220, 38, 38, 0.1);
            pointer-events: none;
            min-width: 220px;
            max-width: 280px;
            animation: tooltipFadeIn 0.2s ease-out;
        }
        
        @keyframes tooltipFadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .calendar-tooltip.hidden {
            display: none;
        }
        
        .tooltip-title {
            font-weight: 700;
            color: #f3f4f6;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #374151;
        }
        
        .tooltip-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0;
            font-size: 0.8rem;
            color: #d1d5db;
        }
        
        .tooltip-status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .tooltip-status.pending { background: #f59e0b; }
        .tooltip-status.confirmed { background: #10b981; }
        .tooltip-status.cancelled { background: #ef4444; }
        .tooltip-status.ongoing { background: #3b82f6; }
        .tooltip-status.completed { background: #6b7280; }
        
        .tooltip-vehicle {
            color: #9ca3af;
            font-size: 0.75rem;
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
                            <h1 class="text-xl font-bold text-white">Reservations / Bookings</h1>
                            <p class="text-xs text-gray-500">Handle advance reservations and approvals</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="hidden md:flex items-center bg-gray-800/80 rounded-lg px-3 py-2 border border-gray-600/30">
                        <i class="fas fa-search text-gray-500 text-sm"></i>
                        <input type="text" id="globalSearch" placeholder="Search reservation..." onkeyup="searchReservations()" class="bg-transparent border-none outline-none text-sm text-gray-300 placeholder-gray-500 ml-2 w-52">
                    </div>
                    <button onclick="openNewReservationModal()" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white rounded-lg text-sm font-medium transition-all shadow-lg shadow-red-600/20">
                        <i class="fas fa-plus"></i>
                        <span>New Reservation</span>
                    </button>
                </div>
            </div>
        </header>

        <div class="p-4 lg:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-red-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-600/30 to-red-700/10 flex items-center justify-center border border-red-500/20">
                            <i class="fas fa-calendar-check text-red-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Total Bookings</h3>
                    <p class="text-2xl font-bold text-white" id="totalBookings"><?php echo $total_bookings; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-amber-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-600/30 to-amber-700/10 flex items-center justify-center border border-amber-500/20">
                            <i class="fas fa-clock text-amber-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Pending Approvals</h3>
                    <p class="text-2xl font-bold text-white" id="pendingApprovals"><?php echo $pending_approvals; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-blue-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-600/30 to-blue-700/10 flex items-center justify-center border border-blue-500/20">
                            <i class="fas fa-chart-line text-blue-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">This Week</h3>
                    <p class="text-2xl font-bold text-white" id="thisWeek"><?php echo $this_week; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-gray-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-gray-600/30 to-gray-700/10 flex items-center justify-center border border-gray-500/20">
                            <i class="fas fa-ban text-gray-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Cancelled</h3>
                    <p class="text-2xl font-bold text-white" id="cancelled"><?php echo $cancelled; ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <button onclick="filterByStatus('all')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-list text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">All Reservations</span>
                </button>
                <button onclick="openNewReservationModal()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-plus-circle text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">New Reservation</span>
                </button>
                <button onclick="filterByStatus('Pending')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-amber-500/50 rounded-xl transition-all group">
                    <i class="fas fa-clock text-amber-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Pending Approvals</span>
                </button>
                <button onclick="filterByStatus('Confirmed')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-emerald-500/50 rounded-xl transition-all group">
                    <i class="fas fa-check-circle text-emerald-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Confirmed</span>
                </button>
                <button onclick="filterByStatus('Cancelled')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-ban text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Cancelled</span>
                </button>
                <button onclick="filterByStatus('Completed')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-blue-500/50 rounded-xl transition-all group">
                    <i class="fas fa-history text-blue-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">History</span>
                </button>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
                <!-- Reservation List Table -->
                <div class="xl:col-span-2 card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Reservation List</h2>
                            <p class="text-sm text-gray-500">Current and upcoming bookings</p>
                        </div>
                        <select id="statusFilter" onchange="filterReservations()" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                            <option value="all">All Status</option>
                            <option value="Confirmed">Confirmed</option>
                            <option value="Pending">Pending</option>
                            <option value="Cancelled">Cancelled</option>
                            <option value="Completed">Completed</option>
                            <option value="Ongoing">Ongoing</option>
                        </select>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-700/30 bg-gray-800/30">
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Reservation #</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Customer</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Vehicle</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Pickup</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Return</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Status</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="reservationTableBody" class="divide-y divide-gray-700/30">
                                <?php if ($recent_result && $recent_result->num_rows > 0): ?>
                                    <?php while($reservation = $recent_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-800/40 transition-colors reservation-row" 
                                            data-status="<?php echo $reservation['status']; ?>"
                                            data-id="<?php echo $reservation['id']; ?>">
                                            <td class="px-5 py-4 text-sm font-mono text-gray-300"><?php echo $reservation['reservation_number']; ?></td>
                                            <td class="px-5 py-4 text-sm text-white"><?php echo $reservation['first_name'] . ' ' . $reservation['last_name']; ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-300"><?php echo $reservation['vehicle_name']; ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-300"><?php echo date('M d, Y', strtotime($reservation['pickup_date'])); ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-300"><?php echo date('M d, Y', strtotime($reservation['return_date'])); ?></td>
                                            <td class="px-5 py-4">
                                                <span class="px-2 py-1 text-xs font-medium 
                                                    <?php 
                                                        if($reservation['status'] == 'Confirmed') echo 'bg-emerald-500/10 text-emerald-500';
                                                        elseif($reservation['status'] == 'Pending') echo 'bg-amber-500/10 text-amber-500';
                                                        elseif($reservation['status'] == 'Cancelled') echo 'bg-red-500/10 text-red-500';
                                                        elseif($reservation['status'] == 'Completed') echo 'bg-blue-500/10 text-blue-500';
                                                        elseif($reservation['status'] == 'Ongoing') echo 'bg-purple-500/10 text-purple-500';
                                                        else echo 'bg-gray-500/10 text-gray-500';
                                                    ?> rounded-full">
                                                    <?php echo $reservation['status']; ?>
                                                </span>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="flex items-center gap-2">
                                                    <button onclick="viewReservation(<?php echo $reservation['id']; ?>)" class="p-2 text-gray-400 hover:text-blue-500 hover:bg-gray-700 rounded-lg transition-colors" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if($reservation['status'] == 'Pending'): ?>
                                                        <button onclick="openReviewModal(<?php echo $reservation['id']; ?>)" class="p-2 text-gray-400 hover:text-amber-500 hover:bg-gray-700 rounded-lg transition-colors" title="Review">
                                                            <i class="fas fa-clipboard-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="px-5 py-8 text-center text-gray-500">
                                            <i class="fas fa-calendar-times text-4xl mb-2 opacity-50"></i>
                                            <p>No reservations found. Click "New Reservation" to get started.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pending Approvals Panel -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Pending Approvals</h2>
                        <p class="text-sm text-gray-500">Bookings awaiting confirmation</p>
                    </div>
                    <div class="p-4 space-y-3" id="pendingApprovalsList">
                        <?php if ($pending_result && $pending_result->num_rows > 0): ?>
                            <?php while($pending = $pending_result->fetch_assoc()): ?>
                                <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/20">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-white"><?php echo $pending['first_name'] . ' ' . $pending['last_name']; ?></p>
                                            <p class="text-xs text-gray-400"><?php echo $pending['vehicle_name']; ?> • <?php echo date('M d', strtotime($pending['pickup_date'])); ?> - <?php echo date('M d', strtotime($pending['return_date'])); ?></p>
                                        </div>
                                        <button onclick="openReviewModal(<?php echo $pending['id']; ?>)" class="text-xs text-amber-500 hover:text-amber-400">Review</button>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-sm text-gray-400 text-center py-4">No pending approvals</p>
                        <?php endif; ?>
                    </div>
                    <div class="p-4 pt-0">
                        <button onclick="filterByStatus('Pending')" class="w-full py-2 text-sm text-red-500 hover:text-red-400 border border-red-500/20 rounded-lg hover:border-red-500/40 transition-colors">
                            View All Pending
                        </button>
                    </div>
                </div>
            </div>

            <!-- Calendar and History Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Booking Calendar -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-white">Booking Calendar</h2>
                                <p class="text-sm text-gray-500" id="calendarMonthYear"><?php echo date('F Y'); ?></p>
                            </div>
                            <div class="flex gap-2">
                                <button onclick="changeMonth(-1)" class="p-2 bg-gray-800 rounded-lg hover:bg-gray-700 transition-colors">
                                    <i class="fas fa-chevron-left text-gray-400"></i>
                                </button>
                                <button onclick="changeMonth(1)" class="p-2 bg-gray-800 rounded-lg hover:bg-gray-700 transition-colors">
                                    <i class="fas fa-chevron-right text-gray-400"></i>
                                </button>
                            </div>
                        </div>
                        <div class="flex gap-4 mt-3 text-xs">
                            <div class="flex items-center gap-1">
                                <div class="w-3 h-3 rounded-full bg-emerald-500/20 border border-emerald-500"></div>
                                <span class="text-gray-400">Confirmed</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <div class="w-3 h-3 rounded-full bg-amber-500/20 border border-amber-500"></div>
                                <span class="text-gray-400">Pending</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <div class="w-3 h-3 rounded-full bg-red-500/20 border border-red-500"></div>
                                <span class="text-gray-400">Cancelled</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <div class="w-3 h-3 rounded-full bg-blue-500/20 border border-blue-500"></div>
                                <span class="text-gray-400">Ongoing/Completed</span>
                            </div>
                        </div>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-7 gap-2 text-center text-xs text-gray-500 mb-3">
                            <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                        </div>
                        <div id="calendar" class="grid grid-cols-7 gap-2">
                            <!-- Calendar will be generated by JavaScript -->
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Recent Activity</h2>
                        <p class="text-sm text-gray-500">Latest reservation updates</p>
                    </div>
                    <div id="recentActivity" class="p-4 space-y-3 max-h-96 overflow-y-auto">
                        <!-- Will be populated by JavaScript -->
                        <div class="flex justify-center py-8">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- New Reservation Modal (3 Steps) -->
    <div id="reservationModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeReservationModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-4xl max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700 sticky top-0 bg-gray-900 z-10">
                    <h2 class="text-xl font-semibold text-white">New Reservation</h2>
                    <button onclick="closeReservationModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <!-- Step Indicator -->
                <div class="step-indicator px-5 pt-5">
                    <div class="step-item active" id="step1Indicator">
                        <div class="step-circle">1</div>
                        <p class="text-xs text-center text-gray-400">Customer</p>
                    </div>
                    <div class="step-item" id="step2Indicator">
                        <div class="step-circle">2</div>
                        <p class="text-xs text-center text-gray-400">Vehicle</p>
                    </div>
                    <div class="step-item" id="step3Indicator">
                        <div class="step-circle">3</div>
                        <p class="text-xs text-center text-gray-400">Details</p>
                    </div>
                </div>
                
                <div class="p-5">
                    <!-- Step 1: Customer Selection -->
                    <div id="step1" class="step-content">
                        <h3 class="text-lg font-medium text-white mb-4">Select Customer</h3>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Search Customer</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                                <input type="text" id="customerSearch" placeholder="Search by name, email, or license number..." class="w-full bg-gray-800 border border-gray-700 rounded-lg pl-10 pr-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" onkeyup="searchCustomers()">
                            </div>
                        </div>
                        
                        <div id="customerResults" class="space-y-2 max-h-60 overflow-y-auto mb-4">
                            <!-- Customer results will appear here -->
                            <p class="text-center text-gray-500 py-4">Start typing to search for verified customers</p>
                        </div>
                        
                        <div id="selectedCustomerInfo" class="hidden p-4 bg-gray-800/50 rounded-lg border border-gray-700">
                            <h4 class="text-sm font-medium text-gray-400 mb-2">Selected Customer</h4>
                            <div id="selectedCustomerDetails"></div>
                        </div>
                        
                        <div class="flex justify-end mt-6">
                            <button onclick="nextStep(2)" id="step1NextBtn" disabled class="px-6 py-2 bg-gray-600 text-gray-300 rounded-lg cursor-not-allowed">Next</button>
                        </div>
                    </div>
                    
                    <!-- Step 2: Vehicle Selection -->
                    <div id="step2" class="step-content hidden">
                        <h3 class="text-lg font-medium text-white mb-4">Select Vehicle</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-400 mb-2">Pickup Date</label>
                                <input type="date" id="pickupDate" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" onchange="searchAvailableVehicles()">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-400 mb-2">Return Date</label>
                                <input type="date" id="returnDate" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" onchange="searchAvailableVehicles()">
                            </div>
                        </div>
                        
                        <div id="vehiclesGrid" class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-96 overflow-y-auto">
                            <!-- Vehicles will appear here -->
                            <p class="text-center text-gray-500 py-4 col-span-2">Select dates to see available vehicles</p>
                        </div>
                        
                        <div class="flex justify-between mt-6">
                            <button onclick="prevStep(1)" class="px-6 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Back</button>
                            <button onclick="nextStep(3)" id="step2NextBtn" disabled class="px-6 py-2 bg-gray-600 text-gray-300 rounded-lg cursor-not-allowed">Next</button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Reservation Details -->
                    <div id="step3" class="step-content hidden">
                        <h3 class="text-lg font-medium text-white mb-4">Reservation Details</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-400 mb-2">Pickup Time</label>
                                <input type="time" id="pickupTime" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" value="09:00">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-400 mb-2">Return Time</label>
                                <input type="time" id="returnTime" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" value="18:00">
                            </div>
                        </div>
                        
                        <div class="bg-gray-800/50 rounded-lg p-4 mb-4">
                            <h4 class="text-sm font-medium text-gray-400 mb-3">Booking Summary</h4>
                            <div id="bookingSummary" class="space-y-2 text-sm">
                                <!-- Summary will be populated -->
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Special Requests (Optional)</label>
                            <textarea id="specialRequests" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Any special requirements..."></textarea>
                        </div>
                        
                        <div class="flex justify-between mt-6">
                            <button onclick="prevStep(2)" class="px-6 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Back</button>
                            <button onclick="createReservation()" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                                Create Reservation
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Reservation Modal -->
    <div id="viewReservationModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeViewReservationModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-3xl modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 class="text-xl font-semibold text-white">Reservation Details</h2>
                    <button onclick="closeViewReservationModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="reservationDetails" class="p-5">
                    <!-- Details will be loaded here -->
                </div>
                <div class="flex justify-end p-5 border-t border-gray-700">
                    <button onclick="closeViewReservationModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Review Reservation Modal (Confirm/Cancel) -->
    <div id="reviewModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeReviewModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 class="text-xl font-semibold text-white">Review Reservation</h2>
                    <button onclick="closeReviewModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="p-5 space-y-4">
                    <input type="hidden" id="reviewReservationId">
                    
                    <div id="reviewReservationInfo" class="bg-gray-800/50 p-3 rounded-lg">
                        <!-- Reservation info will be shown -->
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Action</label>
                        <select id="reviewAction" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                            <option value="Confirmed">Confirm Reservation</option>
                            <option value="Cancelled">Cancel Reservation</option>
                        </select>
                    </div>
                    
                    <div id="cancellationReason" class="hidden">
                        <label class="block text-sm font-medium text-gray-400 mb-2">Cancellation Reason</label>
                        <textarea id="cancelReason" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Please provide reason for cancellation..."></textarea>
                    </div>
                    
                    <div class="flex justify-end gap-3">
                        <button onclick="closeReviewModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Close</button>
                        <button onclick="submitReview()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Submit</button>
                    </div>
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

    <!-- Tooltip for Calendar -->
    <div id="calendarTooltip" class="calendar-tooltip hidden"></div>

    <script>
        // Global variables
        let currentStep = 1;
        let selectedCustomer = null;
        let selectedVehicle = null;
        let availableVehicles = [];
        let currentYear = <?php echo date('Y'); ?>;
        let currentMonth = <?php echo date('n'); ?>;
        
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

        // New Reservation Modal
        function openNewReservationModal() {
            resetReservationModal();
            document.getElementById('reservationModal').classList.remove('hidden');
        }

        function closeReservationModal() {
            document.getElementById('reservationModal').classList.add('hidden');
        }

        function resetReservationModal() {
            currentStep = 1;
            selectedCustomer = null;
            selectedVehicle = null;
            
            // Reset step indicators
            document.querySelectorAll('.step-item').forEach((item, index) => {
                if (index === 0) {
                    item.classList.add('active');
                    item.classList.remove('completed');
                } else {
                    item.classList.remove('active', 'completed');
                }
            });
            
            // Show step 1, hide others
            document.getElementById('step1').classList.remove('hidden');
            document.getElementById('step2').classList.add('hidden');
            document.getElementById('step3').classList.add('hidden');
            
            // Reset inputs
            document.getElementById('customerSearch').value = '';
            document.getElementById('customerResults').innerHTML = '<p class="text-center text-gray-500 py-4">Start typing to search for verified customers</p>';
            document.getElementById('selectedCustomerInfo').classList.add('hidden');
            document.getElementById('step1NextBtn').disabled = true;
            document.getElementById('step1NextBtn').classList.remove('bg-red-600', 'hover:bg-red-700', 'cursor-pointer');
            document.getElementById('step1NextBtn').classList.add('bg-gray-600', 'cursor-not-allowed');
            
            document.getElementById('pickupDate').value = '';
            document.getElementById('returnDate').value = '';
            document.getElementById('vehiclesGrid').innerHTML = '<p class="text-center text-gray-500 py-4 col-span-2">Select dates to see available vehicles</p>';
            document.getElementById('step2NextBtn').disabled = true;
        }

        // Step navigation
        function nextStep(step) {
            currentStep = step;
            
            // Update step indicators
            document.querySelectorAll('.step-item').forEach((item, index) => {
                if (index + 1 < step) {
                    item.classList.add('completed');
                    item.classList.remove('active');
                } else if (index + 1 === step) {
                    item.classList.add('active');
                    item.classList.remove('completed');
                } else {
                    item.classList.remove('active', 'completed');
                }
            });
            
            // Show/hide step contents
            document.getElementById('step1').classList.add('hidden');
            document.getElementById('step2').classList.add('hidden');
            document.getElementById('step3').classList.add('hidden');
            document.getElementById(`step${step}`).classList.remove('hidden');
            
            if (step === 2) {
                // Set min dates for pickup and return
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('pickupDate').min = today;
                document.getElementById('returnDate').min = today;
            } else if (step === 3) {
                updateBookingSummary();
            }
        }

        function prevStep(step) {
            nextStep(step);
        }

        // Customer Search
        let searchTimeout;
        function searchCustomers() {
            clearTimeout(searchTimeout);
            const search = document.getElementById('customerSearch').value.trim();
            
            if (search.length < 2) {
                document.getElementById('customerResults').innerHTML = '<p class="text-center text-gray-500 py-4">Type at least 2 characters to search</p>';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                fetch('reservations.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=search_customers&search=' + encodeURIComponent(search)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayCustomerResults(data.customers);
                    }
                });
            }, 300);
        }

        function displayCustomerResults(customers) {
            const resultsDiv = document.getElementById('customerResults');
            
            if (customers.length === 0) {
                resultsDiv.innerHTML = '<p class="text-center text-gray-500 py-4">No verified customers found</p>';
                return;
            }
            
            let html = '';
            customers.forEach(customer => {
                html += `
                    <div class="customer-result" onclick="selectCustomer(${customer.id}, '${customer.first_name}', '${customer.last_name}', '${customer.email}', '${customer.phone}', '${customer.license_number}')">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-white font-medium">${customer.first_name} ${customer.last_name}</p>
                                <p class="text-xs text-gray-400">${customer.email}</p>
                                <p class="text-xs text-gray-400">${customer.phone} • License: ${customer.license_number}</p>
                            </div>
                            <span class="text-xs bg-emerald-500/10 text-emerald-500 px-2 py-1 rounded-full">Verified</span>
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
        }

        function selectCustomer(id, firstName, lastName, email, phone, license) {
            selectedCustomer = { id, firstName, lastName, email, phone, license };
            
            // Highlight selected customer
            document.querySelectorAll('.customer-result').forEach(el => {
                el.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            // Show selected customer info
            document.getElementById('selectedCustomerInfo').classList.remove('hidden');
            document.getElementById('selectedCustomerDetails').innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-gray-700 flex items-center justify-center">
                        <i class="fas fa-user text-gray-400"></i>
                    </div>
                    <div>
                        <p class="text-white font-medium">${firstName} ${lastName}</p>
                        <p class="text-xs text-gray-400">${email} • ${phone}</p>
                        <p class="text-xs text-gray-400">License: ${license}</p>
                    </div>
                </div>
            `;
            
            // Enable next button
            document.getElementById('step1NextBtn').disabled = false;
            document.getElementById('step1NextBtn').classList.remove('bg-gray-600', 'cursor-not-allowed');
            document.getElementById('step1NextBtn').classList.add('bg-red-600', 'hover:bg-red-700', 'cursor-pointer');
        }

        // Vehicle Search
        function searchAvailableVehicles() {
            const pickupDate = document.getElementById('pickupDate').value;
            const returnDate = document.getElementById('returnDate').value;
            
            if (!pickupDate || !returnDate) {
                return;
            }
            
            if (new Date(pickupDate) > new Date(returnDate)) {
                alert('Return date must be after pickup date');
                return;
            }
            
            fetch('reservations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_available_vehicles&pickup_date=' + pickupDate + '&return_date=' + returnDate
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    availableVehicles = data.vehicles;
                    displayVehicles(data.vehicles);
                }
            });
        }

        function displayVehicles(vehicles) {
            const grid = document.getElementById('vehiclesGrid');
            
            if (vehicles.length === 0) {
                grid.innerHTML = '<p class="text-center text-gray-500 py-4 col-span-2">No vehicles available for selected dates</p>';
                document.getElementById('step2NextBtn').disabled = true;
                return;
            }
            
            let html = '';
            vehicles.forEach(vehicle => {
                const imagePath = vehicle.image_path !== 'default-car.png' ? 
                    `../uploads/vehicles/${vehicle.image_path}` : 
                    'https://via.placeholder.com/300x200/1f2937/ffffff?text=No+Image';
                
                html += `
                    <div class="vehicle-card" onclick="selectVehicle(${vehicle.id})" id="vehicle-${vehicle.id}">
                        <img src="${imagePath}" alt="${vehicle.vehicle_name}" class="vehicle-image" onerror="this.src='https://via.placeholder.com/300x200/1f2937/ffffff?text=No+Image'">
                        <h4 class="text-white font-medium">${vehicle.vehicle_name}</h4>
                        <p class="text-xs text-gray-400">${vehicle.category} • ${vehicle.transmission}</p>
                        <div class="flex justify-between items-center mt-2">
                            <span class="text-xs text-gray-400">${vehicle.fuel_type}</span>
                            <span class="text-sm font-bold text-red-500">₱${parseFloat(vehicle.price_per_day).toFixed(2)}/day</span>
                        </div>
                    </div>
                `;
            });
            
            grid.innerHTML = html;
        }

        function selectVehicle(id) {
            selectedVehicle = availableVehicles.find(v => v.id == id);
            
            // Remove selected class from all vehicles
            document.querySelectorAll('.vehicle-card').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Add selected class to clicked vehicle
            document.getElementById(`vehicle-${id}`).classList.add('selected');
            
            // Enable next button
            document.getElementById('step2NextBtn').disabled = false;
            document.getElementById('step2NextBtn').classList.remove('bg-gray-600', 'cursor-not-allowed');
            document.getElementById('step2NextBtn').classList.add('bg-red-600', 'hover:bg-red-700', 'cursor-pointer');
        }

        // Booking Summary
        function updateBookingSummary() {
            const pickupDate = new Date(document.getElementById('pickupDate').value);
            const returnDate = new Date(document.getElementById('returnDate').value);
            const diffTime = Math.abs(returnDate - pickupDate);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            const dailyRate = selectedVehicle.price_per_day;
            const totalAmount = dailyRate * diffDays;
            
            const summary = `
                <div class="flex justify-between">
                    <span class="text-gray-400">Customer:</span>
                    <span class="text-white">${selectedCustomer.firstName} ${selectedCustomer.lastName}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Vehicle:</span>
                    <span class="text-white">${selectedVehicle.vehicle_name}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Pickup Date:</span>
                    <span class="text-white">${pickupDate.toLocaleDateString()}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Return Date:</span>
                    <span class="text-white">${returnDate.toLocaleDateString()}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Total Days:</span>
                    <span class="text-white">${diffDays}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Daily Rate:</span>
                    <span class="text-white">₱${dailyRate.toFixed(2)}</span>
                </div>
                <div class="flex justify-between pt-2 border-t border-gray-700">
                    <span class="text-gray-400 font-medium">Total Amount:</span>
                    <span class="text-xl font-bold text-red-500">₱${totalAmount.toFixed(2)}</span>
                </div>
            `;
            
            document.getElementById('bookingSummary').innerHTML = summary;
        }

        // Create Reservation - Updated to work with new table columns
        function createReservation() {
            const pickupDate = document.getElementById('pickupDate').value;
            const returnDate = document.getElementById('returnDate').value;
            const pickupTime = document.getElementById('pickupTime').value;
            const returnTime = document.getElementById('returnTime').value;
            const specialRequests = document.getElementById('specialRequests').value;
            
            const diffTime = Math.abs(new Date(returnDate) - new Date(pickupDate));
            const totalDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            const totalAmount = selectedVehicle.price_per_day * totalDays;
            
            const formData = new FormData();
            formData.append('action', 'create_reservation');
            formData.append('customer_id', selectedCustomer.id);
            formData.append('vehicle_id', selectedVehicle.id);
            formData.append('pickup_date', pickupDate);
            formData.append('return_date', returnDate);
            formData.append('pickup_time', pickupTime);
            formData.append('return_time', returnTime);
            formData.append('total_days', totalDays);
            formData.append('daily_rate', selectedVehicle.price_per_day);
            formData.append('total_amount', totalAmount);
            formData.append('special_requests', specialRequests);
            
            fetch('reservations.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeReservationModal();
                    showSuccess('Reservation created successfully!');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // View Reservation - Updated to show new fields
        function viewReservation(id) {
            fetch('reservations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_reservation&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayReservationDetails(data.reservation);
                }
            });
        }

        function displayReservationDetails(r) {
            const statusClass = r.status === 'Confirmed' ? 'text-emerald-500' : 
                               r.status === 'Pending' ? 'text-amber-500' : 
                               r.status === 'Cancelled' ? 'text-red-500' : 
                               r.status === 'Ongoing' ? 'text-purple-500' : 'text-blue-500';
            
            const vehicleImage = r.image_path !== 'default-car.png' ? 
                `../uploads/vehicles/${r.image_path}` : 
                'https://via.placeholder.com/300x200/1f2937/ffffff?text=No+Image';
            
            // Show additional fields if they exist
            const actualPickupHtml = r.actual_pickup_time ? `<p class="text-xs text-emerald-500">Actual Pickup: ${new Date(r.actual_pickup_time).toLocaleString()}</p>` : '';
            const actualReturnHtml = r.actual_return_time ? `<p class="text-xs text-blue-500">Actual Return: ${new Date(r.actual_return_time).toLocaleString()}</p>` : '';
            const lateFeeHtml = r.late_fee > 0 ? `<p class="text-xs text-red-500">Late Fee: ₱${parseFloat(r.late_fee).toFixed(2)}</p>` : '';
            const extensionFeeHtml = r.total_extension_fee > 0 ? `<p class="text-xs text-amber-500">Extension Fee: ₱${parseFloat(r.total_extension_fee).toFixed(2)}</p>` : '';
            
            const details = `
                <div class="space-y-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm text-gray-400">Reservation Number</p>
                            <p class="text-lg font-mono font-bold text-white">${r.reservation_number}</p>
                        </div>
                        <span class="px-3 py-1 text-sm font-medium ${statusClass} bg-opacity-10 rounded-full">${r.status}</span>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <!-- Customer Information -->
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-sm mb-2">Customer Information</p>
                            <p class="text-white font-medium">${r.first_name} ${r.last_name}</p>
                            <p class="text-xs text-gray-400">${r.email}</p>
                            <p class="text-xs text-gray-400">${r.phone}</p>
                            <p class="text-xs text-gray-400">License: ${r.license_number}</p>
                        </div>
                        
                        <!-- Vehicle Information -->
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-sm mb-2">Vehicle Information</p>
                            <img src="${vehicleImage}" alt="${r.vehicle_name}" class="w-full h-24 object-cover rounded-lg mb-2" onerror="this.src='https://via.placeholder.com/300x200/1f2937/ffffff?text=No+Image'">
                            <p class="text-white font-medium">${r.vehicle_name}</p>
                            <p class="text-xs text-gray-400">${r.category} • ${r.license_plate}</p>
                        </div>
                    </div>
                    
                    <!-- Rental Dates -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-sm">Pickup</p>
                            <p class="text-white font-medium">${new Date(r.pickup_date).toLocaleDateString()}</p>
                            <p class="text-xs text-gray-400">${r.pickup_time}</p>
                            ${actualPickupHtml}
                        </div>
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-sm">Return</p>
                            <p class="text-white font-medium">${new Date(r.return_date).toLocaleDateString()}</p>
                            <p class="text-xs text-gray-400">${r.return_time}</p>
                            ${actualReturnHtml}
                        </div>
                    </div>
                    
                    <!-- Payment Summary -->
                    <div class="bg-gray-800/50 p-3 rounded-lg">
                        <p class="text-gray-400 text-sm mb-2">Payment Summary</p>
                        <div class="space-y-1">
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-400">Daily Rate:</span>
                                <span class="text-xs text-white">₱${parseFloat(r.daily_rate).toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-400">Total Days:</span>
                                <span class="text-xs text-white">${r.total_days}</span>
                            </div>
                            ${lateFeeHtml ? `<div class="flex justify-between text-red-400">${lateFeeHtml}</div>` : ''}
                            ${extensionFeeHtml ? `<div class="flex justify-between text-amber-400">${extensionFeeHtml}</div>` : ''}
                            <div class="flex justify-between pt-2 border-t border-gray-700">
                                <span class="text-sm text-gray-400">Total Amount:</span>
                                <span class="text-lg font-bold text-red-500">₱${parseFloat(r.total_amount).toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between mt-2">
                                <span class="text-xs text-gray-400">Payment Status:</span>
                                <span class="text-xs text-amber-500">${r.payment_status}</span>
                            </div>
                        </div>
                    </div>
                    
                    ${r.check_in_notes ? `
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-sm mb-1">Check-In Notes</p>
                            <p class="text-sm text-white">${r.check_in_notes}</p>
                        </div>
                    ` : ''}
                    
                    ${r.check_out_notes ? `
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-sm mb-1">Check-Out Notes</p>
                            <p class="text-sm text-white">${r.check_out_notes}</p>
                        </div>
                    ` : ''}
                    
                    ${r.special_requests ? `
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-sm mb-1">Special Requests</p>
                            <p class="text-sm text-white">${r.special_requests}</p>
                        </div>
                    ` : ''}
                    
                    ${r.cancellation_reason ? `
                        <div class="bg-red-500/10 p-3 rounded-lg">
                            <p class="text-gray-400 text-sm mb-1">Cancellation Reason</p>
                            <p class="text-sm text-red-400">${r.cancellation_reason}</p>
                        </div>
                    ` : ''}
                </div>
            `;
            
            document.getElementById('reservationDetails').innerHTML = details;
            document.getElementById('viewReservationModal').classList.remove('hidden');
        }

        function closeViewReservationModal() {
            document.getElementById('viewReservationModal').classList.add('hidden');
        }

        // Review Reservation (Confirm/Cancel)
        function openReviewModal(id) {
            document.getElementById('reviewReservationId').value = id;
            
            // Fetch reservation details
            fetch('reservations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_reservation&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const r = data.reservation;
                    document.getElementById('reviewReservationInfo').innerHTML = `
                        <p class="text-sm text-white">${r.first_name} ${r.last_name}</p>
                        <p class="text-xs text-gray-400">${r.vehicle_name}</p>
                        <p class="text-xs text-gray-400">${new Date(r.pickup_date).toLocaleDateString()} - ${new Date(r.return_date).toLocaleDateString()}</p>
                    `;
                }
            });
            
            document.getElementById('reviewModal').classList.remove('hidden');
        }

        function closeReviewModal() {
            document.getElementById('reviewModal').classList.add('hidden');
            document.getElementById('cancellationReason').classList.add('hidden');
            document.getElementById('reviewAction').value = 'Confirmed';
            document.getElementById('cancelReason').value = '';
        }

        // Show/hide cancellation reason based on action
        document.getElementById('reviewAction')?.addEventListener('change', function() {
            if (this.value === 'Cancelled') {
                document.getElementById('cancellationReason').classList.remove('hidden');
            } else {
                document.getElementById('cancellationReason').classList.add('hidden');
            }
        });

        function submitReview() {
            const id = document.getElementById('reviewReservationId').value;
            const status = document.getElementById('reviewAction').value;
            const reason = document.getElementById('cancelReason').value;
            
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('id', id);
            formData.append('status', status);
            if (status === 'Cancelled') {
                formData.append('reason', reason);
            }
            
            fetch('reservations.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeReviewModal();
                    showSuccess(data.message);
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // Filter Reservations
        function filterReservations() {
            const status = document.getElementById('statusFilter').value;
            filterByStatus(status);
        }

        function filterByStatus(status) {
            document.getElementById('statusFilter').value = status;
            
            const search = document.getElementById('globalSearch').value;
            
            const formData = new FormData();
            formData.append('action', 'get_reservations');
            formData.append('status', status);
            if (search) {
                formData.append('search', search);
            }
            
            fetch('reservations.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateReservationTable(data.reservations);
                }
            });
        }

        function searchReservations() {
            filterReservations();
        }

        function updateReservationTable(reservations) {
            const tbody = document.getElementById('reservationTableBody');
            
            if (reservations.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-gray-500">
                            <i class="fas fa-calendar-times text-4xl mb-2 opacity-50"></i>
                            <p>No reservations found</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            let html = '';
            reservations.forEach(r => {
                const statusClass = r.status === 'Confirmed' ? 'bg-emerald-500/10 text-emerald-500' :
                                   r.status === 'Pending' ? 'bg-amber-500/10 text-amber-500' :
                                   r.status === 'Cancelled' ? 'bg-red-500/10 text-red-500' :
                                   r.status === 'Completed' ? 'bg-blue-500/10 text-blue-500' :
                                   r.status === 'Ongoing' ? 'bg-purple-500/10 text-purple-500' : 'bg-gray-500/10 text-gray-500';
                
                html += `
                    <tr class="hover:bg-gray-800/40 transition-colors">
                        <td class="px-5 py-4 text-sm font-mono text-gray-300">${r.reservation_number}</td>
                        <td class="px-5 py-4 text-sm text-white">${r.first_name} ${r.last_name}</td>
                        <td class="px-5 py-4 text-sm text-gray-300">${r.vehicle_name}</td>
                        <td class="px-5 py-4 text-sm text-gray-300">${new Date(r.pickup_date).toLocaleDateString()}</td>
                        <td class="px-5 py-4 text-sm text-gray-300">${new Date(r.return_date).toLocaleDateString()}</td>
                        <td class="px-5 py-4">
                            <span class="px-2 py-1 text-xs font-medium ${statusClass} rounded-full">${r.status}</span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-2">
                                <button onclick="viewReservation(${r.id})" class="p-2 text-gray-400 hover:text-blue-500 hover:bg-gray-700 rounded-lg transition-colors" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                ${r.status === 'Pending' ? `
                                    <button onclick="openReviewModal(${r.id})" class="p-2 text-gray-400 hover:text-amber-500 hover:bg-gray-700 rounded-lg transition-colors" title="Review">
                                        <i class="fas fa-clipboard-check"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        // Calendar Functions
        function changeMonth(delta) {
            currentMonth += delta;
            if (currentMonth < 1) {
                currentMonth = 12;
                currentYear--;
            } else if (currentMonth > 12) {
                currentMonth = 1;
                currentYear++;
            }
            
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                               'July', 'August', 'September', 'October', 'November', 'December'];
            document.getElementById('calendarMonthYear').textContent = `${monthNames[currentMonth - 1]} ${currentYear}`;
            
            loadCalendarData();
        }

        function loadCalendarData() {
            fetch('reservations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_calendar_data&year=' + currentYear + '&month=' + currentMonth
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    generateCalendar(data.reservations);
                }
            });
        }

        function generateCalendar(reservations) {
            const firstDay = new Date(currentYear, currentMonth - 1, 1);
            const lastDay = new Date(currentYear, currentMonth, 0);
            
            let calendarHTML = '';
            
            // Empty cells for days before month starts
            for (let i = 0; i < firstDay.getDay(); i++) {
                calendarHTML += '<div class="calendar-day opacity-50"></div>';
            }
            
            // Days of the month
            for (let d = 1; d <= lastDay.getDate(); d++) {
                // Create date in local timezone
                const date = new Date(currentYear, currentMonth - 1, d);
                const dateStr = `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                
                // Find reservations for this date - fix timezone issue
                const dayReservations = reservations.filter(r => {
                    // Parse dates as local time to avoid timezone offset issues
                    const pickupParts = r.pickup_date.split('-');
                    const retParts = r.return_date.split('-');
                    const pickup = new Date(pickupParts[0], pickupParts[1] - 1, pickupParts[2]);
                    const ret = new Date(retParts[0], retParts[1] - 1, retParts[2]);
                    
                    return date >= pickup && date <= ret;
                });
                
                // Group by status
                const pending = dayReservations.filter(r => r.status === 'Pending').length;
                const confirmed = dayReservations.filter(r => r.status === 'Confirmed').length;
                const cancelled = dayReservations.filter(r => r.status === 'Cancelled').length;
                const ongoing = dayReservations.filter(r => r.status === 'Ongoing').length;
                const completed = dayReservations.filter(r => r.status === 'Completed').length;
                
                let dayContent = `<div class="calendar-day-number">${d}</div>`;
                
                // Status indicators - car icons instead of dots
                let carIconsHtml = '';
                
                if (dayReservations.length > 0) {
                    // Show up to 3 car icons
                    const maxCars = Math.min(dayReservations.length, 3);
                    
                    for (let i = 0; i < maxCars; i++) {
                        const r = dayReservations[i];
                        const statusClass = r.status.toLowerCase();
                        carIconsHtml += `<i class="fas fa-car car-icon ${statusClass}" title="${r.first_name} ${r.last_name} - ${r.vehicle_name} (${r.status})"></i>`;
                    }
                    
                    if (carIconsHtml) {
                        dayContent += `<div class="calendar-cars">${carIconsHtml}</div>`;
                    }
                }
                
                // Add hover tooltip with details - use data attributes
                if (dayReservations.length > 0) {
                    // Encode the reservation data as JSON and store in data attribute
                    const reservationsData = encodeURIComponent(JSON.stringify(dayReservations));
                    
                    dayContent = `<div class="calendar-day relative cursor-pointer" 
                        data-reservations="${reservationsData}"
                        onmouseover="showTooltip(event, this)" 
                        onmouseout="hideTooltip()">${dayContent}</div>`;
                } else {
                    dayContent = `<div class="calendar-day">${dayContent}</div>`;
                }
                
                calendarHTML += dayContent;
            }
            
            document.getElementById('calendar').innerHTML = calendarHTML;
        }

        // Tooltip functions - positioned near the specific day
        function showTooltip(event, element) {
            const tooltip = document.getElementById('calendarTooltip');
            
            // Get reservations data from data attribute
            const reservationsData = element.getAttribute('data-reservations');
            const reservations = JSON.parse(decodeURIComponent(reservationsData));
            
            // Build tooltip HTML
            let content = `<div class="tooltip-title">Reservations (${reservations.length})</div>`;
            reservations.forEach(r => {
                content += `
                    <div class="tooltip-item">
                        <span class="tooltip-status ${r.status.toLowerCase()}"></span>
                        <span>${r.first_name} ${r.last_name}</span>
                        <span class="tooltip-vehicle">${r.vehicle_name}</span>
                    </div>
                `;
            });
            
            tooltip.innerHTML = content;
            tooltip.classList.remove('hidden');
            
            // Get the calendar day element that triggered the tooltip
            const target = event.target.closest('.calendar-day');
            if (target) {
                const rect = target.getBoundingClientRect();
                
                // Position tooltip near the day cell
                let x = rect.right + 10;
                let y = rect.top;
                
                if (x + 280 > window.innerWidth) {
                    x = rect.left - 290;
                }
                
                if (y + 200 > window.innerHeight) {
                    y = window.innerHeight - 220;
                }
                
                tooltip.style.left = x + 'px';
                tooltip.style.top = y + 'px';
            } else {
                const x = event.clientX + 15;
                const y = event.clientY + 15;
                tooltip.style.left = x + 'px';
                tooltip.style.top = y + 'px';
            }
        }

        function hideTooltip() {
            document.getElementById('calendarTooltip').classList.add('hidden');
        }

        // Recent Activity
        function loadRecentActivity() {
            fetch('reservations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_recent_activity'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayRecentActivity(data.activities);
                }
            });
        }

        function displayRecentActivity(activities) {
            const container = document.getElementById('recentActivity');
            
            if (activities.length === 0) {
                container.innerHTML = '<p class="text-center text-gray-500 py-4">No recent activity</p>';
                return;
            }
            
            let html = '';
            activities.forEach(activity => {
                const bgClass = activity.status === 'Confirmed' ? 'bg-emerald-500/10 border-emerald-500/20' :
                               activity.status === 'Pending' ? 'bg-amber-500/10 border-amber-500/20' :
                               activity.status === 'Cancelled' ? 'bg-red-500/10 border-red-500/20' :
                               activity.status === 'Ongoing' ? 'bg-purple-500/10 border-purple-500/20' :
                               'bg-blue-500/10 border-blue-500/20';
                
                const icon = activity.status === 'Confirmed' ? 'fa-check-circle' :
                            activity.status === 'Pending' ? 'fa-clock' :
                            activity.status === 'Cancelled' ? 'fa-ban' :
                            activity.status === 'Ongoing' ? 'fa-play-circle' : 'fa-check-double';
                
                const timeAgo = getTimeAgo(new Date(activity.created_at));
                
                html += `
                    <div class="p-3 rounded-lg ${bgClass} border cursor-pointer hover:opacity-80 transition-opacity" onclick="viewReservation(${activity.id})">
                        <div class="flex items-start gap-3">
                            <i class="fas ${icon} text-${activity.status === 'Confirmed' ? 'emerald' : activity.status === 'Pending' ? 'amber' : activity.status === 'Cancelled' ? 'red' : activity.status === 'Ongoing' ? 'purple' : 'blue'}-500 mt-1"></i>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <p class="text-sm font-medium text-white">${activity.first_name} ${activity.last_name}</p>
                                    <span class="text-xs text-gray-500">${timeAgo}</span>
                                </div>
                                <p class="text-xs text-gray-400">${activity.vehicle_name}</p>
                                <p class="text-xs text-gray-500 mt-1">${activity.reservation_number}</p>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        function getTimeAgo(date) {
            const seconds = Math.floor((new Date() - date) / 1000);
            
            let interval = Math.floor(seconds / 31536000);
            if (interval > 1) return interval + ' years ago';
            
            interval = Math.floor(seconds / 2592000);
            if (interval > 1) return interval + ' months ago';
            
            interval = Math.floor(seconds / 86400);
            if (interval > 1) return interval + ' days ago';
            
            interval = Math.floor(seconds / 3600);
            if (interval > 1) return interval + ' hours ago';
            
            interval = Math.floor(seconds / 60);
            if (interval > 1) return interval + ' minutes ago';
            
            return 'just now';
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadCalendarData();
            loadRecentActivity();
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeReservationModal();
                closeViewReservationModal();
                closeReviewModal();
                closeSuccessModal();
                closeConfirmModal();
            }
        });
    </script>
</body>
</html>