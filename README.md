# Fire Brigade Callout Attendance System

A web-based attendance tracking system designed for volunteer fire brigades to record crew attendance during callouts. Built with PHP 8+ and SQLite for easy deployment with minimal dependencies.

## Features

### Attendance Entry (PIN-Protected)
- **Real-time Callout Management**: Start new callouts with ICAD numbers, location, and call type
- **Truck & Position Assignment**: Assign members to specific positions on trucks (OIC, Driver, Crew 1-4)
- **Station Standby**: Track members remaining at the station
- **Live Synchronisation**: Multiple devices can update attendance simultaneously via Server-Sent Events (SSE)
- **Mobile-Optimised**: Responsive design works on phones, tablets, and desktop
- **PWA Support**: Install as an app on mobile devices for quick access
- **Callout History**: Browse recent callouts with attendance details

### Admin Dashboard
- **Member Management**: Add, edit, import (CSV), and deactivate members with rank and join date
- **Truck Configuration**: Configure trucks with customizable positions and drag-to-reorder
- **Callout History**: View, search, filter, and export callout records (CSV/HTML)
- **Settings**: Configure brigade name, email recipients, member sort order, PIN, and admin password
- **QR Code**: Generate a QR code for easy station access
- **Backup & Restore**: Download and restore SQLite database backups
- **Audit Log**: Track all system actions with timestamps and IP addresses

### Super Admin (System-wide)
- **Multi-Brigade Management**: Create and manage multiple brigades from a central dashboard
- **FENZ Data Status**: Monitor automated incident data fetching status

### Member Ordering Options
Members can be sorted by:
- **Rank then Name** (default): CFO → DCFO → SSO → SO → SFF → QFF → FF → RCFF, then alphabetically
- **Rank then Join Date**: By rank, then seniority based on join date
- **Alphabetical**: Simple A-Z ordering

### Security
- PIN protection for attendance entry (4-6 digits)
- Separate admin authentication with username/password
- Super admin for system-wide management
- Rate limiting on login attempts
- Session timeout (30 min admin, 24 hours PIN)
- CSRF protection
- Audit logging

---

## Architecture Overview

### System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              CLIENT LAYER                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌──────────────┐    ┌──────────────┐    ┌──────────────┐                 │
│   │   Mobile     │    │   Desktop    │    │   Tablet     │                 │
│   │   Browser    │    │   Browser    │    │   Browser    │                 │
│   └──────┬───────┘    └──────┬───────┘    └──────┬───────┘                 │
│          │                   │                   │                          │
│          └───────────────────┼───────────────────┘                          │
│                              ▼                                               │
│                    ┌─────────────────┐                                      │
│                    │  Service Worker │  ◄── Offline caching, background    │
│                    │     (sw.js)     │      sync, request queuing          │
│                    └────────┬────────┘                                      │
│                              │                                               │
└──────────────────────────────┼───────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           APPLICATION LAYER                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   public/index.php (Front Controller & Router)                              │
│   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━                               │
│                              │                                               │
│         ┌────────────────────┼────────────────────┐                         │
│         ▼                    ▼                    ▼                         │
│   ┌───────────┐       ┌───────────┐       ┌───────────┐                    │
│   │Controllers│       │Middleware │       │ Services  │                    │
│   ├───────────┤       ├───────────┤       ├───────────┤                    │
│   │Attendance │◄─────►│ PinAuth   │       │ Database  │                    │
│   │Admin      │       │ AdminAuth │       │ Email     │                    │
│   │SuperAdmin │       │ SuperAdmin│       │ FenzFetch │                    │
│   │Auth       │       │   Auth    │       │           │                    │
│   │SSE        │       └───────────┘       └───────────┘                    │
│   │Home       │              │                   │                          │
│   └─────┬─────┘              │                   │                          │
│         │                    │                   │                          │
│         └────────────────────┼───────────────────┘                          │
│                              ▼                                               │
│                       ┌───────────┐                                         │
│                       │  Models   │                                         │
│                       ├───────────┤                                         │
│                       │ Brigade   │                                         │
│                       │ Callout   │                                         │
│                       │ Attendance│                                         │
│                       │ Member    │                                         │
│                       │ Truck     │                                         │
│                       │ Position  │                                         │
│                       └─────┬─────┘                                         │
│                              │                                               │
└──────────────────────────────┼───────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            DATA LAYER                                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────┐       │
│   │                      SQLite Database                             │       │
│   │                    (data/database.sqlite)                        │       │
│   ├─────────────────────────────────────────────────────────────────┤       │
│   │  brigades │ callouts │ attendance │ members │ trucks │ positions│       │
│   │  audit_log│ rate_limits                                         │       │
│   └─────────────────────────────────────────────────────────────────┘       │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Request Flow

