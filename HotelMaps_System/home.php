<?php
// Privacy & Security: Ensure session cookies are secure and protected
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}
require_once 'db.php';

// Unique Session ID for client privacy & booking security
$currentSessionId = session_id();

// Check if user is logged in
$isLoggedIn = !empty($_SESSION['user_id']) && (($_SESSION['role'] ?? '') !== 'guest') && (strpos((string)$_SESSION['user_id'], 'guest_') !== 0);
$userName = $_SESSION['user_name'] ?? 'Guest Traveler';
$userEmail = $_SESSION['user_email'] ?? 'guest@comfortvue.ph';
$userRole = $_SESSION['role'] ?? 'guest';
$userPhone = $_SESSION['user_phone'] ?? '+63 917 555 4321';
$isVerified = false;
$isAdmin = ($userRole === 'admin') || (($_SESSION['user_email'] ?? '') === 'admin@comfortvue.ph');

if ($isLoggedIn && $pdo && !empty($userEmail)) {
    try {
        $stmt = $pdo->prepare("SELECT is_verified, phone, role FROM users WHERE email = ?");
        $stmt->execute([$userEmail]);
        $uRow = $stmt->fetch();
        if ($uRow) {
            $isVerified = (bool)($uRow['is_verified'] == 1);
            $_SESSION['is_verified'] = $isVerified ? 1 : 0;
            if (!empty($uRow['role'])) {
                $userRole = $uRow['role'];
                $_SESSION['role'] = $userRole;
            }
            if ($userRole === 'admin') {
                $isAdmin = true;
            }
            if (!empty($uRow['phone'])) {
                $userPhone = $uRow['phone'];
            }
        }
    } catch (\Exception $e) {
        $isVerified = !empty($_SESSION['is_verified']);
    }
} else {
    $isVerified = !empty($_SESSION['is_verified']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="StayFinder PH - Find motels, Airbnbs, and short-term rentals in Metro Manila.">
  <title>StayFinder PH - Metro Manila Motels, AirBnBs &amp; Rentals</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <!-- Leaflet CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <!-- Google Fonts: Plus Jakarta Sans -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Lora:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/styles.css">
  <!-- Pannellum 360° Viewer -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.css">
  
  <!-- JS Libraries: Vue 3 (local), Bootstrap 5, Leaflet, Axios (local) -->
  <script src="js/vendor/vue.global.prod.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="js/vendor/axios.min.js"></script>
  <!-- Pannellum JS (must load after DOM is available) -->
  <script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.js"></script>
</head>
<body>
<div id="app" v-cloak>
  <!-- Top Navigation Header -->
  <header class="app-header">
    <div class="container-fluid px-3 px-md-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
      <a href="index.php" class="d-flex align-items-center gap-3 text-decoration-none">
        <div class="brand-logo-badge"><i class="bi bi-compass"></i></div>
        <div>
          <div class="d-flex align-items-center gap-2">
            <span class="fs-5 fw-bold tracking-tight" style="color: var(--mocha);">StayFinder PH</span>
            <span class="badge" style="background: rgba(200,113,74,0.12); color: var(--terracotta); border: 1px solid rgba(200,113,74,0.3); font-size: 0.68rem;">
              <i class="bi bi-patch-check-fill me-1"></i>Metro Manila
            </span>
          </div>
          <div style="font-size: 0.72rem; color: var(--text-muted);">Motels • Condo Staycations • Short-Term Rentals</div>
        </div>
      </a>

      <!-- Navigation & Action Controls -->
      <div class="d-flex align-items-center flex-wrap gap-2">
        <a href="index.php" class="btn-nav-pill text-decoration-none"><i class="bi bi-house-door"></i> Home</a>
        <button class="btn-nav-pill active"><i class="bi bi-map"></i> Find a Stay</button>
        <button class="btn-nav-pill" @click="openBookingsModal">
          <i class="bi bi-journal-check"></i> My Bookings
          <span class="badge rounded-pill ms-1" style="background:var(--sage); font-size: 0.65rem;">{{ userBookings.length }} Active</span>
        </button>

        <!-- Account Verification Badge / Action Button -->
        <template v-if="accountVerified">
          <span class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-30 px-3 py-2 rounded-pill d-inline-flex align-items-center gap-1" style="font-size:0.75rem;">
            <i class="bi bi-patch-check-fill text-success"></i> Account Verified
          </span>
        </template>
        <template v-else>
          <button class="btn btn-sm btn-outline-warning rounded-pill px-3 py-1 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" @click="openVerifyModal" title="Verify your actual account">
            <i class="bi bi-shield-exclamation text-warning"></i> Verify Account
          </button>
        </template>

        <!-- User Dropdown Menu -->
        <div class="dropdown">
          <button class="btn-nav-pill dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle text-info"></i> <?= htmlspecialchars($userName) ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow border border-secondary border-opacity-50">
            <li>
              <div class="px-3 py-2">
                <span class="fw-bold text-white d-block"><?= htmlspecialchars($userName) ?></span>
                <span class="small text-secondary d-block"><?= htmlspecialchars($userEmail) ?></span>
                <div class="mt-2">
                  <span v-if="accountVerified" class="badge bg-success" style="font-size:0.65rem;">
                    <i class="bi bi-patch-check-fill me-1"></i>Verified Traveler
                  </span>
                  <span v-else class="badge bg-warning text-dark" style="font-size:0.65rem;">
                    <i class="bi bi-exclamation-triangle me-1"></i>Unverified Account
                  </span>
                </div>
              </div>
            </li>
            <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
            <li v-if="!accountVerified">
              <a class="dropdown-item text-warning" href="javascript:void(0)" @click="openVerifyModal">
                <i class="bi bi-shield-check me-2"></i>Verify Account with 6-Digit PIN
              </a>
            </li>
            <li>
              <a class="dropdown-item text-light" href="javascript:void(0)" @click="openBookingsModal">
                <i class="bi bi-journal-text me-2"></i>View My Reservations ({{ userBookings.length }})
              </a>
            </li>
            <?php if ($userRole === 'guest'): ?>
              <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
              <li><a class="dropdown-item text-info" href="auth.php?action=register"><i class="bi bi-person-plus me-2"></i>Create Real Account</a></li>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
              <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
              <li><a class="dropdown-item text-warning" href="admin.php"><i class="bi bi-shield-lock me-2"></i>Admin Console</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
          </ul>
        </div>
      </div>
    </div>
  </header>

  <!-- Streamlined Filter & Search Bar -->
  <section class="filter-bar">
    <div class="container-fluid px-3 px-md-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <!-- Search Input -->
        <div class="position-relative flex-grow-1" style="min-width: 220px; max-width: 320px;">
          <i class="bi bi-search position-absolute top-50 translate-middle-y text-secondary ms-3"></i>
          <input type="text" v-model="searchQuery" class="form-control search-input-box" placeholder="Search hotels, branch, suites...">
        </div>

        <!-- Category Filter Pills -->
        <div class="d-flex flex-wrap align-items-center gap-1">
          <button class="pill-filter-btn" :class="{ active: selectedCategory === 'all' }" @click="selectedCategory = 'all'">
            <i class="bi bi-grid-fill"></i> All <span class="badge bg-secondary ms-1">{{ stays.length }}</span>
          </button>
          <button class="pill-filter-btn" :class="{ active: selectedCategory === 'motel' }" @click="selectedCategory = 'motel'">
            <i class="bi bi-car-front-fill" style="color: var(--accent-purple);"></i> Motels 🚗 <span class="badge bg-secondary ms-1">{{ countByCategory('motel') }}</span>
          </button>
          <button class="pill-filter-btn" :class="{ active: selectedCategory === 'airbnb' }" @click="selectedCategory = 'airbnb'">
            <i class="bi bi-key-fill text-info"></i> AirBnB/Staycations 🔑 <span class="badge bg-secondary ms-1">{{ countByCategory('airbnb') }}</span>
          </button>
          <button class="pill-filter-btn" :class="{ active: selectedCategory === 'rental' }" @click="selectedCategory = 'rental'">
            <i class="bi bi-house-door-fill text-success"></i> Longer Rentals 🏠 <span class="badge bg-secondary ms-1">{{ countByCategory('rental') }}</span>
          </button>
        </div>

        <!-- Quick Filters: Quick Locate Destination, District Select, Open Suites Toggle, Reset -->
        <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
          <!-- Quick Destination Selector -->
          <div class="d-flex align-items-center gap-1">
            <i class="bi bi-crosshair2 text-info small"></i>
            <select v-model="selectedBranchId" class="form-select form-select-sm bg-dark text-light border-secondary" style="width: auto; max-width: 190px; font-size: 0.8rem;" @change="onBranchChange" title="Quick locate and zoom to any hotel destination">
              <option value="all">★ Locate Destination...</option>
              <option v-for="h in stays" :key="h.id" :value="h.id">{{ h.title }} ({{ h.branch_name }})</option>
            </select>
          </div>

          <!-- District Dropdown -->
          <div class="d-flex align-items-center gap-1">
            <i class="bi bi-geo-alt-fill text-danger small"></i>
            <select v-model="selectedDistrict" class="form-select form-select-sm bg-dark text-light border-secondary" style="width: auto; min-width: 150px; font-size: 0.8rem;">
              <option value="all">All Locations ({{ stays.length }})</option>
              <option v-for="dist in districtsList" :key="dist" :value="dist">
                {{ dist }} ({{ countByDistrict(dist) }})
              </option>
            </select>
          </div>

          <!-- Open Suites Only Toggle -->
          <button class="pill-filter-btn btn-sm" :class="{ active: onlyAvailableRooms }" @click="onlyAvailableRooms = !onlyAvailableRooms" title="Show branches with open room availability only">
            <i class="bi bi-door-open-fill text-success"></i> Available Only
          </button>

          <!-- Reset Filter -->
          <button class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-1" @click="resetFilters" title="Reset All Filters">
            <i class="bi bi-arrow-counterclockwise"></i> Reset
          </button>
        </div>
      </div>
    </div>
  </section>

  <!-- Main View Split (Cards Pane on Left, Portable Leaflet Map on Right) -->
  <main class="main-workspace">
    <div class="container-fluid h-100 p-0">
      <div class="row g-0 h-100 flex-column flex-lg-row flex-lg-nowrap">
        
        <!-- Cards List Column -->
        <div class="col-12 col-lg-5 col-xl-5 cards-pane">
          <div class="d-flex align-items-center justify-content-between mb-3 px-1">
            <div class="d-flex align-items-center gap-2">
              <span class="fw-bold text-white fs-6">Metro Manila Accommodations</span>
              <span class="badge rounded-pill bg-primary px-2 py-1">{{ filteredStays.length }}</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="text-secondary small d-none d-sm-inline"><i class="bi bi-arrow-down-up me-1"></i>Sort:</span>
              <select v-model="sortBy" class="form-select form-select-sm bg-dark text-light border-secondary" style="width: auto; font-size: 0.82rem;">
                <option value="featured">Featured / Best Match</option>
                <option value="rating">Rating: High to Low</option>
                <option value="views">Most Popular (Views)</option>
                <option value="bookings">Most Booked</option>
                <option value="price-asc">Price: Low to High</option>
                <option value="price-desc">Price: High to Low</option>
              </select>
            </div>
          </div>

          <!-- Hotel Cards Stack -->
          <div class="d-flex flex-column gap-3 pb-5">
            <div v-for="stay in filteredStays" :key="stay.id" class="stay-card" :class="{ 'active-selected': selectedStay?.id === stay.id }" @click="selectHotelCard(stay)">
              <div class="row g-0">
                <!-- Hotel Image -->
                <div class="col-12 col-sm-5 position-relative">
                  <div class="stay-card-img-wrapper h-100" style="min-height: 180px;">
                    <img :src="stay.image" :alt="stay.title" class="stay-card-img">
                    <span class="badge-category-pill" :class="'badge-' + stay.category">{{ stay.categoryDisplay }}</span>
                    <span class="badge-rating-pill"><i class="bi bi-star-fill text-warning"></i> {{ stay.rating }}</span>
                    <span class="badge-availability" :class="stay.statusBadgeClass"><i class="bi bi-dot fs-6"></i> {{ stay.statusText }}</span>
                    <span v-if="stay.instant" class="badge-instant">Verified</span>
                  </div>
                </div>

                <!-- Hotel Info Body -->
                <div class="col-12 col-sm-7 p-3 d-flex flex-column justify-content-between">
                  <div>
                    <div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-1">
                      <h6 class="text-white fw-bold mb-0 lh-sm fs-6">{{ stay.title }}</h6>
                      <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.7rem;">
                        <i class="bi bi-geo-alt-fill me-1"></i>{{ stay.branch_name }}
                      </span>
                    </div>

                    <div class="d-flex align-items-center gap-2 mb-1">
                      <span class="text-info fw-semibold" style="font-size: 0.72rem;">{{ stay.brand }}</span>
                      <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-30" style="font-size: 0.65rem;">{{ stay.city }}</span>
                    </div>
                    
                    <p class="text-secondary small mb-1 d-flex align-items-center gap-1" style="font-size: 0.76rem;">
                      <i class="bi bi-geo-alt text-danger"></i> {{ stay.address }}
                    </p>

                    <!-- Live Availability Badge -->
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <span :class="stay.availableRoomsCount > 0 ? 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25' : 'badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25'" style="font-size: 0.72rem;">
                        <i class="bi" :class="stay.availableRoomsCount > 0 ? 'bi-door-open-fill' : 'bi-door-closed-fill'"></i>
                        {{ stay.availableRoomsCount }} of {{ stay.rooms?.length || 0 }} Suites Available
                      </span>
                    </div>

                    <!-- Amenities tags -->
                    <div class="d-flex flex-wrap gap-1 mb-2">
                      <span v-for="tag in (stay.tags || []).slice(0, 3)" :key="tag" class="stay-tag">{{ tag }}</span>
                    </div>
                  </div>

                  <!-- Rate & Action Buttons -->
                  <div class="pt-2 border-top border-secondary border-opacity-25">
                    <div class="d-flex align-items-center justify-content-between">
                      <div>
                        <span class="text-secondary small d-block" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px;">Starting From</span>
                        <div class="text-white fw-bold">
                          <span class="fs-5 text-white">₱{{ Number(stay.price).toLocaleString() }}</span>
                          <span class="text-secondary small"> / {{ stay.durationLabel }}</span>
                        </div>
                      </div>
                      <div class="d-flex gap-2">
                        <button class="btn btn-outline-info btn-sm px-2.5 d-flex align-items-center gap-1" @click.stop="locateDestination(stay)" title="Locate & Zoom to Destination on Map">
                          <i class="bi bi-crosshair2 text-info"></i> Locate
                        </button>
                        <button class="btn btn-primary btn-sm px-3 fw-semibold d-flex align-items-center gap-1 shadow-sm" @click.stop="openRoomDetailsModal(stay)">
                          <i class="bi bi-door-open-fill"></i> Suites & Book
                        </button>
                        <button class="btn btn-outline-secondary btn-sm px-2" @click.stop="startDirections(stay)" title="Show route on map">
                          <i class="bi bi-sign-turn-right-fill text-info"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Empty State -->
            <div v-if="filteredStays.length === 0" class="text-center py-5">
              <i class="bi bi-search text-secondary display-4 d-block mb-3"></i>
              <h5 class="text-white fw-bold">No hotels found</h5>
              <p class="text-secondary small mb-3">Try clearing search filters or selecting All Hotels</p>
              <button class="btn btn-sm btn-primary rounded-pill px-4" @click="resetFilters">Reset Filters</button>
            </div>
          </div>
        </div>

        <!-- Leaflet Portable Map Column -->
        <div class="col-12 col-lg-7 col-xl-7 map-pane-container">
          <div id="leafletMap"></div>

          <!-- Multi-Layer Switching Tools & GPS Location Control -->
          <div class="map-overlay-layers">
            <button class="map-layer-btn" :class="{ active: currentTileLayer === 'street' }" @click="switchMapLayer('street')">
              <i class="bi bi-map me-1"></i> Street
            </button>
            <button class="map-layer-btn" :class="{ active: currentTileLayer === 'satellite' }" @click="switchMapLayer('satellite')">
              <i class="bi bi-globe-americas me-1"></i> Satellite
            </button>
            <button class="map-layer-btn" :class="{ active: currentTileLayer === 'dark' }" @click="switchMapLayer('dark')">
              <i class="bi bi-moon-stars me-1"></i> Dark Map
            </button>
            <div class="vr bg-secondary opacity-50 my-1"></div>
            <!-- GPS User Geolocation Button -->
            <button class="map-layer-btn" :class="{ 'btn-locating': isLocatingUser }" @click="locateUserPosition" title="Detect GPS Location & Route from My Location">
              <i class="bi bi-crosshair me-1" :class="{ 'spin-icon': isLocatingUser }"></i>
              <span class="d-none d-sm-inline">{{ isLocatingUser ? 'Locating...' : 'Locate Me' }}</span>
              <span class="d-sm-none">GPS</span>
            </button>
          </div>

          <!-- Live Routing Card -->
          <div v-if="directionsActive && directionsTarget" class="directions-info-card">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <span class="badge bg-primary text-uppercase px-2 py-1 mb-1">Route Calculated</span>
                <h6 class="text-white fw-bold mb-0">To: {{ directionsTarget.title }}</h6>
                <div class="text-secondary small" style="font-size:0.75rem;">
                  <i class="bi bi-geo-alt-fill text-danger me-1"></i>{{ directionsTarget.address }}
                </div>
              </div>
              <button class="btn-close btn-close-white" @click="clearDirections"></button>
            </div>
            <div class="d-flex align-items-center gap-2 text-secondary small mb-2 flex-wrap">
              <span><i class="bi bi-pin-map text-danger me-1"></i><strong>{{ directionsDistance }} km</strong></span>
              <span>•</span>
              <span><i class="bi bi-speedometer2 text-info me-1"></i>~<strong>{{ directionsDuration }} mins</strong> travel</span>
              <span v-if="userLocation" class="badge bg-info bg-opacity-20 text-info border border-info border-opacity-30 ms-auto">
                <i class="bi bi-crosshair me-1"></i>From Your GPS
              </span>
              <span v-else class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-30 ms-auto">
                From NAIA T3 Airport
              </span>
            </div>
            <!-- Route Navigation Zoom Controls -->
            <div class="d-flex align-items-center gap-2 pt-2 border-top border-secondary border-opacity-30 flex-wrap">
              <button class="btn btn-xs btn-primary rounded-pill py-1 px-3 fw-semibold d-flex align-items-center gap-1" @click="zoomToDestination(directionsTarget)">
                <i class="bi bi-geo-alt-fill"></i> Zoom Destination
              </button>
              <button class="btn btn-xs btn-outline-light rounded-pill py-1 px-3 d-flex align-items-center gap-1" @click="zoomToOrigin">
                <i class="bi bi-crosshair"></i> Zoom Start
              </button>
              <button class="btn btn-xs btn-outline-secondary rounded-pill py-1 px-3 d-flex align-items-center gap-1 ms-auto" @click="fitRouteBounds">
                <i class="bi bi-arrows-fullscreen"></i> Full Route
              </button>
            </div>
          </div>

          <!-- Map Legend Bar -->
          <div class="map-legend-bar">
            <button class="btn btn-sm btn-outline-light rounded-pill px-2 py-0 border-0" @click="fitAllPins" title="Fit all pins in view">
              <i class="bi bi-fullscreen me-1 text-primary"></i> <span style="font-size: 0.75rem;">Fit Pins</span>
            </button>
            <div class="vr bg-secondary opacity-50 my-1"></div>
            <div class="d-flex align-items-center gap-1 small text-light" style="font-size: 0.75rem;">
              <span class="rounded-circle d-inline-block" style="width: 9px; height: 9px; background: #E27D60;"></span> Motels
            </div>
            <div class="d-flex align-items-center gap-1 small text-light" style="font-size: 0.75rem;">
              <span class="rounded-circle d-inline-block" style="width: 9px; height: 9px; background: #38bdf8;"></span> AirBnB
            </div>
            <div class="d-flex align-items-center gap-1 small text-light" style="font-size: 0.75rem;">
              <span class="rounded-circle d-inline-block" style="width: 9px; height: 9px; background: #8FBC8F;"></span> Rentals
            </div>
          </div>
        </div>

      </div>
    </div>
  </main>

  <!-- ============================================== -->
  <!-- MODAL 1: HOTEL ROOM DETAILS MODAL PER HOTEL    -->
  <!-- ============================================== -->
  <div class="modal fade" id="roomDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
      <div class="modal-content modal-content-dark">
        <div class="modal-header border-bottom border-secondary border-opacity-25" v-if="roomDetailsHotel">
          <div>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
              <span class="badge" :class="'badge-' + roomDetailsHotel.category">{{ roomDetailsHotel.categoryDisplay }}</span>
              <span class="badge" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4);"><i class="bi bi-geo-alt-fill me-1"></i>{{ roomDetailsHotel.branch_name }}</span>
              <span class="text-warning small fw-bold"><i class="bi bi-star-fill"></i> {{ roomDetailsHotel.rating }}</span>
              <span class="text-secondary small">• {{ roomDetailsHotel.city }}</span>
            </div>
            <h4 class="modal-title fw-bold text-white">{{ roomDetailsHotel.title }}</h4>
            <div class="text-secondary small d-flex align-items-center gap-2 flex-wrap">
              <span><i class="bi bi-geo-alt-fill text-danger me-1"></i>{{ roomDetailsHotel.address }}</span>
              <span>•</span>
              <span class="font-monospace text-info small"><i class="bi bi-crosshair me-1"></i>{{ Number(roomDetailsHotel.lat).toFixed(6) }}, {{ Number(roomDetailsHotel.lng).toFixed(6) }}</span>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1" @click="locateFromModal(roomDetailsHotel)" title="Close modal and zoom to this hotel on map">
              <i class="bi bi-crosshair2 me-1"></i> Locate on Map
            </button>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" @click="destroyPannellum"></button>
          </div>
        </div>
        <div class="modal-body" v-if="roomDetailsHotel">
          
          <!-- 360° Virtual Tour Section (Tabbed: CSS-pan | Pannellum) -->
          <div v-if="roomDetailsHotel.showroom && roomDetailsHotel.showroom.rooms" class="mb-4">
            <!-- Section header with mode toggle -->
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="fw-bold mb-0 d-flex align-items-center gap-2" style="color:var(--mocha)">
                <i class="bi bi-badge-3d" style="color:var(--terracotta)"></i> 360° Virtual Tour
              </h6>
              <div class="d-flex gap-1">
                <button class="pano-room-btn" :class="{ active: !pannellumActive }" @click.stop="destroyPannellum()">
                  <i class="bi bi-image me-1"></i>Preview
                </button>
                <button class="pano-room-btn" :class="{ active: pannellumActive }" @click.stop="initPannellum('living_room')">
                  <i class="bi bi-vr me-1"></i>Full 360°
                </button>
              </div>
            </div>

            <!-- TAB A: CSS drag-pan preview (fast, no CDN) -->
            <div v-if="!pannellumActive" class="showroom-viewer-wrapper"
                 @mousedown="startPanoDrag"
                 @mousemove="onPanoDrag"
                 @mouseup="endPanoDrag"
                 @mouseleave="endPanoDrag"
                 @touchstart="startPanoDrag"
                 @touchmove="onPanoDrag"
                 @touchend="endPanoDrag">

              <!-- Pano Canvas -->
              <img :src="currentPanoRoom.image_360" class="showroom-pano-canvas" :class="{ loading: panoLoading }"
                   :style="{ transform: 'scale(1.2) translateX(' + panoPanX + 'px)' }" draggable="false">

              <!-- Room switcher buttons -->
              <div class="pano-controls">
                <button v-for="(rm, key) in roomDetailsHotel.showroom.rooms" :key="key"
                        class="pano-room-btn" :class="{ active: currentPanoKey === key }"
                        @click.stop="switchPanoRoom(key)">
                  {{ rm.title }}
                </button>
              </div>

              <!-- Hotspots -->
              <div v-for="(spot, idx) in currentPanoRoom.hotspots" :key="'hs'+idx"
                   class="pano-hotspot" :style="{ left: spot.x + '%', top: spot.y + '%' }"
                   @click.stop="switchPanoRoom(spot.target)">
                <div class="pano-hotspot-icon"><i class="bi bi-chevron-double-up"></i></div>
                <div class="pano-hotspot-label">{{ spot.label }}</div>
              </div>

              <div class="pano-hint" :class="{ hidden: panoPanX !== 0 || panoLoading }">
                <i class="bi bi-arrows-move me-1"></i> Drag to look around · Click a hotspot to move rooms
              </div>
              <div class="pano-loading" :class="{ done: !panoLoading }">
                <div class="pano-spinner"></div>
              </div>
              <div class="pano-room-label">{{ currentPanoRoom.title }}</div>
            </div>

            <!-- TAB B: Full Pannellum 360 viewer (real spherical, hotspot nav, synced with Leaflet) -->
            <div v-if="pannellumActive">
              <!-- Scene switcher row - LEAFLET→PANNELLUM entry point -->
              <div class="d-flex gap-2 mb-2 flex-wrap">
                <button v-for="(cfg, sceneId) in PANNELLUM_SCENES" :key="sceneId"
                        class="pano-room-btn" :class="{ active: pannellumCurrentScene === sceneId }"
                        @click.stop="switchPannellumScene(sceneId)">
                  <i class="bi bi-compass me-1"></i>{{ cfg.title }}
                </button>
              </div>
              <!-- Pannellum mounts here via initPannellum() -->
              <div id="pannellum-container" style="width:100%;height:420px;border-radius:var(--r-lg);overflow:hidden;border:2px solid var(--border);"></div>
              <div class="d-flex align-items-center justify-content-between mt-2">
                <span class="small" style="color:var(--text-muted)">
                  <i class="bi bi-info-circle me-1"></i>
                  Click the <strong>arrow hotspots</strong> inside the viewer to walk between rooms. The map updates automatically.
                </span>
                <span class="badge" style="background:rgba(200,113,74,0.12);color:var(--terracotta);border:1px solid rgba(200,113,74,0.3);">
                  <i class="bi bi-link-45deg me-1"></i>Synced with Map
                </span>
              </div>
            </div>
          </div>

          <!-- Room list grid -->
          <div class="mb-3 d-flex justify-content-between align-items-center">
            <h6 class="text-white fw-bold mb-0" style="color:var(--mocha)!important">
              <i class="bi bi-door-open-fill text-primary me-2"></i>Available Suites &amp; Rooms
            </h6>
            <span class="text-secondary small">Live room status tracked in real-time</span>
          </div>

          <div class="row g-3">
            <div v-for="room in roomDetailsHotel.rooms" :key="room.id" class="col-12 col-md-6 col-lg-6">
              <div class="room-detail-card h-100 d-flex flex-column">
                <div class="room-detail-img-wrapper">
                  <img :src="room.image" :alt="room.room_name" class="room-detail-img">
                  <span class="badge bg-dark bg-opacity-75 text-light position-absolute top-0 start-0 m-2 border border-secondary border-opacity-50">
                    {{ room.room_type }}
                  </span>
                  <!-- Current Room Status Badge -->
                  <span class="badge position-absolute top-0 end-0 m-2" :class="getRoomStatusBadgeClass(room.status)">
                    <i class="bi me-1" :class="getRoomStatusIcon(room.status)"></i>{{ room.status }}
                  </span>
                </div>
                <div class="p-3 d-flex flex-column flex-grow-1 justify-content-between">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-1 flex-wrap">
                      <h5 class="text-white fw-bold mb-0 fs-6">{{ room.room_name }}</h5>
                      <span v-if="room.room_number" class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-50 font-monospace px-2 py-1" style="font-size:0.75rem;">
                        <i class="bi bi-door-closed-fill me-1"></i>{{ room.room_number }}
                      </span>
                    </div>
                    
                    <!-- Specifications -->
                    <div class="d-flex align-items-center gap-2 text-secondary small mb-2 flex-wrap" style="font-size:0.75rem;">
                      <span><i class="bi bi-aspect-ratio text-info me-1"></i>{{ room.size_sqm }} sqm</span>
                      <span>•</span>
                      <span><i class="bi bi-hdd-stack text-warning me-1"></i>{{ room.bed_type }}</span>
                      <span>•</span>
                      <span><i class="bi bi-people text-success me-1"></i>{{ room.capacity }}</span>
                    </div>

                    <!-- Amenities summary -->
                    <p class="text-white-50 small mb-3" style="font-size:0.76rem; line-height:1.4;">
                      <i class="bi bi-check-circle text-success me-1"></i>{{ room.amenities }}
                    </p>
                  </div>

                  <div class="pt-2 border-top border-secondary border-opacity-25 d-flex align-items-center justify-content-between">
                    <div>
                      <div class="text-secondary" style="font-size:0.65rem; font-weight:700;">NIGHTLY RATE</div>
                      <div class="text-white fw-bold fs-5">₱{{ Number(room.price).toLocaleString() }}</div>
                      <div class="text-secondary" style="font-size:0.68rem;"><i class="bi bi-bag-check me-1 text-info"></i>{{ room.total_booked }} booked</div>
                    </div>
                    
                    <!-- Action Button Depending on Current Room Status -->
                    <template v-if="room.status === 'Available'">
                      <button class="btn btn-primary px-3 py-2 fw-semibold rounded-3 shadow-sm" @click="bookSpecificRoom(room)">
                        <i class="bi bi-calendar-check me-1"></i> Reserve Suite
                      </button>
                    </template>
                    <template v-else>
                      <button class="btn btn-outline-secondary px-3 py-2 fw-semibold rounded-3" disabled title="This room suite is currently not available">
                        <i class="bi bi-slash-circle me-1"></i> {{ room.status }}
                      </button>
                    </template>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

  <!-- ============================================== -->
  <!-- MODAL 2: RESERVATION BOOKING MODAL             -->
  <!-- ============================================== -->
  <div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content modal-content-dark">
        <div class="modal-header">
          <div>
            <h5 class="modal-title d-flex align-items-center gap-2">
              <i class="bi bi-calendar2-check" style="color:var(--terracotta)"></i> Make a Reservation
            </h5>
            <div class="small" style="color:var(--text-muted)" v-if="bookingTarget">
              <i class="bi bi-geo-alt-fill me-1" style="color:var(--terracotta)"></i>{{ bookingTarget.title }} — {{ bookingTarget.branch_name }}
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body" v-if="bookingTarget">
          
          <!-- STEP 1: FORM ENTRY -->
          <div v-if="bookingStep === 'form'">
            <form @submit.prevent="proceedToReview">
              
              <!-- Guest Information -->
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label small text-secondary d-flex justify-content-between">
                    <span>Guest Full Name</span>
                    <span v-if="bookingErrors.guestName" class="text-danger small">{{ bookingErrors.guestName }}</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text modal-input border-end-0 text-secondary"><i class="bi bi-person"></i></span>
                    <input type="text" v-model="bookingForm.guestName" class="form-control modal-input border-start-0" required>
                  </div>
                </div>

                <div class="col-md-6">
                  <label class="form-label small text-secondary d-flex justify-content-between">
                    <span>Mobile Contact (Philippines)</span>
                    <span v-if="bookingErrors.guestPhone" class="text-danger small">{{ bookingErrors.guestPhone }}</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text modal-input border-end-0 text-secondary"><i class="bi bi-telephone"></i></span>
                    <input type="tel" v-model="bookingForm.guestPhone" class="form-control modal-input border-start-0" placeholder="+63 9XX XXX XXXX" required>
                  </div>
                </div>
              </div>

              <!-- Suite Selection & Stay Type -->
              <div class="row g-3 mb-3">
                <div class="col-md-7">
                  <label class="form-label small" style="color:var(--text-secondary)">Select Room / Unit</label>
                  <select v-model="bookingForm.selectedRoomId" class="form-select modal-input" @change="onRoomSelectChange">
                    <option v-for="rm in (bookingTarget.rooms || [])" :key="rm.id" :value="rm.id" :disabled="rm.status !== 'Available'">
                      {{ rm.room_number ? '[' + rm.room_number + '] ' : '' }}{{ rm.room_name }} — ₱{{ Number(rm.price).toLocaleString() }}/{{ rm.rate_type || 'night' }} ({{ rm.bed_type }}) [{{ rm.status }}]
                    </option>
                  </select>
                </div>
                <div class="col-md-5">
                  <label class="form-label small" style="color:var(--text-secondary)">Duration</label>
                  <select v-model.number="bookingForm.nights" class="form-select modal-input">
                    <option :value="1">1 Night / Stay</option>
                    <option :value="2">2 Nights (Weekend)</option>
                    <option :value="3">3 Nights</option>
                    <option :value="5">5 Nights</option>
                    <option :value="7">7 Nights / Weekly Rate</option>
                    <option :value="30">30 Days / Monthly Rate</option>
                  </select>
                </div>
              </div>

              <!-- Check-In Date and Time -->
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label small text-secondary d-flex justify-content-between">
                    <span>Target Check-In Date & Time</span>
                    <span v-if="bookingErrors.checkInTime" class="text-danger small">{{ bookingErrors.checkInTime }}</span>
                  </label>
                  <input type="datetime-local" v-model="bookingForm.checkInTime" class="form-control modal-input" :min="minDateTime" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label small" style="color:var(--text-secondary)">Stay Type</label>
                  <select v-model="bookingForm.stayDurationType" class="form-select modal-input">
                    <optgroup label="Motel Rates">
                      <option value="3-Hour Wash Rate">3-Hour Wash Rate</option>
                      <option value="6-Hour Mid Stay">6-Hour Mid Stay</option>
                      <option value="12-Hour Layover">12-Hour Layover</option>
                      <option value="Overnight Stay">Overnight Stay</option>
                    </optgroup>
                    <optgroup label="Condo / AirBnB">
                      <option value="1-Night Staycation">1-Night Staycation</option>
                      <option value="Weekend Staycation">Weekend Staycation (2 Nights)</option>
                    </optgroup>
                    <optgroup label="Apartment / Rental">
                      <option value="Weekly Transient">Weekly Transient</option>
                      <option value="Monthly Lease">Monthly Lease</option>
                    </optgroup>
                  </select>
                </div>
              </div>

              <!-- Price Calculation Summary Box -->
              <div class="booking-summary-box mb-4">
                <div class="d-flex justify-content-between small mb-1" style="color:var(--text-secondary)">
                  <span>Base Rate:</span>
                  <span style="color:var(--mocha);font-weight:600">₱{{ Number(selectedRoomPrice).toLocaleString() }}</span>
                </div>
                <div class="d-flex justify-content-between small mb-1" style="color:var(--text-secondary)">
                  <span>Duration × Stays:</span>
                  <span style="color:var(--mocha)">× {{ bookingForm.nights }}</span>
                </div>
                <div class="d-flex justify-content-between small mb-2" style="color:var(--text-secondary)">
                  <span>Taxes &amp; Local Fees:</span>
                  <span style="color:var(--sage);font-weight:600">Included</span>
                </div>
                <div class="d-flex justify-content-between pt-2" style="border-top:1.5px solid var(--border)">
                  <span style="color:var(--mocha);font-weight:700">Total Estimated:</span>
                  <span style="color:var(--terracotta);font-weight:800;font-size:1.15rem">₱{{ Number(calculateTotalAmount).toLocaleString() }}</span>
                </div>
              </div>

              <!-- Action Button to Review Details -->
              <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow">
                <i class="bi bi-arrow-right-circle-fill me-1"></i> Review & Confirm Reservation
              </button>
            </form>
          </div>

          <!-- STEP 2: CONFIRMATION REVIEW ACTION SCREEN -->
          <div v-else-if="bookingStep === 'review'">
            <div class="text-center mb-4">
              <div class="d-inline-flex p-3 rounded-circle mb-2" style="background:rgba(200,113,74,0.12)">
                <i class="bi bi-patch-check-fill fs-2" style="color:var(--terracotta)"></i>
              </div>
              <h5 class="fw-bold mb-1" style="color:var(--mocha)">Review Your Reservation</h5>
              <p class="small mb-0" style="color:var(--text-muted)">Please verify the details below before confirming.</p>
            </div>

            <div class="booking-review-box mb-4">
              <div class="row g-2 small">
                <div class="col-sm-4" style="color:var(--text-muted)">Property:</div>
                <div class="col-sm-8 fw-bold" style="color:var(--mocha)">{{ bookingTarget.title }}</div>

                <div class="col-sm-4 text-secondary">Room Suite & Unit #:</div>
                <div class="col-sm-8 text-info fw-bold">
                  <span v-if="selectedRoomNumber" class="badge bg-primary font-monospace me-2"><i class="bi bi-door-closed-fill me-1"></i>{{ selectedRoomNumber }}</span>
                  {{ selectedRoomName }}
                </div>

                <div class="col-sm-4 text-secondary">Guest Name:</div>
                <div class="col-sm-8 text-white">{{ bookingForm.guestName }}</div>

                <div class="col-sm-4 text-secondary">Contact Number:</div>
                <div class="col-sm-8 text-white">{{ bookingForm.guestPhone }}</div>

                <div class="col-sm-4 text-secondary">Check-In Date/Time:</div>
                <div class="col-sm-8 text-white">{{ formatDateTime(bookingForm.checkInTime) }}</div>

                <div class="col-sm-4 text-secondary">Duration:</div>
                <div class="col-sm-8 text-white">{{ bookingForm.nights }} Night(s) ({{ bookingForm.stayDurationType }})</div>

                <div class="col-12"><hr class="border-secondary border-opacity-25 my-2"></div>

                <div class="col-sm-4 text-secondary fs-6">Grand Total Amount:</div>
                <div class="col-sm-8 text-success fw-bold fs-5">₱{{ Number(calculateTotalAmount).toLocaleString() }}</div>
              </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary w-50 py-2 fw-semibold rounded-3" @click="bookingStep = 'form'">
                <i class="bi bi-arrow-left me-1"></i> Edit Details
              </button>
              <button type="button" class="btn btn-success w-50 py-2 fw-bold rounded-3 shadow" :disabled="isSubmittingBooking" @click="finalizeBooking">
                <span v-if="isSubmittingBooking" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="bi bi-check-circle-fill me-1"></i> Confirm & Complete Booking
              </button>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- ============================================== -->
  <!-- MODAL 3: CONFIRMATION ACTION MODAL (FOR CANCEL) -->
  <!-- ============================================== -->
  <div class="modal fade" id="confirmCancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-content-dark">
        <div class="modal-body p-4 text-center" v-if="cancelTarget">
          <div class="d-inline-flex p-3 rounded-circle bg-danger bg-opacity-20 text-danger mb-3 border border-danger border-opacity-25">
            <i class="bi bi-exclamation-triangle-fill fs-2"></i>
          </div>
          <h5 class="text-white fw-bold mb-2">Cancel Reservation?</h5>
          <p class="text-white-50 small mb-3">
            Are you sure you want to cancel booking <strong class="text-info">#{{ cancelTarget.id }}</strong> for <strong class="text-white">{{ cancelTarget.stayTitle }}</strong> ({{ cancelTarget.roomName || 'Luxury Suite' }})?
          </p>
          <div class="p-3 rounded-3 mb-4 text-start small" style="background:#131c31; border:1px solid #1e293b;">
            <div class="d-flex justify-content-between mb-1">
              <span class="text-secondary">Refundable Amount:</span>
              <span class="text-success fw-bold">₱{{ Number(cancelTarget.amount).toLocaleString() }}</span>
            </div>
            <div class="text-secondary" style="font-size:0.75rem;">
              <i class="bi bi-info-circle me-1"></i> The room inventory slot will immediately be released back to other travelers.
            </div>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary w-50 py-2 fw-semibold rounded-3" data-bs-dismiss="modal">
              Keep Reservation
            </button>
            <button type="button" class="btn btn-danger w-50 py-2 fw-bold rounded-3 shadow" @click="confirmCancelBookingAction">
              <i class="bi bi-x-circle-fill me-1"></i> Yes, Cancel Booking
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================== -->
  <!-- MODAL 4: USER BOOKINGS HISTORY MODAL           -->
  <!-- ============================================== -->
  <div class="modal fade" id="userBookingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content modal-content-dark">
        <div class="modal-header">
          <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
            <i class="bi bi-journal-check text-success"></i> My Stays & Booking History
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="table-responsive">
            <table class="table table-dark table-hover align-middle border-secondary mb-0">
              <thead class="table-dark">
                <tr class="text-secondary small">
                  <th>ID</th>
                  <th>Hotel Property</th>
                  <th>Suite & Room #</th>
                  <th>Stay Package</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="b in userBookings" :key="b.id">
                  <td class="text-white-50 small fw-mono">#{{ b.id }}</td>
                  <td class="fw-bold text-white">{{ b.stayTitle }}</td>
                  <td>
                    <div class="d-flex align-items-center gap-1 flex-wrap">
                      <span v-if="b.roomNumber" class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-50 font-monospace me-1">{{ b.roomNumber }}</span>
                      <span class="text-info small">{{ b.roomName || 'Deluxe Room' }}</span>
                    </div>
                  </td>
                  <td><span class="badge bg-dark border border-secondary">{{ b.stayType }}</span></td>
                  <td class="text-success fw-bold">₱{{ Number(b.amount).toLocaleString() }}</td>
                  <td><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Confirmed</span></td>
                  <td>
                    <button class="btn btn-outline-danger btn-xs py-1 px-2" @click="promptCancelBooking(b)">
                      <i class="bi bi-trash3 me-1"></i>Cancel
                    </button>
                  </td>
                </tr>
                <tr v-if="userBookings.length === 0">
                  <td colspan="7" class="text-center text-secondary py-4">
                    <i class="bi bi-journal-x fs-3 d-block mb-2"></i>
                    No active hotel reservations found yet.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================== -->
  <!-- MODAL 5: ACCOUNT VERIFICATION ACTION MODAL     -->
  <!-- ============================================== -->
  <div class="modal fade" id="verifyAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-content-dark">
        <div class="modal-header">
          <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
            <i class="bi bi-patch-check-fill text-warning"></i> Verify Real User Account
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-secondary small mb-3">
            Confirm your account for <strong><?= htmlspecialchars($userEmail) ?></strong> to unlock verified guest booking privileges.
          </p>

          <!-- Demo Quick Code Badge -->
          <div class="p-3 rounded-3 mb-3 border border-primary border-opacity-30" style="background: rgba(59, 130, 246, 0.1);">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <span class="small text-secondary d-block">Demo Verification Code:</span>
                <span class="fw-mono text-white fw-bold fs-5 letter-spacing-2">849201</span>
              </div>
              <button type="button" class="btn btn-xs btn-outline-info rounded-pill py-1 px-3" @click="verifyCodeInput = '849201'">
                <i class="bi bi-magic me-1"></i> Auto-Fill
              </button>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small text-secondary">Enter 6-Digit PIN</label>
            <input type="text" v-model="verifyCodeInput" maxlength="6" class="form-control modal-input text-center fs-4 fw-bold letter-spacing-3" placeholder="••••••">
            <div v-if="verifyError" class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>{{ verifyError }}</div>
          </div>

          <!-- Action Buttons -->
          <div class="d-grid gap-2">
            <button type="button" class="btn btn-primary py-2 fw-semibold rounded-3 shadow" @click="submitAccountVerification">
              <i class="bi bi-shield-check me-1"></i> Verify Account Now
            </button>
            <button type="button" class="btn btn-outline-success py-2 fw-semibold rounded-3" @click="instantVerifyAccount">
              <i class="bi bi-lightning-charge-fill me-1"></i> 1-Click Instant Verification Pass
            </button>
            <button type="button" class="btn btn-outline-secondary py-1 text-secondary small rounded-3" @click="resendVerificationCode">
              <i class="bi bi-arrow-clockwise me-1"></i> Resend Security Code
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================== -->
  <!-- FLOATING ACTION TOAST NOTIFICATIONS            -->
  <!-- ============================================== -->
  <!-- v-if on message ensures this is absent from DOM entirely until a toast fires -->
  <div class="action-toast-wrapper" v-if="activeToast && activeToast.message">
    <div class="action-toast">
      <div class="d-flex align-items-start gap-2 mb-2">
        <i class="bi fs-5" :class="activeToast?.iconClass || 'bi-info-circle-fill text-info'"></i>
        <div class="flex-grow-1">
          <div class="fw-bold small" style="color:var(--mocha)">{{ activeToast?.title || '' }}</div>
          <div class="small" style="color:var(--text-secondary)">{{ activeToast?.message || '' }}</div>
        </div>
        <button type="button" class="btn-close btn-close-white btn-sm" @click="activeToast = null"></button>
      </div>
      <div class="d-flex justify-content-end gap-2 mt-2" v-if="activeToast?.actionButton">
        <button class="btn btn-xs btn-primary rounded-pill px-3 py-1" @click="activeToast.actionButton.handler">
          {{ activeToast?.actionButton?.label || '' }}
        </button>
      </div>
    </div>
  </div>

</div>

<script>
const { createApp, ref, computed, onMounted, nextTick, watch } = Vue;

createApp({
  setup() {
    const stays = ref([]);
    const userBookings = ref([]);
    const selectedStay = ref(null);
    const searchQuery = ref('');
    const selectedCategory = ref('all');
    const sortBy = ref('featured');

    // Account verification state
    const accountVerified = ref(<?= json_encode($isVerified) ?>);
    const verifyCodeInput = ref('');
    const verifyError = ref('');

    // Helper to get local ISO string formatted for input[type="datetime-local"]
    const getLocalNowISO = () => {
      const now = new Date();
      const pad = (n) => String(n).padStart(2, '0');
      return `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    };
    const getTomorrowLocalISO = () => {
      const tmrw = new Date(Date.now() + 86400000);
      const pad = (n) => String(n).padStart(2, '0');
      return `${tmrw.getFullYear()}-${pad(tmrw.getMonth()+1)}-${pad(tmrw.getDate())}T${pad(tmrw.getHours())}:${pad(tmrw.getMinutes())}`;
    };

    // Leaflet Map Objects & Location State
    let map = null;
    let markersLayer = null;
    let directionsPolyline = null;
    let directionsOriginMarker = null;
    let userLocationMarker = null;
    let markersMap = {};
    const currentTileLayer = ref('satellite');
    let tileLayers = {};
    const directionsActive = ref(false);
    const directionsTarget = ref(null);
    const directionsDistance = ref('0');
    const directionsDuration = ref('0');
    const isLocatingUser = ref(false);
    const userLocation = ref(null);

    // Modals instances
    let bookingModalInstance = null;
    let userBookingsModalInstance = null;
    let roomDetailsModalInstance = null;
    let confirmCancelModalInstance = null;
    let verifyAccountModalInstance = null;

    // Room Details Modal Target
    const roomDetailsHotel = ref(null);

    // Booking state
    const bookingTarget = ref(null);
    const bookingStep = ref('form'); // 'form' or 'review'
    const isSubmittingBooking = ref(false);
    const bookingErrors = ref({});
    const minDateTime = ref(getLocalNowISO());

    const bookingForm = ref({
      guestName: '<?= addslashes($userName) ?>',
      guestPhone: '<?= addslashes($userPhone) ?>',
      selectedRoomId: '',
      stayDurationType: 'Overnight Stay',
      nights: 1,
      checkInTime: getTomorrowLocalISO()
    });

    // Cancellation Target
    const cancelTarget = ref(null);

    // Action Toast State
    const activeToast = ref(null);
    const showToast = (title, message, iconClass, actionBtn) => {
      activeToast.value = {
        title,
        message,
        iconClass: iconClass || 'bi-check-circle-fill text-success',
        actionButton: actionBtn || null
      };
      setTimeout(() => {
        if (activeToast.value?.title === title) {
          activeToast.value = null;
        }
      }, 6500);
    };

    // Filter & Search Controls
    const selectedDistrict = ref('all');
    const selectedBranchId = ref('all');
    const onlyAvailableRooms = ref(false);
    const districtsList = computed(() => {
      const defaultOrder = [
        'Makati CBD',
        'BGC Taguig',
        'Entertainment City',
        'Pasay & Manila Bay',
        'Ortigas Center',
        'Quezon City',
        'Historic Manila',
        'Alabang Muntinlupa',
        'Baguio City',
        'San Fernando Pampanga',
        'Cebu City',
        'Cebu & Mactan',
        'Davao City',
        'Cagayan de Oro',
        'Naga City Bicol'
      ];
      const existing = new Set((stays.value || []).map(s => s.city).filter(Boolean));
      const result = defaultOrder.filter(d => existing.has(d));
      existing.forEach(c => {
        if (!result.includes(c)) result.push(c);
      });
      return result;
    });

    const countByCategory = (cat) => stays.value.filter(s => s.category === cat).length;
    const countByDistrict = (dist) => stays.value.filter(s => s.city === dist).length;

    const resetFilters = () => {
      searchQuery.value = '';
      selectedCategory.value = 'all';
      selectedDistrict.value = 'all';
      selectedBranchId.value = 'all';
      onlyAvailableRooms.value = false;
      sortBy.value = 'featured';
    };

    // Helpers for Room Status display
    const getRoomStatusBadgeClass = (status) => {
      if (status === 'Available') return 'bg-success bg-opacity-25 text-success border border-success border-opacity-35';
      if (status === 'Occupied') return 'bg-info bg-opacity-25 text-info border border-info border-opacity-35';
      if (status === 'Reserved') return 'bg-warning bg-opacity-25 text-warning border border-warning border-opacity-35';
      if (status === 'Under Maintenance') return 'bg-danger bg-opacity-25 text-danger border border-danger border-opacity-35';
      return 'bg-secondary text-light';
    };

    const getRoomStatusIcon = (status) => {
      if (status === 'Available') return 'bi-check-circle-fill text-success';
      if (status === 'Occupied') return 'bi-person-fill-lock text-info';
      if (status === 'Reserved') return 'bi-clock-fill text-warning';
      if (status === 'Under Maintenance') return 'bi-tools text-danger';
      return 'bi-info-circle';
    };

    // Filtered & Sorted Hotels (matches Title, Branch Name, District, Address, Brand, Tags, and Room Suites)
    const filteredStays = computed(() => {
      return stays.value.filter(stay => {
        // Category filter
        if (selectedCategory.value !== 'all' && stay.category !== selectedCategory.value) return false;

        // Designated District filter
        if (selectedDistrict.value !== 'all' && stay.city !== selectedDistrict.value) return false;

        // Specific branch selector filter
        if (selectedBranchId.value !== 'all' && stay.id !== selectedBranchId.value) return false;

        // Available suites only filter
        if (onlyAvailableRooms.value && (stay.availableRoomsCount || 0) === 0) return false;

        // Search query
        if (searchQuery.value.trim() !== '') {
          const q = searchQuery.value.toLowerCase().trim();
          const titleMatch = (stay.title || '').toLowerCase().includes(q);
          const branchMatch = (stay.branch_name || '').toLowerCase().includes(q);
          const cityMatch = (stay.city || '').toLowerCase().includes(q);
          const addressMatch = (stay.address || '').toLowerCase().includes(q);
          const brandMatch = (stay.brand || '').toLowerCase().includes(q);
          const tagsMatch = (stay.tags || []).some(t => t.toLowerCase().includes(q));
          const roomsMatch = (stay.rooms || []).some(r => 
            (r.room_name || '').toLowerCase().includes(q) || 
            (r.room_number || '').toLowerCase().includes(q) || 
            (r.room_type || '').toLowerCase().includes(q) ||
            (r.amenities || '').toLowerCase().includes(q)
          );
          return titleMatch || branchMatch || cityMatch || addressMatch || brandMatch || tagsMatch || roomsMatch;
        }
        return true;
      }).sort((a, b) => {
        if (sortBy.value === 'rating') return (b.rating || 0) - (a.rating || 0);
        if (sortBy.value === 'views') return (b.viewsCount || 0) - (a.viewsCount || 0);
        if (sortBy.value === 'bookings') return (b.totalBooked || 0) - (a.totalBooked || 0);
        if (sortBy.value === 'price-asc') return (a.price || 0) - (b.price || 0);
        if (sortBy.value === 'price-desc') return (b.price || 0) - (a.price || 0);
        return 0;
      });
    });

    // Selected Room Details — all guarded for null bookingTarget
    const selectedRoomPrice = computed(() => {
      if (!bookingTarget.value) return 0;
      if (!bookingTarget.value.rooms || !bookingTarget.value.rooms.length) {
        return Number(bookingTarget.value.price) || 0;
      }
      const rm = bookingTarget.value.rooms.find(r => r.id === bookingForm.value.selectedRoomId);
      return Number(rm ? rm.price : (bookingTarget.value.rooms[0]?.price ?? bookingTarget.value.price ?? 0)) || 0;
    });

    const selectedRoomName = computed(() => {
      if (!bookingTarget.value) return '';
      if (!bookingTarget.value.rooms || !bookingTarget.value.rooms.length) return 'Room';
      const rm = bookingTarget.value.rooms.find(r => r.id === bookingForm.value.selectedRoomId);
      return rm ? (rm.room_name || 'Room') : (bookingTarget.value.rooms[0]?.room_name || 'Room');
    });

    const selectedRoomNumber = computed(() => {
      if (!bookingTarget.value || !bookingTarget.value.rooms) return '';
      const rm = bookingTarget.value.rooms.find(r => r.id === bookingForm.value.selectedRoomId);
      return rm ? (rm.room_number || '') : (bookingTarget.value.rooms[0]?.room_number || '');
    });

    const calculateTotalAmount = computed(() => {
      const price = Number(selectedRoomPrice.value) || 0;
      const nights = Math.max(1, Number(bookingForm.value.nights) || 1);
      return price * nights;
    });

    // ── PANNELLUM 360° VIEWER STATE ──────────────────────────────────
    // Two-way sync: Vue ↔ Pannellum ↔ Leaflet
    let pannellumViewer = null;
    const pannellumActive = ref(false);
    const pannellumCurrentScene = ref('');
    const pannellumContainerId = 'pannellum-container';

    // Pannellum scene config using free equirectangular samples
    const PANNELLUM_SCENES = {
      living_room: {
        title: 'Living / Common Area',
        mapLabel: 'Living Room',
        // Pannellum official sample panorama
        panorama: 'https://pannellum.org/images/alma.jpg',
        hotSpots: [
          {
            id: 'to_bedroom',
            pitch: -5,
            yaw: 120,
            type: 'scene',
            text: '→ Bedroom',
            sceneId: 'bedroom',
            cssClass: 'pano-hs-nav'
          }
        ]
      },
      bedroom: {
        title: 'Master Bedroom',
        mapLabel: 'Bedroom',
        // Another Pannellum-hosted public equirectangular image
        panorama: 'https://pannellum.org/images/cerro-toco-0.jpg',
        hotSpots: [
          {
            id: 'to_living',
            pitch: -8,
            yaw: -60,
            type: 'scene',
            text: '← Living Room',
            sceneId: 'living_room',
            cssClass: 'pano-hs-nav'
          }
        ]
      }
    };

    /**
     * Boot (or re-init) Pannellum on a given scene.
     * Destroys any existing instance first to avoid DOM conflicts.
     */
    const initPannellum = (startScene = 'living_room') => {
      if (typeof pannellum === 'undefined') {
        console.warn('[Pannellum] library not loaded yet');
        return;
      }
      // Destroy previous instance
      if (pannellumViewer) {
        try { pannellumViewer.destroy(); } catch(e) {}
        pannellumViewer = null;
      }

      pannellumActive.value = true;
      pannellumCurrentScene.value = startScene;

      // Build multi-scene config
      const scenes = {};
      Object.entries(PANNELLUM_SCENES).forEach(([sceneId, cfg]) => {
        scenes[sceneId] = {
          title: cfg.title,
          type: 'equirectangular',
          panorama: cfg.panorama,
          hotSpots: cfg.hotSpots
        };
      });

      nextTick(() => {
        const el = document.getElementById(pannellumContainerId);
        if (!el) return;

        pannellumViewer = pannellum.viewer(pannellumContainerId, {
          default: {
            firstScene: startScene,
            sceneFadeDuration: 1000,
            autoLoad: true,
            showControls: true,
            mouseZoom: true,
            hfov: 100
          },
          scenes
        });

        // ── PANNELLUM → VUE → LEAFLET sync ──────────────────────────
        pannellumViewer.on('scenechange', (newSceneId) => {
          pannellumCurrentScene.value = newSceneId;
          // Move/highlight the Leaflet marker of the currently viewed stay
          if (roomDetailsHotel.value && map) {
            const stay = roomDetailsHotel.value;
            const lat = parseFloat(stay.lat);
            const lng = parseFloat(stay.lng);
            if (!isNaN(lat) && !isNaN(lng)) {
              // Pan map to the property marker and briefly highlight it
              map.panTo([lat, lng], { animate: true, duration: 0.8 });
              const marker = markersMap[stay.id];
              if (marker) {
                marker.openPopup();
              }
            }
          }
          // Update Vue reactive label
          showToast(
            '360° Room Changed',
            `Now viewing: ${PANNELLUM_SCENES[newSceneId]?.title || newSceneId}`,
            'bi-badge-3d text-primary'
          );
        });
      });
    };

    /**
     * Switch Pannellum to a specific scene (called from Leaflet click or room button).
     * LEAFLET → VUE → PANNELLUM sync.
     */
    const switchPannellumScene = (sceneId) => {
      if (!PANNELLUM_SCENES[sceneId]) return;
      if (pannellumViewer) {
        pannellumViewer.loadScene(sceneId);
        pannellumCurrentScene.value = sceneId;
      } else {
        initPannellum(sceneId);
      }
    };

    const destroyPannellum = () => {
      if (pannellumViewer) {
        try { pannellumViewer.destroy(); } catch(e) {}
        pannellumViewer = null;
      }
      pannellumActive.value = false;
      pannellumCurrentScene.value = '';
    };
    // ── END PANNELLUM ──────────────────────────────────────────────

    // Initialize Map
    const initMap = () => {
      if (map) return;
      const container = document.getElementById('leafletMap');
      if (!container) return;

      tileLayers = {
        satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { 
          maxZoom: 19,
          attribution: '&copy; Esri &mdash; World Imagery'
        }),
        street: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap contributors'
        }),
        dark: L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { 
          maxZoom: 19,
          attribution: '&copy; CartoDB'
        })
      };

      const bounds = L.latLngBounds([[14.334, 120.898], [14.776, 121.135]]);
      map = L.map(container, {
        center: [14.5995, 120.9842],
        zoom: 11,
        maxBounds: bounds,
        maxBoundsViscosity: 1.0,
        zoomControl: false,
        layers: [tileLayers[currentTileLayer.value] || tileLayers.satellite]
      });

      L.control.zoom({ position: 'topright' }).addTo(map);
      markersLayer = L.layerGroup().addTo(map);

      setTimeout(() => { if (map) map.invalidateSize(); }, 150);
      setTimeout(() => { if (map) map.invalidateSize(); }, 500);

      window.addEventListener('resize', () => {
        if (map) map.invalidateSize();
      });

      renderMapMarkers();
    };

    const switchMapLayer = (name) => {
      if (!map || !tileLayers[name]) return;
      Object.values(tileLayers).forEach(layer => {
        if (map.hasLayer(layer)) map.removeLayer(layer);
      });
      map.addLayer(tileLayers[name]);
      currentTileLayer.value = name;
    };

    const renderMapMarkers = () => {
      if (!map || !markersLayer) return;
      markersLayer.clearLayers();
      markersMap = {};

      const currentList = filteredStays.value || [];
      currentList.forEach(stay => {
        const lat = parseFloat(stay.lat);
        const lng = parseFloat(stay.lng);
        if (isNaN(lat) || isNaN(lng)) return;

        const isDest = selectedStay.value && selectedStay.value.id === stay.id;
        const customIcon = L.divIcon({
          className: 'leaflet-custom-marker',
          html: `<div class="map-price-bubble bubble-${stay.category || 'hotel'} ${isDest ? 'is-destination' : ''}"><span>₱${Number(stay.price).toLocaleString()}</span></div>`,
          iconSize: [95, 32],
          iconAnchor: [47, 16]
        });

        const marker = L.marker([lat, lng], { icon: customIcon });
        const popupContent = `
          <div style="min-width: 255px; font-family: 'Inter', sans-serif;">
            <div style="font-weight: 800; font-size: 13.5px; margin-bottom: 3px; color: #fff;">${stay.title}</div>
            <div style="display:flex; align-items:center; gap:6px; margin-bottom:6px; flex-wrap:wrap;">
              <span style="background: rgba(56,189,248,0.2); color: #38bdf8; border: 1px solid rgba(56,189,248,0.4); padding: 2px 7px; border-radius: 4px; font-size: 10px; font-weight: 700;">
                <i class="bi bi-geo-alt-fill me-1"></i>${stay.branch_name || 'Main Branch'}
              </span>
              <span style="background: rgba(148,163,184,0.18); color: #cbd5e1; padding: 2px 6px; border-radius: 4px; font-size: 10px;">
                ${stay.city}
              </span>
            </div>
            <div style="font-size: 10.5px; color: #94a3b8; margin-bottom: 4px;">
              <i class="bi bi-pin-map text-danger me-1"></i>${stay.address}
            </div>
            <div style="font-family: monospace; font-size: 9.5px; color: #64748b; margin-bottom: 8px;">
              <i class="bi bi-crosshair text-info me-1"></i>Coords: ${Number(stay.lat).toFixed(6)}, ${Number(stay.lng).toFixed(6)}
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 6px; margin-bottom: 8px;">
              <div>
                <span style="font-size: 9.5px; color: #94a3b8; display:block;">FROM</span>
                <span style="font-size: 13.5px; color: #34d399; font-weight: 800;">₱${Number(stay.price).toLocaleString()}</span>
              </div>
              <div style="text-align: right;">
                <span style="font-size: 10px; font-weight: 700; color: ${stay.availableRoomsCount > 0 ? '#34d399' : '#f87171'}; background: #0f172a; border: 1px solid #1e293b; padding: 3px 7px; border-radius: 4px; display:inline-block;">
                  ${stay.availableRoomsCount > 0 ? '🟢 ' + stay.availableRoomsCount + ' Available' : '🔴 Fully Booked'}
                </span>
              </div>
            </div>
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
              <button onclick="window.vueApp.openRoomDetailsFromId('${stay.id}')" style="flex:1; background: #6366f1; color: #fff; border:none; border-radius:6px; font-size:11px; font-weight:600; padding:6px 8px; cursor:pointer;" title="View Suite Options">
                <i class="bi bi-door-open-fill me-1"></i>Suites (${stay.rooms?.length || 0})
              </button>
              <button onclick="window.vueApp.locateDestinationFromId('${stay.id}')" style="background: #0284c7; color: #fff; border:none; border-radius:6px; font-size:11px; font-weight:600; padding:6px 10px; cursor:pointer;" title="Zoom In to Destination">
                <i class="bi bi-crosshair2"></i>
              </button>
              <button onclick="window.vueApp.quickRouteToId('${stay.id}')" style="background: #0d9488; color: #fff; border:none; border-radius:6px; font-size:11px; font-weight:600; padding:6px 10px; cursor:pointer;" title="Route to Destination">
                <i class="bi bi-sign-turn-right-fill"></i>
              </button>
              <button onclick="window.vueApp.quickBookId('${stay.id}')" style="background: #10b981; color: #fff; border:none; border-radius:6px; font-size:11px; font-weight:600; padding:6px 10px; cursor:pointer;" title="Reserve Hotel">
                Book
              </button>
            </div>
          </div>
        `;
        marker.bindPopup(popupContent);
        marker.on('click', () => { 
          locateDestination(stay, false);
        });
        markersLayer.addLayer(marker);
        markersMap[stay.id] = marker;
      });
    };

    const highlightDestinationMarker = (stay) => {
      if (!stay) return;
      document.querySelectorAll('.map-price-bubble').forEach(el => {
        el.classList.remove('is-destination');
      });

      if (markersMap[stay.id]) {
        const markerEl = markersMap[stay.id].getElement();
        if (markerEl) {
          const bubble = markerEl.querySelector('.map-price-bubble');
          if (bubble) bubble.classList.add('is-destination');
        }
      }
    };

    const locateDestination = (stay, userTriggered = true) => {
      if (!stay) return;
      selectedStay.value = stay;
      incrementViews(stay);

      const lat = parseFloat(stay.lat);
      const lng = parseFloat(stay.lng);
      if (map && !isNaN(lat) && !isNaN(lng)) {
        // High-definition destination close-up zoom (level 16)
        map.flyTo([lat, lng], 16, { animate: true, duration: 1.1 });

        // Highlight marker with pulsating blue beacon
        highlightDestinationMarker(stay);

        // Open marker popup
        setTimeout(() => {
          if (markersMap[stay.id]) {
            markersMap[stay.id].openPopup();
          }
        }, 320);

        // On mobile or small screens, scroll viewport to map container
        if (window.innerWidth < 992) {
          const mapEl = document.querySelector('.map-pane-container');
          if (mapEl) {
            mapEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        }

        if (userTriggered) {
          showToast(
            'Destination Located',
            `Centered on ${stay.title} (${stay.branch_name || stay.city}) with 16x zoom view.`,
            'bi-geo-alt-fill text-info',
            { label: 'View Suites', handler: () => openRoomDetailsModal(stay) }
          );
        }
      }
    };

    const zoomToDestination = (stay) => {
      locateDestination(stay || directionsTarget.value, true);
    };

    const selectHotelCard = (stay) => {
      locateDestination(stay, false);
    };

    const locateFromModal = (hotel) => {
      if (!hotel) return;
      roomDetailsModalInstance.hide();
      setTimeout(() => {
        locateDestination(hotel, true);
      }, 300);
    };

    const onBranchChange = () => {
      if (selectedBranchId.value && selectedBranchId.value !== 'all') {
        const stay = stays.value.find(s => s.id === selectedBranchId.value);
        if (stay) {
          locateDestination(stay, true);
        }
      } else {
        fitAllPins();
      }
    };

    const locateUserPosition = () => {
      if (!navigator.geolocation) {
        showToast('GPS Unsupported', 'Geolocation is not supported by your browser.', 'bi-exclamation-triangle text-warning');
        return;
      }

      isLocatingUser.value = true;
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          isLocatingUser.value = false;
          const lat = pos.coords.latitude;
          const lng = pos.coords.longitude;
          userLocation.value = [lat, lng];

          if (userLocationMarker && map) map.removeLayer(userLocationMarker);
          const userGpsIcon = L.divIcon({
            className: 'leaflet-custom-marker',
            html: '<div class="user-gps-marker"><div class="user-gps-pulse"></div><div class="user-gps-dot"></div></div>',
            iconSize: [26, 26],
            iconAnchor: [13, 13]
          });
          userLocationMarker = L.marker([lat, lng], { icon: userGpsIcon, zIndexOffset: 1200 }).addTo(map);
          userLocationMarker.bindPopup(`
            <div style="font-family:'Inter',sans-serif; text-align:center; padding:5px;">
              <div style="color:#38bdf8; font-weight:800; font-size:12px; margin-bottom:2px;"><i class="bi bi-crosshair me-1"></i>Your Current Location</div>
              <div style="font-size:10.5px; color:#cbd5e1;">Coords: ${lat.toFixed(4)}, ${lng.toFixed(4)}</div>
              <div style="font-size:9.5px; color:#94a3b8; margin-top:2px;">Accuracy: ±${Math.round(pos.coords.accuracy)}m</div>
            </div>
          `).openPopup();

          if (selectedStay.value) {
            const destLat = parseFloat(selectedStay.value.lat);
            const destLng = parseFloat(selectedStay.value.lng);
            if (!isNaN(destLat) && !isNaN(destLng)) {
              map.fitBounds([[lat, lng], [destLat, destLng]], { padding: [65, 65] });
            }
          } else {
            map.flyTo([lat, lng], 15, { duration: 1.0 });
          }

          showToast('GPS Location Detected', 'Your current coordinates have been mapped successfully.', 'bi-check-circle-fill text-success');
        },
        (err) => {
          isLocatingUser.value = false;
          console.warn('Geolocation notice:', err.message);
          showToast('GPS Notice', 'Unable to detect location. Using NAIA Terminal 3 Airport as reference.', 'bi-info-circle text-info');
        },
        { enableHighAccuracy: true, timeout: 8000 }
      );
    };

    const incrementViews = async (stay) => {
      try {
        const res = await axios.post('api.php?action=increment_views', { id: stay.id });
        if (res.data && res.data.views_count) {
          stay.viewsCount = res.data.views_count;
        }
      } catch (e) {
        stay.viewsCount++;
      }
    };

    const fitAllPins = () => {
      if (!map || !filteredStays.value || filteredStays.value.length === 0) return;
      const validCoords = filteredStays.value
        .map(s => [parseFloat(s.lat), parseFloat(s.lng)])
        .filter(c => !isNaN(c[0]) && !isNaN(c[1]));
      if (validCoords.length > 0) {
        map.fitBounds(L.latLngBounds(validCoords), { padding: [50, 50] });
      }
    };

    const startDirections = (stay) => {
      directionsTarget.value = stay;
      directionsActive.value = true;
      selectedStay.value = stay;

      const lat = parseFloat(stay.lat);
      const lng = parseFloat(stay.lng);
      if (isNaN(lat) || isNaN(lng)) return;
      const destination = [lat, lng];

      const origin = userLocation.value ? userLocation.value : [14.5176, 121.0158]; // User location or NAIA T3
      const originLabel = userLocation.value ? 'Your Current GPS Location' : 'NAIA Terminal 3 Airport (Default Start)';

      const distance = (Math.hypot((destination[0]-origin[0])*111, (destination[1]-origin[1])*105) * 1.35).toFixed(1);
      directionsDistance.value = distance;
      directionsDuration.value = Math.max(8, Math.round(distance * 3.4 + 5));

      if (directionsPolyline && map) map.removeLayer(directionsPolyline);
      if (directionsOriginMarker && map) map.removeLayer(directionsOriginMarker);

      const originIcon = L.divIcon({
        className: 'leaflet-custom-marker',
        html: `<div class="origin-pin-badge"><i class="bi bi-geo-alt-fill text-white"></i> ${userLocation.value ? 'Start (You)' : 'Start (NAIA)'}</div>`,
        iconSize: [95, 28],
        iconAnchor: [47, 14]
      });
      directionsOriginMarker = L.marker(origin, { icon: originIcon, zIndexOffset: 950 }).addTo(map);
      directionsOriginMarker.bindPopup(`
        <div style="font-family:'Inter',sans-serif; padding:4px;">
          <div style="color:#34d399; font-weight:800; font-size:12px;"><i class="bi bi-pin-map-fill me-1"></i>Starting Point</div>
          <div style="font-size:10.5px; color:#cbd5e1;">${originLabel}</div>
        </div>
      `);

      directionsPolyline = L.polyline([origin, destination], { 
        color: '#38bdf8', 
        weight: 5, 
        dashArray: '8, 8',
        opacity: 0.95 
      }).addTo(map);

      map.fitBounds(directionsPolyline.getBounds(), { padding: [60, 60] });
      highlightDestinationMarker(stay);
    };

    const zoomToOrigin = () => {
      const origin = userLocation.value ? userLocation.value : [14.5176, 121.0158];
      if (map) {
        map.flyTo(origin, 16, { duration: 1.0 });
        if (directionsOriginMarker) directionsOriginMarker.openPopup();
      }
    };

    const fitRouteBounds = () => {
      if (directionsPolyline && map) {
        map.fitBounds(directionsPolyline.getBounds(), { padding: [60, 60] });
      }
    };

    const simulateNavStep = () => {
      if (directionsTarget.value) startDirections(directionsTarget.value);
    };

    const clearDirections = () => {
      if (directionsPolyline && map) map.removeLayer(directionsPolyline);
      if (directionsOriginMarker && map) map.removeLayer(directionsOriginMarker);
      directionsPolyline = null;
      directionsOriginMarker = null;
      directionsActive.value = false;
      directionsTarget.value = null;
    };

    // Load Data
    const loadData = async () => {
      try {
        const [resStays, resBookings] = await Promise.all([
          axios.get('api.php?action=get_stays'),
          axios.get('api.php?action=get_bookings')
        ]);
        stays.value = resStays.data;
        userBookings.value = resBookings.data;
      } catch (e) {
        console.error('Error loading data:', e);
      }
    };

    // Room Details Modal Handlers
    const panoPanX = Vue.ref(0);
    const currentPanoKey = Vue.ref('');
    const panoLoading = Vue.ref(false);
    let isDraggingPano = false;
    let panoStartX = 0;

    const currentPanoRoom = Vue.computed(() => {
      if (!roomDetailsHotel.value || !roomDetailsHotel.value.showroom) return {};
      return roomDetailsHotel.value.showroom.rooms[currentPanoKey.value] || {};
    });

    const switchPanoRoom = (key) => {
      if (!roomDetailsHotel.value?.showroom?.rooms[key] || currentPanoKey.value === key) return;
      panoLoading.value = true;
      panoPanX.value = 0;
      setTimeout(() => {
        currentPanoKey.value = key;
        panoLoading.value = false;
      }, 400); // Simulate network load
    };

    const startPanoDrag = (e) => {
      isDraggingPano = true;
      panoStartX = e.clientX || (e.touches && e.touches[0].clientX) || 0;
    };
    const onPanoDrag = (e) => {
      if (!isDraggingPano) return;
      const x = e.clientX || (e.touches && e.touches[0].clientX) || 0;
      const diff = x - panoStartX;
      panoStartX = x;
      panoPanX.value += diff * 1.5; // Drag sensitivity
      
      // Limit panning boundaries (rough estimate)
      if (panoPanX.value > 300) panoPanX.value = 300;
      if (panoPanX.value < -300) panoPanX.value = -300;
    };
    const endPanoDrag = () => { isDraggingPano = false; };

    const openRoomDetailsModal = (hotel) => {
      roomDetailsHotel.value = hotel;
      
      // Init 360 viewer if showroom data exists
      if (hotel.showroom && hotel.showroom.initial_room) {
        switchPanoRoom(hotel.showroom.initial_room);
      }
      
      incrementViews(hotel);
      roomDetailsModalInstance.show();
    };

    const bookSpecificRoom = (room) => {
      if (!roomDetailsHotel.value) return;
      roomDetailsModalInstance.hide();
      openBookingModalFor(roomDetailsHotel.value, room.id);
    };

    // Booking Handlers
    const openBookingModalFor = (stay, specificRoomId) => {
      bookingTarget.value = stay;
      bookingStep.value = 'form';
      bookingErrors.value = {};

      const availableRoom = (stay.rooms || []).find(r => r.status === 'Available') || (stay.rooms || [])[0];
      const defaultRoomId = specificRoomId || (availableRoom ? availableRoom.id : '');
      bookingForm.value.selectedRoomId = defaultRoomId;
      bookingModalInstance.show();
    };

    const onRoomSelectChange = () => {
      // triggers computed price update
    };

    // Validation before review
    const proceedToReview = () => {
      bookingErrors.value = {};
      const name = bookingForm.value.guestName?.trim() || '';
      const phone = bookingForm.value.guestPhone?.trim() || '';
      const checkIn = bookingForm.value.checkInTime;

      if (name.length < 2) {
        bookingErrors.value.guestName = 'Name must be at least 2 letters';
      }
      if (phone.length < 7) {
        bookingErrors.value.guestPhone = 'Valid phone number required';
      }
      if (!checkIn) {
        bookingErrors.value.checkInTime = 'Please select check-in date/time';
      } else {
        const checkInDate = new Date(checkIn);
        if (checkInDate.getTime() < Date.now() - 3600000) {
          bookingErrors.value.checkInTime = 'Check-in cannot be in the past';
        }
      }

      if (Object.keys(bookingErrors.value).length > 0) {
        return;
      }
      bookingStep.value = 'review';
    };

    // Finalize booking
    const finalizeBooking = async () => {
      isSubmittingBooking.value = true;
      const payload = {
        hotel_id: bookingTarget.value.id,
        stay_id: bookingTarget.value.id,
        room_id: bookingForm.value.selectedRoomId,
        hotel_title: bookingTarget.value.title,
        room_name: selectedRoomName.value,
        room_number: selectedRoomNumber.value,
        guest_name: bookingForm.value.guestName,
        guest_phone: bookingForm.value.guestPhone,
        stay_type: bookingForm.value.stayDurationType,
        check_in_time: bookingForm.value.checkInTime,
        nights: bookingForm.value.nights,
        amount: calculateTotalAmount.value
      };

      try {
        const res = await axios.post('api.php?action=create_booking', payload);
        bookingModalInstance.hide();
        await loadData();

        showToast(
          'Reservation Confirmed!',
          `Booking for ${payload.hotel_title} (${payload.room_name}) confirmed.`,
          'bi-check-circle-fill text-success',
          { label: 'View Bookings', handler: () => openBookingsModal() }
        );
      } catch (err) {
        console.error('Error creating booking:', err);
      } finally {
        isSubmittingBooking.value = false;
      }
    };

    // Cancellation with Action Modal
    const promptCancelBooking = (booking) => {
      cancelTarget.value = booking;
      userBookingsModalInstance.hide();
      confirmCancelModalInstance.show();
    };

    const confirmCancelBookingAction = async () => {
      if (!cancelTarget.value) return;
      const idToCancel = cancelTarget.value.id;
      const hotelName = cancelTarget.value.stayTitle;

      try {
        await axios.post('api.php?action=cancel_booking', { id: idToCancel });
        confirmCancelModalInstance.hide();
        await loadData();

        showToast(
          'Reservation Cancelled',
          `Reservation #${idToCancel} for ${hotelName} has been cancelled.`,
          'bi-info-circle-fill text-warning'
        );
      } catch (err) {
        console.error('Error cancelling booking:', err);
      }
    };

    // Account Verification Action Handlers
    const openVerifyModal = () => {
      verifyError.value = '';
      verifyCodeInput.value = '';
      verifyAccountModalInstance.show();
    };

    const submitAccountVerification = async () => {
      if (!verifyCodeInput.value || verifyCodeInput.value.length < 6) {
        verifyError.value = 'Please enter a valid 6-digit PIN code.';
        return;
      }
      try {
        const res = await axios.post('api.php?action=verify_account', { code: verifyCodeInput.value });
        if (res.data.status === 'success') {
          accountVerified.value = true;
          verifyAccountModalInstance.hide();
          showToast('Account Verified!', 'Your account has been officially verified with verified traveler status.', 'bi-patch-check-fill text-success');
        } else {
          verifyError.value = res.data.message || 'Invalid code.';
        }
      } catch (err) {
        verifyError.value = 'Verification failed. Try instant pass.';
      }
    };

    const instantVerifyAccount = async () => {
      try {
        const res = await axios.post('api.php?action=verify_account', { instant: 1 });
        if (res.data.status === 'success') {
          accountVerified.value = true;
          verifyAccountModalInstance.hide();
          showToast('Account Verified!', 'Instant 1-Click Verification applied successfully.', 'bi-lightning-charge-fill text-success');
        }
      } catch (err) {
        accountVerified.value = true;
        verifyAccountModalInstance.hide();
      }
    };

    const resendVerificationCode = async () => {
      try {
        const res = await axios.post('api.php?action=send_verification_code');
        showToast('Code Resent', `A new 6-digit code has been dispatched: [${res.data.code}]`, 'bi-shield-check text-info');
      } catch (err) {
        showToast('Code Generated', 'Demo code ready: [849201]', 'bi-shield-check text-info');
      }
    };

    const openBookingsModal = () => userBookingsModalInstance.show();

    const formatDateTime = (dtStr) => {
      if (!dtStr) return '';
      const d = new Date(dtStr);
      return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
    };

    onMounted(async () => {
      bookingModalInstance = new bootstrap.Modal(document.getElementById('bookingModal'));
      userBookingsModalInstance = new bootstrap.Modal(document.getElementById('userBookingsModal'));
      roomDetailsModalInstance = new bootstrap.Modal(document.getElementById('roomDetailsModal'));
      confirmCancelModalInstance = new bootstrap.Modal(document.getElementById('confirmCancelModal'));
      verifyAccountModalInstance = new bootstrap.Modal(document.getElementById('verifyAccountModal'));

      // Expose to window for popup clicks
      window.vueApp = {
        openRoomDetailsFromId: (id) => {
          const h = stays.value.find(s => s.id === id);
          if (h) openRoomDetailsModal(h);
        },
        quickRouteToId: (id) => {
          const h = stays.value.find(s => s.id === id);
          if (h) startDirections(h);
        },
        quickBookId: (id) => {
          const h = stays.value.find(s => s.id === id);
          if (h) openBookingModalFor(h, null);
        },
        locateDestinationFromId: (id) => {
          const h = stays.value.find(s => s.id === id);
          if (h) locateDestination(h, true);
        }
      };

      nextTick(() => {
        initMap();
      });

      await loadData();

      setTimeout(() => {
        fitAllPins();
      }, 350);

      // Check URL for verified param
      if (window.location.search.includes('verified=1')) {
        accountVerified.value = true;
        showToast('Account Verified!', 'Welcome to StayFinder PH. You can now make reservations.', 'bi-patch-check-fill text-success');
      }
    });

    const filteredStayIds = computed(() => (filteredStays.value || []).map(s => s.id).join(','));

    watch(filteredStayIds, () => {
      renderMapMarkers();
      if (selectedStay.value) {
        highlightDestinationMarker(selectedStay.value);
      } else {
        setTimeout(() => { fitAllPins(); }, 120);
      }
    });

    watch(selectedBranchId, (newId) => {
      if (newId && newId !== 'all') {
        const target = stays.value.find(s => s.id === newId);
        if (target) locateDestination(target, true);
      } else {
        fitAllPins();
      }
    });

    return {
      stays, userBookings, selectedStay, searchQuery, selectedCategory,
      sortBy, filteredStays, countByCategory, resetFilters,
      selectedDistrict, selectedBranchId, onlyAvailableRooms, districtsList, countByDistrict,
      getRoomStatusBadgeClass, getRoomStatusIcon,
      accountVerified, verifyCodeInput, verifyError, openVerifyModal,
      submitAccountVerification, instantVerifyAccount, resendVerificationCode,
      currentTileLayer, switchMapLayer, directionsActive, directionsTarget,
      directionsDistance, directionsDuration, startDirections, clearDirections, simulateNavStep,
      selectHotelCard, fitAllPins, locateDestination, zoomToDestination, highlightDestinationMarker,
      locateFromModal, onBranchChange, locateUserPosition, zoomToOrigin, fitRouteBounds,
      isLocatingUser, userLocation,
      roomDetailsHotel, openRoomDetailsModal, bookSpecificRoom,
      panoPanX, currentPanoKey, panoLoading, currentPanoRoom,
      switchPanoRoom, startPanoDrag, onPanoDrag, endPanoDrag,
      PANNELLUM_SCENES, pannellumActive, pannellumCurrentScene, initPannellum, switchPannellumScene, destroyPannellum,
      bookingTarget, bookingStep, bookingForm, bookingErrors, minDateTime,
      selectedRoomPrice, selectedRoomName, selectedRoomNumber, calculateTotalAmount, onRoomSelectChange,
      openBookingModalFor, proceedToReview, finalizeBooking, isSubmittingBooking,
      cancelTarget, promptCancelBooking, confirmCancelBookingAction,
      openBookingsModal, formatDateTime, activeToast
    };
  }
}).mount('#app');
</script>
</body>
</html>