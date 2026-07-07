<?php
// Dashboard - Velocity Rentals
$admin_base = false;
require_once 'config/connect.php';

// Handle AJAX requests for revenue chart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_revenue_chart') {
    header('Content-Type: application/json');
    
    $conn = getConnection();
    $filter = $_POST['filter'] ?? 'this_week';
    $chart_data = [];
    $period_revenue = 0;
    $total_revenue = 0;
    
    $today = date('Y-m-d');
    
    // Get total revenue
    $total_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Completed'")->fetch_assoc()['total'] ?? 0;
    
    if ($filter === 'this_week') {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $week_start = date('Y-m-d', strtotime('monday this week'));
        
        for ($i = 0; $i < 7; $i++) {
            $day_date = date('Y-m-d', strtotime("$week_start +$i days"));
            $day_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) = '$day_date' AND status = 'Completed'")->fetch_assoc()['total'] ?? 0;
            $chart_data[] = ['day' => $days[$i], 'revenue' => (float)$day_revenue];
            $period_revenue += $day_revenue;
        }
    } elseif ($filter === 'last_week') {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $week_start = date('Y-m-d', strtotime('monday last week'));
        
        for ($i = 0; $i < 7; $i++) {
            $day_date = date('Y-m-d', strtotime("$week_start +$i days"));
            $day_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) = '$day_date' AND status = 'Completed'")->fetch_assoc()['total'] ?? 0;
            $chart_data[] = ['day' => $days[$i], 'revenue' => (float)$day_revenue];
            $period_revenue += $day_revenue;
        }
    } elseif ($filter === 'this_month') {
        $days_in_month = date('t');
        $month_start = date('Y-m-01');
        
        for ($i = 1; $i <= $days_in_month; $i++) {
            $day_date = date('Y-m-d', strtotime("$month_start +" . ($i - 1) . " days"));
            $day_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) = '$day_date' AND status = 'Completed'")->fetch_assoc()['total'] ?? 0;
            $chart_data[] = ['day' => $i, 'revenue' => (float)$day_revenue];
            $period_revenue += $day_revenue;
        }
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'chart_data' => $chart_data,
        'stats' => [
            'period_revenue' => $period_revenue,
            'total_revenue' => $total_revenue
        ]
    ]);
    exit;
}

// Get database connection
$conn = getConnection();

// Default values
$total_vehicles = 0;
$available_vehicles = 0;
$rented_vehicles = 0;
$reservations_today = 0;
$total_customers = 0;
$total_revenue = 0;
$revenue_today = 0;
$revenue_week = 0;
$maintenance_count = 0;
$recent_reservations = [];
$upcoming_maintenance = [];

$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

if ($conn) {
    // Vehicle stats
    $total_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles")->fetch_assoc()['count'] ?? 0;
    $available_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Available'")->fetch_assoc()['count'] ?? 0;
    $rented_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Rented'")->fetch_assoc()['count'] ?? 0;
    $maintenance_count = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Maintenance'")->fetch_assoc()['count'] ?? 0;
    
    // Reservations today
    $reservations_today = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status IN ('Confirmed', 'Ongoing') AND pickup_date <= '$today' AND return_date >= '$today'")->fetch_assoc()['count'] ?? 0;
    
    // Total customers
    $total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'] ?? 0;
    
    // Revenue stats
    $total_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Completed'")->fetch_assoc()['total'] ?? 0;
    $revenue_today = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) = '$today' AND status = 'Completed'")->fetch_assoc()['total'] ?? 0;
    $revenue_week = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) BETWEEN '$week_start' AND '$week_end' AND status = 'Completed'")->fetch_assoc()['total'] ?? 0;
    $revenue_month = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) BETWEEN '$month_start' AND '$month_end' AND status = 'Completed'")->fetch_assoc()['total'] ?? 0;
    
    // Get weekly revenue data for chart
    $weekly_revenue = [];
    $days_of_week = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $max_daily_revenue = 0;
    
    for ($i = 0; $i < 7; $i++) {
        $day_date = date('Y-m-d', strtotime("monday this week +$i days"));
        $day_name = $days_of_week[$i];
        $day_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE DATE(payment_date) = '$day_date' AND status = 'Completed'")->fetch_assoc()['total'] ?? 0;
        $weekly_revenue[] = [
            'day' => $day_name,
            'date' => $day_date,
            'revenue' => $day_revenue
        ];
        if ($day_revenue > $max_daily_revenue) {
            $max_daily_revenue = $day_revenue;
        }
    }
    
    // Get upcoming maintenance
    $maint_result = $conn->query("SELECT mt.*, v.vehicle_name, v.license_plate FROM maintenance_tasks mt JOIN vehicles v ON mt.vehicle_id = v.id WHERE mt.status IN ('Scheduled', 'In Progress') AND mt.due_date BETWEEN '$today' AND DATE_ADD('$today', INTERVAL 7 DAY) ORDER BY mt.due_date ASC LIMIT 3");
    if ($maint_result) {
        while ($row = $maint_result->fetch_assoc()) {
            $upcoming_maintenance[] = $row;
        }
    }
    
    // Get recent reservations
    $res_sql = "SELECT r.*, c.first_name, c.last_name, c.email, v.vehicle_name, v.license_plate 
                FROM reservations r 
                JOIN customers c ON r.customer_id = c.id 
                JOIN vehicles v ON r.vehicle_id = v.id 
                ORDER BY r.created_at DESC LIMIT 5";
    $res_result = $conn->query($res_sql);
    if ($res_result) {
        while ($row = $res_result->fetch_assoc()) {
            $recent_reservations[] = $row;
        }
    }
    
    $conn->close();
}

