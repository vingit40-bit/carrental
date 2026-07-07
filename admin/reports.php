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
            // Current month revenue
            $month_start = date('Y-m-01');
            $month_end = date('Y-m-t');
            
            $revenue_sql = "SELECT COALESCE(SUM(amount), 0) as total 
                           FROM payments 
                           WHERE DATE(payment_date) BETWEEN '$month_start' AND '$month_end' 
                           AND status = 'Completed'";
            $revenue_result = $conn->query($revenue_sql);
            $revenue = $revenue_result->fetch_assoc()['total'];
            
            // Previous month revenue for growth calculation
            $prev_month_start = date('Y-m-01', strtotime('first day of last month'));
            $prev_month_end = date('Y-m-t', strtotime('last day of last month'));
            $prev_revenue_sql = "SELECT COALESCE(SUM(amount), 0) as total 
                                FROM payments 
                                WHERE DATE(payment_date) BETWEEN '$prev_month_start' AND '$prev_month_end' 
                                AND status = 'Completed'";
            $prev_revenue_result = $conn->query($prev_revenue_sql);
            $prev_revenue = $prev_revenue_result->fetch_assoc()['total'];
            
            // Utilization rate (vehicles rented vs total vehicles)
            $total_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles")->fetch_assoc()['count'];
            $rented_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Rented'")->fetch_assoc()['count'];
            $utilization = $total_vehicles > 0 ? round(($rented_vehicles / $total_vehicles) * 100) : 0;
            
            // Current month bookings
            $bookings_sql = "SELECT COUNT(*) as count 
                            FROM reservations 
                            WHERE DATE(created_at) BETWEEN '$month_start' AND '$month_end'";
            $bookings_result = $conn->query($bookings_sql);
            $bookings = $bookings_result->fetch_assoc()['count'];
            
            // Current month maintenance cost
            $maintenance_sql = "SELECT COALESCE(SUM(estimated_cost), 0) as total 
                               FROM maintenance_tasks 
                               WHERE status = 'Completed' 
                               AND DATE(completion_date) BETWEEN '$month_start' AND '$month_end'";
            $maintenance_result = $conn->query($maintenance_sql);
            $maintenance = $maintenance_result->fetch_assoc()['total'];
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'revenue' => $revenue,
                    'prev_revenue' => $prev_revenue,
                    'utilization' => $utilization,
                    'bookings' => $bookings,
                    'maintenance' => $maintenance
                ]
            ]);
        }
        
        // Get Revenue Report Data
        elseif ($_POST['action'] === 'get_revenue_report') {
            $period = isset($_POST['period']) ? $_POST['period'] : 'month';
            
            $data = [];
            $labels = [];
            
            if ($period === 'week') {
                // Last 7 days
                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $labels[] = date('D', strtotime($date));
                    
                    $sql = "SELECT COALESCE(SUM(amount), 0) as total 
                           FROM payments 
                           WHERE DATE(payment_date) = '$date' 
                           AND status = 'Completed'";
                    $result = $conn->query($sql);
                    $data[] = (float)$result->fetch_assoc()['total'];
                }
            } elseif ($period === 'month') {
                // Last 4 weeks
                for ($i = 3; $i >= 0; $i--) {
                    $week_start = date('Y-m-d', strtotime("-$i week monday"));
                    $week_end = date('Y-m-d', strtotime("-$i week sunday"));
                    $labels[] = "Week " . (4 - $i);
                    
                    $sql = "SELECT COALESCE(SUM(amount), 0) as total 
                           FROM payments 
                           WHERE DATE(payment_date) BETWEEN '$week_start' AND '$week_end' 
                           AND status = 'Completed'";
                    $result = $conn->query($sql);
                    $data[] = (float)$result->fetch_assoc()['total'];
                }
            } elseif ($period === 'quarter') {
                // Last 3 months
                for ($i = 2; $i >= 0; $i--) {
                    $month = date('F', strtotime("-$i month"));
                    $labels[] = $month;
                    
                    $month_start = date('Y-m-01', strtotime("-$i month"));
                    $month_end = date('Y-m-t', strtotime("-$i month"));
                    
                    $sql = "SELECT COALESCE(SUM(amount), 0) as total 
                           FROM payments 
                           WHERE DATE(payment_date) BETWEEN '$month_start' AND '$month_end' 
                           AND status = 'Completed'";
                    $result = $conn->query($sql);
                    $data[] = (float)$result->fetch_assoc()['total'];
                }
            } elseif ($period === 'year') {
                // Last 12 months
                for ($i = 11; $i >= 0; $i--) {
                    $month = date('M', strtotime("-$i month"));
                    $labels[] = $month;
                    
                    $month_start = date('Y-m-01', strtotime("-$i month"));
                    $month_end = date('Y-m-t', strtotime("-$i month"));
                    
                    $sql = "SELECT COALESCE(SUM(amount), 0) as total 
                           FROM payments 
                           WHERE DATE(payment_date) BETWEEN '$month_start' AND '$month_end' 
                           AND status = 'Completed'";
                    $result = $conn->query($sql);
                    $data[] = (float)$result->fetch_assoc()['total'];
                }
            }
            
            // Get summary stats
            $month_start = date('Y-m-01');
            $month_end = date('Y-m-t');
            
            $total_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Completed'")->fetch_assoc()['total'];
            
            $avg_booking_sql = "SELECT AVG(amount) as avg 
                               FROM payments 
                               WHERE status = 'Completed' 
                               AND DATE(payment_date) BETWEEN '$month_start' AND '$month_end'";
            $avg_booking = $conn->query($avg_booking_sql)->fetch_assoc()['avg'] ?? 0;
            
            $growth_sql = "SELECT 
                (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(payment_date) BETWEEN '$month_start' AND '$month_end' AND status = 'Completed') as current,
                (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(payment_date) BETWEEN DATE_SUB('$month_start', INTERVAL 1 MONTH) AND LAST_DAY(DATE_SUB('$month_start', INTERVAL 1 MONTH)) AND status = 'Completed') as previous";
            $growth_result = $conn->query($growth_sql)->fetch_assoc();
            $growth = $growth_result['previous'] > 0 ? round((($growth_result['current'] - $growth_result['previous']) / $growth_result['previous']) * 100) : 0;
            
            echo json_encode([
                'success' => true,
                'labels' => $labels,
                'data' => $data,
                'summary' => [
                    'total_revenue' => $total_revenue,
                    'avg_booking' => $avg_booking,
                    'growth' => $growth
                ]
            ]);
        }
        
        // Get Booking Report Data
        elseif ($_POST['action'] === 'get_booking_report') {
            $period = isset($_POST['period']) ? $_POST['period'] : 'month';
            
            $month_start = date('Y-m-01');
            $month_end = date('Y-m-t');
            
            // Total bookings
            $total_bookings = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE DATE(created_at) BETWEEN '$month_start' AND '$month_end'")->fetch_assoc()['count'];
            
            // Cancelled bookings
            $cancelled = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Cancelled' AND DATE(created_at) BETWEEN '$month_start' AND '$month_end'")->fetch_assoc()['count'];
            
            // No-show (pending that passed pickup date)
            $no_show = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'Pending' AND pickup_date < CURDATE()")->fetch_assoc()['count'];
            
            // Bookings by status
            $status_sql = "SELECT status, COUNT(*) as count 
                          FROM reservations 
                          WHERE DATE(created_at) BETWEEN '$month_start' AND '$month_end'
                          GROUP BY status";
            $status_result = $conn->query($status_sql);
            $status_counts = [];
            while($row = $status_result->fetch_assoc()) {
                $status_counts[$row['status']] = $row['count'];
            }
            
            echo json_encode([
                'success' => true,
                'summary' => [
                    'total' => $total_bookings,
                    'cancelled' => $cancelled,
                    'no_show' => $no_show,
                    'confirmed' => $status_counts['Confirmed'] ?? 0,
                    'pending' => $status_counts['Pending'] ?? 0,
                    'completed' => $status_counts['Completed'] ?? 0,
                    'ongoing' => $status_counts['Ongoing'] ?? 0
                ]
            ]);
        }
        
        // Get Customer Report Data
        elseif ($_POST['action'] === 'get_customer_report') {
            $month_start = date('Y-m-01');
            $month_end = date('Y-m-t');
            
            // New customers this month
            $new_customers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE DATE(created_at) BETWEEN '$month_start' AND '$month_end'")->fetch_assoc()['count'];
            
            // Total customers
            $total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'];
            
            // Verified customers
            $verified = $conn->query("SELECT COUNT(*) as count FROM customers WHERE verification_status = 'Verified'")->fetch_assoc()['count'];
            
            // Blacklisted
            $blacklisted = $conn->query("SELECT COUNT(*) as count FROM customers WHERE verification_status = 'Blacklisted'")->fetch_assoc()['count'];
            
            // Returning customers (have more than 1 reservation)
            $returning = $conn->query("SELECT COUNT(DISTINCT customer_id) as count FROM reservations GROUP BY customer_id HAVING COUNT(*) > 1")->num_rows;
            
            // Top customers by revenue
            $top_sql = "SELECT c.first_name, c.last_name, COALESCE(SUM(p.amount), 0) as total_spent
                       FROM customers c
                       LEFT JOIN payments p ON c.id = p.customer_id AND p.status = 'Completed'
                       GROUP BY c.id
                       ORDER BY total_spent DESC
                       LIMIT 5";
            $top_result = $conn->query($top_sql);
            $top_customers = [];
            while($row = $top_result->fetch_assoc()) {
                $top_customers[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'summary' => [
                    'new' => $new_customers,
                    'total' => $total_customers,
                    'verified' => $verified,
                    'blacklisted' => $blacklisted,
                    'returning' => $returning
                ],
                'top_customers' => $top_customers
            ]);
        }
        
        // Get Maintenance Report Data
        elseif ($_POST['action'] === 'get_maintenance_report') {
            $month_start = date('Y-m-01');
            $month_end = date('Y-m-t');
            
            // Services completed this month
            $services_done = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE status = 'Completed' AND DATE(completion_date) BETWEEN '$month_start' AND '$month_end'")->fetch_assoc()['count'];
            
            // Open repairs (pending tasks)
            $open_repairs = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE status IN ('Scheduled', 'In Progress')")->fetch_assoc()['count'];
            
            // Average cost per vehicle
            $avg_cost_sql = "SELECT AVG(estimated_cost) as avg 
                            FROM maintenance_tasks 
                            WHERE status = 'Completed' 
                            AND DATE(completion_date) BETWEEN '$month_start' AND '$month_end'";
            $avg_cost = $conn->query($avg_cost_sql)->fetch_assoc()['avg'] ?? 0;
            
            // Total maintenance cost
            $total_cost = $conn->query("SELECT COALESCE(SUM(estimated_cost), 0) as total FROM maintenance_tasks WHERE status = 'Completed'")->fetch_assoc()['total'];
            
            // Tasks by priority
            $priority_sql = "SELECT priority, COUNT(*) as count 
                            FROM maintenance_tasks 
                            WHERE status IN ('Scheduled', 'In Progress')
                            GROUP BY priority";
            $priority_result = $conn->query($priority_sql);
            $priority_counts = [];
            while($row = $priority_result->fetch_assoc()) {
                $priority_counts[$row['priority']] = $row['count'];
            }
            
            echo json_encode([
                'success' => true,
                'summary' => [
                    'services_done' => $services_done,
                    'open_repairs' => $open_repairs,
                    'avg_cost' => $avg_cost,
                    'total_cost' => $total_cost,
                    'critical' => $priority_counts['Critical'] ?? 0,
                    'high' => $priority_counts['High'] ?? 0,
                    'medium' => $priority_counts['Medium'] ?? 0,
                    'low' => $priority_counts['Low'] ?? 0
                ]
            ]);
        }
        
        // Get Vehicle Report Data
        elseif ($_POST['action'] === 'get_vehicle_report') {
            // Vehicle status distribution
            $status_sql = "SELECT status, COUNT(*) as count FROM vehicles GROUP BY status";
            $status_result = $conn->query($status_sql);
            $status_counts = [];
            while($row = $status_result->fetch_assoc()) {
                $status_counts[$row['status']] = $row['count'];
            }
            
            // Most rented vehicles
            $popular_sql = "SELECT v.vehicle_name, v.license_plate, COUNT(r.id) as rental_count
                           FROM vehicles v
                           LEFT JOIN reservations r ON v.id = r.vehicle_id AND r.status IN ('Completed', 'Ongoing')
                           GROUP BY v.id
                           ORDER BY rental_count DESC
                           LIMIT 5";
            $popular_result = $conn->query($popular_sql);
            $popular_vehicles = [];
            while($row = $popular_result->fetch_assoc()) {
                $popular_vehicles[] = $row;
            }
            
            // Revenue by vehicle
            $revenue_sql = "SELECT v.vehicle_name, COALESCE(SUM(r.total_amount), 0) as revenue
                           FROM vehicles v
                           LEFT JOIN reservations r ON v.id = r.vehicle_id AND r.status IN ('Completed', 'Ongoing')
                           GROUP BY v.id
                           ORDER BY revenue DESC
                           LIMIT 5";
            $revenue_result = $conn->query($revenue_sql);
            $revenue_vehicles = [];
            while($row = $revenue_result->fetch_assoc()) {
                $revenue_vehicles[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'status' => $status_counts,
                'popular' => $popular_vehicles,
                'revenue' => $revenue_vehicles
            ]);
        }
        
        // Generate Report (Export)
        elseif ($_POST['action'] === 'generate_report') {
            $report_type = $conn->real_escape_string($_POST['report_type']);
            $format = $conn->real_escape_string($_POST['format']);
            $date_range = $conn->real_escape_string($_POST['date_range']);
            
            // Parse date range
            switch($date_range) {
                case 'today':
                    $start_date = date('Y-m-d');
                    $end_date = date('Y-m-d');
                    break;
                case 'week':
                    $start_date = date('Y-m-d', strtotime('monday this week'));
                    $end_date = date('Y-m-d', strtotime('sunday this week'));
                    break;
                case 'month':
                    $start_date = date('Y-m-01');
                    $end_date = date('Y-m-t');
                    break;
                case 'quarter':
                    $start_date = date('Y-m-01', strtotime('-3 months'));
                    $end_date = date('Y-m-t');
                    break;
                case 'year':
                    $start_date = date('Y-01-01');
                    $end_date = date('Y-12-31');
                    break;
                default:
                    $start_date = date('Y-m-01');
                    $end_date = date('Y-m-t');
            }
            
            // Generate filename
            $filename = $report_type . '_Report_' . date('Y-m-d') . '.' . ($format === 'pdf' ? 'pdf' : 'xlsx');
            
            // For now, just return success with filename
            // In production, you would actually generate the file here
            
            echo json_encode([
                'success' => true,
                'message' => 'Report generated successfully',
                'filename' => $filename,
                'download_url' => 'downloads/' . $filename
            ]);
        }
        
        $conn->close();
        exit;
    }
}

