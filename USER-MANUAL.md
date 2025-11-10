# TD Booking – User Manual

This guide covers how to add the booking form to your site, configure key options, and enable the optional step-by-step flow, terms modal, and staff/service specific shortcodes.

## Add the booking form

- Use the shortcode on any page or post:
   - [td_booking_form]
   - Optional attributes:
      - service="slug-or-id" to preselect a service
      - address="on|off" to show/hide the Address field (default on)
      - title="Custom Title" heading above the form

Examples:

- Basic: [td_booking_form]
- Preselected Service: [td_booking_form service="haircut"]
- Hide address + custom title: [td_booking_form address="off" title="Book now"]

## Staff/service-specific shortcodes

When you want to constrain bookings to a specific technician or service, use these helpers. They render the same form as [td_booking_form] but pre-limit the selection.

- [td_booking_service service="slug-or-id" title="..."]
   - Preselects a service. The customer can still change dates/times.

- [td_booking_staff staff="ID" title="..."]
   - Restricts available slots to a single staff member. Availability and booking are enforced server-side for this staff.

- [td_booking_service_staff service="slug-or-id" staff="ID" title="..."]
   - Combines both preselected service and restricted staff.

Notes:
- The UI will only show slots for the specified staff, and the server will reject attempts to book a different staff at that time.
- If the staff is not mapped to the chosen service or is unavailable, the day/time will not show, or booking will return “Selected time is no longer available”.

Tip: You can quickly create a WordPress page to test these shortcodes in Admin → TD Booking → Customer Demo. Use the "Create shortcode test page" form (enter a service slug/ID and/or a staff ID) and it will publish a page with the appropriate shortcode.

## Step-by-step booking UI

You can optionally gate the form into steps: pick a service → pick date/time → fill customer details → submit.

Enable it in Settings → UI & Privacy → “Step-by-step booking UI”. When enabled, the calendar and slots are hidden until a service is selected, and the customer information appears after a slot is chosen. The Book button is disabled until required inputs are filled and Terms are accepted.

## Terms link or modal

In Settings → UI & Privacy, configure a Terms page or external URL. Choose display mode:
- Link: the terms text links to your page in a new tab.
- Modal: clicking the terms text opens a modal. The plugin will try to load and extract the readable content from the page; if cross-origin blocks it, it falls back to opening a new tab.

You can customize the checkbox label via the “Terms text” option.

## Group bookings

