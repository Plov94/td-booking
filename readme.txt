=== TD Booking ===
Contributors: Gabriel K. Sagaard
Tags: booking, appointments, caldav, technicians, scheduling, calendar, woocommerce
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: td-booking

Professional booking management system for WordPress with CalDAV sync, staff scheduling, and WooCommerce integration.

== Description ==

TD Booking is a comprehensive appointment scheduling and booking management system designed for service-based businesses. It provides a complete solution for managing services, bookings, staff availability, and customer communications.

**Key Features:**

* **Service Management** - Create and manage unlimited services with duration, pricing, and availability settings
* **Smart Staff Assignment** - Automatic technician assignment based on availability and qualifications
* **CalDAV Integration** - Bidirectional sync with Nextcloud, iCloud, Google Calendar, and other CalDAV servers
* **Staff Breaks & Holidays** - Manage staff-wide breaks, holidays, and unavailable periods
* **Email Notifications** - Customizable email templates for booking confirmations and updates
* **WooCommerce Integration** - Optional integration for payment processing and order management
* **Public Booking Forms** - Easy-to-use shortcodes and widgets for customer self-booking
* **Comprehensive Admin Interface** - Full-featured backend for managing all aspects of your booking system
* **REST API** - Complete API for integrations and custom applications
* **Internationalization Ready** - Fully translatable with .pot file included

**Perfect for:**
* Service providers (technicians, consultants, repairs)
* Healthcare providers (appointments, consultations)
* Personal services (beauty, fitness, coaching)
* Professional services (legal, accounting, real estate)
* Any business requiring appointment scheduling

== Installation ==

**Requirements:**
* WordPress 6.2 or higher
* PHP 8.0 or higher
* TD Technicians plugin (dependency)
* MySQL/MariaDB database

**Installation Steps:**

1. **Install TD Technicians Plugin First** (required dependency)
   - Download and install the TD Technicians plugin
   - Activate it before installing TD Booking

2. **Install TD Booking**
   - Upload the plugin files to `/wp-content/plugins/td-booking/`
   - Or install via WordPress admin: Plugins > Add New > Upload
   - Activate the plugin through the 'Plugins' screen

3. **Initial Setup**
   - Go to TD Booking > Settings in your WordPress admin
   - Configure your email settings
   - Set up business hours and availability preferences
   - Add your first service via TD Booking > Services

4. **Optional: CalDAV Setup**
   - Navigate to TD Booking > Settings
   - Enter your CalDAV server details (Nextcloud, iCloud, etc.)
   - Test the connection and enable sync

== Configuration ==

**Basic Setup:**

1. **Services Configuration**
   - Create services with names, descriptions, and durations
   - Set pricing if using WooCommerce integration
   - Configure availability and staff assignment rules

2. **Email Settings**
   - Set custom "From" email address
   - Customize email templates for confirmations
   - Test email delivery

3. **Staff Management**
   - Use TD Technicians plugin to create staff profiles
   - Set individual availability and qualifications
   - Manage staff-wide breaks and holidays

4. **CalDAV Integration** (Optional)
   - Obtain CalDAV server credentials
   - Configure server URL, username, and password
   - Enable bidirectional sync for calendar events

**Advanced Settings:**

* Business hours configuration
* Booking time slot intervals
* Group booking settings
* Cache management
* Debug logging

== Usage ==

**For Administrators:**

1. **Managing Services**
   - Navigate to TD Booking > Services
   - Add/edit services with full details
   - Set availability rules and staff assignments

2. **Viewing Bookings**
   - Go to TD Booking > Bookings
   - View daily, weekly, or custom date ranges
   - Manage booking status and customer information

3. **Staff Scheduling**
   - Use TD Booking > Staff-wide Breaks & Holidays
   - Set vacation periods, breaks, and closures
   - Override individual staff availability

