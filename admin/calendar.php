<?php
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
            $today = date('Y-m-d');
            
            // Available today
            $available_sql = "SELECT COUNT(*) as count FROM vehicles 
                              WHERE status = 'Available' 
                              AND id NOT IN (
                                  SELECT vehicle_id FROM reservations 
                                  WHERE status IN ('Confirmed', 'Ongoing') 
                                  AND pickup_date <= '$today' 
                                  AND return_date >= '$today'
                              )";
            $available = $conn->query($available_sql)->fetch_assoc()['count'];
            
            // Reservations today
            $reservations_sql = "SELECT COUNT(*) as count FROM reservations 
                                WHERE status IN ('Confirmed', 'Ongoing')
                                AND pickup_date <= '$today' 
                                AND return_date >= '$today'";
            $reservations_today = $conn->query($reservations_sql)->fetch_assoc()['count'];
            
            // Maintenance this week
            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_end = date('Y-m-d', strtotime('sunday this week'));
            $maintenance_sql = "SELECT COUNT(*) as count FROM maintenance_tasks 
                               WHERE due_date BETWEEN '$week_start' AND '$week_end'
                               AND status IN ('Scheduled', 'In Progress')";
            $maintenance_week = $conn->query($maintenance_sql)->fetch_assoc()['count'];
            
            // Peak season load (average occupancy for next 30 days)
            $thirty_days = date('Y-m-d', strtotime('+30 days'));
            $peak_sql = "SELECT 
                (SELECT COUNT(*) FROM vehicles) as total_vehicles,
                COUNT(DISTINCT r.id) as booked_days
                FROM reservations r
                WHERE r.status IN ('Confirmed', 'Ongoing')
                AND r.pickup_date <= '$thirty_days'
                AND r.return_date >= '$today'";
            $peak_result = $conn->query($peak_sql)->fetch_assoc();
            $total_vehicles = $peak_result['total_vehicles'];
            $booked_days = $peak_result['booked_days'];
            $peak_load = $total_vehicles > 0 ? round(($booked_days / ($total_vehicles * 30)) * 100) : 0;
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'available_today' => $available,
                    'reservations_today' => $reservations_today,
                    'maintenance_week' => $maintenance_week,
                    'peak_load' => $peak_load
                ]
            ]);
        }
        
        // Get Calendar Data
        elseif ($_POST['action'] === 'get_calendar_data') {
            $year = (int)$_POST['year'];
            $month = (int)$_POST['month'];
            $view = isset($_POST['view']) ? $_POST['view'] : 'month';
            $vehicle_filter = isset($_POST['vehicle_filter']) ? $_POST['vehicle_filter'] : 'all';
            
            $start_date = "$year-$month-01";
            $end_date = date('Y-m-t', strtotime($start_date));
            
            // Get total vehicles count for occupancy calculation
            $total_vehicles_result = $conn->query("SELECT COUNT(*) as count FROM vehicles");
            $total_vehicles = $total_vehicles_result ? $total_vehicles_result->fetch_assoc()['count'] : 0;
            
            // Build filter conditions
            $vehicle_status_cond = "";
            if ($vehicle_filter === 'available') {
                $vehicle_status_cond = " AND v.status = 'Available'";
            } elseif ($vehicle_filter === 'reserved') {
                $vehicle_status_cond = " AND v.status = 'Rented'";
            } elseif ($vehicle_filter === 'maintenance') {
                $vehicle_status_cond = " AND v.status = 'Maintenance'";
            }
            
            // Get all vehicles
            $vehicles_sql = "SELECT id, vehicle_name, license_plate, status FROM vehicles WHERE 1=1 $vehicle_status_cond ORDER BY vehicle_name";
            $vehicles_result = $conn->query($vehicles_sql);
            $vehicles = [];
            if ($vehicles_result) {
                while($row = $vehicles_result->fetch_assoc()) {
                    $vehicles[] = $row;
                }
            }
            
            // Get reservations for the month
            $reservations_sql = "SELECT r.*, 
                                 c.first_name, c.last_name,
                                 v.vehicle_name, v.license_plate
                                 FROM reservations r
                                 JOIN customers c ON r.customer_id = c.id
                                 JOIN vehicles v ON r.vehicle_id = v.id
                                 WHERE ((r.pickup_date BETWEEN '$start_date' AND '$end_date')
                                 OR (r.return_date BETWEEN '$start_date' AND '$end_date'))
                                 ORDER BY r.pickup_date ASC";
            $reservations_result = $conn->query($reservations_sql);
            $reservations = [];
            if ($reservations_result) {
                while($row = $reservations_result->fetch_assoc()) {
                    $reservations[] = $row;
                }
            }
            
            // Get maintenance tasks for the month
            $maintenance_sql = "SELECT mt.*, v.vehicle_name, v.license_plate
                               FROM maintenance_tasks mt
                               JOIN vehicles v ON mt.vehicle_id = v.id
                               WHERE (mt.due_date BETWEEN '$start_date' AND '$end_date')
                               AND mt.status IN ('Scheduled', 'In Progress')
                               ORDER BY mt.due_date ASC";
            $maintenance_result = $conn->query($maintenance_sql);
            $maintenance = [];
            if ($maintenance_result) {
                while($row = $maintenance_result->fetch_assoc()) {
                    $maintenance[] = $row;
                }
            }
            
            // Calculate daily stats
            $daily_stats = [];
            $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            
            for ($d = 1; $d <= $days_in_month; $d++) {
                $date = sprintf("%04d-%02d-%02d", $year, $month, $d);
                
                // Count available vehicles for this date
                $available_count = 0;
                foreach ($vehicles as $vehicle) {
                    $is_reserved = false;
                    foreach ($reservations as $res) {
                        if ($res['vehicle_id'] == $vehicle['id']) {
                            $pickup = new DateTime($res['pickup_date']);
                            $return = new DateTime($res['return_date']);
                            $current = new DateTime($date);
                            if ($current >= $pickup && $current <= $return) {
                                $is_reserved = true;
                                break;
                            }
                        }
                    }
                    
                    // Check maintenance
                    $in_maintenance = false;
                    foreach ($maintenance as $maint) {
                        if ($maint['vehicle_id'] == $vehicle['id'] && $maint['due_date'] == $date) {
                            $in_maintenance = true;
                            break;
                        }
                    }
                    
                    if (!$is_reserved && !$in_maintenance && $vehicle['status'] == 'Available') {
                        $available_count++;
                    }
                }
                
                // Count reservations for this date
                $reservation_count = 0;
                foreach ($reservations as $res) {
                    $pickup = new DateTime($res['pickup_date']);
                    $return = new DateTime($res['return_date']);
                    $current = new DateTime($date);
                    if ($current >= $pickup && $current <= $return) {
                        $reservation_count++;
                    }
                }
                
                // Count maintenance for this date
                $maintenance_count = 0;
                foreach ($maintenance as $maint) {
                    if ($maint['due_date'] == $date) {
                        $maintenance_count++;
                    }
                }
                
                // Determine status color
                $status = 'available';
                if ($maintenance_count > 0) {
                    $status = 'maintenance';
                } elseif ($reservation_count > 0) {
                    $status = 'reserved';
                }
                
                $daily_stats[$date] = [
                    'date' => $date,
                    'available' => $available_count,
                    'reservations' => $reservation_count,
                    'maintenance' => $maintenance_count,
                    'status' => $status,
                    'occupancy' => $total_vehicles > 0 ? round(($reservation_count / $total_vehicles) * 100) : 0
                ];
            }
            
            echo json_encode([
                'success' => true,
                'year' => $year,
                'month' => $month,
                'vehicles' => $vehicles,
                'reservations' => $reservations,
                'maintenance' => $maintenance,
                'daily_stats' => $daily_stats,
                'days_in_month' => $days_in_month,
                'first_day' => date('w', strtotime($start_date))
            ]);
        }
        
        // Get Vehicle Availability for a specific date
        elseif ($_POST['action'] === 'get_vehicle_availability') {
            $date = $conn->real_escape_string($_POST['date']);
            
            // Get all vehicles with their status for this date
            $sql = "SELECT v.*,
                    CASE 
                        WHEN r.id IS NOT NULL THEN 'Reserved'
                        WHEN mt.id IS NOT NULL THEN 'Maintenance'
                        ELSE 'Available'
                    END as availability_status,
                    r.reservation_number,
                    r.pickup_date,
                    r.return_date,
                    r.pickup_time,
                    r.return_time,
                    c.first_name as customer_first,
                    c.last_name as customer_last,
                    mt.task_name as maintenance_task,
                    mt.priority as maintenance_priority
                    FROM vehicles v
                    LEFT JOIN reservations r ON v.id = r.vehicle_id 
                        AND r.status IN ('Confirmed', 'Ongoing')
                        AND r.pickup_date <= '$date' 
                        AND r.return_date >= '$date'
                    LEFT JOIN customers c ON r.customer_id = c.id
                    LEFT JOIN maintenance_tasks mt ON v.id = mt.vehicle_id 
                        AND mt.due_date = '$date'
                        AND mt.status IN ('Scheduled', 'In Progress')
                    ORDER BY v.vehicle_name";
            
            $result = $conn->query($sql);
            $vehicles = [];
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $vehicles[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'vehicles' => $vehicles, 'date' => $date]);
        }
        
        // Get Upcoming Reservations
        elseif ($_POST['action'] === 'get_upcoming_reservations') {
            $today = date('Y-m-d');
            $next_week = date('Y-m-d', strtotime('+7 days'));
            
            $sql = "SELECT r.*, 
                    c.first_name, c.last_name,
                    v.vehicle_name, v.license_plate
                    FROM reservations r
                    JOIN customers c ON r.customer_id = c.id
                    JOIN vehicles v ON r.vehicle_id = v.id
                    WHERE r.status IN ('Confirmed', 'Ongoing')
                    AND r.pickup_date BETWEEN '$today' AND '$next_week'
                    ORDER BY r.pickup_date ASC
                    LIMIT 10";
            
            $result = $conn->query($sql);
            $reservations = [];
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $reservations[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'reservations' => $reservations]);
        }
        
        // Get Upcoming Maintenance
        elseif ($_POST['action'] === 'get_upcoming_maintenance') {
            $today = date('Y-m-d');
            $next_week = date('Y-m-d', strtotime('+7 days'));
            
            $sql = "SELECT mt.*, v.vehicle_name, v.license_plate
                    FROM maintenance_tasks mt
                    JOIN vehicles v ON mt.vehicle_id = v.id
                    WHERE mt.status IN ('Scheduled', 'In Progress')
                    AND mt.due_date BETWEEN '$today' AND '$next_week'
                    ORDER BY 
                        CASE 
                            WHEN mt.priority = 'Critical' THEN 1
                            WHEN mt.priority = 'High' THEN 2
                            WHEN mt.priority = 'Medium' THEN 3
                            ELSE 4
                        END,
                        mt.due_date ASC";
            
            $result = $conn->query($sql);
            $maintenance = [];
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $maintenance[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'maintenance' => $maintenance]);
        }
        
        // Get Peak Season Data (occupancy trends)
        elseif ($_POST['action'] === 'get_peak_season') {
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime('+30 days'));
            
            $total_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles")->fetch_assoc()['count'];
            
            // Get daily occupancy for next 30 days
            $occupancy = [];
            $current = new DateTime($start_date);
            $end = new DateTime($end_date);
            
            while ($current <= $end) {
                $date = $current->format('Y-m-d');
                
                // Count reservations for this date
                $res_sql = "SELECT COUNT(*) as count FROM reservations 
                           WHERE status IN ('Confirmed', 'Ongoing')
                           AND pickup_date <= '$date' 
                           AND return_date >= '$date'";
                $res_count = $conn->query($res_sql)->fetch_assoc()['count'];
                
                $occupancy_rate = $total_vehicles > 0 ? round(($res_count / $total_vehicles) * 100) : 0;
                
                $occupancy[] = [
                    'date' => $date,
                    'week' => 'Week ' . $current->format('W'),
                    'reservations' => $res_count,
                    'occupancy' => $occupancy_rate
                ];
                
                $current->modify('+1 day');
            }
            
            // Group by week for summary
            $weekly_summary = [];
            foreach ($occupancy as $day) {
                $week = $day['week'];
                if (!isset($weekly_summary[$week])) {
                    $weekly_summary[$week] = [
                        'week' => $week,
                        'total_occupancy' => 0,
                        'days' => 0,
                        'max_occupancy' => 0
                    ];
                }
                $weekly_summary[$week]['total_occupancy'] += $day['occupancy'];
                $weekly_summary[$week]['days']++;
                $weekly_summary[$week]['max_occupancy'] = max($weekly_summary[$week]['max_occupancy'], $day['occupancy']);
            }
            
            // Calculate average
            foreach ($weekly_summary as &$week) {
                $week['avg_occupancy'] = round($week['total_occupancy'] / $week['days']);
            }
            
            echo json_encode([
                'success' => true,
                'occupancy' => $occupancy,
                'weekly_summary' => array_values($weekly_summary)
            ]);
        }
        
        $conn->close();
        exit;
    }
}

