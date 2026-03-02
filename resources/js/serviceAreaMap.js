/**
 * Service Area Map Handler
 *
 * Handles Google Maps visualization of service areas and location validation
 * for the booking wizard step 3 (vehicle & location).
 *
 * Features:
 * - Loads and displays service area circles on map
 * - Validates user location against service areas via AJAX
 * - Shows visual feedback (green/red markers)
 * - Fits map viewport to show all service areas
 */

export class ServiceAreaMapHandler {
    constructor(mapElementId, options = {}) {
        this.mapElementId = mapElementId;
        this.map = null;
        this.serviceAreaCircles = [];
        this.userMarker = null;

        // API endpoints
        this.validationEndpoint = options.validationEndpoint || '/api/service-area/validate';
        this.areasEndpoint = options.areasEndpoint || '/api/service-area/areas';

        // Google Maps options
        this.defaultCenter = options.defaultCenter || { lat: 52.2297, lng: 21.0122 }; // Warsaw
        this.defaultZoom = options.defaultZoom || 7;

        // Service areas data
        this.serviceAreas = [];

        // Loading state
        this.isLoading = false;
    }

    /**
     * Initialize the map handler
     * Loads service areas and draws them on the map
     */
    async initialize() {
        if (!this.map) {
            console.error('ServiceAreaMapHandler: Map instance not found. Call setMap() first.');
            return false;
        }

        try {
            await this.loadServiceAreas();
            this.drawAllServiceAreas();
            this.fitMapToServiceAreas();
            return true;
        } catch (error) {
            console.error('ServiceAreaMapHandler initialization error:', error);
            return false;
        }
    }

    /**
     * Set the Google Maps instance
     * @param {google.maps.Map} mapInstance
     */
    setMap(mapInstance) {
        this.map = mapInstance;
    }

    /**
     * Load service areas from API
     * @returns {Promise<Array>}
     */
    async loadServiceAreas() {
        if (this.isLoading) {
            console.log('ServiceAreaMapHandler: Already loading service areas');
            return this.serviceAreas;
        }

        this.isLoading = true;

        try {
            const response = await fetch(this.areasEndpoint, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            this.serviceAreas = data.areas || [];

            console.log(`ServiceAreaMapHandler: Loaded ${this.serviceAreas.length} service areas`);
            return this.serviceAreas;
        } catch (error) {
            console.error('ServiceAreaMapHandler: Failed to load service areas', error);
            throw error;
        } finally {
            this.isLoading = false;
        }
    }

    /**
     * Draw all service area circles on the map
     */
    drawAllServiceAreas() {
        // Clear existing circles
        this.clearServiceAreaCircles();

        if (!this.serviceAreas || this.serviceAreas.length === 0) {
            console.warn('ServiceAreaMapHandler: No service areas to draw');
            return;
        }

        // Draw each service area
        this.serviceAreas.forEach(area => {
            this.drawServiceAreaCircle(area);
        });

        console.log(`ServiceAreaMapHandler: Drew ${this.serviceAreaCircles.length} service area circles`);
    }

    /**
     * Draw a single service area circle
     * @param {Object} area - Service area object {city, center: {lat, lng}, radius, color}
     */
    drawServiceAreaCircle(area) {
        if (!this.map) {
            console.error('ServiceAreaMapHandler: Cannot draw circle - map not initialized');
            return null;
        }

        const circle = new google.maps.Circle({
            strokeColor: area.color || '#4CAF50',
            strokeOpacity: 0.8,
            strokeWeight: 2,
            fillColor: area.color || '#4CAF50',
            fillOpacity: 0.15,
            map: this.map,
            center: area.center,
            radius: area.radius, // meters
            clickable: false,
        });

        // Add info window with city name on circle
        const infoWindow = new google.maps.InfoWindow({
            content: `<div class="font-semibold text-sm">${area.city}</div>
                      <div class="text-xs text-gray-600">Promień: ${(area.radius / 1000).toFixed(0)} km</div>`,
            position: area.center
        });

        // Show info window on hover
        google.maps.event.addListener(circle, 'mouseover', () => {
            infoWindow.open(this.map);
        });

        google.maps.event.addListener(circle, 'mouseout', () => {
            infoWindow.close();
        });

        this.serviceAreaCircles.push(circle);
        return circle;
    }

    /**
     * Clear all service area circles from the map
     */
    clearServiceAreaCircles() {
        this.serviceAreaCircles.forEach(circle => {
            circle.setMap(null);
        });
        this.serviceAreaCircles = [];
    }

    /**
     * Fit map viewport to show all service areas
     */
    fitMapToServiceAreas() {
        if (!this.map || !this.serviceAreas || this.serviceAreas.length === 0) {
            // No service areas - use default center
            this.map.setCenter(this.defaultCenter);
            this.map.setZoom(this.defaultZoom);
            return;
        }

        const bounds = new google.maps.LatLngBounds();

        // Extend bounds to include all service area circles
        this.serviceAreas.forEach(area => {
            const center = new google.maps.LatLng(area.center.lat, area.center.lng);

            // Calculate north/south/east/west points of the circle
            const radiusInDegrees = area.radius / 111320; // Rough conversion: 1 degree ≈ 111.32 km

            bounds.extend(new google.maps.LatLng(
                area.center.lat + radiusInDegrees,
                area.center.lng
            ));
            bounds.extend(new google.maps.LatLng(
                area.center.lat - radiusInDegrees,
                area.center.lng
            ));
            bounds.extend(new google.maps.LatLng(
                area.center.lat,
                area.center.lng + radiusInDegrees
            ));
            bounds.extend(new google.maps.LatLng(
                area.center.lat,
                area.center.lng - radiusInDegrees
            ));
        });

        this.map.fitBounds(bounds);
    }

    /**
     * Validate a location against service areas via AJAX
     * @param {number} lat - Latitude
     * @param {number} lng - Longitude
     * @returns {Promise<Object>} Validation result {valid, message, nearestArea}
     */
    async validateLocation(lat, lng) {
        try {
            const response = await fetch(this.validationEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    latitude: lat,
                    longitude: lng
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            console.log('ServiceAreaMapHandler: Validation result', result);
            return result;
        } catch (error) {
            console.error('ServiceAreaMapHandler: Validation request failed', error);
            throw error;
        }
    }

    /**
     * Show user's selected location on the map with color-coded marker
     * @param {number} lat - Latitude
     * @param {number} lng - Longitude
     * @param {boolean} valid - Whether location is valid (true = green, false = red)
     */
    showUserLocation(lat, lng, valid) {
        if (!this.map) {
            console.error('ServiceAreaMapHandler: Cannot show marker - map not initialized');
            return;
        }

        // Remove existing marker
        this.clearUserLocation();

        const position = { lat, lng };

        // Create marker with appropriate color
        this.userMarker = new google.maps.Marker({
            position: position,
            map: this.map,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 10,
                fillColor: valid ? '#22c55e' : '#ef4444', // green-500 : red-500
                fillOpacity: 1,
                strokeColor: '#ffffff',
                strokeWeight: 2,
            },
            title: valid ? 'Lokalizacja obsługiwana' : 'Lokalizacja poza obszarem obsługi'
        });

        // Pan map to show the marker
        this.map.panTo(position);
    }

    /**
     * Clear user location marker from the map
     */
    clearUserLocation() {
        if (this.userMarker) {
            this.userMarker.setMap(null);
            this.userMarker = null;
        }
    }

    /**
     * Destroy the handler and clean up
     */
    destroy() {
        this.clearServiceAreaCircles();
        this.clearUserLocation();
        this.map = null;
        this.serviceAreas = [];
    }
}