4. **Reports and Analytics**
   - Access TD Booking > Reports (if enabled)
   - View booking statistics and trends
   - Generate performance reports

**For Customers:**

1. **Booking Appointments**
   - Use the `[td_booking_form]` shortcode on any page
   - Select service, date, and time
   - Complete booking with contact information

2. **Managing Bookings**
   - Receive email confirmations
   - Cancel bookings via email links
   - Automatic calendar invitations (if CalDAV enabled)

== Shortcodes ==

**[td_booking_form]**
The main booking form for customers.

Parameters:
* `service` - Pre-select a specific service by slug
* `address` - Show/hide address field ("on" or "off")
* `title` - Custom form title

Examples:
```
[td_booking_form]
[td_booking_form service="repair-service" address="on"]
[td_booking_form title="Book Your Appointment"]
```

== REST API ==

TD Booking provides a comprehensive REST API for integrations:

**Public Endpoints:**
* `GET /wp-json/td/v1/services` - List available services
* `GET /wp-json/td/v1/availability` - Check availability for dates/times
* `POST /wp-json/td/v1/book` - Create new booking
* `POST /wp-json/td/v1/booking/{id}/cancel` - Cancel existing booking

**Admin Endpoints:**
* `POST /wp-json/td/v1/admin/test-connection` - Test CalDAV connection
* `POST /wp-json/td/v1/admin/reconcile` - Sync with CalDAV server
* `POST /wp-json/td/v1/admin/retry-failed` - Retry failed operations
* `POST /wp-json/td/v1/admin/debug-slot` - Debug specific time slots

== Frequently Asked Questions ==

= Do I need the TD Technicians plugin? =

Yes, TD Technicians is a required dependency. TD Booking uses it for staff management and assignment functionality.

= Can I integrate with my existing calendar? =

Yes! TD Booking supports CalDAV integration with Nextcloud, iCloud, Google Calendar, and any CalDAV-compatible server.

= Does it work with WooCommerce? =

Yes, TD Booking has optional WooCommerce integration for payment processing and order management.

= Can I customize the booking form? =

The booking form can be customized through the shortcode parameters and WordPress themes. Advanced customization is possible through hooks and filters.

= How are times handled across time zones? =

All times are stored in UTC in the database and converted to the appropriate time zone for display based on WordPress settings.

= Can I set staff-specific availability? =

Yes, use the TD Technicians plugin for individual staff availability, and TD Booking's staff breaks feature for organization-wide scheduling.

= Is the plugin translation-ready? =

Yes, TD Booking is fully internationalized with a complete .pot file for translators.

= What happens if CalDAV sync fails? =

TD Booking includes automatic retry mechanisms and detailed logging. Failed sync attempts can be retried manually from the admin interface.

== Screenshots ==

1. **Services Management** - Create and manage your services
2. **Booking Calendar** - View and manage all bookings
3. **Settings Panel** - Configure email, CalDAV, and business rules  
4. **Public Booking Form** - Customer-facing booking interface
5. **Staff Breaks Management** - Manage holidays and unavailable periods
6. **Reports Dashboard** - Analytics and booking statistics

== Changelog ==

= 0.1.0 - 2024-12-19 =
**Initial Release**

* Complete booking management system
* Service creation and management interface
* Automatic staff assignment via TD Technicians integration
* CalDAV bidirectional sync (Nextcloud, iCloud, Google Calendar)
* Staff-wide breaks and holidays management
* Customizable email notifications with templates
* Public booking forms via shortcodes
* WooCommerce integration for payments
* Comprehensive REST API endpoints
* Multi-language support (i18n ready)
* Advanced admin tools and debugging features
* Booking confirmation and cancellation system
* Cache management for optimal performance
* Detailed audit logging
* Admin dashboard with booking overview
* Responsive design for mobile compatibility

== Upgrade Notice ==

= 0.1.0 =
Initial release of TD Booking. Requires TD Technicians plugin as dependency.
