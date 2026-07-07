<?php
$current_page = basename($_SERVER['PHP_SELF'], '.php');
if (empty($current_page)) $current_page = 'index';
$base = (!empty($admin_base)) ? '../' : '';

// Page hrefs
$dashboard_href = (!empty($admin_base)) ? '../index.php' : 'index.php';
$vehicles_href = (!empty($admin_base)) ? 'vehicles.php' : 'admin/vehicles.php';
$reservations_href = (!empty($admin_base)) ? 'reservations.php' : 'admin/reservations.php';
$rentals_href = (!empty($admin_base)) ? 'rentals.php' : 'admin/rentals.php';
$customers_href = (!empty($admin_base)) ? 'customers.php' : 'admin/customers.php';
$payments_href = (!empty($admin_base)) ? 'payments.php' : 'admin/payments.php';
$maintenance_href = (!empty($admin_base)) ? 'maintenance.php' : 'admin/maintenance.php';
$reports_href = (!empty($admin_base)) ? 'reports.php' : 'admin/reports.php';
$calendar_href = (!empty($admin_base)) ? 'calendar.php' : 'admin/calendar.php';
$settings_href = (!empty($admin_base)) ? 'settings.php' : 'admin/settings.php';
?>

<!-- Mobile Overlay -->
<div id="sidebar-overlay" class="fixed inset-0 bg-black/60 z-40 lg:hidden hidden backdrop-blur-sm transition-all duration-300"></div>

<!-- Sidebar -->
<aside id="sidebar" class="sidebar-glow fixed top-0 bottom-0 left-0 z-50 w-64 bg-gradient-to-b from-gray-900 via-gray-900 to-black border-r border-gray-700/50 shadow-2xl lg:shadow-none flex flex-col" style="height: 100vh; height: 100dvh;" aria-label="Main navigation">
  
  <!-- Logo Section -->
  <div class="sidebar-header-glow flex items-center gap-3 h-16 px-4 border-b border-gray-700/50 flex-shrink-0">
    <!-- Logo Icon - Red Accent with glow -->
    <div class="relative flex items-center justify-center w-10 h-10 rounded-xl bg-gradient-to-br from-red-600 to-red-700 text-white shadow-lg shadow-red-600/20">
      <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 14h8m-4-4V6m6 14h2a2 2 0 002-2v-2a2 2 0 00-2-2h-2.343M14 4h-4m-6 14H4a2 2 0 01-2-2v-2a2 2 0 012-2h2.343"/>
      </svg>
    </div>
    
    <!-- Logo Text -->
    <div class="flex-1">
      <div class="flex items-baseline gap-1">
        <span class="text-lg font-bold text-white">Velocity</span>
        <span class="text-xs font-semibold text-red-500">Rentals</span>
      </div>
      <div class="flex items-center gap-1">
        <div class="h-px flex-1 bg-gray-700"></div>
        <span class="text-[10px] font-medium text-gray-500 tracking-widest uppercase">Admin</span>
        <div class="h-px flex-1 bg-gray-700"></div>
      </div>
    </div>
  </div>

  <!-- Main Navigation -->
  <nav class="flex-1 overflow-y-auto overflow-x-hidden py-3 px-3 min-h-0 sidebar-nav">
    <div class="text-xs uppercase text-gray-500 font-semibold tracking-wider px-3 mb-3 flex items-center gap-2">
      <div class="h-px w-4 bg-gray-700"></div>
      Main Menu
      <div class="h-px flex-1 bg-gray-800"></div>
    </div>
    
    <ul class="space-y-1">
      <!-- Dashboard -->
      <li>
        <a href="<?php echo $dashboard_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $current_page === 'index' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
          <svg class="w-5 h-5 <?php echo $current_page === 'index' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
          </svg>
          <span>Dashboard</span>
        </a>
      </li>

      <!-- Vehicle Management -->
      <li>
        <a href="<?php echo $vehicles_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $current_page === 'vehicles' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
          <svg class="w-5 h-5 <?php echo $current_page === 'vehicles' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 14h8m-4-4V6m6 14h2a2 2 0 002-2v-2a2 2 0 00-2-2h-2.343M14 4h-4m-6 14H4a2 2 0 01-2-2v-2a2 2 0 012-2h2.343"/>
          </svg>
          <span>Vehicle Management</span>
        </a>
      </li>

      <!-- Reservations / Bookings -->
      <li>
        <a href="<?php echo $reservations_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $current_page === 'reservations' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
          <svg class="w-5 h-5 <?php echo $current_page === 'reservations' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
          <span>Reservations / Bookings</span>
        </a>
      </li>

      <!-- Rental Management -->
      <li>
        <a href="<?php echo $rentals_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $current_page === 'rentals' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
          <svg class="w-5 h-5 <?php echo $current_page === 'rentals' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
          </svg>
          <span>Rental Management</span>
        </a>
      </li>

      <!-- Customers / Clients -->
      <li>
        <a href="<?php echo $customers_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $current_page === 'customers' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
          <svg class="w-5 h-5 <?php echo $current_page === 'customers' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          <span>Customers / Clients</span>
        </a>
      </li>

      <!-- Payments & Billing -->
      <li>
        <a href="<?php echo $payments_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $current_page === 'payments' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
          <svg class="w-5 h-5 <?php echo $current_page === 'payments' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
          </svg>
          <span>Payments & Billing</span>
        </a>
      </li>

      <!-- Maintenance Management -->
      <li>
        <a href="<?php echo $maintenance_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $current_page === 'maintenance' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
          <svg class="w-5 h-5 <?php echo $current_page === 'maintenance' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          <span>Maintenance Management</span>
        </a>
      </li>

      <!-- Reports & Analytics -->
      <li>
        <a href="<?php echo $reports_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $current_page === 'reports' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
          <svg class="w-5 h-5 <?php echo $current_page === 'reports' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
          </svg>
          <span>Reports & Analytics</span>
        </a>
      </li>

      <!-- Calendar / Scheduling -->
      <li>
        <a href="<?php echo $calendar_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $current_page === 'calendar' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
          <svg class="w-5 h-5 <?php echo $current_page === 'calendar' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
          <span>Calendar / Scheduling</span>
        </a>
      </li>
    </ul>
  </nav>

  <!-- Bottom Section -->
  <div class="border-t border-gray-700/50 p-3 bottom-section">
    <!-- System Settings -->
    <a href="<?php echo $settings_href; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 mb-2 <?php echo $current_page === 'settings' ? 'bg-red-600/10 text-white border-l-3 border-red-500' : 'text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent'; ?>">
      <svg class="w-5 h-5 <?php echo $current_page === 'settings' ? 'text-red-500' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      <span>System Settings</span>
    </a>

    <!-- Logout -->
    <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 text-gray-400 hover:bg-gray-800 hover:text-white border-l-3 border-transparent">
      <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
      </svg>
      <span>Logout</span>
    </a>
  </div>