```
┌─────────┐     ┌─────────┐     ┌────────────┐     ┌──────────┐     ┌────────┐
│ Browser │────►│ Apache  │────►│ index.php  │────►│Controller│────►│ Model  │
│         │     │.htaccess│     │  (Router)  │     │          │     │        │
└─────────┘     └─────────┘     └────────────┘     └──────────┘     └────────┘
                                       │                                  │
                                       ▼                                  ▼
                                ┌────────────┐                     ┌──────────┐
                                │ Middleware │                     │  SQLite  │
                                │ (Auth)     │                     │    DB    │
                                └────────────┘                     └──────────┘
                                       │
                                       ▼
                                ┌────────────┐
                                │  Template  │
                                │   (View)   │
                                └────────────┘
```

### Real-Time Sync (SSE)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        REAL-TIME UPDATE FLOW                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Device A                    Server                      Device B           │
│   ────────                    ──────                      ────────           │
│      │                           │                           │               │
│      │◄──── SSE Connection ─────►│◄──── SSE Connection ─────►│              │
│      │                           │                           │               │
│      │                           │                           │               │
│      │─── POST /attendance ─────►│                           │               │
│      │                           │                           │               │
│      │                      ┌────┴────┐                      │               │
│      │                      │ Update  │                      │               │
│      │                      │   DB    │                      │               │
│      │                      └────┬────┘                      │               │
│      │                           │                           │               │
│      │◄── SSE: attendance ───────┤───── SSE: attendance ────►│              │
│      │    updated                │       updated             │               │
│      │                           │                           │               │
│      ▼                           ▼                           ▼               │
│   UI Updated                                              UI Updated         │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Data Model

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           ENTITY RELATIONSHIPS                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌──────────┐                                                              │
│   │ Brigade  │                                                              │
│   │──────────│                                                              │
│   │ id       │                                                              │
│   │ name     │                                                              │
│   │ slug     │◄─────────────────────────────────────────────┐               │
│   │ pin_hash │                                              │               │
│   │ region   │                                              │               │
│   └────┬─────┘                                              │               │
│        │                                                    │               │
│        │ 1:N                                                │               │
│        ▼                                                    │               │
│   ┌──────────┐     ┌──────────┐     ┌──────────┐           │               │
│   │  Truck   │     │  Member  │     │  Callout │           │               │
│   │──────────│     │──────────│     │──────────│           │               │
│   │ id       │     │ id       │     │ id       │           │               │
│   │ name     │     │ name     │     │ icad_num │           │               │
│   │ is_station│    │ rank     │     │ location │           │               │
│   │ sort_order│    │ join_date│     │ call_type│           │               │
│   └────┬─────┘     │ is_active│     │ status   │           │               │
│        │           └────┬─────┘     └────┬─────┘           │               │
│        │ 1:N            │                │                  │               │
│        ▼                │                │ 1:N              │               │
│   ┌──────────┐          │                ▼                  │               │
│   │ Position │          │          ┌──────────┐            │               │
│   │──────────│          │          │Attendance│            │               │
│   │ id       │          └─────────►│──────────│◄───────────┘               │
│   │ name     │                     │ id       │                             │
│   │ allow_   │                     │callout_id│                             │
│   │ multiple │◄────────────────────│member_id │                             │
│   └──────────┘                     │truck_id  │                             │
│                                    │position_id                             │
│                                    └──────────┘                             │
│                                                                              │
│   ┌──────────┐                                                              │
│   │Audit Log │  (Tracks all actions with IP, timestamp, details)            │
│   └──────────┘                                                              │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Technology Stack

| Layer | Technology | Purpose |
|-------|------------|---------|
| Frontend | Vanilla JS, HTML5, CSS3 | UI rendering, drag-and-drop |
| Real-time | Server-Sent Events (SSE) | Live attendance updates |
| Offline | Service Worker + IndexedDB | PWA caching, offline queue |
| Backend | PHP 8.x | Application logic, routing |
| Database | SQLite | Data persistence |
| Auth | Session-based | PIN + password authentication |

---

## Requirements

- PHP 8.0 or higher
- SQLite3 extension
- Apache with mod_rewrite (or nginx with equivalent config)
- HTTPS recommended for production

## Directory Structure

