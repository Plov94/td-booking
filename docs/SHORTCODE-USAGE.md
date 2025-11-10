# TD Booking – Shortcode Usage Guide

This guide shows how to place the booking form on any WordPress page, preselect services or staff, and embed it on other domains.

## Add the form to a page

1. In WordPress admin, go to Pages → Add New (or edit an existing page).
2. Add a "Shortcode" block.
3. Paste one of the examples below and publish.

Classic editor: paste the shortcode directly into the editor.

---

## Shortcode reference

Main form (user chooses the service):

- `[td_booking_form title="Book an appointment" staff_select="on" notes="on"]`

Attributes:
- `service` (slug or ID): preselect a service. Example: `service="cleaning"` or `service="12"`
- `address`: `on|off` – show or hide address field (default `on`)
- `title`: heading displayed above the form
- `time_format`: `auto|12|24` – control time display
- `api_base`: advanced – override REST base URL when reverse-proxying (see Embedding)
- `staff_select`: `on|off` – show a staff dropdown filtered by selected service
- `notes`: `on|off` – show the optional notes textarea (default `on`)

---

Preselected service:

- `[td_booking_service service="cleaning" title="Book cleaning"]`

Preselected staff (staff-first, no service required – shows as "Custom / Specifically requested" in admin):

- `[td_booking_staff staff="42" agnostic="1" title="Book Alice directly"]`

Preselected service and staff:

- `[td_booking_service_staff service="cleaning" staff="42" title="Book Alice for cleaning"]`

Notes:
- `staff_select="on"` displays a staff dropdown (only when not already locking staff).
- `time_format="24"` forces 24-hour times; omit for auto.

---

## Staff-first pages without a service selector

If you want users to book a person directly (no service choice):

- Use the shortcode: `[td_booking_staff staff="<ID>" agnostic="1" title="Book <Name>"]`
- Or generate a page with Tools → TD Booking Demo Helper and check "Staff-first (no service required)".

These bookings will display the service as "Custom / Specifically requested" in the admin lists.

---

## Embedding on another site/domain

Recommended approach: embed the published booking page in an iframe.

```html
<iframe src="https://your-wp-site.com/book/" style="width:100%;min-height:800px;border:0" loading="lazy"></iframe>
```

Advanced option: reverse-proxy the REST API and host the form on the other WP site.

- Place the shortcode on the remote WP site and set `api_base` to a proxied path, e.g.:
  - `[td_booking_form api_base="/booking-api/" ...]`
- Configure your web server so `/booking-api/` proxies to `https://your-wp-site.com/wp-json/td/v1/`.
- This keeps requests same-origin (nonce-compatible). Avoid pointing `api_base` directly to another domain unless you also implement CORS + nonce handling.

---

## Tips

- Add services and staff mappings first for best UX.
- Clear cache/CDN and hard-refresh after plugin updates to ensure the latest JS is served.
- Set a default duration for staff-first bookings in plugin settings (fallback is 30 minutes).
- Configure Terms (link or modal) in plugin settings; the form respects that.

If you share your service slug and staff ID, you can paste-ready shortcodes like:
- `[td_booking_service service="cleaning" title="Book cleaning"]`
- `[td_booking_staff staff="42" agnostic="1" title="Book Alice directly"]`