</aside>

<script>
// Sidebar functionality
(function() {
  var overlay = document.getElementById('sidebar-overlay');
  var sidebar = document.getElementById('sidebar');
  
  // Ensure sidebar uses full viewport height
  function setFullHeight() {
    if (sidebar) {
      sidebar.style.height = '100vh';
      if (CSS.supports('height', '100dvh')) {
        sidebar.style.height = '100dvh';
      }
    }
  }
  
  setFullHeight();
  
  window.addEventListener('resize', setFullHeight);
  window.addEventListener('orientationchange', setFullHeight);
  
  function openSidebar() {
    if (sidebar) sidebar.classList.remove('-translate-x-full');
    if (overlay) { 
      overlay.classList.remove('hidden'); 
      overlay.setAttribute('aria-hidden', 'false'); 
      document.body.style.overflow = 'hidden';
    }
  }
  
  function closeSidebar() {
    if (sidebar) sidebar.classList.add('-translate-x-full');
    if (overlay) { 
      overlay.classList.add('hidden'); 
      overlay.setAttribute('aria-hidden', 'true'); 
      document.body.style.overflow = '';
    }
  }
  
  overlay && overlay.addEventListener('click', closeSidebar);
  
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && sidebar && !sidebar.classList.contains('-translate-x-full')) {
      closeSidebar();
    }
  });
  
  window.toggleSidebar = function() {
    sidebar.classList.contains('-translate-x-full') ? openSidebar() : closeSidebar();
  };
})();
</script>

<style>
/* Global styles to ensure full height */
html, body {
  height: 100%;
  margin: 0;
  padding: 0;
  overflow-x: hidden;
}

/* Border left helper */
.border-l-3 {
  border-left-width: 3px;
}

/* Sidebar styles */
#sidebar {
  overflow: hidden;
  height: 100vh; /* Fallback */
  height: 100dvh; /* Modern browsers */
  max-height: 100vh;
  max-height: 100dvh;
  top: 0;
  bottom: 0;
  position: fixed;
  display: flex;
  flex-direction: column;
}

/* Navigation scrolling */
.sidebar-nav {
  scrollbar-width: thin;
  scrollbar-color: #374151 #1f2937;
  flex: 1 1 auto;
  overflow-y: auto;
  min-height: 0; /* Important for flex child scrolling */
}

/* Bottom section stays at bottom */
.bottom-section {
  flex-shrink: 0;
  margin-top: auto;
}

/* Custom scrollbar for webkit browsers */
.sidebar-nav::-webkit-scrollbar {
  width: 5px;
}

.sidebar-nav::-webkit-scrollbar-track {
  background: #1f2937;
}

.sidebar-nav::-webkit-scrollbar-thumb {
  background: #374151;
  border-radius: 3px;
}

.sidebar-nav::-webkit-scrollbar-thumb:hover {
  background: #4b5563;
}

/* Glow effects - Dark Mode */
.sidebar-glow {
  box-shadow:
    0 0 0 1px rgba(255, 255, 255, 0.05),
    0 14px 36px rgba(0, 0, 0, 0.45),
    0 0 26px rgba(255, 255, 255, 0.06);
}

.sidebar-header-glow {
  box-shadow:
    inset 0 -1px 0 rgba(255, 255, 255, 0.04),
    0 0 18px rgba(255, 255, 255, 0.05);
}

#sidebar nav a,
#sidebar > div:last-child a {
  box-shadow:
    0 0 0 1px rgba(255, 255, 255, 0.02),
    0 0 12px rgba(255, 255, 255, 0.03);
}

#sidebar nav a:hover,
#sidebar > div:last-child a:hover {
  box-shadow:
    0 0 0 1px rgba(220, 38, 38, 0.22),
    0 0 20px rgba(220, 38, 38, 0.12);
}

/* Ensure content doesn't get cut off on mobile */
@media (max-width: 1023px) {
  #sidebar {
    height: 100vh !important;
    transform: translateX(0);
    transition: transform 0.3s ease-in-out;
  }
  
  #sidebar.-translate-x-full {
    transform: translateX(-100%);
  }
}
</style>