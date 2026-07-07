<?php
session_start();
require_once '../config/connect.php';

$admin_base = true;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        $conn = getConnection();
        
        // Add Vehicle
        if ($_POST['action'] === 'add') {
            $vehicle_name = $conn->real_escape_string($_POST['vehicle_name']);
            $category = $conn->real_escape_string($_POST['category']);
            $license_plate = $conn->real_escape_string($_POST['license_plate']);
            $model_year = (int)$_POST['model_year'];
            $fuel_type = $conn->real_escape_string($_POST['fuel_type']);
            $transmission = $conn->real_escape_string($_POST['transmission']);
            $price_per_day = (float)$_POST['price_per_day'];
            $status = $conn->real_escape_string($_POST['status']);
            $description = $conn->real_escape_string($_POST['description']);
            
            // Handle image upload
            $image_path = 'default-car.png';
            if (isset($_FILES['vehicle_image']) && $_FILES['vehicle_image']['error'] === 0) {
                $target_dir = "../uploads/vehicles/";
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                $image_extension = pathinfo($_FILES['vehicle_image']['name'], PATHINFO_EXTENSION);
                $image_path = time() . '_' . uniqid() . '.' . $image_extension;
                $target_file = $target_dir . $image_path;
                
                if (move_uploaded_file($_FILES['vehicle_image']['tmp_name'], $target_file)) {
                    // Image uploaded successfully
                } else {
                    $image_path = 'default-car.png';
                }
            }
            
            $sql = "INSERT INTO vehicles (vehicle_name, category, license_plate, model_year, fuel_type, transmission, price_per_day, status, description, image_path) 
                    VALUES ('$vehicle_name', '$category', '$license_plate', $model_year, '$fuel_type', '$transmission', $price_per_day, '$status', '$description', '$image_path')";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Vehicle added successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Get Vehicle Details
        elseif ($_POST['action'] === 'get') {
            $id = (int)$_POST['id'];
            $sql = "SELECT * FROM vehicles WHERE id = $id";
            $result = $conn->query($sql);
            
            if ($result->num_rows > 0) {
                $vehicle = $result->fetch_assoc();
                echo json_encode(['success' => true, 'vehicle' => $vehicle]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Vehicle not found']);
            }
        }
        
        // Update Vehicle
        elseif ($_POST['action'] === 'update') {
            $id = (int)$_POST['id'];
            $vehicle_name = $conn->real_escape_string($_POST['vehicle_name']);
            $category = $conn->real_escape_string($_POST['category']);
            $license_plate = $conn->real_escape_string($_POST['license_plate']);
            $model_year = (int)$_POST['model_year'];
            $fuel_type = $conn->real_escape_string($_POST['fuel_type']);
            $transmission = $conn->real_escape_string($_POST['transmission']);
            $price_per_day = (float)$_POST['price_per_day'];
            $status = $conn->real_escape_string($_POST['status']);
            $description = $conn->real_escape_string($_POST['description']);
            
            // Start building the SQL query
            $sql = "UPDATE vehicles SET 
                    vehicle_name = '$vehicle_name',
                    category = '$category',
                    license_plate = '$license_plate',
                    model_year = $model_year,
                    fuel_type = '$fuel_type',
                    transmission = '$transmission',
                    price_per_day = $price_per_day,
                    status = '$status',
                    description = '$description'";
            
            // Handle image upload if a new image is provided
            if (isset($_FILES['vehicle_image']) && $_FILES['vehicle_image']['error'] === 0) {
                $target_dir = "../uploads/vehicles/";
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                // Get old image to delete
                $old_image_sql = "SELECT image_path FROM vehicles WHERE id = $id";
                $old_image_result = $conn->query($old_image_sql);
                if ($old_image_result->num_rows > 0) {
                    $old_image = $old_image_result->fetch_assoc()['image_path'];
                    if ($old_image !== 'default-car.png') {
                        $old_image_file = $target_dir . $old_image;
                        if (file_exists($old_image_file)) {
                            unlink($old_image_file);
                        }
                    }
                }
                
                // Upload new image
                $image_extension = pathinfo($_FILES['vehicle_image']['name'], PATHINFO_EXTENSION);
                $image_path = time() . '_' . uniqid() . '.' . $image_extension;
                $target_file = $target_dir . $image_path;
                
                if (move_uploaded_file($_FILES['vehicle_image']['tmp_name'], $target_file)) {
                    $sql .= ", image_path = '$image_path'";
                }
            }
            
            $sql .= " WHERE id = $id";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Vehicle updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Delete Vehicle
        elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            
            // First get the image path to delete the file
            $sql = "SELECT image_path FROM vehicles WHERE id = $id";
            $result = $conn->query($sql);
            if ($result->num_rows > 0) {
                $vehicle = $result->fetch_assoc();
                if ($vehicle['image_path'] !== 'default-car.png') {
                    $image_file = "../uploads/vehicles/" . $vehicle['image_path'];
                    if (file_exists($image_file)) {
                        unlink($image_file);
                    }
                }
            }
            
            $sql = "DELETE FROM vehicles WHERE id = $id";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Vehicle deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        $conn->close();
        exit;
    }
}

// Fetch all vehicles for display
$conn = getConnection();
$vehicles_sql = "SELECT * FROM vehicles ORDER BY created_at DESC";
$vehicles_result = $conn->query($vehicles_sql);

// Get statistics
$total_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles")->fetch_assoc()['count'];
$available_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Available'")->fetch_assoc()['count'];
$rented_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Rented'")->fetch_assoc()['count'];
$maintenance_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Maintenance'")->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Management - Velocity Rentals</title>
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

        /* Image preview */
        .image-preview {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 0.5rem;
            border: 2px solid #374151;
        }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen">
    <?php include '../includes/sidebar.php'; ?>
    
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
                            <h1 class="text-xl font-bold text-white">Vehicle Management</h1>
                            <p class="text-xs text-gray-500">Manage your rental fleet</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <!-- Search -->
                    <div class="hidden md:flex items-center bg-gray-800/80 rounded-lg px-3 py-2 border border-gray-600/30">
                        <i class="fas fa-search text-gray-500 text-sm"></i>
                        <input type="text" id="searchInput" placeholder="Search vehicles..." onkeyup="filterVehicles()" class="bg-transparent border-none outline-none text-sm text-gray-300 placeholder-gray-500 ml-2 w-48">
                    </div>
                    <!-- Add Vehicle Button -->
                    <button onclick="openAddModal()" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white rounded-lg text-sm font-medium transition-all shadow-lg shadow-red-600/20">
                        <i class="fas fa-plus"></i>
                        <span>Add Vehicle</span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="p-4 lg:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <!-- Total Vehicles -->
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-red-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-600/30 to-red-700/10 flex items-center justify-center border border-red-500/20">
                            <i class="fas fa-car text-red-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Total Vehicles</h3>
                    <p class="text-2xl font-bold text-white" id="totalVehicles"><?php echo $total_vehicles; ?></p>
                </div>

                <!-- Available -->
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-emerald-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-600/30 to-emerald-700/10 flex items-center justify-center border border-emerald-500/20">
                            <i class="fas fa-check-circle text-emerald-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Available</h3>
                    <p class="text-2xl font-bold text-white" id="availableVehicles"><?php echo $available_vehicles; ?></p>
                </div>

                <!-- Rented -->
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-blue-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-600/30 to-blue-700/10 flex items-center justify-center border border-blue-500/20">
                            <i class="fas fa-road text-blue-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Rented</h3>
                    <p class="text-2xl font-bold text-white" id="rentedVehicles"><?php echo $rented_vehicles; ?></p>
                </div>

                <!-- Maintenance -->
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-amber-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-600/30 to-amber-700/10 flex items-center justify-center border border-amber-500/20">
                            <i class="fas fa-tools text-amber-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Maintenance</h3>
                    <p class="text-2xl font-bold text-white" id="maintenanceVehicles"><?php echo $maintenance_vehicles; ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-6">
                <button onclick="filterByStatus('all')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-red-600/20 to-red-700/10 flex items-center justify-center border border-red-500/20 group-hover:border-red-500/40 transition-colors">
                        <i class="fas fa-list text-red-500"></i>
                    </div>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">All Vehicles</span>
                </button>
                <button onclick="openAddModal()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-red-600/20 to-red-700/10 flex items-center justify-center border border-red-500/20 group-hover:border-red-500/40 transition-colors">
                        <i class="fas fa-plus-circle text-red-500"></i>
                    </div>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Add New</span>
                </button>
                <button onclick="filterByStatus('Available')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-emerald-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-emerald-600/20 to-emerald-700/10 flex items-center justify-center border border-emerald-500/20 group-hover:border-emerald-500/40 transition-colors">
                        <i class="fas fa-check-circle text-emerald-500"></i>
                    </div>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Available</span>
                </button>
                <button onclick="filterByStatus('Rented')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-blue-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-600/20 to-blue-700/10 flex items-center justify-center border border-blue-500/20 group-hover:border-blue-500/40 transition-colors">
                        <i class="fas fa-road text-blue-500"></i>
                    </div>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Rented</span>
                </button>
                <button onclick="filterByStatus('Maintenance')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-amber-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-amber-600/20 to-amber-700/10 flex items-center justify-center border border-amber-500/20 group-hover:border-amber-500/40 transition-colors">
                        <i class="fas fa-tools text-amber-500"></i>
                    </div>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Maintenance</span>
                </button>
                <button onclick="filterByCategory('Luxury')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-red-600/20 to-red-700/10 flex items-center justify-center border border-red-500/20 group-hover:border-red-500/40 transition-colors">
                        <i class="fas fa-crown text-red-500"></i>
                    </div>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Luxury</span>
                </button>
                <button onclick="filterByCategory('Electric')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-red-600/20 to-red-700/10 flex items-center justify-center border border-red-500/20 group-hover:border-red-500/40 transition-colors">
                        <i class="fas fa-bolt text-red-500"></i>
                    </div>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Electric</span>
                </button>
            </div>

            <!-- Vehicle List Table -->
            <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                    <div>
                        <h2 class="text-lg font-semibold text-white">Vehicle Fleet</h2>
                        <p class="text-sm text-gray-500">Manage your rental vehicles</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <select id="statusFilter" onchange="filterVehicles()" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                            <option value="all">All Status</option>
                            <option value="Available">Available</option>
                            <option value="Rented">Rented</option>
                            <option value="Maintenance">Maintenance</option>
                        </select>
                        <select id="categoryFilter" onchange="filterVehicles()" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                            <option value="all">All Categories</option>
                            <option value="Sedan">Sedan</option>
                            <option value="SUV">SUV</option>
                            <option value="Truck">Truck</option>
                            <option value="Luxury">Luxury</option>
                            <option value="Electric">Electric</option>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-700/30 bg-gray-800/30">
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Vehicle</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Category</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">License Plate</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Fuel</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Transmission</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Price/Day</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Status</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="vehicleTableBody" class="divide-y divide-gray-700/30">
                            <?php if ($vehicles_result->num_rows > 0): ?>
                                <?php while($vehicle = $vehicles_result->fetch_assoc()): ?>
                                    <tr class="hover:bg-gray-800/40 transition-colors vehicle-row" 
                                        data-status="<?php echo $vehicle['status']; ?>" 
                                        data-category="<?php echo $vehicle['category']; ?>"
                                        data-name="<?php echo strtolower($vehicle['vehicle_name']); ?>"
                                        data-plate="<?php echo strtolower($vehicle['license_plate']); ?>">
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-12 h-12 rounded-lg bg-gray-800 flex items-center justify-center overflow-hidden">
                                                    <?php if($vehicle['image_path'] !== 'default-car.png'): ?>
                                                        <img src="../uploads/vehicles/<?php echo $vehicle['image_path']; ?>" alt="<?php echo $vehicle['vehicle_name']; ?>" class="w-full h-full object-cover">
                                                    <?php else: ?>
                                                        <i class="fas fa-car text-gray-500 text-2xl"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-medium text-white"><?php echo $vehicle['vehicle_name']; ?></p>
                                                    <p class="text-xs text-gray-500"><?php echo $vehicle['model_year']; ?> Model</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-5 py-4 text-sm text-gray-300"><?php echo $vehicle['category']; ?></td>
                                        <td class="px-5 py-4 text-sm text-gray-300"><?php echo $vehicle['license_plate']; ?></td>
                                        <td class="px-5 py-4 text-sm text-gray-300"><?php echo $vehicle['fuel_type']; ?></td>
                                        <td class="px-5 py-4 text-sm text-gray-300"><?php echo $vehicle['transmission']; ?></td>
                                        <td class="px-5 py-4 text-sm font-medium text-white">₱<?php echo number_format($vehicle['price_per_day'], 2); ?></td>
                                        <td class="px-5 py-4">
                                            <span class="px-2 py-1 text-xs font-medium 
                                                <?php 
                                                    if($vehicle['status'] == 'Available') echo 'bg-emerald-500/10 text-emerald-500';
                                                    elseif($vehicle['status'] == 'Rented') echo 'bg-blue-500/10 text-blue-500';
                                                    else echo 'bg-amber-500/10 text-amber-500';
                                                ?> rounded-full">
                                                <?php echo $vehicle['status']; ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-2">
                                                <button onclick="viewVehicle(<?php echo $vehicle['id']; ?>)" class="p-2 text-gray-400 hover:text-blue-500 hover:bg-gray-700 rounded-lg transition-colors" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button onclick="editVehicle(<?php echo $vehicle['id']; ?>)" class="p-2 text-gray-400 hover:text-white hover:bg-gray-700 rounded-lg transition-colors" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="confirmDelete(<?php echo $vehicle['id']; ?>)" class="p-2 text-gray-400 hover:text-red-500 hover:bg-gray-700 rounded-lg transition-colors" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="px-5 py-8 text-center text-gray-500">
                                        <i class="fas fa-car text-4xl mb-2 opacity-50"></i>
                                        <p>No vehicles found. Click "Add Vehicle" to get started.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Add/Edit Vehicle Modal -->
    <div id="vehicleModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-2xl max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 id="modalTitle" class="text-xl font-semibold text-white">Add New Vehicle</h2>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="vehicleForm" class="p-5 space-y-4" enctype="multipart/form-data">
                    <input type="hidden" id="vehicleId" name="id">
                    <input type="hidden" id="action" name="action" value="add">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Vehicle Name</label>
                            <input type="text" id="vehicleName" name="vehicle_name" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="e.g., Toyota Camry">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Category</label>
                            <select id="category" name="category" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                                <option value="">Select Category</option>
                                <option value="Sedan">Sedan</option>
                                <option value="SUV">SUV</option>
                                <option value="Truck">Truck</option>
                                <option value="Luxury">Luxury</option>
                                <option value="Electric">Electric</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">License Plate</label>
                            <input type="text" id="licensePlate" name="license_plate" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="e.g., ABC-1234">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Model Year</label>
                            <input type="number" id="modelYear" name="model_year" required min="1900" max="2025" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="e.g., 2024">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Fuel Type</label>
                            <select id="fuelType" name="fuel_type" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                                <option value="">Select Fuel Type</option>
                                <option value="Petrol">Petrol</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Electric">Electric</option>
                                <option value="Hybrid">Hybrid</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Transmission</label>
                            <select id="transmission" name="transmission" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                                <option value="">Select Transmission</option>
                                <option value="Automatic">Automatic</option>
                                <option value="Manual">Manual</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Price per Day (₱)</label>
                            <input type="number" id="pricePerDay" name="price_per_day" required min="0" step="0.01" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="e.g., 45">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Status</label>
                            <select id="status" name="status" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                                <option value="Available">Available</option>
                                <option value="Maintenance">Maintenance</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Vehicle Image</label>
                        <input type="file" id="vehicleImage" name="vehicle_image" accept="image/*" onchange="previewImage(this)" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-red-600 file:text-white hover:file:bg-red-700">
                        <div id="imagePreview" class="mt-2 hidden">
                            <img src="" alt="Preview" class="image-preview">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">Description</label>
                        <textarea id="description" name="description" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500 h-24" placeholder="Vehicle description..."></textarea>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                        <button type="submit" id="submitBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Add Vehicle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Vehicle Modal -->
    <div id="viewModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeViewModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-2xl modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 class="text-xl font-semibold text-white">Vehicle Details</h2>
                    <button onclick="closeViewModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="vehicleDetails" class="p-5">
                    <!-- Details will be loaded here via JavaScript -->
                </div>
                <div class="flex justify-end p-5 border-t border-gray-700">
                    <button onclick="closeViewModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Close</button>
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
                        <div class="w-10 h-10 rounded-full bg-red-500/20 flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Confirm Action</h3>
                    </div>
                </div>
                <div class="p-5">
                    <p id="confirmMessage" class="text-gray-300">Are you sure you want to delete this vehicle?</p>
                </div>
                <div class="flex justify-end gap-3 p-5 border-t border-gray-700">
                    <button onclick="closeConfirmModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                    <button id="confirmActionBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Confirm</button>
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

        // Image preview function
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const previewImg = preview.querySelector('img');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    preview.classList.remove('hidden');
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Modal functions
        let currentDeleteId = null;

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Vehicle';
            document.getElementById('action').value = 'add';
            document.getElementById('submitBtn').textContent = 'Add Vehicle';
            document.getElementById('vehicleForm').reset();
            document.getElementById('vehicleId').value = '';
            document.getElementById('imagePreview').classList.add('hidden');
            document.getElementById('vehicleModal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('vehicleModal').classList.add('hidden');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.add('hidden');
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.add('hidden');
        }

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

        // View Vehicle
        function viewVehicle(id) {
            fetch('vehicles.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const v = data.vehicle;
                    const details = `
                        <div class="space-y-4">
                            <div class="flex items-center gap-4">
                                <div class="w-24 h-24 rounded-lg bg-gray-800 flex items-center justify-center overflow-hidden">
                                    ${v.image_path !== 'default-car.png' ? 
                                        `<img src="../uploads/vehicles/${v.image_path}" alt="${v.vehicle_name}" class="w-full h-full object-cover">` : 
                                        `<i class="fas fa-car text-gray-500 text-4xl"></i>`
                                    }
                                </div>
                                <div>
                                    <h3 class="text-2xl font-bold text-white">${v.vehicle_name}</h3>
                                    <p class="text-gray-400">${v.model_year} Model</p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Category</p>
                                    <p class="text-white font-medium">${v.category}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">License Plate</p>
                                    <p class="text-white font-medium">${v.license_plate}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Fuel Type</p>
                                    <p class="text-white font-medium">${v.fuel_type}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Transmission</p>
                                    <p class="text-white font-medium">${v.transmission}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Price per Day</p>
                                    <p class="text-white font-medium">₱${parseFloat(v.price_per_day).toFixed(2)}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Status</p>
                                    <p class="font-medium ${v.status === 'Available' ? 'text-emerald-500' : v.status === 'Rented' ? 'text-blue-500' : 'text-amber-500'}">${v.status}</p>
                                </div>
                            </div>
                            
                            <div class="bg-gray-800/50 p-3 rounded-lg">
                                <p class="text-gray-400 text-sm mb-2">Description</p>
                                <p class="text-white">${v.description || 'No description available.'}</p>
                            </div>
                        </div>
                    `;
                    document.getElementById('vehicleDetails').innerHTML = details;
                    document.getElementById('viewModal').classList.remove('hidden');
                }
            });
        }

        // Edit Vehicle
        function editVehicle(id) {
            fetch('vehicles.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const v = data.vehicle;
                    document.getElementById('modalTitle').textContent = 'Edit Vehicle';
                    document.getElementById('action').value = 'update';
                    document.getElementById('submitBtn').textContent = 'Update Vehicle';
                    document.getElementById('vehicleId').value = v.id;
                    document.getElementById('vehicleName').value = v.vehicle_name;
                    document.getElementById('category').value = v.category;
                    document.getElementById('licensePlate').value = v.license_plate;
                    document.getElementById('modelYear').value = v.model_year;
                    document.getElementById('fuelType').value = v.fuel_type;
                    document.getElementById('transmission').value = v.transmission;
                    document.getElementById('pricePerDay').value = v.price_per_day;
                    document.getElementById('status').value = v.status;
                    document.getElementById('description').value = v.description;
                    
                    // Show current image if exists
                    if (v.image_path !== 'default-car.png') {
                        const preview = document.getElementById('imagePreview');
                        const previewImg = preview.querySelector('img');
                        previewImg.src = '../uploads/vehicles/' + v.image_path;
                        preview.classList.remove('hidden');
                    } else {
                        document.getElementById('imagePreview').classList.add('hidden');
                    }
                    
                    document.getElementById('vehicleModal').classList.remove('hidden');
                }
            });
        }

        // Confirm Delete
        function confirmDelete(id) {
            currentDeleteId = id;
            document.getElementById('confirmMessage').textContent = 'Are you sure you want to delete this vehicle? This action cannot be undone.';
            document.getElementById('confirmModal').classList.remove('hidden');
            
            document.getElementById('confirmActionBtn').onclick = function() {
                deleteVehicle(currentDeleteId);
            };
        }

        // Delete Vehicle
        function deleteVehicle(id) {
            fetch('vehicles.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                closeConfirmModal();
                if (data.success) {
                    showSuccess(data.message);
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // Handle Form Submit
        document.getElementById('vehicleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('vehicles.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeModal();
                if (data.success) {
                    showSuccess(data.message);
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });

        // Filter Vehicles
        function filterVehicles() {
            const statusFilter = document.getElementById('statusFilter').value;
            const categoryFilter = document.getElementById('categoryFilter').value;
            const searchTerm = document.getElementById('searchInput')?.value.toLowerCase() || '';
            
            const rows = document.querySelectorAll('.vehicle-row');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const status = row.dataset.status;
                const category = row.dataset.category;
                const name = row.dataset.name;
                const plate = row.dataset.plate;
                
                const statusMatch = statusFilter === 'all' || status === statusFilter;
                const categoryMatch = categoryFilter === 'all' || category === categoryFilter;
                const searchMatch = searchTerm === '' || name.includes(searchTerm) || plate.includes(searchTerm);
                
                if (statusMatch && categoryMatch && searchMatch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show "no results" message if needed
            const tableBody = document.getElementById('vehicleTableBody');
            const noResultsRow = document.getElementById('noResultsRow');
            
            if (visibleCount === 0) {
                if (!noResultsRow) {
                    const newRow = document.createElement('tr');
                    newRow.id = 'noResultsRow';
                    newRow.innerHTML = '<td colspan="8" class="px-5 py-8 text-center text-gray-500"><i class="fas fa-search text-4xl mb-2 opacity-50"></i><p>No vehicles match your filters</p></td>';
                    tableBody.appendChild(newRow);
                }
            } else if (noResultsRow) {
                noResultsRow.remove();
            }
        }

        function filterByStatus(status) {
            document.getElementById('statusFilter').value = status;
            filterVehicles();
        }

        function filterByCategory(category) {
            document.getElementById('categoryFilter').value = category;
            filterVehicles();
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeViewModal();
                closeConfirmModal();
                closeSuccessModal();
            }
        });
    </script>
</body>
</html>