// Fetch initial statistics
$conn = getConnection();

$revenue = 0;
$prev_revenue = 0;
$utilization = 0;
$bookings = 0;
$maintenance = 0;

if ($conn) {
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    
    $revenue_result = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) BETWEEN '$month_start' AND '$month_end' AND status = 'Completed'");
    $revenue = $revenue_result->fetch_assoc()['total'];
    
    $prev_month_start = date('Y-m-01', strtotime('first day of last month'));
    $prev_month_end = date('Y-m-t', strtotime('last day of last month'));
    $prev_revenue_result = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) BETWEEN '$prev_month_start' AND '$prev_month_end' AND status = 'Completed'");
    $prev_revenue = $prev_revenue_result->fetch_assoc()['total'];
    
    $total_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles")->fetch_assoc()['count'];
    $rented_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Rented'")->fetch_assoc()['count'];
    $utilization = $total_vehicles > 0 ? round(($rented_vehicles / $total_vehicles) * 100) : 0;
    
    $bookings_result = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE DATE(created_at) BETWEEN '$month_start' AND '$month_end'");
    $bookings = $bookings_result->fetch_assoc()['count'];
    
    $maintenance_result = $conn->query("SELECT COALESCE(SUM(estimated_cost), 0) as total FROM maintenance_tasks WHERE status = 'Completed' AND DATE(completion_date) BETWEEN '$month_start' AND '$month_end'");
    $maintenance = $maintenance_result->fetch_assoc()['total'];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Velocity Rentals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js for graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
                            <h1 class="text-xl font-bold text-white">Reports & Analytics</h1>
                            <p class="text-xs text-gray-500">System-wide operational and financial reporting</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="hidden md:flex items-center bg-gray-800/80 rounded-lg px-3 py-2 border border-gray-600/30">
                        <i class="fas fa-search text-gray-500 text-sm"></i>
                        <input type="text" id="searchInput" placeholder="Search report..." class="bg-transparent border-none outline-none text-sm text-gray-300 placeholder-gray-500 ml-2 w-52">
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
                            <i class="fas fa-chart-line text-emerald-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Revenue (Month)</h3>
                    <p class="text-2xl font-bold text-white" id="revenueStat">₱<?php echo number_format($revenue, 2); ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-blue-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-600/30 to-blue-700/10 flex items-center justify-center border border-blue-500/20">
                            <i class="fas fa-chart-pie text-blue-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Utilization Rate</h3>
                    <p class="text-2xl font-bold text-white" id="utilizationStat"><?php echo $utilization; ?>%</p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-amber-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-600/30 to-amber-700/10 flex items-center justify-center border border-amber-500/20">
                            <i class="fas fa-calendar-check text-amber-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Bookings (Month)</h3>
                    <p class="text-2xl font-bold text-white" id="bookingsStat"><?php echo $bookings; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-red-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-600/30 to-red-700/10 flex items-center justify-center border border-red-500/20">
                            <i class="fas fa-tools text-red-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Maintenance Cost</h3>
                    <p class="text-2xl font-bold text-white" id="maintenanceStat">₱<?php echo number_format($maintenance, 2); ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
                <button onclick="loadRevenueReport('month')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group active-report" data-report="revenue">
                    <i class="fas fa-chart-line text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Revenue Reports</span>
                </button>
                <button onclick="loadBookingReport()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group" data-report="bookings">
                    <i class="fas fa-calendar-check text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Booking Reports</span>
                </button>
                <button onclick="loadCustomerReport()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group" data-report="customers">
                    <i class="fas fa-users text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Customer Reports</span>
                </button>
                <button onclick="loadMaintenanceReport()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group" data-report="maintenance">
                    <i class="fas fa-tools text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Maintenance Reports</span>
                </button>
            </div>

            <!-- Revenue Report Section (Default) -->
            <div id="revenueReport" class="report-section">
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
                    <!-- Revenue Reports -->
                    <div class="xl:col-span-2 card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                        <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                            <div>
                                <h2 class="text-lg font-semibold text-white">Revenue Reports</h2>
                                <p class="text-sm text-gray-500">Monthly revenue and trend overview</p>
                            </div>
                            <select id="revenuePeriod" onchange="loadRevenueReport(this.value)" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                                <option value="week">This Week</option>
                                <option value="month" selected>This Month</option>
                                <option value="quarter">Last 3 Months</option>
                                <option value="year">This Year</option>
                            </select>
                        </div>
                        <div class="p-5">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
                                <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                                    <p class="text-xs text-gray-500">Total Revenue</p>
                                    <p class="text-lg font-semibold text-white" id="totalRevenue">₱0.00</p>
                                </div>
                                <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                                    <p class="text-xs text-gray-500">Avg / Booking</p>
                                    <p class="text-lg font-semibold text-white" id="avgBooking">₱0.00</p>
                                </div>
                                <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                                    <p class="text-xs text-gray-500">Growth</p>
                                    <p class="text-lg font-semibold" id="growth">0%</p>
                                </div>
                            </div>
                            <div class="h-64">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Overview (in same row as Revenue Reports) -->
                    <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                        <div class="light-strip p-5 border-b border-gray-700/30">
                            <h2 class="text-lg font-semibold text-white">Maintenance Overview</h2>
                            <p class="text-sm text-gray-500">This month's maintenance status</p>
                        </div>
                        <div class="p-5">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                                <div class="p-3 bg-gray-800/40 rounded-lg">
                                    <p class="text-xs text-gray-400">Services Done</p>
                                    <p class="text-xl font-bold text-emerald-500" id="maintServicesDone">0</p>
                                </div>
                                <div class="p-3 bg-gray-800/40 rounded-lg">
                                    <p class="text-xs text-gray-400">Open Repairs</p>
                                    <p class="text-xl font-bold text-amber-500" id="maintOpenRepairs">0</p>
                                </div>
                                <div class="p-3 bg-gray-800/40 rounded-lg">
                                    <p class="text-xs text-gray-400">Total Cost</p>
                                    <p class="text-xl font-bold text-red-500" id="maintTotalCost">₱0</p>
                                </div>
                                <div class="p-3 bg-gray-800/40 rounded-lg">
                                    <p class="text-xs text-gray-400">Avg Cost/Service</p>
                                    <p class="text-xl font-bold text-blue-500" id="maintAvgCost">₱0</p>
                                </div>
                            </div>
                            <div class="h-48">
                                <canvas id="maintenancePriorityChart"></canvas>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Vehicle Status & Customer Analytics - Side by Side (Row 2) -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
                <!-- Vehicle Status -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Vehicle Status</h2>
                        <p class="text-sm text-gray-500">Current fleet distribution</p>
                    </div>
                    <div class="p-4">
                        <div class="h-56">
                            <canvas id="vehicleStatusChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Customer Analytics -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Customer Analytics</h2>
                        <p class="text-sm text-gray-500">Customer breakdown</p>
                    </div>
                    <div class="p-4">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Total Customers</span>
                                <span class="text-white font-semibold" id="custTotal">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Verified</span>
                                <span class="text-emerald-500 font-semibold" id="custVerified">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">New This Month</span>
                                <span class="text-blue-500 font-semibold" id="custNew">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Returning</span>
                                <span class="text-purple-500 font-semibold" id="custReturning">0</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking Report Section (Hidden by default) -->
            <div id="bookingReport" class="report-section hidden">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                        <div class="light-strip p-4 border-b border-gray-700/30">
                            <h3 class="text-base font-semibold text-white">Booking Summary</h3>
                        </div>
                        <div class="p-4 space-y-3">
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Total Bookings</span>
                                <span class="text-white font-semibold" id="totalBookings">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Confirmed</span>
                                <span class="text-emerald-500 font-semibold" id="confirmedBookings">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Pending</span>
                                <span class="text-amber-500 font-semibold" id="pendingBookings">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Completed</span>
                                <span class="text-blue-500 font-semibold" id="completedBookings">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Ongoing</span>
                                <span class="text-purple-500 font-semibold" id="ongoingBookings">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Cancelled</span>
                                <span class="text-red-500 font-semibold" id="cancelledBookings">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">No-Show</span>
                                <span class="text-red-500 font-semibold" id="noShowBookings">0</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                        <div class="light-strip p-4 border-b border-gray-700/30">
                            <h3 class="text-base font-semibold text-white">Booking Trends</h3>
                        </div>
                        <div class="p-4 h-64">
                            <canvas id="bookingChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Report Section (Hidden by default) -->
            <div id="customerReport" class="report-section hidden">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                        <div class="light-strip p-4 border-b border-gray-700/30">
                            <h3 class="text-base font-semibold text-white">Customer Summary</h3>
                        </div>
                        <div class="p-4 space-y-3">
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Total Customers</span>
                                <span class="text-white font-semibold" id="totalCustomers">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">New This Month</span>
                                <span class="text-emerald-500 font-semibold" id="newCustomers">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Verified</span>
                                <span class="text-emerald-500 font-semibold" id="verifiedCustomers">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Returning</span>
                                <span class="text-blue-500 font-semibold" id="returningCustomers">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Blacklisted</span>
                                <span class="text-red-500 font-semibold" id="blacklistedCustomers">0</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                        <div class="light-strip p-4 border-b border-gray-700/30">
                            <h3 class="text-base font-semibold text-white">Top Customers</h3>
                        </div>
                        <div class="p-4 space-y-3" id="topCustomersList">
                            <!-- Will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Maintenance Report Section (Hidden by default) -->
            <div id="maintenanceReport" class="report-section hidden">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                        <div class="light-strip p-4 border-b border-gray-700/30">
                            <h3 class="text-base font-semibold text-white">Maintenance Summary</h3>
                        </div>
                        <div class="p-4 space-y-3">
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Services Completed</span>
                                <span class="text-white font-semibold" id="servicesDone">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Open Repairs</span>
                                <span class="text-amber-500 font-semibold" id="openRepairs">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Avg Cost / Vehicle</span>
                                <span class="text-white font-semibold" id="avgCost">₱0.00</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Total Cost</span>
                                <span class="text-red-500 font-semibold" id="totalCost">₱0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                        <div class="light-strip p-4 border-b border-gray-700/30">
                            <h3 class="text-base font-semibold text-white">Tasks by Priority</h3>
                        </div>
                        <div class="p-4 space-y-3">
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Critical</span>
                                <span class="text-red-500 font-semibold" id="criticalTasks">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">High</span>
                                <span class="text-orange-500 font-semibold" id="highTasks">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Medium</span>
                                <span class="text-amber-500 font-semibold" id="mediumTasks">0</span>
                            </div>
                            <div class="flex items-center justify-between text-sm p-2 bg-gray-800/40 rounded">
                                <span class="text-gray-400">Low</span>
                                <span class="text-emerald-500 font-semibold" id="lowTasks">0</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Export Modal -->
    <div id="exportModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeExportModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 class="text-xl font-semibold text-white">Generate Report</h2>
                    <button onclick="closeExportModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="exportForm" class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Report Type</label>
                        <select id="exportType" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                            <option value="Revenue">Revenue Report</option>
                            <option value="Bookings">Booking Report</option>
                            <option value="Customers">Customer Report</option>
                            <option value="Maintenance">Maintenance Report</option>
                            <option value="Vehicles">Vehicle Report</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Format</label>
                        <select id="exportFormat" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                            <option value="pdf">PDF</option>
                            <option value="excel">Excel</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Date Range</label>
                        <select id="exportDateRange" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month" selected>This Month</option>
                            <option value="quarter">Last 3 Months</option>
                            <option value="year">This Year</option>
                        </select>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closeExportModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-download mr-2"></i>Generate
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
                    <p id="successMessage" class="text-gray-300">Report generated successfully!</p>
                </div>
                <div class="flex justify-end p-5 border-t border-gray-700">
                    <button onclick="closeSuccessModal()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">OK</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Chart instances
        let revenueChart = null;
        let bookingChart = null;
        
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

        function showSuccess(message) {
            document.getElementById('successMessage').textContent = message;
            document.getElementById('successModal').classList.remove('hidden');
            setTimeout(() => {
                closeSuccessModal();
            }, 3000);
        }

        // Export Modal
        function openExportModal() {
            document.getElementById('exportModal').classList.remove('hidden');
        }

        function closeExportModal() {
            document.getElementById('exportModal').classList.add('hidden');
            document.getElementById('exportForm').reset();
        }

        // Load initial data
        document.addEventListener('DOMContentLoaded', function() {
            loadRevenueReport('month');
            loadDashboardAnalytics();
        });

        // Switch between report sections
        document.querySelectorAll('[data-report]').forEach(button => {
            button.addEventListener('click', function() {
                const reportType = this.dataset.report;
                
                // Hide all sections
                document.querySelectorAll('.report-section').forEach(section => {
                    section.classList.add('hidden');
                });
                
                // Show selected section
                document.getElementById(reportType + 'Report').classList.remove('hidden');
                
                // Load report data
                switch(reportType) {
                    case 'revenue':
                        loadRevenueReport('month');
                        break;
                    case 'bookings':
                        loadBookingReport();
                        break;
                    case 'customers':
                        loadCustomerReport();
                        break;
                    case 'maintenance':
                        loadMaintenanceReport();
                        break;
                }
            });
        });

        // Revenue Report
        function loadRevenueReport(period) {
            fetch('reports.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_revenue_report&period=' + period
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateRevenueChart(data.labels, data.data);
                    document.getElementById('totalRevenue').textContent = '₱' + parseFloat(data.summary.total_revenue).toFixed(2);
                    document.getElementById('avgBooking').textContent = '₱' + parseFloat(data.summary.avg_booking).toFixed(2);
                    
                    const growth = data.summary.growth;
                    const growthEl = document.getElementById('growth');
                    growthEl.textContent = (growth > 0 ? '+' : '') + growth + '%';
                    growthEl.className = 'text-lg font-semibold ' + (growth >= 0 ? 'text-emerald-500' : 'text-red-500');
                }
            });
        }

        // Dashboard Analytics - Load all additional data
        function loadDashboardAnalytics() {
            // Load Maintenance Data
            fetch('reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_maintenance_report'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('maintServicesDone').textContent = data.summary.services_done;
                    document.getElementById('maintOpenRepairs').textContent = data.summary.open_repairs;
                    document.getElementById('maintTotalCost').textContent = '₱' + parseFloat(data.summary.total_cost).toFixed(2);
                    document.getElementById('maintAvgCost').textContent = '₱' + parseFloat(data.summary.avg_cost || 0).toFixed(2);
                    updateMaintenancePriorityChart([data.summary.critical, data.summary.high, data.summary.medium, data.summary.low]);
                }
            });

            // Load Vehicle Status Data
            fetch('reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_vehicle_report'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateVehicleStatusChart(data.status);
                }
            });

            // Load Customer Data
            fetch('reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_customer_report'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('custTotal').textContent = data.summary.total;
                    document.getElementById('custVerified').textContent = data.summary.verified;
                    document.getElementById('custNew').textContent = data.summary.new;
                    document.getElementById('custReturning').textContent = data.summary.returning;
                }
            });
        }

        // Maintenance Priority Chart
        let maintenancePriorityChart = null;
        function updateMaintenancePriorityChart(data) {
            const ctx = document.getElementById('maintenancePriorityChart').getContext('2d');
            if (maintenancePriorityChart) {
                maintenancePriorityChart.destroy();
            }
            maintenancePriorityChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Critical', 'High', 'Medium', 'Low'],
                    datasets: [{
                        data: data,
                        backgroundColor: ['#ef4444', '#f97316', '#eab308', '#22c55e'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { color: '#9ca3af', font: { size: 10 } }
                        }
                    }
                }
            });
        }

        // Vehicle Status Chart
        let vehicleStatusChart = null;
        function updateVehicleStatusChart(statusData) {
            const ctx = document.getElementById('vehicleStatusChart').getContext('2d');
            if (vehicleStatusChart) {
                vehicleStatusChart.destroy();
            }
            const labels = Object.keys(statusData);
            const data = Object.values(statusData);
            const colors = labels.map(s => {
                if (s === 'Available') return '#22c55e';
                if (s === 'Rented') return '#3b82f6';
                if (s === 'Maintenance') return '#eab308';
                return '#6b7280';
            });
            vehicleStatusChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { color: '#9ca3af', font: { size: 10 } }
                        }
                    }
                }
            });
        }

        function updateRevenueChart(labels, data) {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            if (revenueChart) {
                revenueChart.destroy();
            }
            
            revenueChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue (₱)',
                        data: data,
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#374151'
                            },
                            ticks: {
                                color: '#9ca3af'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#9ca3af'
                            }
                        }
                    }
                }
            });
        }

        // Booking Report
        function loadBookingReport() {
            fetch('reports.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_booking_report'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('totalBookings').textContent = data.summary.total;
                    document.getElementById('confirmedBookings').textContent = data.summary.confirmed;
                    document.getElementById('pendingBookings').textContent = data.summary.pending;
                    document.getElementById('completedBookings').textContent = data.summary.completed;
                    document.getElementById('ongoingBookings').textContent = data.summary.ongoing;
                    document.getElementById('cancelledBookings').textContent = data.summary.cancelled;
                    document.getElementById('noShowBookings').textContent = data.summary.no_show;
                    
                    updateBookingChart(data.summary);
                }
            });
        }

        function updateBookingChart(summary) {
            const ctx = document.getElementById('bookingChart').getContext('2d');
            
            if (bookingChart) {
                bookingChart.destroy();
            }
            
            bookingChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Confirmed', 'Pending', 'Ongoing', 'Completed', 'Cancelled'],
                    datasets: [{
                        data: [
                            summary.confirmed,
                            summary.pending,
                            summary.ongoing,
                            summary.completed,
                            summary.cancelled
                        ],
                        backgroundColor: [
                            '#10b981',
                            '#f59e0b',
                            '#8b5cf6',
                            '#3b82f6',
                            '#ef4444'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: '#9ca3af'
                            }
                        }
                    }
                }
            });
        }

        // Customer Report
        function loadCustomerReport() {
            fetch('reports.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_customer_report'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('totalCustomers').textContent = data.summary.total;
                    document.getElementById('newCustomers').textContent = data.summary.new;
                    document.getElementById('verifiedCustomers').textContent = data.summary.verified;
                    document.getElementById('returningCustomers').textContent = data.summary.returning;
                    document.getElementById('blacklistedCustomers').textContent = data.summary.blacklisted;
                    
                    displayTopCustomers(data.top_customers);
                }
            });
        }

        function displayTopCustomers(customers) {
            const container = document.getElementById('topCustomersList');
            
            if (customers.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No customer data available</p>';
                return;
            }
            
            let html = '';
            customers.forEach((c, index) => {
                html += `
                    <div class="flex items-center justify-between p-2 bg-gray-800/40 rounded">
                        <div>
                            <span class="text-xs text-gray-500 mr-2">#${index + 1}</span>
                            <span class="text-sm text-white">${c.first_name} ${c.last_name}</span>
                        </div>
                        <span class="text-sm font-semibold text-emerald-500">₱${parseFloat(c.total_spent).toFixed(2)}</span>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Maintenance Report
        function loadMaintenanceReport() {
            fetch('reports.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_maintenance_report'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('servicesDone').textContent = data.summary.services_done;
                    document.getElementById('openRepairs').textContent = data.summary.open_repairs;
                    document.getElementById('avgCost').textContent = '₱' + parseFloat(data.summary.avg_cost).toFixed(2);
                    document.getElementById('totalCost').textContent = '₱' + parseFloat(data.summary.total_cost).toFixed(2);
                    document.getElementById('criticalTasks').textContent = data.summary.critical;
                    document.getElementById('highTasks').textContent = data.summary.high;
                    document.getElementById('mediumTasks').textContent = data.summary.medium;
                    document.getElementById('lowTasks').textContent = data.summary.low;
                }
            });
        }

        // Export Report
        document.getElementById('exportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'generate_report');
            formData.append('report_type', document.getElementById('exportType').value);
            formData.append('format', document.getElementById('exportFormat').value);
            formData.append('date_range', document.getElementById('exportDateRange').value);
            
            fetch('reports.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeExportModal();
                    showSuccess('Report generated successfully');
                    
                    // In production, you would trigger download here
                    // window.location.href = data.download_url;
                }
            });
        });

        function exportReport(type, format) {
            document.getElementById('exportType').value = type;
            document.getElementById('exportFormat').value = format;
            openExportModal();
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeExportModal();
                closeSuccessModal();
            }
        });
    </script>
</body>
</html>