# TD Booking - Developer Documentation

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Database Schema](#database-schema)
3. [Plugin Structure](#plugin-structure)
4. [REST API Reference](#rest-api-reference)
5. [Hooks & Filters](#hooks--filters)
6. [CalDAV Integration](#caldav-integration)
7. [Email System](#email-system)
8. [Caching System](#caching-system)
9. [Staff Assignment Engine](#staff-assignment-engine)
10. [Customization Guide](#customization-guide)
11. [Testing & Debugging](#testing--debugging)
12. [Internationalization (i18n)](#internationalization-i18n)
13. [At-rest Encryption](#at-rest-encryption)

## Architecture Overview

TD Booking is built as a modular WordPress plugin with the following architectural principles:

- **Dependency Injection**: Uses a service container for managing dependencies
- **Separation of Concerns**: Clear separation between admin, public, and API functionality
- **UTC Time Storage**: All times stored in UTC for timezone consistency
- **RESTful API**: Full REST API for external integrations
- **Extensible Design**: Hook-based architecture for customization

### Core Components

```
TD Booking
├── Admin Interface (WP Admin pages)
├── Public Interface (Shortcodes, widgets)
├── REST API (JSON endpoints)
├── CalDAV Client (Sync with external calendars)
├── Email System (Notifications and templates)
├── Staff Assignment Engine (Automatic technician assignment)
├── Availability Engine (Time slot calculation)
└── Database Layer (Custom tables and WP options)
```

## Database Schema

TD Booking uses custom database tables for optimal performance and data integrity.

### Tables Overview

#### `{prefix}_td_services`
Stores service definitions and configuration.

```sql
CREATE TABLE wp_td_services (
    id int(11) PRIMARY KEY AUTO_INCREMENT,
    name varchar(255) NOT NULL,
    slug varchar(255) UNIQUE NOT NULL,
    description text,
    duration_minutes int(11) NOT NULL DEFAULT 60,
    price decimal(10,2) DEFAULT NULL,
    is_active tinyint(1) DEFAULT 1,
    created_at datetime,
    updated_at datetime
);
```

#### `{prefix}_td_bookings`
Main bookings table with customer and scheduling information.

```sql
CREATE TABLE wp_td_bookings (
    id int(11) PRIMARY KEY AUTO_INCREMENT,
    service_id int(11) NOT NULL,
    technician_id int(11) DEFAULT NULL,
    customer_name varchar(255) NOT NULL,
    customer_email varchar(255) NOT NULL,
    customer_phone varchar(50),
    customer_address text,
    start_utc datetime NOT NULL,
    end_utc datetime NOT NULL,
    status enum('pending','confirmed','cancelled','completed') DEFAULT 'pending',
    group_size int(11) DEFAULT 1,
    wc_order_id int(11) DEFAULT NULL,
    notes text,
    created_at datetime,
    updated_at datetime,
    
    INDEX idx_service (service_id),
    INDEX idx_technician (technician_id),
    INDEX idx_start_time (start_utc),
    INDEX idx_status (status),
    FOREIGN KEY (service_id) REFERENCES wp_td_services(id)
);
```

#### `{prefix}_td_caldav_mapping`
Maps bookings to CalDAV events for synchronization.

```sql
CREATE TABLE wp_td_caldav_mapping (
    id int(11) PRIMARY KEY AUTO_INCREMENT,
    booking_id int(11) NOT NULL,
    caldav_uid varchar(255) NOT NULL,
    caldav_etag varchar(255),
    last_sync datetime,
    
    UNIQUE KEY unique_booking (booking_id),
    FOREIGN KEY (booking_id) REFERENCES wp_td_bookings(id) ON DELETE CASCADE
);
```

#### `{prefix}_td_availability_cache`
Caches availability calculations for performance.

```sql
CREATE TABLE wp_td_availability_cache (
    id int(11) PRIMARY KEY AUTO_INCREMENT,
    cache_key varchar(255) UNIQUE NOT NULL,
    cache_data longtext NOT NULL,
    expires_at datetime NOT NULL,
    
    INDEX idx_expires (expires_at)
);
```

#### `{prefix}_td_audit_log`
Audit trail for bookings and system events.

```sql
CREATE TABLE wp_td_audit_log (
    id int(11) PRIMARY KEY AUTO_INCREMENT,
    booking_id int(11),
    action varchar(50) NOT NULL,
    details text,
    user_id int(11),
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_booking (booking_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
);
```

#### `{prefix}_td_staff_breaks`
Staff-wide breaks and holidays.

```sql
CREATE TABLE wp_td_staff_breaks (
    id int(11) PRIMARY KEY AUTO_INCREMENT,
    staff_id int(11) DEFAULT 0, -- 0 = all staff
    start_utc datetime NOT NULL,
    end_utc datetime NOT NULL,
    type enum('break','holiday') DEFAULT 'break',
    notes text,
    created_at datetime,
    updated_at datetime,
    
    INDEX idx_staff (staff_id),
    INDEX idx_timerange (start_utc, end_utc)
);
```

## Plugin Structure

```
td-booking/
├── td-booking.php              # Main plugin file
├── readme.txt                  # WordPress.org readme
├── DEVELOPER.md               # This file
├── USER-MANUAL.md            # User documentation
├── uninstall.php             # Cleanup on uninstall
│
├── assets/                   # Frontend assets
│   ├── css/
│   │   ├── admin.css        # Admin interface styles
│   │   └── public.css       # Public form styles
│   └── js/
│       ├── admin.js         # Admin interface scripts
│       └── public.js        # Public form scripts
│
├── includes/                 # Core functionality
│   ├── loader.php           # Main loader and initialization
│   ├── hooks.php            # WordPress hooks and filters
│   ├── schema.php           # Database schema management
│   ├── service-container.php # Dependency injection
│   ├── capabilities.php     # User capabilities
│   ├── helpers.php          # Utility functions
│   ├── time.php             # Time handling utilities
│   ├── nonce.php            # Security nonce management
│   ├── ratelimit.php        # API rate limiting
│   ├── debug.php            # Debug utilities
│   ├── mailer.php           # Email system
│   ├── email-templates.php  # Email template management
│   ├── sms.php              # SMS integration (future)
│   ├── cron-sms.php         # SMS cron jobs (future)
│   ├── ics.php              # iCalendar generation
│   └── integration.php      # Third party integrations
│
│   ├── admin/               # Admin interface
│   │   ├── class-admin-menu.php # Admin menu and pages
│   │   └── pages/           # Individual admin pages
│   │       ├── services-list.php
│   │       ├── service-edit.php
│   │       ├── bookings-list.php
│   │       ├── booking-view.php
│   │       ├── settings.php
│   │       ├── staff-breaks.php
│   │       ├── logs.php
│   │       └── reports.php
│   │
│   ├── rest/                # REST API endpoints
│   │   ├── public-services.php      # GET /services
│   │   ├── public-availability.php  # GET /availability
│   │   ├── public-book.php          # POST /book
│   │   ├── public-booking-actions.php # POST /booking/{id}/cancel
│   │   ├── admin-tools.php          # Admin API endpoints
│   │   └── debug-integration.php    # Debug endpoints
│   │
│   ├── widgets/             # Frontend components
│   │   ├── booking-form-shortcode.php # [td_booking_form] shortcode
│   │   └── booking-form-widget.php    # WordPress widget (future)
│   │
│   ├── availability/        # Availability calculation
│   │   ├── engine.php       # Core availability logic
│   │   └── cache.php        # Availability caching
│   │
│   ├── assignment/          # Staff assignment
│   │   └── roundrobin.php   # Round-robin assignment
│   │
│   ├── caldav/              # CalDAV integration
│   │   ├── client.php       # CalDAV client
│   │   └── mapper.php       # Booking <-> CalDAV mapping
│   │
│   └── jobs/                # Background processing
│       ├── scheduler.php    # Job scheduling
│       ├── reconcile.php    # CalDAV reconciliation
│       └── retry.php        # Failed job retry
│
└── languages/               # Internationalization
    └── td-booking.pot       # Translation template
```

## REST API Reference

### Base URL

All API endpoints are available at:
```
https://yoursite.com/wp-json/td/v1/
```

### Authentication

- **Public Endpoints**: No authentication required
- **Admin Endpoints**: Require WordPress authentication and `manage_td_booking` capability

### Public Endpoints

#### GET /services

Returns list of active services.

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Computer Repair",
      "slug": "computer-repair",
      "description": "Professional computer diagnostics and repair",
      "duration_minutes": 120,
      "price": "75.00"
    }
  ]
}
```

#### GET /availability

Check availability for specific date range and service.

**Parameters:**
- `service_id` (required): Service ID
- `start_date` (required): Start date (YYYY-MM-DD)
- `end_date` (required): End date (YYYY-MM-DD)
- `group_size` (optional): Number of people (default: 1)

**Response:**
```json
{
  "success": true,
  "data": {
    "2024-12-20": [
      {
        "start": "2024-12-20T09:00:00Z",
        "end": "2024-12-20T11:00:00Z",
        "available": true
      }
    ]
  }
}
```

#### POST /book

Create a new booking.

**Request Body:**
```json
{
  "service_id": 1,
  "start_utc": "2024-12-20T09:00:00Z",
  "customer_name": "John Doe",
  "customer_email": "john@example.com",
  "customer_phone": "+1234567890",
  "customer_address": "123 Main St",
  "group_size": 1,
  "notes": "Special requirements"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "booking_id": 123,
    "confirmation_number": "BKG-20241220-123",
    "status": "confirmed"
  }
}
```

#### POST /booking/{id}/cancel

Cancel an existing booking.

**Parameters:**
- `id`: Booking ID

### Admin Endpoints

#### POST /admin/test-connection

Test CalDAV server connection.

**Request Body:**
```json
{
  "server_url": "https://nextcloud.example.com/remote.php/dav/calendars/user/",
  "username": "calendar_user",
  "password": "app_password"
}
```

#### POST /admin/reconcile

Sync with CalDAV server.

**Request Body:**
```json
{
  "force": false,
  "dry_run": true
}
```

#### POST /admin/debug-slot

Debug specific time slot availability.

**Request Body:**
```json
{
  "service_id": 1,
  "datetime": "2024-12-20T09:00:00Z"
}
```

## Hooks & Filters

TD Booking provides extensive customization through WordPress hooks.

### Action Hooks

#### `td_booking_before_save_booking`
Fired before a booking is saved to the database.

```php
add_action('td_booking_before_save_booking', function($booking_data) {
    // Modify booking data before saving
    error_log('New booking: ' . print_r($booking_data, true));
});
```

#### `td_booking_after_save_booking`
Fired after a booking is successfully saved.

```php
add_action('td_booking_after_save_booking', function($booking_id, $booking_data) {
    // Send custom notifications, integrate with other systems
    update_post_meta($booking_id, 'custom_field', $booking_data['notes']);
}, 10, 2);
```

#### `td_booking_booking_cancelled`
Fired when a booking is cancelled.

```php
add_action('td_booking_booking_cancelled', function($booking_id, $reason) {
    // Handle cancellation logic
    do_action('send_cancellation_sms', $booking_id);
});
```

#### `td_booking_email_sent`
Fired after an email is sent.

```php
add_action('td_booking_email_sent', function($email_type, $recipient, $success) {
    // Log email activity
    error_log("Email {$email_type} to {$recipient}: " . ($success ? 'sent' : 'failed'));
}, 10, 3);
```

### Filter Hooks

#### `td_booking_available_slots`
Filter available time slots before returning to client.

```php
add_filter('td_booking_available_slots', function($slots, $service_id, $date) {
    // Remove slots during lunch break
    return array_filter($slots, function($slot) {
        $hour = date('H', strtotime($slot['start']));
        return !($hour >= 12 && $hour < 13);
    });
}, 10, 3);
```

#### `td_booking_technician_assignment`
Filter technician assignment logic.

```php
add_filter('td_booking_technician_assignment', function($technician_id, $service_id, $datetime) {
    // Custom assignment logic
    if ($service_id === 5) { // VIP service
        return get_option('vip_technician_id');
    }
    return $technician_id;
}, 10, 3);
```

#### `td_booking_email_template`
Filter email templates before sending.

```php
add_filter('td_booking_email_template', function($template, $type, $booking_data) {
    if ($type === 'confirmation') {
        // Add custom content to confirmation emails
        $template .= "\n\nSpecial instructions: Please arrive 10 minutes early.";
    }
    return $template;
}, 10, 3);
```

#### `td_booking_caldav_event_data`
Filter CalDAV event data before sync.

```php
add_filter('td_booking_caldav_event_data', function($event_data, $booking) {
    // Add custom properties to CalDAV events
    $event_data['DESCRIPTION'] .= "\nCustomer Phone: " . $booking['customer_phone'];
    return $event_data;
}, 10, 2);
```

## CalDAV Integration

TD Booking includes a full CalDAV client for bidirectional calendar synchronization.

### Supported Servers

- **Nextcloud/ownCloud** - Full support
- **iCloud** - Full support (requires app-specific password)
- **Google Calendar** - Read/write via CalDAV
- **Office 365** - Limited support
- **Generic CalDAV** - Standards-compliant servers

### Configuration

CalDAV settings are stored in WordPress options:

```php
$caldav_config = [
    'enabled' => get_option('td_bkg_caldav_enabled', false),
    'server_url' => get_option('td_bkg_caldav_server_url'),
    'username' => get_option('td_bkg_caldav_username'),
    'password' => get_option('td_bkg_caldav_password'), // Encrypted
    'calendar_path' => get_option('td_bkg_caldav_calendar_path'),
    'sync_interval' => get_option('td_bkg_caldav_sync_interval', 300), // seconds
];
```

### Sync Process

1. **Fetch Remote Events**: Query CalDAV server for calendar events
2. **Compare ETags**: Check for changes since last sync
3. **Update Local Bookings**: Create/update bookings from calendar events
4. **Push Local Changes**: Send new/modified bookings to CalDAV server
5. **Store Sync Metadata**: Update ETags and timestamps

### Error Handling

CalDAV sync includes comprehensive error handling:

```php
try {
    $client = new TD_Booking_CalDAV_Client($config);
    $result = $client->sync_calendar();
} catch (TD_Booking_CalDAV_Exception $e) {
    error_log('CalDAV sync failed: ' . $e->getMessage());
    
    // Schedule retry
    wp_schedule_single_event(time() + 300, 'td_booking_retry_caldav_sync');
}
```

## Email System

TD Booking includes a flexible email system with template support.

### Email Types

- **confirmation** - Booking confirmation
- **cancellation** - Booking cancellation
- **reminder** - Appointment reminder (future)
- **rescheduled** - Booking changed (future)

### Template System

Email templates support placeholders for dynamic content:

```php
$template = "Dear {customer_name},\n\n" .
           "Your {service_name} appointment is confirmed for {appointment_date} at {appointment_time}.\n\n" .
           "Details:\n" .
           "Service: {service_name}\n" .
           "Date: {appointment_date}\n" .
           "Time: {appointment_time}\n" .
           "Duration: {duration_minutes} minutes\n\n" .
           "Location: {business_address}\n\n" .
           "To cancel or reschedule, please click: {cancel_link}";
```

Available placeholders:
- `{customer_name}` - Customer's name
- `{customer_email}` - Customer's email
- `{service_name}` - Service name
- `{appointment_date}` - Formatted date
- `{appointment_time}` - Formatted time
- `{duration_minutes}` - Service duration
- `{business_name}` - Business name
- `{business_address}` - Business address
- `{cancel_link}` - Cancellation URL
- `{confirmation_number}` - Booking reference

### Custom Templates

Override default templates using filters:

```php
add_filter('td_booking_email_template', function($template, $type, $booking) {
    if ($type === 'confirmation') {
        return get_option('my_custom_confirmation_template', $template);
    }
    return $template;
}, 10, 3);
```

## Caching System

TD Booking implements intelligent caching for performance optimization.

### Cache Types

1. **Availability Cache** - Time slot calculations
2. **Service Cache** - Service data and rules
3. **Staff Cache** - Technician availability
4. **CalDAV Cache** - Remote calendar data

### Cache Keys

Cache keys follow a consistent pattern:

```php
$cache_key = sprintf(
    'td_bkg_avail_%s_%s_%s',
    $service_id,
    $date,
    md5(serialize($parameters))
);
```

### Cache Invalidation

Caches are automatically invalidated when relevant data changes:

```php
// Clear availability cache when booking is created
add_action('td_booking_after_save_booking', function($booking_id) {
    td_booking_clear_availability_cache();
});

// Clear service cache when service is updated
add_action('td_booking_service_updated', function($service_id) {
    td_booking_clear_service_cache($service_id);
});
```

### Manual Cache Management

Admin interface provides cache management tools:

```php
// Clear all caches
td_booking_clear_all_caches();

// Clear specific cache type
td_booking_clear_cache('availability');

// Warm up caches
td_booking_warm_availability_cache();
```

## Staff Assignment Engine

TD Booking includes an intelligent staff assignment system that integrates with the TD Technicians plugin.

### Assignment Strategies

#### Round Robin (Default)
Distributes bookings evenly among qualified technicians:

```php
class TD_Booking_Assignment_RoundRobin {
    public function assign_technician($service_id, $datetime, $duration) {
        $qualified = $this->get_qualified_technicians($service_id);
        $available = $this->filter_available($qualified, $datetime, $duration);
        
        return $this->select_next_in_rotation($available);
    }
}
```

#### Custom Assignment
Implement custom assignment logic:

```php
add_filter('td_booking_technician_assignment', function($assigned_id, $service_id, $datetime) {
    // Priority assignment based on service type
    if ($service_id === 1) { // Emergency repairs
        return get_option('emergency_technician_id');
    }
    
    // Load balancing based on current workload
    $technicians = td_tech()->get_available_technicians($datetime);
    return $this->get_least_busy_technician($technicians);
}, 10, 3);
```

### Qualification Matching

Technicians are matched to services based on qualifications:

```php
$qualified_technicians = td_tech()->get_qualified_for_service($service_id);
```

### Availability Checking

Staff availability considers multiple factors:

1. **Working Hours** - Individual schedules
2. **Existing Bookings** - Current appointments
3. **Staff Breaks** - Organization-wide breaks/holidays
4. **CalDAV Events** - External calendar conflicts

## Customization Guide

### Adding Custom Fields

Add custom fields to bookings:

```php
// Add field to booking form
add_action('td_booking_form_fields', function() {
    echo '<div class="td-booking-field">';
    echo '<label for="special_requests">Special Requests:</label>';
    echo '<textarea name="special_requests" id="special_requests"></textarea>';
    echo '</div>';
});

// Save custom field
add_action('td_booking_before_save_booking', function($booking_data) {
    if (isset($_POST['special_requests'])) {
        $booking_data['notes'] .= "\nSpecial Requests: " . sanitize_textarea_field($_POST['special_requests']);
    }
    return $booking_data;
});
```

### Custom Validation
### Shortcodes: service/staff enforcement

Three shortcodes render the booking form with optional preselection/restriction:

- `[td_booking_form service="slug-or-id" address="on|off" title="..."]`
- `[td_booking_service service="slug-or-id" title="..."]`
- `[td_booking_staff staff="ID" title="..."]`
- `[td_booking_service_staff service="slug-or-id" staff="ID" title="..."]`

Client → server contract:
- When a staff restriction is applied (via staff attribute), the front-end passes `with_staff=1&staff_id={ID}` to GET /availability and `staff_id` in POST /book. The backend filters availability per staff and enforces that the chosen slot matches the staff_id. If not, booking returns 409 not_available.

Rendering placement details:
- The extra participants container (`.td-participants`) is under the `.customer-info` section to avoid double selectors and keep related inputs together. It’s hidden initially and shown dynamically when participants > 1.

### Group bookings contract

- Front-end visible field: `participants` (int ≥ 1). JS maps it to `group_size` in POST /book.
- Backend uses `group_size` for capacity checks during availability verification and booking creation.
- Additional participant details (arrays `p_name[]`, `p_email[]`, `p_phone[]`) are posted under `participants` array in JSON for optional custom persistence (not stored by default).


Add custom booking validation:

```php
add_filter('td_booking_validate_booking', function($is_valid, $booking_data) {
    // Require phone number for weekend bookings
    $day_of_week = date('N', strtotime($booking_data['start_utc']));
    if (($day_of_week == 6 || $day_of_week == 7) && empty($booking_data['customer_phone'])) {
        wp_die('Phone number required for weekend appointments.');
    }
    
    return $is_valid;
}, 10, 2);
```

### Custom Service Types

Extend service functionality:

```php
// Add service type field
add_action('td_booking_service_edit_form', function($service_id) {
    $service_type = get_post_meta($service_id, 'service_type', true);
    echo '<tr>';
    echo '<th>Service Type</th>';
    echo '<td><select name="service_type">';
    echo '<option value="standard"' . selected($service_type, 'standard', false) . '>Standard</option>';
    echo '<option value="premium"' . selected($service_type, 'premium', false) . '>Premium</option>';
    echo '</select></td>';
    echo '</tr>';
});

// Handle different pricing for service types
add_filter('td_booking_service_price', function($price, $service_id) {
    $service_type = get_post_meta($service_id, 'service_type', true);
    if ($service_type === 'premium') {
        return $price * 1.5; // 50% premium surcharge
    }
    return $price;
}, 10, 2);
```

### Integration with Other Plugins

#### WooCommerce Integration

```php
add_action('td_booking_after_save_booking', function($booking_id, $booking_data) {
    if (class_exists('WooCommerce')) {
        // Create WooCommerce order for booking
        $order = wc_create_order();
        $product_id = get_option('td_booking_default_product_id');
        $order->add_product(get_product($product_id), 1);
        $order->calculate_totals();
        
        // Link booking to order
        update_post_meta($booking_id, 'wc_order_id', $order->get_id());
    }
}, 10, 2);
```

#### Event Calendar Integration

```php
add_action('td_booking_after_save_booking', function($booking_id, $booking_data) {
    if (function_exists('tribe_create_event')) {
        // Create event in The Events Calendar
        $event_id = tribe_create_event([
            'post_title' => $booking_data['service_name'] . ' - ' . $booking_data['customer_name'],
            'post_content' => 'Booking ID: ' . $booking_id,
            'post_status' => 'publish',
            'EventStartDate' => $booking_data['start_utc'],
            'EventEndDate' => $booking_data['end_utc'],
        ]);
    }
}, 10, 2);
```

## Testing & Debugging

### Debug Mode

Enable debug mode for detailed logging:

```php
define('TD_BOOKING_DEBUG', true);
```

### Debug Endpoints

The plugin includes debug endpoints for testing:

```bash
# Test availability calculation
curl -X POST "https://yoursite.com/wp-json/td/v1/admin/debug-slot" \
  -H "Content-Type: application/json" \
  -d '{"service_id": 1, "datetime": "2024-12-20T09:00:00Z"}'

# Test CalDAV connection
curl -X POST "https://yoursite.com/wp-json/td/v1/admin/test-connection" \
  -H "Content-Type: application/json" \
  -d '{"server_url": "https://cal.example.com/", "username": "user", "password": "pass"}'
```

### Logging

TD Booking logs important events to WordPress debug log:

```php
if (TD_BOOKING_DEBUG) {
    error_log('TD Booking: ' . $message);
}
```

### Unit Testing

Basic PHPUnit test structure:

```php
class TD_Booking_Tests extends WP_UnitTestCase {
    
    public function test_booking_creation() {
        $booking_data = [
            'service_id' => 1,
            'start_utc' => '2024-12-20 09:00:00',
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com'
        ];
        
        $booking_id = td_booking_create_booking($booking_data);
        $this->assertIsInt($booking_id);
        $this->assertGreaterThan(0, $booking_id);
    }
    
    public function test_availability_calculation() {
        $slots = td_booking_get_availability(1, '2024-12-20', '2024-12-20');
        $this->assertIsArray($slots);
    }
}
```

### Performance Testing

Monitor performance with built-in timing:

```php
$start_time = microtime(true);
$availability = td_booking_get_availability($service_id, $date, $date);
$execution_time = microtime(true) - $start_time;

if ($execution_time > 1.0) {
    error_log("Slow availability query: {$execution_time}s");
}
```

## Contributing

When contributing to TD Booking:

1. **Follow WordPress Coding Standards**
2. **Add appropriate hooks and filters**
3. **Include comprehensive error handling**
4. **Write unit tests for new functionality**
5. **Update this documentation**
6. **Test with multiple WordPress/PHP versions**

### Code Style

```php
// Good
class TD_Booking_Feature {
    private $dependency;
    
    public function __construct(TD_Booking_Dependency $dependency) {
        $this->dependency = $dependency;
    }
    
    public function process_data($input) {
        $sanitized = sanitize_text_field($input);
        
        if (empty($sanitized)) {
            return new WP_Error('invalid_input', __('Input cannot be empty', 'td-booking'));
        }
        
        return $this->dependency->handle($sanitized);
    }
}
```

### Security Considerations

1. **Always sanitize input**: Use `sanitize_*` functions
2. **Validate permissions**: Check user capabilities
3. **Use nonces**: Protect forms against CSRF
4. **Escape output**: Use `esc_*` functions
5. **Prepare SQL**: Use `$wpdb->prepare()` for queries

```php
// Input sanitization
$service_id = intval($_POST['service_id']);
$customer_name = sanitize_text_field($_POST['customer_name']);
$notes = sanitize_textarea_field($_POST['notes']);

// Permission check
if (!current_user_can('manage_td_booking')) {
    wp_die('Insufficient permissions');
}

// Nonce verification
if (!wp_verify_nonce($_POST['_wpnonce'], 'td_booking_action')) {
    wp_die('Security check failed');
}

// Safe SQL query
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}td_bookings WHERE service_id = %d",
    $service_id
));

// Output escaping
echo '<h1>' . esc_html($booking['customer_name']) . '</h1>';
echo '<a href="' . esc_url($cancel_link) . '">Cancel</a>';
```

This developer documentation provides comprehensive technical information for extending and customizing TD Booking. For user-focused documentation, see the [User Manual](USER-MANUAL.md).

## Internationalization (i18n)

The plugin is fully translatable. Text domain: `td-booking`, Domain Path: `languages/`.

Files:
- `languages/td-booking.pot` – template
- `languages/td-booking-nb_NO.po` – Norwegian Bokmål source
- `languages/td-booking-nb_NO.mo` – compiled binary (load this on the site)

Workflow (offline, no server installs):
1) Generate/refresh POT from source (run locally on your machine):
    - Using WP-CLI i18n: wp i18n make-pot . languages/td-booking.pot --domain=td-booking
    - Or Poedit: File → New from POT/Source code → set text domain to td-booking → Save into languages/

2) Merge updates into an existing PO (e.g., nb_NO):
    - With msgmerge: msgmerge --update languages/td-booking-nb_NO.po languages/td-booking.pot
    - With Poedit: Open the .po, Catalog → Update from POT file…

3) Compile MO from PO (locally):
    - msgfmt -o languages/td-booking-nb_NO.mo languages/td-booking-nb_NO.po
    - Or just Save in Poedit (it writes the .mo next to the .po)

4) Deploy only the .mo (and updated .po/.pot if you keep them in VCS) to the server. Do not install gettext tools on the server.

Notes:
- Use `__()`, `esc_html__()`, `esc_attr__()`, `esc_js()`, and `wp_date()` where appropriate.
- Add translators’ comments for placeholders, e.g. /* translators: %d = number of services */.
- For JS, pass translated strings via localized data from PHP.

## At-rest Encryption

This plugin can encrypt secrets and PII using libsodium (XChaCha20-Poly1305) with envelope format, plus deterministic HMAC indexes for exact-match search.

What’s encrypted initially:
- SMS API key (option `td_bkg_sms_api_key`) – stored as a JSON envelope when crypto is available, kept masked in UI, decrypted only at send time.

Planned (optional) PII encryption for bookings:
- Email, phone, address, notes as envelopes; keep name plaintext for usability. Add HMAC columns (email_hash/phone_hash) for search.

Requirements outside this plugin:
- PHP sodium extension enabled.
- Add the following to wp-config.php (keys are base64-encoded 32-byte values):

```php
define('TD_BKG_KMS_ACTIVE_KID', 'v1');
define('TD_BKG_KMS_KEY_V1', 'base64-encoded-32-bytes');
define('TD_BKG_HMAC_KEY_V1', 'base64-encoded-32-bytes');
```

Generate secure keys locally (examples):
- Linux/macOS: `dd if=/dev/urandom bs=32 count=1 | base64`
- Node.js: `node -e "console.log(require('crypto').randomBytes(32).toString('base64'))"`

How it works:
- Envelope JSON: { alg: "XChaCha20-Poly1305", kid: "v1", n: base64(nonce), c: base64(cipher) }.
- AAD binds context (e.g., 'td-booking:sms') to prevent cross-context misuse.
- Deterministic HMAC (SHA-256) provides hex indexes for exact search without revealing plaintext.

Fallback behavior:
- If libsodium/keys are missing, values are stored in plaintext. Settings page shows a warning so you can add keys later.

Rotation:
- Define a new key and switch TD_BKG_KMS_ACTIVE_KID. Implement a one-time migration to re-encrypt values if needed.