```
dlb/
├── config/
│   ├── config.sample.php   # Sample configuration (copy to config.php)
│   └── config.php          # Your local configuration (git-ignored)
├── data/
│   ├── database.sqlite     # SQLite database (auto-created)
│   └── fenz_cache/         # FENZ data fetch cache
├── public/
│   ├── .htaccess           # Apache rewrite rules
│   ├── index.php           # Application entry point & router
│   ├── cron.php            # Background job endpoint
│   ├── sw.js               # Service Worker for PWA
│   ├── manifest.json       # PWA manifest
│   └── assets/
│       ├── css/app.css     # Stylesheet
│       ├── js/
│       │   ├── admin.js    # Admin utilities
│       │   └── attendance.js # Attendance app
│       └── images/         # Icons and images
├── src/
│   ├── Controllers/
│   │   ├── AttendanceController.php  # Callout & attendance APIs
│   │   ├── AdminController.php       # Brigade admin APIs
│   │   ├── SuperAdminController.php  # System admin APIs
│   │   ├── AuthController.php        # PIN & login handling
│   │   ├── SSEController.php         # Real-time event streaming
│   │   └── HomeController.php        # Landing page
│   ├── Middleware/
│   │   ├── PinAuth.php         # PIN authentication
│   │   ├── AdminAuth.php       # Admin authentication
│   │   └── SuperAdminAuth.php  # Super admin authentication
│   ├── Models/
│   │   ├── Brigade.php    # Brigade data access
│   │   ├── Callout.php    # Callout data access
│   │   ├── Attendance.php # Attendance records
│   │   ├── Member.php     # Member management
│   │   ├── Truck.php      # Truck configuration
│   │   └── Position.php   # Position definitions
│   ├── Services/
│   │   ├── Database.php   # SQLite connection & schema
│   │   ├── EmailService.php # Email notifications
│   │   └── FenzFetcher.php  # FENZ incident data
│   └── helpers.php        # Utility functions
└── templates/
    ├── layouts/
    │   ├── app.php        # Main layout
    │   ├── admin.php      # Admin layout
    │   └── error.php      # Error pages
    ├── attendance/
    │   ├── entry.php      # Attendance entry UI
    │   ├── history.php    # Callout history browser
    │   └── pin.php        # PIN entry form
    ├── admin/
    │   ├── dashboard.php  # Admin overview
    │   ├── members.php    # Member management
    │   ├── trucks.php     # Truck configuration
    │   ├── callouts.php   # Callout management
    │   ├── settings.php   # Brigade settings
    │   ├── audit.php      # Audit log viewer
    │   └── login.php      # Admin login
    └── superadmin/
        ├── dashboard.php  # System overview
        ├── fenz-status.php # FENZ fetch status
        └── login.php      # Super admin login
```

---

## Deployment Instructions

### 1. Upload Files

Upload all files to your web server. For subdirectory deployment (e.g., `https://example.com/dlb/`):

```bash
# Upload to: /var/www/html/dlb/
scp -r ./* user@server:/var/www/html/dlb/
```

### 2. Configure Application

Copy the sample config and edit for your environment:

```bash
cd /var/www/html/dlb/config
cp config.sample.php config.php
nano config.php
```

Update these values in `config.php`:

```php
'app' => [
    'name' => 'Brigade Attendance',
    'url' => 'https://example.com/dlb',      // Your full URL
    'base_path' => '/dlb',                    // Subdirectory path (no trailing slash)
    'debug' => false,                         // Set to false in production
],
```

**Note:** `config.php` is git-ignored, so your settings won't be overwritten when pulling updates.

### 3. Configure Apache (.htaccess)

The `public/.htaccess` file is pre-configured. Ensure `RewriteBase` matches your subdirectory:

```apache
RewriteBase /dlb/
```

If deploying at the root, change to:
```apache
RewriteBase /
```

And update `config.php`:
```php
'base_path' => '',
```

### 4. Set Permissions

```bash
# Make data directory writable
chmod 755 /var/www/html/dlb/data
chmod 644 /var/www/html/dlb/data/database.sqlite

# Secure config file
chmod 640 /var/www/html/dlb/config/config.php
```

### 5. Point Document Root

Your web server should serve from the `public/` directory. Options:

**Option A: Symlink (Recommended)**
```bash
ln -s /var/www/html/dlb/public /var/www/html/dlb-public
# Then configure Apache to serve from dlb-public
```

**Option B: Apache VirtualHost**
```apache
<Directory /var/www/html/dlb/public>
    AllowOverride All
    Require all granted
</Directory>

Alias /dlb /var/www/html/dlb/public
```

**Option C: Move public contents**
If you can't modify server config, you can restructure:
1. Move contents of `public/` to your web root's `/dlb/` folder
2. Update paths in `index.php` to point to parent directories for `src/`, `config/`, etc.

### 6. Enable HTTPS (Recommended)

Uncomment in `public/.htaccess`:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 7. First Access