// Fetch initial statistics
$conn = getConnection();

$available_today = 0;
$reservations_today = 0;
$maintenance_week = 0;
$peak_load = 0;

if ($conn) {
    $today = date('Y-m-d');
    
    $available = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Available'");
    $available_today = $available ? $available->fetch_assoc()['count'] : 0;
    
    $reservations = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status IN ('Confirmed', 'Ongoing') AND pickup_date <= '$today' AND return_date >= '$today'");
    $reservations_today = $reservations ? $reservations->fetch_assoc()['count'] : 0;
    
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_end = date('Y-m-d', strtotime('sunday this week'));
    $maintenance = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE due_date BETWEEN '$week_start' AND '$week_end' AND status IN ('Scheduled', 'In Progress')");
    $maintenance_week = $maintenance ? $maintenance->fetch_assoc()['count'] : 0;
    
    $thirty_days = date('Y-m-d', strtotime('+30 days'));
    $total_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles")->fetch_assoc()['count'];
    $booked_days = $conn->query("SELECT COUNT(DISTINCT id) as count FROM reservations WHERE status IN ('Confirmed', 'Ongoing') AND pickup_date <= '$thirty_days' AND return_date >= '$today'")->fetch_assoc()['count'];
    $peak_load = $total_vehicles > 0 ? round(($booked_days / ($total_vehicles * 30)) * 100) : 0;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar / Scheduling - Velocity Rentals</title>
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

        /* Calendar cell styles */
        .calendar-day {
            min-height: 80px;
            padding: 0.5rem;
            border-radius: 0.5rem;
            background: #1f2937;
            border: 1px solid #374151;
            transition: all 0.2s;
            cursor: pointer;
            display: flex;
            flex-direction: column;
        }
        
        .calendar-day:hover {
            border-color: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
        }
        
        .calendar-day.available {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.3);
        }
        
        .calendar-day.reserved {
            background: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        .calendar-day.maintenance {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .calendar-day-number {
            font-size: 1rem;
            font-weight: 600;
            color: #e5e7eb;
            margin-bottom: 0.25rem;
        }
        
        .calendar-stats {
            font-size: 0.7rem;
            color: #9ca3af;
            margin-top: auto;
        }
        
        .calendar-stats span {
            display: block;
            margin-top: 0.15rem;
        }

        /* Tooltip */
        .calendar-tooltip {
            position: fixed;
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            border: 1px solid #374151;
            border-radius: 0.75rem;
            padding: 1rem;
            z-index: 100;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.6);
            pointer-events: none;
            min-width: 250px;
            max-width: 300px;
            animation: tooltipFadeIn 0.2s ease-out;
        }
        
        @keyframes tooltipFadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .calendar-tooltip.hidden {
            display: none;
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
                            <h1 class="text-xl font-bold text-white">Calendar / Scheduling</h1>
                            <p class="text-xs text-gray-500">Visual booking, maintenance, and demand planning</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="hidden md:flex items-center bg-gray-800/80 rounded-lg px-3 py-2 border border-gray-600/30">
                        <i class="fas fa-search text-gray-500 text-sm"></i>
                        <input type="text" id="searchInput" placeholder="Search date or vehicle..." class="bg-transparent border-none outline-none text-sm text-gray-300 placeholder-gray-500 ml-2 w-52">
                    </div>
                </div>
            </div>
        </header>

        <div class="p-4 lg:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-emerald-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-600/30 to-emerald-700/10 flex items-center justify-center border border-emerald-500/20">
                            <i class="fas fa-car text-emerald-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Available Today</h3>
                    <p class="text-2xl font-bold text-white" id="availableToday"><?php echo $available_today; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-blue-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-600/30 to-blue-700/10 flex items-center justify-center border border-blue-500/20">
                            <i class="fas fa-calendar-check text-blue-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Reservations Today</h3>
                    <p class="text-2xl font-bold text-white" id="reservationsToday"><?php echo $reservations_today; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-amber-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-600/30 to-amber-700/10 flex items-center justify-center border border-amber-500/20">
                            <i class="fas fa-tools text-amber-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Maintenance This Week</h3>
                    <p class="text-2xl font-bold text-white" id="maintenanceWeek"><?php echo $maintenance_week; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-red-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-600/30 to-red-700/10 flex items-center justify-center border border-red-500/20">
                            <i class="fas fa-chart-line text-red-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Peak Season Load</h3>
                    <p class="text-2xl font-bold text-white" id="peakLoad"><?php echo $peak_load; ?>%</p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <button onclick="loadView('availability')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-car-side text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Vehicle Availability</span>
                </button>
                <button onclick="loadView('reservations')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-blue-500/50 rounded-xl transition-all group">
                    <i class="fas fa-calendar-check text-blue-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Reservation Dates</span>
                </button>
                <button onclick="loadView('maintenance')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-amber-500/50 rounded-xl transition-all group">
                    <i class="fas fa-tools text-amber-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Maintenance Schedule</span>
                </button>
                <button onclick="loadView('peak')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-chart-area text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Peak Season Monitor</span>
                </button>
            </div>

            <!-- Main Calendar View -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
                <div class="xl:col-span-2 card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                        <div class="flex items-center gap-4">
                            <div>
                                <h2 class="text-lg font-semibold text-white">Fleet Calendar</h2>
                                <p class="text-sm text-gray-500" id="calendarMonthYear"><?php echo date('F Y'); ?></p>
                            </div>
                            <div class="flex gap-2">
                                <button onclick="changeMonth(-1)" class="p-2 bg-gray-800 rounded-lg hover:bg-gray-700 transition-colors">
                                    <i class="fas fa-chevron-left text-gray-400"></i>
                                </button>
                                <button onclick="changeMonth(1)" class="p-2 bg-gray-800 rounded-lg hover:bg-gray-700 transition-colors">
                                    <i class="fas fa-chevron-right text-gray-400"></i>
                                </button>
                                <button onclick="goToToday()" class="px-3 py-2 bg-gray-800 text-sm text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">
                                    Today
                                </button>
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
                        <div class="flex flex-wrap gap-4 mt-5 text-xs">
                            <span class="flex items-center gap-2 text-gray-400"><span class="w-3 h-3 rounded bg-emerald-500/30 border border-emerald-500/40"></span>Available</span>
                            <span class="flex items-center gap-2 text-gray-400"><span class="w-3 h-3 rounded bg-blue-500/30 border border-blue-500/40"></span>Reserved</span>
                            <span class="flex items-center gap-2 text-gray-400"><span class="w-3 h-3 rounded bg-red-500/30 border border-red-500/40"></span>Maintenance</span>
                        </div>
                    </div>
                </div>

                <!-- Right Panel - Changes based on view -->
                <div id="rightPanel" class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white" id="panelTitle">Upcoming Reservations</h2>
                        <p class="text-sm text-gray-500" id="panelSubtitle">Next 7 days</p>
                    </div>
                    <div id="panelContent" class="p-4 space-y-3 max-h-96 overflow-y-auto">
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Sections -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Maintenance Schedule -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Maintenance Schedule</h2>
                        <p class="text-sm text-gray-500">Service windows in calendar context</p>
                    </div>
                    <div id="maintenanceList" class="p-4 space-y-3 max-h-80 overflow-y-auto">
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>

                <!-- Peak Season Monitoring -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Peak Season Monitoring</h2>
                        <p class="text-sm text-gray-500">Demand pressure and occupancy trend</p>
                    </div>
                    <div id="peakList" class="p-4 space-y-3 max-h-80 overflow-y-auto">
                        <div class="flex justify-center py-4">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Day Details Modal -->
    <div id="dayModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeDayModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-2xl max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700 sticky top-0 bg-gray-900">
                    <h2 class="text-xl font-semibold text-white" id="dayModalTitle">March 15, 2026</h2>
                    <button onclick="closeDayModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="dayModalContent" class="p-5">
                    <!-- Content will be loaded dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Tooltip -->
    <div id="calendarTooltip" class="calendar-tooltip hidden"></div>

    <script>
        // Global variables
        let currentYear = <?php echo date('Y'); ?>;
        let currentMonth = <?php echo date('n'); ?>;
        let currentView = 'availability';
        let calendarData = null;
        
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

        // Load initial data
        document.addEventListener('DOMContentLoaded', function() {
            loadCalendarData();
            loadUpcomingReservations();
            loadMaintenanceList();
            loadPeakData();
        });

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
            
            updateMonthDisplay();
            loadCalendarData();
        }

        function goToToday() {
            currentYear = <?php echo date('Y'); ?>;
            currentMonth = <?php echo date('n'); ?>;
            updateMonthDisplay();
            loadCalendarData();
        }

        function updateMonthDisplay() {
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                               'July', 'August', 'September', 'October', 'November', 'December'];
            document.getElementById('calendarMonthYear').textContent = `${monthNames[currentMonth - 1]} ${currentYear}`;
        }

        function loadCalendarData() {
            // Show loading state
            const calendar = document.getElementById('calendar');
            calendar.innerHTML = '<div class="col-span-7 text-center py-8"><div class="spinner"></div><p class="mt-2 text-gray-400">Loading calendar...</p></div>';
            
            const vehicleFilter = document.getElementById('vehicleFilter')?.value || 'all';
            
            fetch('calendar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_calendar_data&year=' + currentYear + '&month=' + currentMonth + '&vehicle_filter=' + vehicleFilter
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    calendarData = data;
                    generateCalendar(data);
                } else {
                    calendar.innerHTML = '<div class="col-span-7 text-center py-8"><p class="text-red-400">Error loading calendar data</p></div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                calendar.innerHTML = '<div class="col-span-7 text-center py-8"><p class="text-red-400">Failed to load calendar</p></div>';
            });
        }
        
        function applyFilters() {
            loadCalendarData();
        }

        function generateCalendar(data) {
            const calendar = document.getElementById('calendar');
            const daysInMonth = data.days_in_month;
            const firstDay = data.first_day; // 0 = Sunday, 1 = Monday, etc.
            
            let html = '';
            
            // Empty cells for days before month starts
            for (let i = 0; i < firstDay; i++) {
                html += '<div class="calendar-day opacity-50"></div>';
            }
            
            // Days of the month
            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr = `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const dayData = data.daily_stats[dateStr];
                
                if (dayData) {
                    const statusClass = dayData.status === 'available' ? 'available' :
                                       dayData.status === 'reserved' ? 'reserved' : 'maintenance';
                    
                    html += `
                        <div class="calendar-day ${statusClass}" onclick="showDayDetails('${dateStr}')" 
                             onmouseover="showDayTooltip(event, '${dateStr}', ${dayData.available}, ${dayData.reservations}, ${dayData.maintenance})" 
                             onmouseout="hideTooltip()">
                            <div class="calendar-day-number">${d}</div>
                            <div class="calendar-stats">
                                <span class="text-emerald-500">${dayData.available} available</span>
                                <span class="text-blue-500">${dayData.reservations} reserved</span>
                                <span class="text-red-500">${dayData.maintenance} maintenance</span>
                            </div>
                        </div>
                    `;
                } else {
                    html += `<div class="calendar-day"><div class="calendar-day-number">${d}</div></div>`;
                }
            }
            
            calendar.innerHTML = html;
        }

        // Tooltip functions
        function showDayTooltip(event, date, available, reservations, maintenance) {
            const tooltip = document.getElementById('calendarTooltip');
            
            const formattedDate = new Date(date).toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            tooltip.innerHTML = `
                <div class="tooltip-title">${formattedDate}</div>
                <div class="space-y-1 mt-2">
                    <div class="flex justify-between">
                        <span class="text-emerald-500">Available:</span>
                        <span class="text-white">${available} vehicles</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-blue-500">Reserved:</span>
                        <span class="text-white">${reservations} vehicles</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-red-500">Maintenance:</span>
                        <span class="text-white">${maintenance} vehicles</span>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Click to view details</p>
            `;
            
            tooltip.classList.remove('hidden');
            
            const x = event.clientX + 15;
            const y = event.clientY + 15;
            
            tooltip.style.left = x + 'px';
            tooltip.style.top = y + 'px';
        }

        function hideTooltip() {
            document.getElementById('calendarTooltip').classList.add('hidden');
        }

        // Day Details Modal
        function showDayDetails(date) {
            document.getElementById('dayModalTitle').textContent = new Date(date).toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            fetch('calendar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_vehicle_availability&date=' + date
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayDayDetails(data.vehicles);
                }
            });
            
            document.getElementById('dayModal').classList.remove('hidden');
        }

        function displayDayDetails(vehicles) {
            const content = document.getElementById('dayModalContent');
            
            let html = '<div class="space-y-3">';
            
            vehicles.forEach(v => {
                const statusColor = v.availability_status === 'Available' ? 'text-emerald-500' :
                                   v.availability_status === 'Reserved' ? 'text-blue-500' : 'text-red-500';
                
                html += `
                    <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-white font-medium">${v.vehicle_name}</p>
                                <p class="text-xs text-gray-400">${v.license_plate}</p>
                            </div>
                            <span class="text-sm font-medium ${statusColor}">${v.availability_status}</span>
                        </div>
                `;
                
                if (v.availability_status === 'Reserved') {
                    html += `
                        <div class="mt-2 text-sm">
                            <p class="text-xs text-gray-400">Reservation: ${v.reservation_number}</p>
                            <p class="text-xs text-gray-400">Customer: ${v.customer_first} ${v.customer_last}</p>
                            <p class="text-xs text-gray-400">${v.pickup_time} - ${v.return_time}</p>
                        </div>
                    `;
                } else if (v.availability_status === 'Maintenance') {
                    html += `
                        <div class="mt-2 text-sm">
                            <p class="text-xs text-gray-400">Task: ${v.maintenance_task}</p>
                            <p class="text-xs text-${v.maintenance_priority === 'Critical' ? 'red' : v.maintenance_priority === 'High' ? 'orange' : 'amber'}-500">Priority: ${v.maintenance_priority}</p>
                        </div>
                    `;
                }
                
                html += '</div>';
            });
            
            html += '</div>';
            content.innerHTML = html;
        }

        function closeDayModal() {
            document.getElementById('dayModal').classList.add('hidden');
        }

        // Right Panel Functions
        function loadView(view) {
            currentView = view;
            
            const panelTitle = document.getElementById('panelTitle');
            const panelSubtitle = document.getElementById('panelSubtitle');
            const panelContent = document.getElementById('panelContent');
            
            panelContent.innerHTML = '<div class="flex justify-center py-4"><div class="spinner"></div></div>';
            
            if (view === 'availability') {
                panelTitle.textContent = 'Vehicle Availability';
                panelSubtitle.textContent = 'Quick stats by status';
                loadAvailabilityStats();
            } else if (view === 'reservations') {
                panelTitle.textContent = 'Upcoming Reservations';
                panelSubtitle.textContent = 'Next 7 days';
                loadUpcomingReservations();
            } else if (view === 'maintenance') {
                panelTitle.textContent = 'Maintenance Schedule';
                panelSubtitle.textContent = 'Upcoming tasks';
                loadMaintenanceList();
            } else if (view === 'peak') {
                panelTitle.textContent = 'Peak Season Monitor';
                panelSubtitle.textContent = '30-day outlook';
                loadPeakData();
            }
        }

        function loadAvailabilityStats() {
            // Use calendar data to show stats
            if (!calendarData) {
                setTimeout(loadAvailabilityStats, 500);
                return;
            }
            
            const stats = calendarData.daily_stats;
            const today = new Date().toISOString().split('T')[0];
            const todayStats = stats[today] || { available: 0, reservations: 0, maintenance: 0 };
            
            // Calculate averages
            let totalAvailable = 0;
            let totalReserved = 0;
            let totalMaintenance = 0;
            let days = 0;
            
            for (let date in stats) {
                totalAvailable += stats[date].available;
                totalReserved += stats[date].reservations;
                totalMaintenance += stats[date].maintenance;
                days++;
            }
            
            const avgAvailable = days > 0 ? Math.round(totalAvailable / days) : 0;
            const avgReserved = days > 0 ? Math.round(totalReserved / days) : 0;
            const avgMaintenance = days > 0 ? Math.round(totalMaintenance / days) : 0;
            
            const panelContent = document.getElementById('panelContent');
            panelContent.innerHTML = `
                <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                    <p class="text-sm font-medium text-white">Today's Snapshot</p>
                    <div class="mt-2 space-y-2">
                        <div class="flex justify-between">
                            <span class="text-xs text-gray-400">Available:</span>
                            <span class="text-xs text-emerald-500">${todayStats.available}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-xs text-gray-400">Reserved:</span>
                            <span class="text-xs text-blue-500">${todayStats.reservations}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-xs text-gray-400">Maintenance:</span>
                            <span class="text-xs text-red-500">${todayStats.maintenance}</span>
                        </div>
                    </div>
                </div>
                
                <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30 mt-3">
                    <p class="text-sm font-medium text-white">Monthly Averages</p>
                    <div class="mt-2 space-y-2">
                        <div class="flex justify-between">
                            <span class="text-xs text-gray-400">Available:</span>
                            <span class="text-xs text-emerald-500">${avgAvailable}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-xs text-gray-400">Reserved:</span>
                            <span class="text-xs text-blue-500">${avgReserved}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-xs text-gray-400">Maintenance:</span>
                            <span class="text-xs text-red-500">${avgMaintenance}</span>
                        </div>
                    </div>
                </div>
                
                <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30 mt-3">
                    <p class="text-sm font-medium text-white">Utilization</p>
                    <div class="mt-2">
                        <div class="flex justify-between mb-1">
                            <span class="text-xs text-gray-400">Peak occupancy:</span>
                            <span class="text-xs text-red-500">${document.getElementById('peakLoad').textContent}</span>
                        </div>
                        <div class="w-full bg-gray-700 rounded-full h-2">
                            <div class="bg-red-600 h-2 rounded-full" style="width: ${document.getElementById('peakLoad').textContent}"></div>
                        </div>
                    </div>
                </div>
            `;
        }

        function loadUpcomingReservations() {
            fetch('calendar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_upcoming_reservations'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayUpcomingReservations(data.reservations);
                }
            });
        }

        function displayUpcomingReservations(reservations) {
            const panelContent = document.getElementById('panelContent');
            
            if (reservations.length === 0) {
                panelContent.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No upcoming reservations</p>';
                return;
            }
            
            let html = '';
            reservations.forEach(r => {
                const pickup = new Date(r.pickup_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                const ret = new Date(r.return_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                
                html += `
                    <div class="p-3 rounded-lg bg-blue-500/10 border border-blue-500/20">
                        <p class="text-sm font-medium text-white">${r.reservation_number}</p>
                        <p class="text-xs text-gray-400">${r.first_name} ${r.last_name}</p>
                        <p class="text-xs text-gray-400">${r.vehicle_name}</p>
                        <p class="text-xs text-gray-500 mt-1">${pickup} - ${ret}</p>
                    </div>
                `;
            });
            
            panelContent.innerHTML = html;
        }

        function loadMaintenanceList() {
            fetch('calendar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_upcoming_maintenance'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayMaintenanceList(data.maintenance);
                }
            });
        }

        function displayMaintenanceList(maintenance) {
            const container = document.getElementById('maintenanceList');
            
            if (maintenance.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No upcoming maintenance</p>';
                return;
            }
            
            let html = '';
            maintenance.forEach(m => {
                const priorityClass = m.priority === 'Critical' ? 'bg-red-500/10 border-red-500/20' :
                                     m.priority === 'High' ? 'bg-orange-500/10 border-orange-500/20' :
                                     m.priority === 'Medium' ? 'bg-amber-500/10 border-amber-500/20' :
                                     'bg-emerald-500/10 border-emerald-500/20';
                
                const dueDate = new Date(m.due_date).toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric',
                    year: 'numeric'
                });
                
                html += `
                    <div class="p-3 rounded-lg ${priorityClass} border">
                        <p class="text-sm font-medium text-white">${m.vehicle_name}</p>
                        <p class="text-xs text-gray-400">${m.task_name}</p>
                        <p class="text-xs text-gray-500 mt-1">Due: ${dueDate}</p>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        function loadPeakData() {
            fetch('calendar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_peak_season'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayPeakData(data);
                }
            });
        }

        function displayPeakData(data) {
            const panelContent = document.getElementById('panelContent');
            const peakList = document.getElementById('peakList');
            
            // Update right panel
            panelContent.innerHTML = `
                <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                    <p class="text-sm font-medium text-white">30-Day Outlook</p>
                    <p class="text-xs text-gray-400 mt-1">Average occupancy: ${Math.round(data.occupancy.reduce((a, b) => a + b.occupancy, 0) / data.occupancy.length)}%</p>
                </div>
            `;
            
            // Update peak list
            let html = '';
            data.weekly_summary.forEach(week => {
                const statusClass = week.avg_occupancy >= 80 ? 'text-red-500' :
                                   week.avg_occupancy >= 60 ? 'text-amber-500' : 'text-emerald-500';
                
                html += `
                    <div class="flex items-center justify-between p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                        <span class="text-sm text-gray-300">${week.week}</span>
                        <span class="text-sm font-medium ${statusClass}">${week.avg_occupancy}% occupancy</span>
                    </div>
                `;
            });
            
            peakList.innerHTML = html;
        }

        // Search function
        function searchCalendar() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            // Implement search if needed
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDayModal();
            }
        });
    </script>
</body>
</html>