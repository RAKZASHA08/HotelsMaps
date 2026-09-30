
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
    const accountVerified = ref(false);
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
      guestName: 'Guest Traveler',
      guestPhone: '+63 917 555 4321',
      selectedRoomId: '',
      stayDurationType: 'Overnight Luxury Stay',
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

    // Selected Room Details
    const selectedRoomPrice = computed(() => {
      if (!bookingTarget.value || !bookingTarget.value.rooms) return bookingTarget.value?.price || 12000;
      const rm = bookingTarget.value.rooms.find(r => r.id === bookingForm.value.selectedRoomId);
      return rm ? rm.price : (bookingTarget.value.rooms[0]?.price || bookingTarget.value.price || 12000);
    });

    const selectedRoomName = computed(() => {
      if (!bookingTarget.value || !bookingTarget.value.rooms) return 'Deluxe Room';
      const rm = bookingTarget.value.rooms.find(r => r.id === bookingForm.value.selectedRoomId);
      return rm ? rm.room_name : (bookingTarget.value.rooms[0]?.room_name || 'Deluxe Room');
    });

    const selectedRoomNumber = computed(() => {
      if (!bookingTarget.value || !bookingTarget.value.rooms) return '';
      const rm = bookingTarget.value.rooms.find(r => r.id === bookingForm.value.selectedRoomId);
      return rm ? (rm.room_number || '') : (bookingTarget.value.rooms[0]?.room_number || '');
    });

    const calculateTotalAmount = computed(() => {
      return selectedRoomPrice.value * Math.max(1, bookingForm.value.nights || 1);
    });

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
          html: `<div class="map-price-bubble bubble-${stay.category || 'hotel'} ${isDest ? 'is-destination' : ''}"><span>Gé¦${Number(stay.price).toLocaleString()}</span></div>`,
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
                <span style="font-size: 13.5px; color: #34d399; font-weight: 800;">Gé¦${Number(stay.price).toLocaleString()}</span>
              </div>
              <div style="text-align: right;">
                <span style="font-size: 10px; font-weight: 700; color: ${stay.availableRoomsCount > 0 ? '#34d399' : '#f87171'}; background: #0f172a; border: 1px solid #1e293b; padding: 3px 7px; border-radius: 4px; display:inline-block;">
                  ${stay.availableRoomsCount > 0 ? '=ƒƒó ' + stay.availableRoomsCount + ' Available' : '=ƒö¦ Fully Booked'}
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
              <div style="font-size:9.5px; color:#94a3b8; margin-top:2px;">Accuracy: -¦${Math.round(pos.coords.accuracy)}m</div>
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
    const openRoomDetailsModal = (hotel) => {
      roomDetailsHotel.value = hotel;
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
        showToast('Account Officially Verified!', 'Welcome to ComfortVue luxury hotel portal.', 'bi-patch-check-fill text-success');
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
      bookingTarget, bookingStep, bookingForm, bookingErrors, minDateTime,
      selectedRoomPrice, selectedRoomName, selectedRoomNumber, calculateTotalAmount, onRoomSelectChange,
      openBookingModalFor, proceedToReview, finalizeBooking, isSubmittingBooking,
      cancelTarget, promptCancelBooking, confirmCancelBookingAction,
      openBookingsModal, formatDateTime, activeToast
    };
  }
}).mount('#app');