If “Group bookings” is enabled in Settings, the form shows a small (+/−) quantity UI labeled “Number of participants”. The native number spinners are hidden to avoid duplicate controls. When you increase the count above 1, the form reveals additional participant fields under Customer Information (Participant #2, Participant #3, etc.).

How it maps:
- Front-end field “Number of participants” maps to backend group_size for capacity checks.
- Additional participant details (name/email/phone) are collected in the form; by default they’re not persisted to the database unless you extend the plugin (see Developer docs for options).

Step-by-step mode:
- When enabled, the extra participant fields remain hidden until after you’ve selected a time slot (along with the rest of the customer info section).

## Embedding and demos

- The demo pages in the plugin root show the form in standalone contexts:
   - customer-booking-demo.html
   - api-integration-examples.html

You can also embed the booking form on external sites using an iframe pointing to a WordPress page containing the shortcode. Ensure CORS is permitted if you rely on the Terms modal to load content inline; otherwise the fallback will open a new tab.

Shortcode test pages:
- In Admin → TD Booking → Customer Demo you can create a page specifically for testing a service-only, staff-only, or combined service+staff shortcode.

## CalDAV reschedule and reliability

Rescheduling deletes the old CalDAV event and creates a new one with a fresh UID. Sync diagnostics and audit logs are recorded with sensitive values masked. Failed or conflicted sync states are treated as busy for availability.

## Troubleshooting

- No slots showing: verify service hours, staff schedules, lead time/horizon, and that staff are mapped to the service.
- Staff-specific page shows others’ times: confirm you used [td_booking_staff staff="ID"] or the combined shortcode and that the staff is correctly mapped to the service.
- Terms modal blank: if the Terms URL is on another domain and disallows cross-origin fetch, the link will open in a new tab.
- CalDAV sync errors: check Settings → Debug Tools (enable Debug Mode first) and review Logs for masked error details.

## Table of Contents

1. [Getting Started](#getting-started)
2. [Initial Setup](#initial-setup)
3. [Managing Services](#managing-services)
4. [Booking Management](#booking-management)
5. [Staff Scheduling](#staff-scheduling)
6. [Email Configuration](#email-configuration)
7. [CalDAV Integration](#caldav-integration)
8. [Customer Booking Process](#customer-booking-process)
9. [Reports and Analytics](#reports-and-analytics)
10. [Settings and Configuration](#settings-and-configuration)
11. [Troubleshooting](#troubleshooting)
12. [Frequently Asked Questions](#frequently-asked-questions)

## Getting Started

TD Booking is a comprehensive appointment scheduling system for WordPress that helps you manage services, bookings, staff availability, and customer communications. This manual will guide you through every feature and help you get the most out of your booking system.

### Prerequisites

Before using TD Booking, ensure you have:

- WordPress 6.2 or higher
- PHP 8.0 or higher
- TD Technicians plugin installed and activated
- Administrative access to your WordPress site

### Key Concepts

- **Services**: The appointments or services you offer to customers
- **Bookings**: Individual appointments scheduled by customers
- **Technicians**: Staff members who perform services (managed by TD Technicians plugin)
- **Staff Breaks**: Organization-wide holidays and unavailable periods
- **CalDAV**: Calendar synchronization with external calendar services

## Initial Setup

### Step 1: Plugin Installation

1. **Install TD Technicians First** (Required)
   - Go to **Plugins > Add New**
   - Search for "TD Technicians" 
   - Install and activate the plugin
   - Add at least one technician profile

2. **Install TD Booking**
   - Upload the TD Booking plugin files
   - Activate through **Plugins > Installed Plugins**
   - You'll see "TD Booking" appear in your admin menu

### Step 2: Initial Configuration

After activation, go to **TD Booking > Settings** to configure basic settings:

1. **Email Settings**
   - Set your "From Email" address for booking confirmations
   - Test email delivery using the built-in tester
   - Configure custom email templates (optional)

2. **Business Hours**
   - Set your standard operating hours
   - Configure time slot intervals (15, 30, or 60 minutes)
   - Set booking advance notice requirements

3. **Basic Options**
   - Enable/disable features like group bookings
   - Set default booking status (pending/confirmed)
   - Configure customer information requirements

### Step 3: Create Your First Service

1. Navigate to **TD Booking > Services**
2. Click **Add New**
3. Fill in the service details:
   - **Service Name**: e.g., "Computer Repair"
   - **Description**: Detailed description for customers
   - **Duration**: How long the service takes (in minutes)
   - **Price**: Cost of service (optional, for WooCommerce integration)
4. Click **Save Service**

## Managing Services

Services are the core of your booking system - they represent what customers can book appointments for.

### Creating Services

1. **Go to TD Booking > Services**
2. **Click "Add New"**
3. **Complete the service form:**

   **Basic Information:**
   - **Name**: Clear, descriptive service name
   - **Slug**: Auto-generated URL-friendly version
   - **Description**: Detailed explanation for customers
   - **Duration**: Service length in minutes
   - **Price**: Cost (if using WooCommerce)

   **Availability Settings:**
   - **Active**: Whether customers can book this service
   - **Advance Notice**: Minimum time before booking
   - **Max Advance**: How far ahead bookings are allowed

4. **Save your service**

### Service Management Tips

- **Use clear, customer-friendly names** like "Home Computer Repair" instead of "Tech Service #1"
- **Write detailed descriptions** to help customers understand what's included
- **Set realistic durations** including travel time if applicable
- **Consider seasonal services** - deactivate services during off-seasons

### Editing Services

1. Go to **TD Booking > Services**
2. Click **Edit** next to the service you want to modify
3. Make your changes
4. Click **Update Service**

**Note**: Changes to active services will affect existing bookings, so be careful with duration modifications.

### Service Categories (Advanced)

While TD Booking doesn't have built-in categories, you can organize services using naming conventions:

- **Home Services**: "Home Computer Repair", "Home Network Setup"
- **Business Services**: "Office IT Support", "Server Maintenance"
- **Remote Services**: "Remote Consultation", "Virtual Training"

## Booking Management

The booking management system helps you view, manage, and track all customer appointments.

### Viewing Bookings

#### Daily View
1. **Go to TD Booking > Bookings**
2. **Select today's date** (default view)
3. **Review today's schedule:**
   - See all bookings for the selected date
   - View customer information
   - Check booking status and technician assignments

#### Date Navigation
- **Use the date picker** to jump to specific dates
- **Navigation arrows** to move day by day
- **Today button** to return to current date

#### Booking Information Display
Each booking shows:
- **Time slot** and duration
- **Service name** and details
- **Customer name** and contact information
- **Assigned technician**
- **Booking status** (Pending, Confirmed, Cancelled, Completed)
- **Group size** (if applicable)

### Booking Status Management

#### Status Types
- **Pending**: New booking awaiting confirmation
- **Confirmed**: Booking confirmed and scheduled
- **Cancelled**: Booking cancelled by customer or admin
- **Completed**: Service has been completed

#### Changing Status
1. **Find the booking** in the list
2. **Click the status dropdown**
3. **Select new status**
4. **Status changes automatically trigger:**
   - Email notifications to customers
   - CalDAV sync updates
   - Audit log entries

### Managing Individual Bookings

#### Viewing Booking Details
1. **Click on a booking** in the list
2. **View comprehensive information:**
   - Complete customer details
   - Service specifications
   - Technician assignment
   - Booking history and notes
   - Payment information (if WooCommerce integrated)

#### Editing Bookings
- **Customer Information**: Update contact details
- **Time/Date**: Reschedule appointments
- **Service**: Change service type (affects duration/pricing)
- **Technician**: Reassign to different staff member
- **Notes**: Add internal notes or customer requests

#### Cancelling Bookings
1. **Select the booking**
2. **Change status to "Cancelled"**
3. **Add cancellation reason** in notes
4. **Customer receives automatic cancellation email**
5. **Calendar sync updates** remove the appointment

### Booking Analytics

The booking list provides quick insights:
- **Daily capacity utilization**
- **No-show tracking**
- **Popular services identification**
- **Peak booking times**

#### Daily Summary Features
- **✨ Encouragement messages** on slow days
- **📊 Capacity indicators** showing how busy you are
- **🔒 Closure notifications** for holidays
- **📈 Quick stats** on booking volume

## Staff Scheduling

Staff scheduling manages organization-wide availability, breaks, and holidays that affect all technicians.

### Understanding Staff Scheduling

TD Booking uses a two-layer availability system:
1. **Individual availability** (managed by TD Technicians plugin)
2. **Organization-wide breaks** (managed by TD Booking)

Staff-wide breaks override individual availability - if you set a holiday, no technicians will be available regardless of their personal schedules.

### Managing Staff Breaks & Holidays

#### Accessing Staff Breaks
1. **Go to TD Booking > Staff-wide Breaks & Holidays**
2. **View existing breaks** and holidays
3. **Add new entries** as needed

#### Adding Breaks/Holidays

1. **Click the form section** at the top of the page
2. **Fill in the details:**

   **Start Date/Time (UTC):**
   - Use the date/time picker
   - Times are stored in UTC for consistency
   - Consider your local timezone when setting

   **End Date/Time (UTC):**
   - Set when the break/holiday ends
   - Can span multiple days for vacation periods

   **Type:**
   - **Break**: Short-term unavailability (lunch, meetings)
   - **Holiday**: Longer periods (vacations, company holidays)

   **Notes:**
   - Optional description
   - Helpful for remembering why the break was set
   - Examples: "Company Christmas Party", "Annual Maintenance"

3. **Click "Save"**

#### Managing Existing Breaks

**Editing Breaks:**
1. **Click "Edit"** next to any break/holiday
2. **Modify the details** as needed
3. **Save changes**

**Deleting Breaks:**
1. **Click "Delete"** next to the entry
2. **Confirm the deletion**
3. **Break is immediately removed** and availability restored

### Planning Staff Schedules

#### Annual Holiday Planning
1. **Plan major holidays early** in the year
2. **Add company holidays** like Christmas, New Year's Day
3. **Include staff vacation periods** when entire team is away
4. **Consider seasonal closures** for business types

#### Regular Break Management
- **Lunch breaks**: Daily recurring breaks
- **Staff meetings**: Weekly team meetings
- **Maintenance windows**: System downtime periods
- **Training sessions**: When staff are in training

#### Best Practices
- **Add breaks well in advance** so customers see accurate availability
- **Use clear, descriptive notes** for future reference
- **Coordinate with individual staff schedules** in TD Technicians
- **Review and clean up** old breaks periodically

### Impact on Customer Bookings

When staff breaks are active:
- **Booking form shows "unavailable"** for affected time slots
- **Existing bookings are protected** (breaks won't override confirmed appointments)
- **Calendar sync reflects** the unavailability
- **Availability API excludes** the blocked times

## Email Configuration

TD Booking includes a comprehensive email system for customer communications.

### Email Settings Overview

Navigate to **TD Booking > Settings** and scroll to the email section to configure:

#### From Email Configuration
- **Default behavior**: Uses WordPress admin email
- **Custom email**: Set a professional booking-specific address
- **Validation**: WordPress validates email addresses for deliverability
- **Override option**: Force custom email even if validation fails

#### Email Types
- **Booking Confirmations**: Sent when bookings are created
- **Cancellation Notifications**: Sent when bookings are cancelled
- **Reminder Emails**: Upcoming appointment reminders (future feature)

### Setting Up Email

#### Step 1: Configure From Address
1. **Go to TD Booking > Settings**
2. **Find "Email Settings" section**
3. **Enter your desired "From Email"**:
   - Use professional address like `bookings@yourbusiness.com`
   - Ensure the email domain matches your website for best deliverability
4. **Test the email** using the built-in tester

#### Step 2: Test Email Delivery
1. **Use the "Test Email Validation" tool**
2. **Enter a test email address**
3. **Click "Test Email"**
4. **Check for success/failure messages**
5. **Review error logs** if emails fail

#### Step 3: Monitor Email Status
The settings page shows:
- **Current "From Email" setting**
- **Email validation status**
- **Recent email activity**
- **Troubleshooting notes**

### Email Templates

TD Booking uses template placeholders for dynamic content:

#### Available Placeholders
- `{customer_name}` - Customer's full name
- `{customer_email}` - Customer's email address
- `{service_name}` - Booked service name
- `{appointment_date}` - Formatted appointment date
- `{appointment_time}` - Formatted appointment time
- `{duration_minutes}` - Service duration
- `{business_name}` - Your business name
- `{confirmation_number}` - Booking reference number
- `{cancel_link}` - One-click cancellation link

#### Default Confirmation Template
```
Dear {customer_name},

Your {service_name} appointment has been confirmed!

Appointment Details:
- Service: {service_name}
- Date: {appointment_date}
- Time: {appointment_time}
- Duration: {duration_minutes} minutes

Your confirmation number is: {confirmation_number}

To cancel or reschedule this appointment, please click here: {cancel_link}

Thank you for choosing our services!
```

### Customizing Email Templates

While TD Booking provides default templates, you can customize them using WordPress hooks (requires developer knowledge) or by using the template customization features if available in your version.

### Email Troubleshooting

#### Common Issues and Solutions

**Emails Not Sending:**
1. Check WordPress mail configuration
2. Verify SMTP settings if using SMTP plugin
3. Check server mail logs
4. Ensure "From Email" is valid

**Emails Going to Spam:**
1. Use email address matching your domain
2. Set up SPF/DKIM records
3. Avoid spam trigger words in templates
4. Consider using SMTP authentication

**Email Validation Failing:**
1. Use the override option in settings
2. Check email format (must be valid email address)
3. Verify domain exists and accepts email
4. Contact hosting provider about email restrictions

**Customer Not Receiving Emails:**
1. Check customer's spam folder
2. Verify customer email address accuracy
3. Test with different email providers
4. Review email logs in TD Booking > Settings

## CalDAV Integration

CalDAV integration allows TD Booking to sync with external calendar services like Nextcloud, iCloud, and Google Calendar.

### Understanding CalDAV

CalDAV is a standard protocol for calendar synchronization that enables:
- **Bidirectional sync**: Bookings appear in external calendars and vice versa
- **Real-time updates**: Changes sync automatically
- **Multiple calendar support**: Connect to various calendar services
- **Conflict prevention**: Avoid double-booking across systems

### Supported Calendar Services

#### Nextcloud/ownCloud
- **Full support** with read/write capabilities
- **Server URL format**: `https://yourcloud.com/remote.php/dav/calendars/username/`
- **Authentication**: Username and password or app password

#### iCloud
- **Full support** with some setup complexity
- **Server URL**: `https://caldav.icloud.com/`
- **Authentication**: Apple ID and app-specific password (required)
- **Setup**: Must generate app-specific password in Apple ID settings

#### Google Calendar
- **CalDAV support** available
- **Server URL**: `https://caldav-mini.calendar.google.com/`
- **Authentication**: Google account credentials
- **Note**: Google recommends API integration over CalDAV

#### Generic CalDAV Servers
Any standards-compliant CalDAV server should work with TD Booking.

### Setting Up CalDAV Integration

#### Step 1: Gather Calendar Credentials

**For Nextcloud:**
1. Log into your Nextcloud instance
2. Go to **Settings > Security**
3. Generate an **app password** for TD Booking
4. Note your **calendar URL** (found in calendar settings)

**For iCloud:**
1. Go to **appleid.apple.com**
2. Sign in and go to **Security**
3. Generate an **app-specific password**
4. Label it "TD Booking" for reference

**For Google Calendar:**
1. Enable **CalDAV access** in Google Calendar settings
2. Use your **Google account credentials**
3. Note: May require app-specific passwords if 2FA enabled

#### Step 2: Configure TD Booking

1. **Navigate to TD Booking > Settings**
2. **Find "CalDAV Integration" section**
3. **Enter your calendar details:**

   **Server URL:**
   - Full CalDAV endpoint URL
   - Must include the calendar path
   - Examples provided for common services

   **Username:**
   - Your calendar service username
   - For iCloud: your Apple ID email
   - For Nextcloud: your Nextcloud username

   **Password:**
   - App-specific password (recommended)
   - Regular account password (less secure)
   - Passwords are encrypted in the database

   **Calendar Path:**
   - Specific calendar within your account
   - Usually auto-detected after connection

4. **Test the connection** using the built-in tester

#### Step 3: Test and Enable Sync

1. **Click "Test CalDAV Connection"**
2. **Review connection results:**
   - Success: Shows available calendars
   - Failure: Displays error details for troubleshooting
3. **Enable synchronization** if test succeeds
4. **Configure sync frequency** (default: every 5 minutes)

### Managing CalDAV Sync

#### Sync Process
1. **Automatic synchronization** runs at configured intervals
2. **Manual sync** available through admin tools
3. **Bidirectional updates**:
   - New TD Booking appointments → Calendar events
   - New calendar events → TD Booking blocks (prevents double-booking)
   - Updates and cancellations sync in both directions

#### Monitoring Sync Status

**Sync Information Available:**
- **Last successful sync** timestamp
- **Sync errors** and failure reasons
- **Event mapping** between bookings and calendar events
- **Pending sync operations**

**Manual Sync Operations:**
1. **Go to TD Booking > Settings**
2. **Find "CalDAV Tools" section**
3. **Available actions:**
   - **Test Connection**: Verify server connectivity
   - **Force Full Sync**: Complete sync of all data
   - **Reconcile Differences**: Fix sync inconsistencies
   - **Clear Sync Data**: Reset sync mappings

#### Handling Sync Conflicts

**Common Conflict Scenarios:**
- **Time changes**: Appointment time changed in both systems
- **Cancellations**: Event deleted in one system but not the other
- **New events**: Overlapping events created simultaneously

**Conflict Resolution:**
1. **TD Booking prioritizes** its own bookings over external events
2. **External events** create "busy" blocks to prevent double-booking
3. **Manual resolution** may be needed for complex conflicts
4. **Audit logs** track all sync decisions

### CalDAV Troubleshooting

#### Connection Issues

**"Connection Failed" Errors:**
1. **Verify server URL** format and accessibility
2. **Check username/password** credentials
3. **Test network connectivity** from your server
4. **Review server firewall** settings

**Authentication Problems:**
1. **Use app-specific passwords** instead of account passwords
2. **Verify 2FA settings** on calendar service
3. **Check account permissions** for CalDAV access
4. **Try different authentication methods**

#### Sync Issues

**Events Not Syncing:**
1. **Check sync frequency** settings
2. **Review error logs** in admin interface
3. **Verify calendar permissions** (read/write access)
4. **Force manual sync** to trigger immediate update

**Duplicate Events:**
1. **Clear sync mappings** and perform full sync
2. **Check for multiple calendar configurations**
3. **Review event UIDs** for uniqueness
4. **May require manual cleanup** in external calendar

**Performance Problems:**
1. **Reduce sync frequency** if server load is high
2. **Limit sync date range** to recent events only
3. **Monitor server resources** during sync operations
4. **Consider caching improvements**

## Customer Booking Process

Understanding how customers interact with your booking system helps you optimize their experience and troubleshoot issues.

### Customer Journey Overview

1. **Discovery**: Customer finds your booking form
2. **Service Selection**: Choose what they need
3. **Time Selection**: Pick available appointment slot
4. **Information Entry**: Provide contact details
5. **Confirmation**: Receive booking confirmation
6. **Management**: Ability to cancel/reschedule

### Booking Form Setup

#### Adding Booking Forms to Your Site

**Using Shortcodes:**
The primary method for adding booking forms is the `[td_booking_form]` shortcode.

**Basic Implementation:**
```
[td_booking_form]
```

**With Parameters:**
```
[td_booking_form service="computer-repair" address="on" title="Schedule Your Repair"]
```

**Shortcode Parameters:**
- `service`: Pre-select specific service by slug
- `address`: Show address field ("on" or "off")
- `title`: Custom form title
- `theme`: Form styling theme (if available)

#### Placement Recommendations

**Dedicated Booking Page:**
1. **Create new page**: "Book Appointment" or "Schedule Service"
2. **Add shortcode** to page content
3. **Set as primary booking destination**
4. **Link from navigation menu**

**Service Pages:**
1. **Add to individual service pages**
2. **Pre-select the relevant service**
3. **Provide immediate booking after service description**

**Contact Page:**
1. **Include alongside contact information**
2. **Offer booking as alternative to phone calls**
3. **Position prominently for visibility**

### Customer Experience Flow

#### Step 1: Service Selection

**What Customers See:**
- **Dropdown list** of all active services
- **Service names** and brief descriptions
- **Duration indicators** showing appointment length
- **Pricing information** (if configured)

**Behind the Scenes:**
- **Only active services** appear in the list
- **Services ordered** alphabetically by default
- **Availability pre-filtering** may hide unavailable services

#### Step 2: Date and Time Selection

**Date Picker:**
- **Calendar interface** for date selection
- **Unavailable dates** grayed out or disabled
- **Holiday/break periods** clearly marked
- **Booking advance limits** enforced

**Time Slot Selection:**
- **Available slots** shown as clickable buttons
- **Duration display** shows start and end times
- **Real-time availability** checking
- **Time zone display** based on WordPress settings

**Availability Factors:**
- **Business hours** configuration
- **Staff availability** from TD Technicians
- **Existing bookings** blocking slots
- **Staff breaks/holidays** creating unavailable periods
- **Service-specific rules** and restrictions

#### Step 3: Customer Information

**Required Fields:**
- **Full Name**: Customer identification
- **Email Address**: For confirmations and communications
- **Phone Number**: Contact information (may be optional)

**Optional Fields:**
- **Address**: For on-site services (controlled by shortcode parameter)
- **Special Requests**: Additional notes or requirements
- **Group Size**: For services supporting multiple people

**Data Validation:**
- **Email format** checking
- **Phone number** validation (if provided)
- **Required field** enforcement
- **Sanitization** of all input data

#### Step 4: Booking Confirmation

**Immediate Feedback:**
- **Success message** confirming booking creation
- **Confirmation number** for reference
- **Next steps** information
- **Calendar invitation** option (if CalDAV enabled)

**Email Confirmation:**
- **Automatic email** sent to customer
- **Booking details** including time, service, location
- **Cancellation link** for customer self-service
- **Business contact** information

### Customer Self-Service Features

#### Booking Cancellation

**Cancellation Process:**
1. **Customer clicks** cancellation link in email
2. **Confirmation page** asks to verify cancellation
3. **Cancellation processed** and booking status updated
4. **Confirmation email** sent acknowledging cancellation
5. **Calendar sync** removes the event

**Cancellation Policies:**
- **Time limits**: Configure minimum notice required
- **Automated handling**: No admin intervention needed
- **Audit trail**: All cancellations logged for records

#### Booking Modifications

**Current Capabilities:**
- **Cancellation**: Full self-service capability
- **Rescheduling**: Future feature (currently requires admin assistance)
- **Service changes**: Future feature (currently requires admin assistance)

**Customer Support:**
- **Contact information** provided in all emails
- **Clear instructions** for requesting changes
- **Admin tools** for quick modification processing

### Optimizing Customer Experience

#### Form Usability

**Best Practices:**
- **Clear service descriptions** help customers choose correctly
- **Reasonable booking windows** (not too far in advance)
- **Mobile-friendly design** for smartphone users
- **Fast loading times** for time-sensitive bookings

**Customization Options:**
- **Styling**: Match your website's design
- **Field requirements**: Only ask for necessary information
- **Confirmation messages**: Customize for your business tone
- **Error messages**: Provide helpful guidance

#### Reducing Booking Friction

**Streamline the Process:**
- **Minimize required fields** to essential information only
- **Pre-select services** when linking from service pages
- **Show availability immediately** without extra clicks
- **Provide clear next steps** after booking

**Address Common Issues:**
- **Time zone confusion**: Display times clearly
- **Availability questions**: Provide clear business hours
- **Service confusion**: Detailed descriptions and duration info
- **Technical problems**: Simple error messages and contact options

## Reports and Analytics

TD Booking includes reporting features to help you understand your business performance and make data-driven decisions.

### Accessing Reports

**Navigation:**
1. **Go to TD Booking > Reports** (if enabled in settings)
2. **Select date ranges** for analysis
3. **Choose report types** to generate
4. **Export data** for external analysis

**Enabling Reports:**
If you don't see the Reports menu:
1. **Go to TD Booking > Settings**
2. **Find "Advanced Features" section**
3. **Enable "Reports Module"**
4. **Save settings**

### Available Reports

#### Booking Volume Reports

**Daily Booking Trends:**
- **Bookings per day** over selected period
- **Peak booking days** identification
- **Seasonal patterns** analysis
- **Growth trends** month-over-month

**Service Popularity:**
- **Most booked services** ranking
- **Service revenue** breakdown (if pricing enabled)
- **Average booking duration** by service
- **Service utilization rates**

#### Staff Performance Reports

**Technician Workload:**
- **Bookings per technician** distribution
- **Utilization rates** for each staff member
- **Popular technician** identification
- **Work balance** analysis

**Assignment Efficiency:**
- **Auto-assignment success** rates
- **Manual override** frequency
- **Assignment conflicts** and resolutions

#### Customer Analytics

**Customer Behavior:**
- **New vs. returning** customer ratios
- **Booking lead times** (how far in advance customers book)
- **Cancellation rates** and patterns
- **Peak booking times** throughout the day

**Geographic Analysis:**
- **Customer locations** (if address collection enabled)
- **Service area** coverage
- **Travel time** implications for scheduling

#### Revenue Reports (WooCommerce Integration)

**Financial Performance:**
- **Total revenue** by period
- **Average order value** per booking
- **Revenue by service** type
- **Payment method** analysis

**Conversion Tracking:**
- **Booking-to-payment** conversion rates
- **Cart abandonment** in WooCommerce flow
- **Upselling opportunities** identification

### Using Reports for Business Decisions

#### Capacity Planning

**Staffing Decisions:**
- **High-demand periods** requiring more staff
- **Low-utilization times** for training or maintenance
- **Service expansion** opportunities
- **Staff scheduling** optimization

**Service Optimization:**
- **Underperforming services** that may need promotion or removal
- **Popular services** that could be expanded
- **Pricing optimization** based on demand
- **Duration adjustments** for efficiency

#### Marketing Insights

**Customer Acquisition:**
- **Booking sources** (which pages generate most bookings)
- **Peak inquiry times** for marketing campaigns
- **Customer retention** strategies
- **Referral patterns** identification

**Service Promotion:**
- **Services needing promotion** due to low booking rates
- **Cross-selling opportunities** based on customer patterns
- **Seasonal service** adjustments
- **Package deals** potential

### Report Customization

#### Date Range Selection
- **Preset ranges**: Today, This Week, This Month, This Quarter
- **Custom ranges**: Specific start and end dates
- **Comparison periods**: Year-over-year, month-over-month
- **Rolling periods**: Last 30 days, Last 90 days

#### Data Filtering
- **Service filtering**: Focus on specific services
- **Staff filtering**: Individual technician performance
- **Status filtering**: Confirmed bookings only, include cancellations
- **Customer type**: New vs. returning customers

#### Export Options
- **PDF reports**: Professional formatting for presentations
- **CSV export**: Data analysis in spreadsheet applications
- **Chart images**: Visual data for marketing materials
- **Scheduled reports**: Automatic generation and email delivery

## Settings and Configuration

The settings panel provides comprehensive control over all aspects of TD Booking functionality.

### Accessing Settings

**Navigation Path:**
1. **WordPress Admin** → **TD Booking** → **Settings**
2. **Settings are organized** into logical sections
3. **Changes save automatically** or require "Save Changes" button
4. **Testing tools** available for most configurations

### Email Settings Section

#### From Email Configuration
- **Purpose**: Set the sender address for booking emails
- **Default**: Uses WordPress admin email
- **Custom email**: Professional booking-specific address
- **Validation**: WordPress checks email deliverability
- **Override option**: Force custom email if validation fails

#### Email Testing Tools
- **Test validation**: Check if email address will work
- **Send test email**: Verify email delivery to specific address
- **View current settings**: See active email configuration
- **Troubleshooting notes**: Guidance for common email issues

### Availability Settings Section

#### Business Hours Configuration
- **Daily schedules**: Set operating hours for each day of the week
- **Different hours**: Configure varying schedules (e.g., shorter Friday hours)
- **Closed days**: Mark days when no bookings are accepted
- **Holiday schedules**: Temporary schedule overrides

#### Booking Parameters
- **Time slot intervals**: 15, 30, or 60-minute booking increments
- **Advance booking**: Minimum notice required for bookings
- **Booking horizon**: How far in advance customers can book
- **Buffer times**: Gaps between appointments for preparation

#### Group Booking Settings
- **Enable group bookings**: Allow multiple people per appointment
- **Maximum group size**: Limit party size for services
- **Group pricing**: Adjust pricing for additional people
- **Capacity management**: Handle resource allocation for groups

### CalDAV Integration Section

#### Server Configuration
- **CalDAV server URL**: Full endpoint address for calendar server
- **Username**: Account username for calendar service
- **Password**: Encrypted storage of authentication credentials
- **Calendar path**: Specific calendar within the account

#### Sync Settings
- **Sync frequency**: How often to check for calendar changes
- **Sync direction**: Bidirectional, TD Booking to Calendar, or Calendar to TD Booking only
- **Conflict resolution**: How to handle scheduling conflicts
- **Date range**: How far back and forward to sync events

#### Testing and Monitoring
- **Connection test**: Verify server connectivity and credentials
- **Force sync**: Immediate synchronization trigger
- **Sync status**: Last sync time and success/failure information
- **Error logs**: Detailed information about sync issues

### Advanced Features Section

#### Feature Toggles
- **Staff breaks module**: Enable organization-wide break management
- **Reports module**: Activate booking analytics and reporting
- **WooCommerce integration**: Connect with e-commerce functionality
- **Debug logging**: Enable detailed logging for troubleshooting

#### Performance Settings
- **Cache duration**: How long to store availability calculations
- **Rate limiting**: API request limits for performance protection
- **Database optimization**: Cleanup old data and optimize queries
- **Background processing**: Enable/disable async job processing

#### Security Settings
- **Nonce validation**: CSRF protection for forms
- **Capability management**: User permission requirements
- **API access**: Control external API access and authentication
- **Data sanitization**: Input cleaning and validation settings

### Notification Settings

#### Customer Notifications
- **Booking confirmations**: Automatic emails when bookings are created
- **Cancellation notices**: Emails when bookings are cancelled
- **Reminder emails**: Upcoming appointment reminders (future feature)
- **Rescheduling notices**: Emails when appointments are changed (future feature)

#### Admin Notifications
- **New booking alerts**: Email admins about new bookings
- **Cancellation notices**: Admin notification of cancellations
- **System alerts**: Technical issues and sync problems
- **Daily summaries**: Overview of daily booking activity

### Integration Settings (Future Features)

#### SMS Integration
- **SMS provider**: Configuration for text message services
- **Message templates**: Customizable SMS content
- **Trigger events**: When to send SMS notifications
- **Phone number validation**: Ensure deliverable phone numbers

#### Payment Processing
- **WooCommerce products**: Link services to WooCommerce products
- **Payment requirements**: Require payment before confirmation
- **Pricing rules**: Advanced pricing based on time, customer type, etc.
- **Refund handling**: Automatic refund processing for cancellations

### Maintenance and Troubleshooting

#### Cache Management
- **Clear all caches**: Remove stored availability calculations
- **Warm cache**: Pre-calculate availability for upcoming dates
- **Cache statistics**: Usage and performance metrics
- **Cache debugging**: Detailed cache hit/miss information

#### Debug Tools
- **Debug mode**: Enable detailed logging throughout the system
- **API testing**: Test endpoints and view raw responses
- **Database queries**: Monitor and optimize database performance
- **Error reporting**: Capture and display system errors

#### Data Management
- **Export bookings**: Download booking data for backup or analysis
- **Import services**: Bulk upload service configurations
- **Database cleanup**: Remove old logs and temporary data
- **Backup settings**: Export/import plugin configuration

## Troubleshooting

This section helps you diagnose and resolve common issues with TD Booking.

### Getting Help

#### Built-in Diagnostic Tools
1. **Go to TD Booking > Settings**
2. **Scroll to "Debug Tools" section**
3. **Use available testing tools:**
   - Email validation tester
   - CalDAV connection tester
   - Availability debugging
   - Database integrity checker

#### Information Gathering
Before seeking support, gather:
- **WordPress version** and **PHP version**
- **Plugin versions** (TD Booking and TD Technicians)
- **Error messages** (exact text)
- **Steps to reproduce** the issue
- **Browser and device** information

### Common Issues and Solutions

#### Email Problems

**Problem: Confirmation emails not sending**

**Diagnosis:**
1. **Test email function**: Use TD Booking > Settings email tester
2. **Check WordPress mail**: Send test email from WordPress admin
3. **Review server logs**: Look for mail delivery errors
4. **Verify SMTP**: If using SMTP plugin, check configuration

**Solutions:**
- **Fix SMTP settings**: Configure proper mail server details
- **Use different email**: Try admin email instead of custom email
- **Enable override**: Force custom email even if validation fails
- **Contact hosting provider**: Resolve server mail restrictions

**Problem: Emails going to spam**

**Solutions:**
- **Use domain-matching email**: booking@yourdomain.com instead of gmail.com
- **Set up SPF records**: DNS configuration for email authentication
- **Configure DKIM**: Email signing for deliverability
- **Review email content**: Avoid spam trigger words

#### Booking Form Issues

**Problem: No available time slots showing**

**Diagnosis:**
1. **Check business hours**: Verify operating hours are set
2. **Review staff availability**: Ensure technicians have availability
3. **Check staff breaks**: Look for organization-wide blocks
4. **Verify service settings**: Confirm service is active and properly configured

**Solutions:**
- **Set business hours**: Configure when you accept bookings
- **Add technician availability**: Use TD Technicians plugin to set staff schedules
- **Review staff breaks**: Remove outdated holidays or breaks
- **Activate services**: Ensure target services are marked as active

**Problem: Booking form not appearing**

**Solutions:**
- **Check shortcode syntax**: Ensure `[td_booking_form]` is correctly written
- **Verify plugin activation**: Both TD Booking and TD Technicians must be active
- **Review theme compatibility**: Switch to default theme temporarily for testing
- **Clear caches**: Page caching may prevent form display

#### CalDAV Sync Issues

**Problem: Calendar sync not working**

**Diagnosis:**
1. **Test connection**: Use built-in CalDAV connection tester
2. **Check credentials**: Verify username/password accuracy
3. **Review server URL**: Ensure correct CalDAV endpoint
4. **Check sync logs**: Look for error messages in admin interface

**Solutions:**
- **Update credentials**: Use app-specific passwords instead of account passwords
- **Verify server URL**: Check CalDAV documentation for correct format
- **Check firewall**: Ensure server can reach external CalDAV services
- **Force manual sync**: Trigger immediate synchronization

**Problem: Duplicate calendar events**

**Solutions:**
- **Clear sync mappings**: Reset CalDAV sync relationships
- **Perform full sync**: Complete re-synchronization of all data
- **Check calendar settings**: Ensure only one calendar is configured
- **Manual cleanup**: Remove duplicates from external calendar

#### Performance Issues

**Problem: Slow booking form loading**

**Solutions:**
- **Enable caching**: Turn on availability caching in settings
- **Reduce date range**: Limit how far ahead customers can book
- **Optimize database**: Clean up old bookings and logs
- **Check server resources**: Monitor CPU and memory usage

**Problem: Admin pages loading slowly**

**Solutions:**
- **Limit booking display**: Show fewer bookings per page
- **Clear debug logs**: Remove old log entries
- **Disable unnecessary features**: Turn off unused modules like reports
- **Database optimization**: Run database cleanup routines

#### Technician Assignment Problems

**Problem: No technician assigned to bookings**

**Diagnosis:**
1. **Check TD Technicians**: Verify plugin is active and has technicians
2. **Review qualifications**: Ensure technicians are qualified for services
3. **Check availability**: Verify technicians have availability during booking times
4. **Review assignment settings**: Look for custom assignment rules

**Solutions:**
- **Add technicians**: Create technician profiles in TD Technicians plugin
- **Set qualifications**: Match technician skills to service requirements
- **Configure availability**: Set working hours for technicians
- **Review assignment logic**: Check for conflicts in assignment rules

### Advanced Troubleshooting

#### Debug Mode
Enable debug mode for detailed logging:
```php
define('TD_BOOKING_DEBUG', true);
```
Add this to your `wp-config.php` file.

#### Database Issues
Check database table integrity:
1. **Go to TD Booking > Settings**
2. **Find "Database Tools" section**
3. **Run integrity check**
4. **Repair tables if needed**

#### Plugin Conflicts
Test for plugin conflicts:
1. **Deactivate all other plugins**
2. **Test TD Booking functionality**
3. **Reactivate plugins one by one**
4. **Identify conflicting plugin**

#### Theme Compatibility
Test theme compatibility:
1. **Switch to default WordPress theme**
2. **Test booking form functionality**
3. **If it works, theme is causing issues**
4. **Contact theme developer for resolution**

## Frequently Asked Questions

### General Questions

**Q: Do I need the TD Technicians plugin?**
A: Yes, TD Technicians is a required dependency. TD Booking uses it for staff management and cannot function without it.

**Q: Can I use TD Booking without CalDAV?**
A: Absolutely! CalDAV integration is optional. TD Booking works perfectly as a standalone booking system.

**Q: Is TD Booking compatible with my theme?**
A: TD Booking is designed to work with any properly coded WordPress theme. If you experience display issues, try switching to a default theme temporarily to isolate the problem.

**Q: Can customers book multiple services at once?**
A: Currently, customers book one service at a time. Each booking requires a separate form submission.

### Booking Management

**Q: How do I handle walk-in customers?**
A: You can create bookings manually through the admin interface (TD Booking > Bookings) for walk-in customers.

**Q: Can I set different prices for different times?**
A: Basic time-based pricing isn't built-in, but it can be implemented through WordPress hooks or WooCommerce integration.

**Q: How do I prevent double-booking?**
A: TD Booking automatically checks availability and prevents double-booking. CalDAV integration adds additional protection by syncing with external calendars.

**Q: What happens if a technician calls in sick?**
A: You can reassign bookings to other available technicians through the admin interface, or use staff-wide breaks to block time and reschedule affected appointments.

### Technical Questions

**Q: What time zone does TD Booking use?**
A: All times are stored in UTC in the database and converted to your WordPress timezone for display. This ensures consistency across different time zones.

**Q: Can I customize the booking form appearance?**
A: Yes, the booking form uses standard CSS classes and can be styled to match your website. Advanced customization may require developer assistance.

**Q: Is there a mobile app?**
A: TD Booking doesn't have a dedicated mobile app, but the booking forms are responsive and work well on mobile devices.

**Q: Can I integrate with other calendar systems?**
A: Currently, TD Booking supports CalDAV-compatible systems. Direct API integrations with specific services may require custom development.

### Troubleshooting

**Q: Why aren't my emails sending?**
A: Check your WordPress mail configuration, test with the built-in email tester, and verify your SMTP settings if using an SMTP plugin.

**Q: The booking form shows "No available times" - why?**
A: This usually indicates missing business hours, no available technicians, or staff-wide breaks blocking all times. Check each of these areas.

**Q: My CalDAV sync isn't working - what should I do?**
A: Use the connection tester in TD Booking > Settings, verify your credentials are correct, and check that your server can reach the CalDAV service.

**Q: Can I recover deleted bookings?**
A: Deleted bookings cannot be automatically recovered. However, the audit log keeps records of all booking changes for reference.

---

This user manual provides comprehensive guidance for using TD Booking effectively. For technical documentation and customization information, see the [Developer Documentation](DEVELOPER.md).

## Support and Updates

For additional support:
- Check the WordPress plugin repository for updates
- Review error logs in TD Booking > Settings
- Use built-in diagnostic tools
- Contact your developer for custom modifications

TD Booking is designed to grow with your business and can be extended through WordPress hooks and filters for advanced functionality.