// Get company name from settings
$company_name = 'Velocity Rentals';
$conn = getConnection();
if ($conn) {
    $company_result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
    if ($company_result && $company_result->num_rows > 0) {
        $company_name = $company_result->fetch_assoc()['setting_value'];
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Velocity Rentals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        html {
            font-size: 14px;
        }
        /* Custom scrollbar */
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
        
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 24px rgba(220, 38, 38, 0.2);
        }

        /* Card glow effect */
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

        /* Subtle light strip */
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
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen">
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="lg:ml-64 min-h-screen">
        <!-- Top Header -->
        <header class="header-glow bg-gray-900/90 backdrop-blur-md border-b border-gray-700/50 sticky top-0 z-30">
            <div class="flex items-center justify-between h-16 px-4 lg:px-6">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg hover:bg-gray-800 text-gray-400 hover:text-white transition-colors">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <div class="w-1 h-8 bg-gradient-to-b from-red-500 to-red-700 rounded-full"></div>
                        <div>
                            <h1 class="text-xl font-bold text-white">Dashboard</h1>
                            <p class="text-xs text-gray-500">Welcome back, Admin</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="p-4 lg:p-6">
            <!-- Welcome Banner -->
            <div class="mb-6 relative overflow-hidden rounded-2xl bg-gradient-to-r from-red-900/80 via-red-800/60 to-gray-900/80 border border-red-500/20">
                <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGRlZnM+PHBhdHRlcm4gaWQ9ImdyaWQiIHdpZHRoPSI2MCIgaGVpZ2h0PSI2MCIgcGF0dGVyblVuaXRzPSJ1c2VyU3BhY2VPblVzZSI+PHBhdGggZD0iTSA2MCAwIEwgMCAwIDAgNjAiIGZpbGw9Im5vbmUiIHN0cm9rZT0icmdiYSgyNTAsMjU1LDI1NSwwLjEpIiBzdHJva2Utd2lkdGg9IjAuNSIvPjwvcGF0dGVybj48L2RlZnM+PHJlY3Qgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgZmlsbD0idXJsKCNncmlkKSIvPjwvc3ZnPg==')] opacity-30"></div>
                <div class="relative px-6 py-8 flex flex-col md:flex-row items-center justify-between gap-6">
                    <!-- Left Side - Welcome Text -->
                    <div class="flex-1 z-10">
                        <h2 class="text-2xl font-bold text-white mb-1">Welcome back, Admin!</h2>
                        <p class="text-gray-300">Here's what's happening with <span class="text-red-400 font-semibold"><?php echo htmlspecialchars($company_name); ?></span> today.</p>
                        <div class="flex items-center gap-4 mt-3 text-sm text-gray-400">
                            <span class="flex items-center gap-1"><i class="fas fa-calendar-alt text-red-400"></i> <?php echo date('l, F j, Y'); ?></span>
                        </div>
                        <div class="flex gap-3 mt-4">
                            <div class="text-center px-4 py-2 bg-white/10 rounded-lg backdrop-blur">
                                <p class="text-2xl font-bold text-white"><?php echo number_format($total_vehicles); ?></p>
                                <p class="text-xs text-gray-300">Vehicles</p>
                            </div>
                            <div class="text-center px-4 py-2 bg-white/10 rounded-lg backdrop-blur">
                                <p class="text-2xl font-bold text-emerald-400"><?php echo number_format($available_vehicles); ?></p>
                                <p class="text-xs text-gray-300">Available</p>
                            </div>
                            <div class="text-center px-4 py-2 bg-white/10 rounded-lg backdrop-blur">
                                <p class="text-2xl font-bold text-blue-400"><?php echo number_format($rented_vehicles); ?></p>
                                <p class="text-xs text-gray-300">Rented</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Side - Animated Car Icon -->
                    <div class="hidden md:flex w-40 h-24 relative items-center justify-center">
                        <div class="absolute inset-0 bg-red-500/10 rounded-full blur-2xl animate-pulse"></div>
                        <i class="fas fa-car text-red-500 text-7xl"></i>
                    </div>
                </div>
            </div>

            <!-- Second Row - Revenue & Quick Stats -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <!-- Revenue Summary -->
                <div class="lg:col-span-2 card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Revenue Summary</h2>
                            <p class="text-sm text-gray-500" id="revenue-subtitle">Weekly earnings overview</p>
                        </div>
                        <select id="revenue-filter" onchange="updateRevenueChart()" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                            <option value="this_week">This Week</option>
                            <option value="last_week">Last Week</option>
                        </select>
                    </div>
                    <div class="p-5">
                        <!-- Revenue Stats -->
                        <div class="grid grid-cols-3 gap-4 mb-6">
                            <div class="text-center p-4 bg-gray-800/40 rounded-lg border border-gray-700/20">
                                <p class="text-gray-400 text-xs mb-1">Total Revenue</p>
                                <p class="text-xl font-bold text-white" id="stat-total-revenue">₱<?php echo number_format($total_revenue, 2); ?></p>
                            </div>
                            <div class="text-center p-4 bg-gray-800/40 rounded-lg border border-gray-700/20">
                                <p class="text-gray-400 text-xs mb-1">This Week</p>
                                <p class="text-xl font-bold text-white" id="stat-period-revenue">₱<?php echo number_format($revenue_week, 2); ?></p>
                            </div>
                            <div class="text-center p-4 bg-gray-800/40 rounded-lg border border-gray-700/20">
                                <p class="text-gray-400 text-xs mb-1">Today</p>
                                <p class="text-xl font-bold text-white">₱<?php echo number_format($revenue_today, 2); ?></p>
                            </div>
                        </div>
                        <!-- Weekly Revenue Chart -->
                        <div id="revenue-chart" class="space-y-3">
                            <?php foreach($weekly_revenue as $day_data): ?>
                            <?php $width = $max_daily_revenue > 0 ? round(($day_data['revenue'] / $max_daily_revenue) * 100) : 0; ?>
                            <div class="flex items-center gap-4">
                                <span class="text-xs text-gray-500 w-12"><?php echo $day_data['day']; ?></span>
                                <div class="flex-1 h-6 bg-gray-800/50 rounded-full overflow-hidden border border-gray-700/20">
                                    <div class="h-full bg-gradient-to-r from-red-600 to-red-500 rounded-full" style="width: <?php echo $width; ?>%"></div>
                                </div>
                                <span class="text-xs text-gray-400 w-20 text-right">₱<?php echo number_format($day_data['revenue']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Quick Stats</h2>
                            <p class="text-sm text-gray-500">Fleet at a glance</p>
                        </div>
                    </div>
                    <div class="p-5 space-y-4">
                        <!-- Total Customers -->
                        <div class="flex items-center justify-between p-3 bg-blue-500/10 rounded-lg border border-blue-500/20">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-lg bg-blue-500/20 flex items-center justify-center">
                                    <i class="fas fa-users text-blue-500"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-white">Total Customers</p>
                                    <p class="text-xs text-gray-400">Registered users</p>
                                </div>
                            </div>
                            <p class="text-xl font-bold text-blue-500"><?php echo number_format($total_customers); ?></p>
                        </div>
                        
                        <!-- Maintenance -->
                        <div class="flex items-center justify-between p-3 bg-amber-500/10 rounded-lg border border-amber-500/20">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-lg bg-amber-500/20 flex items-center justify-center">
                                    <i class="fas fa-tools text-amber-500"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-white">Maintenance</p>
                                    <p class="text-xs text-gray-400">In service</p>
                                </div>
                            </div>
                            <p class="text-xl font-bold text-amber-500"><?php echo number_format($maintenance_count); ?></p>
                        </div>
                        
                        <!-- This Week Revenue -->
                        <div class="flex items-center justify-between p-3 bg-emerald-500/10 rounded-lg border border-emerald-500/20">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                                    <i class="fas fa-chart-line text-emerald-500"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-white">This Week</p>
                                    <p class="text-xs text-gray-400">Revenue</p>
                                </div>
                            </div>
                            <p class="text-xl font-bold text-emerald-500">₱<?php echo number_format($revenue_week); ?></p>
                        </div>
                    </div>
                    
                    <!-- Upcoming Maintenance -->
                    <?php if(!empty($upcoming_maintenance)): ?>
                    <div class="p-5 border-t border-gray-700/30">
                        <h3 class="text-sm font-medium text-white mb-3">Upcoming Maintenance</h3>
                        <div class="space-y-2">
                            <?php foreach($upcoming_maintenance as $maint): ?>
                            <div class="flex items-center justify-between p-2 bg-gray-800/40 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-wrench text-amber-500 text-xs"></i>
                                    <span class="text-xs text-gray-300"><?php echo htmlspecialchars($maint['vehicle_name']); ?></span>
                                </div>
                                <span class="text-xs text-gray-500"><?php echo date('M j', strtotime($maint['due_date'])); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="p-3 border-t border-gray-700/30">
                        <a href="admin/vehicles.php" class="w-full text-center text-sm text-red-500 hover:text-red-400 font-medium transition-colors block">
                            View Fleet
                        </a>
                    </div>
                </div>
            </div>

            <!-- Third Row - Recent Bookings -->
            <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                    <div>
                        <h2 class="text-lg font-semibold text-white">Recent Bookings</h2>
                        <p class="text-sm text-gray-500">Latest reservation transactions</p>
                    </div>
                    <a href="admin/reservations.php" class="text-sm text-red-500 hover:text-red-400 font-medium transition-colors">View All</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-700/30 bg-gray-800/30">
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Customer</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Vehicle</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Pickup Date</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Return Date</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Status</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700/30">
                            <?php if(empty($recent_reservations)): ?>
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-center text-gray-500">No recent reservations found</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach($recent_reservations as $res): ?>
                                <tr class="hover:bg-gray-800/40 transition-colors">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-red-500 to-red-700 flex items-center justify-center text-white text-xs font-bold">
                                                <?php echo strtoupper(substr($res['first_name'], 0, 1) . substr($res['last_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-white"><?php echo htmlspecialchars($res['first_name'] . ' ' . $res['last_name']); ?></p>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($res['email']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm text-white"><?php echo htmlspecialchars($res['vehicle_name']); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($res['license_plate']); ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-gray-300"><?php echo date('M j, Y', strtotime($res['pickup_date'])); ?></td>
                                    <td class="px-5 py-4 text-sm text-gray-300"><?php echo date('M j, Y', strtotime($res['return_date'])); ?></td>
                                    <td class="px-5 py-4">
                                        <?php 
                                        $status_class = '';
                                        $status_text = '';
                                        switch($res['status']) {
                                            case 'Confirmed':
                                            case 'Ongoing':
                                                $status_class = 'bg-emerald-500/10 text-emerald-500';
                                                $status_text = 'Active';
                                                break;
                                            case 'Pending':
                                                $status_class = 'bg-blue-500/10 text-blue-500';
                                                $status_text = 'Pending';
                                                break;
                                            case 'Completed':
                                                $status_class = 'bg-gray-500/10 text-gray-500';
                                                $status_text = 'Completed';
                                                break;
                                            case 'Cancelled':
                                                $status_class = 'bg-red-500/10 text-red-500';
                                                $status_text = 'Cancelled';
                                                break;
                                            default:
                                                $status_class = 'bg-gray-500/10 text-gray-500';
                                                $status_text = $res['status'];
                                        }
                                        ?>
                                        <span class="px-2 py-1 text-xs font-medium <?php echo $status_class; ?> rounded-full"><?php echo $status_text; ?></span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-medium text-white">₱<?php echo number_format($res['total_amount'] ?? 0, 2); ?></p>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="admin/reservations.php" class="flex items-center justify-center gap-2 p-4 card-glow bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-red-600/20 to-red-700/10 flex items-center justify-center border border-red-500/20 group-hover:border-red-500/40 transition-colors">
                        <i class="fas fa-plus text-red-500"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-300 group-hover:text-white">New Booking</span>
                </a>
                <a href="admin/vehicles.php" class="flex items-center justify-center gap-2 p-4 card-glow bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-red-600/20 to-red-700/10 flex items-center justify-center border border-red-500/20 group-hover:border-red-500/40 transition-colors">
                        <i class="fas fa-car text-red-500"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-300 group-hover:text-white">Add Vehicle</span>
                </a>
                <a href="admin/customers.php" class="flex items-center justify-center gap-2 p-4 card-glow bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-red-600/20 to-red-700/10 flex items-center justify-center border border-red-500/20 group-hover:border-red-500/40 transition-colors">
                        <i class="fas fa-user-plus text-red-500"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-300 group-hover:text-white">New Customer</span>
                </a>
                <a href="admin/reports.php" class="flex items-center justify-center gap-2 p-4 card-glow bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-red-600/20 to-red-700/10 flex items-center justify-center border border-red-500/20 group-hover:border-red-500/40 transition-colors">
                        <i class="fas fa-file-invoice text-red-500"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-300 group-hover:text-white">View Report</span>
                </a>
            </div>
        </div>
    </main>

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

        // Revenue chart filter
        function updateRevenueChart() {
            const filter = document.getElementById('revenue-filter').value;
            const subtitle = document.getElementById('revenue-subtitle');
            const chartContainer = document.getElementById('revenue-chart');
            
            // Update subtitle
            const subtitles = {
                'this_week': 'This week earnings',
                'last_week': 'Last week earnings',
                'this_month': 'This month earnings'
            };
            subtitle.textContent = subtitles[filter];
            
            // Show loading
            chartContainer.innerHTML = '<div class="flex items-center justify-center py-8"><i class="fas fa-spinner fa-spin text-red-500 text-2xl"></i></div>';
            
            // Fetch data via AJAX
            const formData = new FormData();
            formData.append('action', 'get_revenue_chart');
            formData.append('filter', filter);
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    const maxRevenue = Math.max(...data.chart_data.map(d => d.revenue), 1);
                    
                    data.chart_data.forEach(day => {
                        const width = maxRevenue > 0 ? Math.round((day.revenue / maxRevenue) * 100) : 0;
                        const revenueDisplay = day.revenue > 0 ? '₱' + day.revenue.toLocaleString() : '₱0';
                        html += `
                        <div class="flex items-center gap-4">
                            <span class="text-xs text-gray-500 w-12">${day.day}</span>
                            <div class="flex-1 h-6 bg-gray-800/50 rounded-full overflow-hidden border border-gray-700/20">
                                <div class="h-full bg-gradient-to-r from-red-600 to-red-500 rounded-full revenue-bar" style="width: ${width}%"></div>
                            </div>
                            <span class="text-xs text-gray-400 w-20 text-right">${revenueDisplay}</span>
                        </div>`;
                    });
                    
                    chartContainer.innerHTML = html;
                    
                    // Update stats
                    document.getElementById('stat-period-revenue').textContent = '₱' + data.stats.period_revenue.toLocaleString();
                    document.getElementById('stat-total-revenue').textContent = '₱' + data.stats.total_revenue.toLocaleString();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                chartContainer.innerHTML = '<div class="text-center py-4 text-gray-500">Failed to load data</div>';
            });
        }
    </script>
</body>
</html>