1. Navigate to your URL (e.g., `https://example.com/dlb/`)
2. A demo brigade is auto-created on first run
3. Access admin at `/demo-brigade/admin`
4. Default credentials: `admin` / `admin123`
5. **Change the password immediately!**

---

## Configuration Options

### Email Settings

Configure email notifications for submitted callouts:

```php
'email' => [
    'driver' => 'smtp',              // smtp, sendmail, or mail
    'host' => 'smtp.example.com',
    'port' => 587,
    'username' => 'your-email',
    'password' => 'your-password',
    'encryption' => 'tls',
    'from_address' => 'attendance@example.com',
    'from_name' => 'Brigade Attendance',
],
```

### Session Settings

```php
'session' => [
    'timeout' => 1800,        // Admin session: 30 minutes
    'pin_timeout' => 86400,   // PIN session: 24 hours
],
```

### Security Settings

```php
'security' => [
    'rate_limit_attempts' => 5,   // Max login attempts
    'rate_limit_window' => 900,   // Lockout window: 15 minutes
],
```

### Super Admin

```php
'super_admin' => [
    'enabled' => true,
    'username' => 'superadmin',
    'password_hash' => password_hash('your-secure-password', PASSWORD_DEFAULT),
],
```

---

## Usage

### Starting a Callout

1. Enter PIN at brigade page
2. Enter ICAD number (e.g., F4363832)
3. Optionally enter date/time, location, and call type
4. Click "Start Callout"

### Recording Attendance

1. Select a member from the "Available Members" list
2. Click a position on a truck to assign them
3. Click an assigned position to remove the member
4. Use "Station" for standby personnel
5. Changes sync in real-time to all connected devices

### Submitting Attendance

1. Click "Submit" when all crew are recorded
2. Confirm submission
3. Email notifications are sent (if configured)
4. Callout becomes read-only

### Viewing History

1. From the "Start New Callout" screen, click "Browse Recent Callouts"
2. View callouts from the last 30 days
3. Click on a callout to see full attendance details
4. Link to SITREP report for each callout

### Admin Tasks

Access admin at `/{brigade-slug}/admin`:
- **Dashboard**: Overview and quick actions
- **Members**: Manage brigade roster
- **Trucks**: Configure vehicles and positions
- **Callouts**: View history, edit details, unlock if needed
- **Settings**: Configure brigade options
- **Audit Log**: Review system activity

---

## API Reference

### Public Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/{slug}` | Brigade entry page |
| POST | `/{slug}/auth` | PIN authentication |

### Member Endpoints (PIN Required)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/{slug}/api/callout/active` | Get active callout or state |
| POST | `/{slug}/api/callout` | Create new callout |
| PUT | `/{slug}/api/callout/{id}` | Update callout (ICAD) |
| POST | `/{slug}/api/callout/{id}/submit` | Submit callout |
| POST | `/{slug}/api/attendance` | Add attendance record |
| DELETE | `/{slug}/api/attendance/{id}` | Remove attendance |
| GET | `/{slug}/api/sse/callout/{id}` | SSE stream for updates |
| GET | `/{slug}/api/history` | Get recent callouts |
| GET | `/{slug}/api/callout/{id}/detail` | Get callout detail |

### Admin Endpoints (Admin Auth Required)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET/POST | `/{slug}/admin/api/members` | List/create members |
| PUT/DELETE | `/{slug}/admin/api/members/{id}` | Update/delete member |
| POST | `/{slug}/admin/api/members/import` | CSV import |
| GET/POST | `/{slug}/admin/api/trucks` | List/create trucks |
| PUT/DELETE | `/{slug}/admin/api/trucks/{id}` | Update/delete truck |
| GET/PUT | `/{slug}/admin/api/settings` | Brigade settings |
| GET | `/{slug}/admin/api/audit` | Audit log entries |

---

## Troubleshooting

### "Page not found" errors
- Verify `mod_rewrite` is enabled: `a2enmod rewrite`
- Check `.htaccess` is being read: `AllowOverride All`
- Verify `RewriteBase` matches your subdirectory

### Database errors
- Ensure `data/` directory is writable
- Check PHP has SQLite3 extension: `php -m | grep sqlite`

### SSE not working
- Disable output buffering in PHP
- Check nginx buffering: add `X-Accel-Buffering: no` header
- Verify no proxy is buffering responses

### Slow performance
- The built-in PHP server is single-threaded; use Apache/nginx in production
- SSE connections may block on PHP dev server

### Service Worker issues
- Clear browser cache and unregister old service workers
- Check browser DevTools > Application > Service Workers
- Ensure HTTPS is enabled (required for PWA)

---

## License

MIT License - Feel free to use and modify for your brigade.

## Support

For issues or feature requests, please open an issue on GitHub.
