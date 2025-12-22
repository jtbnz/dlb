# Fire Brigade Callout Attendance System - Technical Specification

## 1. Overview

A mobile-first Progressive Web Application (PWA) for recording fire brigade member attendance at callouts. The system supports multiple brigades with data isolation, real-time collaborative entry, and offline capability.

### 1.1 Key Features
- Multi-brigade support with complete data isolation
- Real-time collaborative attendance entry via Server-Sent Events (SSE)
- Offline-first PWA with background sync
- QR code access for quick brigade-specific entry
- Email notifications with ICAD report links
- Call history and reporting
- Audit logging for accountability

---

## 2. Technology Stack

| Component | Technology |
|-----------|------------|
| Backend | PHP 8.x |
| Database | SQLite |
| Real-time | Server-Sent Events (SSE) |
| Frontend | Vanilla JS / HTML5 / CSS3 |
| Offline | Service Worker + IndexedDB |

### 2.1 Hosting Requirements
- PHP 8.0+ with SQLite3 extension
- HTTPS (required for PWA/Service Workers)
- Write permissions for database directory

---

## 3. Data Model

### 3.1 Entity Hierarchy
```
Brigade (1) ──┬── Truck (many)
              │      └── Position (many)
              ├── Member (many)
              └── Callout (many)
                     └── Attendance (many)
```

### 3.2 Database Schema

#### `brigades`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| name | TEXT | Brigade name |
| slug | TEXT UNIQUE | URL-safe identifier for QR codes |
| pin_hash | TEXT | Hashed PIN for member access |
| admin_password_hash | TEXT | Hashed admin password |
| email_recipients | TEXT | JSON array of email addresses |
| include_non_attendees | BOOLEAN | Include absent members in email |
| created_at | DATETIME | |
| updated_at | DATETIME | |

#### `trucks`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| brigade_id | INTEGER FK | |
| name | TEXT | e.g., "Pump 1", "Tanker", "Station" |
| is_station | BOOLEAN | True for virtual station truck |
| sort_order | INTEGER | Display order |
| created_at | DATETIME | |

#### `positions`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| truck_id | INTEGER FK | |
| name | TEXT | e.g., "OIC", "DR", "1", "Standby" |
| allow_multiple | BOOLEAN | True for standby positions |
| sort_order | INTEGER | Display order |

#### `members`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| brigade_id | INTEGER FK | |
| name | TEXT | Full name |
| rank | TEXT | Member rank |
| is_active | BOOLEAN | Soft delete flag |
| created_at | DATETIME | |
| updated_at | DATETIME | |

#### `callouts`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| brigade_id | INTEGER FK | |
| icad_number | TEXT | Unique ICAD reference (freetext) |
| status | TEXT | 'active', 'submitted', 'locked' |
| submitted_at | DATETIME | When finalized |
| submitted_by | TEXT | Who submitted |
| created_at | DATETIME | |
| updated_at | DATETIME | |

#### `attendance`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| callout_id | INTEGER FK | |
| member_id | INTEGER FK | |
| truck_id | INTEGER FK | |
| position_id | INTEGER FK | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

**Unique constraint**: One member can only have one position per callout (callout_id, member_id)

#### `audit_log`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| brigade_id | INTEGER FK | |
| callout_id | INTEGER FK | Nullable |
| action | TEXT | e.g., 'attendance_added', 'callout_submitted' |
| details | TEXT | JSON with change details |
| ip_address | TEXT | |
| user_agent | TEXT | |
| created_at | DATETIME | |

---

## 4. Authentication & Security

### 4.1 Access Levels

| Level | Authentication | Capabilities |
|-------|---------------|--------------|
| Public | None | View QR code landing page only |
| Member | Brigade PIN | Enter attendance for active callouts |
| Admin | Username + Password | Full brigade management |

### 4.2 Brigade PIN Access
- 4-6 digit numeric PIN per brigade
- PIN entered once per session (stored in sessionStorage)
- PIN grants access to attendance entry only
- Rate limiting: 5 failed attempts = 15 minute lockout

### 4.3 Admin Access
- Separate admin login page per brigade
- Username/password authentication
- Session-based with configurable timeout (default 30 mins)
- Password requirements: minimum 8 characters

### 4.4 URL Structure
```
/                           # Landing page
/{brigade-slug}             # Brigade PIN entry
/{brigade-slug}/attendance  # Attendance entry (requires PIN)
/{brigade-slug}/admin       # Admin login
/{brigade-slug}/admin/*     # Admin pages (requires admin auth)
```

---

## 5. User Interface

### 5.1 Attendance Entry (Mobile-First)

#### Layout
```
┌─────────────────────────────────┐
│ Brigade Name          [Submit]  │
│ ICAD: F4363832        [Change]  │
├─────────────────────────────────┤
│ ┌─────────────────────────────┐ │
│ │ PUMP 1                      │ │
│ │ ┌─────┐ ┌─────┐ ┌─────┐    │ │
│ │ │ OIC │ │ DR  │ │  1  │... │ │
│ │ │Smith│ │Jones│ │     │    │ │
│ │ └─────┘ └─────┘ └─────┘    │ │
│ └─────────────────────────────┘ │
│ ┌─────────────────────────────┐ │
│ │ STATION (Standby)           │ │
│ │ ┌───────────────────────┐   │ │
│ │ │ Brown, Wilson, Clark  │   │ │
│ │ └───────────────────────┘   │ │
│ └─────────────────────────────┘ │
├─────────────────────────────────┤
│ AVAILABLE MEMBERS               │
│ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐│
│ │Adams│ │Baker│ │Davis│ │Evans││
│ └─────┘ └─────┘ └─────┘ └─────┘│
│ ┌─────┐ ┌─────┐                 │
│ │Ford │ │Grant│                 │
│ └─────┘ └─────┘                 │
└─────────────────────────────────┘
```

#### Interaction Flow (Tap-to-Assign)
1. User taps a member name from "Available Members"
2. Member becomes highlighted/selected
3. User taps a position slot on any truck
4. Member is assigned to that position
5. Change saves immediately to server
6. Other users see update via SSE within ~100ms

#### Removing Assignment
- Tap assigned member to return them to available pool
- Or tap and hold for context menu with "Remove" option

### 5.2 Real-Time Sync (SSE)
- Client establishes SSE connection on page load
- Server broadcasts attendance changes to all connected clients for that callout
- Visual indicator shows sync status (connected/reconnecting/offline)
- Optimistic UI updates with rollback on conflict

### 5.3 Offline Mode (PWA)
- Service Worker caches app shell and assets
- IndexedDB stores local state when offline
- Visual indicator: "Offline - changes will sync when connected"
- Background sync queues changes
- On reconnection:
  1. Pull latest server state
  2. Apply queued local changes
  3. Resolve conflicts (last-write-wins with audit log)

---

## 6. Admin Interface

### 6.1 Dashboard
- Active callouts count
- Recent callout history
- Quick links to common actions

### 6.2 Member Management
- List all members with search/filter
- Add/Edit/Deactivate members
- Fields: Name, Rank
- CSV Import:
  - Expected format: `name,rank`
  - Preview before import
  - Option to update existing or skip duplicates

### 6.3 Truck Configuration
- List trucks with drag-to-reorder
- Add/Edit/Delete trucks
- Position configuration per truck:
  - Preset templates:
    - Light: OIC, DR
    - Medium: OIC, DR, 1, 2
    - Full: OIC, DR, 1, 2, 3, 4
    - Custom: Define own positions
  - For Station truck: single "Standby" position with allow_multiple=true

### 6.4 Callout History
- List all callouts with filters:
  - Date range
  - ICAD number search
  - Status (active/submitted/locked)
- View callout details and attendance
- Export options: CSV, PDF
- Unlock submitted callouts if needed

### 6.5 Brigade Settings
- Brigade name
- PIN management (change PIN)
- Admin password change
- Email recipients (add/remove)
- Toggle: Include non-attendees in email
- Generate/Download QR code

### 6.6 Audit Log Viewer
- Filterable list of all actions
- Details: timestamp, action, IP, user agent, changes made

---

## 7. Email Notifications

### 7.1 Trigger
- Sent when callout is submitted (Submit button pressed)

### 7.2 Content
```
Subject: Callout Attendance - {ICAD Number} - {Brigade Name}

ICAD: {icad_number}
Date: {submission_date}
Brigade: {brigade_name}

ATTENDANCE
──────────
{Truck Name}
  OIC: {member_name} ({rank})
  DR:  {member_name} ({rank})
  1:   {member_name} ({rank})
  ...

Station (Standby)
  - {member_name} ({rank})
  - {member_name} ({rank})

{If include_non_attendees enabled:}
NOT IN ATTENDANCE
─────────────────
  - {member_name} ({rank})
  - {member_name} ({rank})

ICAD Report: https://sitrep.fireandemergency.nz/report/{icad_number}

---
Submitted by: {device_identifier}
Submitted at: {timestamp}
```

### 7.3 Email Configuration
- SMTP settings in config file
- Support for common providers (Gmail, SendGrid, etc.)

---

## 8. QR Code System

### 8.1 Generation
- Admin can generate QR code from settings page
- QR encodes URL: `https://{domain}/{brigade-slug}`
- Downloadable as PNG in print-friendly sizes

### 8.2 Usage
- Brigade prints and posts QR code at station
- Members scan with phone camera
- Opens directly to brigade's attendance page
- PIN entry required on first access per session

---

## 9. API Endpoints

### 9.1 Public
```
GET  /{slug}                    # Brigade entry page
POST /{slug}/auth               # PIN authentication
```

### 9.2 Member (PIN Required)
```
GET  /{slug}/api/callout/active      # Get or create active callout
POST /{slug}/api/callout             # Create new callout with ICAD
GET  /{slug}/api/callout/{id}        # Get callout details
PUT  /{slug}/api/callout/{id}        # Update ICAD number
POST /{slug}/api/callout/{id}/submit # Submit and email

GET  /{slug}/api/members             # List all active members
GET  /{slug}/api/trucks              # List trucks with positions

POST /{slug}/api/attendance          # Add attendance
DELETE /{slug}/api/attendance/{id}   # Remove attendance

GET  /{slug}/api/sse/callout/{id}    # SSE stream for real-time updates
```

### 9.3 Admin (Admin Auth Required)
```
POST /{slug}/admin/login             # Admin authentication
POST /{slug}/admin/logout            # End admin session

# Members CRUD
GET    /{slug}/admin/api/members
POST   /{slug}/admin/api/members
PUT    /{slug}/admin/api/members/{id}
DELETE /{slug}/admin/api/members/{id}
POST   /{slug}/admin/api/members/import  # CSV import

# Trucks CRUD
GET    /{slug}/admin/api/trucks
POST   /{slug}/admin/api/trucks
PUT    /{slug}/admin/api/trucks/{id}
DELETE /{slug}/admin/api/trucks/{id}
PUT    /{slug}/admin/api/trucks/reorder

# Positions CRUD
POST   /{slug}/admin/api/trucks/{id}/positions
PUT    /{slug}/admin/api/positions/{id}
DELETE /{slug}/admin/api/positions/{id}

# Callouts
GET    /{slug}/admin/api/callouts
GET    /{slug}/admin/api/callouts/{id}
PUT    /{slug}/admin/api/callouts/{id}/unlock
GET    /{slug}/admin/api/callouts/export

# Settings
GET    /{slug}/admin/api/settings
PUT    /{slug}/admin/api/settings
PUT    /{slug}/admin/api/settings/pin
PUT    /{slug}/admin/api/settings/password
GET    /{slug}/admin/api/qrcode

# Audit
GET    /{slug}/admin/api/audit
```

---

## 10. Backup & Restore

### 10.1 Backup
- SQLite database is single file
- Admin UI option to download database backup
- Recommended: automated daily backup via cron to external storage

### 10.2 Restore
- Admin UI option to upload and restore database
- Confirmation required (destructive action)
- Creates automatic backup before restore

---

## 11. File Structure

```
/dlb
├── public/
│   ├── index.php              # Front controller
│   ├── assets/
│   │   ├── css/
│   │   │   └── app.css
│   │   ├── js/
│   │   │   ├── app.js         # Main application
│   │   │   ├── attendance.js  # Attendance entry logic
│   │   │   ├── sse-client.js  # Real-time sync
│   │   │   └── offline.js     # PWA/offline logic
│   │   └── images/
│   ├── sw.js                  # Service Worker
│   └── manifest.json          # PWA manifest
├── src/
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── AttendanceController.php
│   │   ├── CalloutController.php
│   │   ├── AdminController.php
│   │   └── SSEController.php
│   ├── Models/
│   │   ├── Brigade.php
│   │   ├── Truck.php
│   │   ├── Position.php
│   │   ├── Member.php
│   │   ├── Callout.php
│   │   ├── Attendance.php
│   │   └── AuditLog.php
│   ├── Services/
│   │   ├── Database.php
│   │   ├── EmailService.php
│   │   └── QRCodeService.php
│   ├── Middleware/
│   │   ├── PinAuth.php
│   │   └── AdminAuth.php
│   └── helpers.php
├── templates/
│   ├── layouts/
│   │   ├── app.php
│   │   └── admin.php
│   ├── attendance/
│   │   ├── entry.php
│   │   └── pin.php
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── members.php
│   │   ├── trucks.php
│   │   ├── callouts.php
│   │   ├── settings.php
│   │   └── audit.php
│   └── email/
│       └── attendance.php
├── data/
│   └── database.sqlite        # SQLite database
├── config/
│   └── config.php             # App configuration
├── .htaccess
└── README.md
```

---

## 12. Configuration

```php
// config/config.php
return [
    'app' => [
        'name' => 'Brigade Attendance',
        'url' => 'https://your-domain.com',
        'debug' => false,
    ],
    'database' => [
        'path' => __DIR__ . '/../data/database.sqlite',
    ],
    'session' => [
        'timeout' => 1800,  // 30 minutes for admin
        'pin_timeout' => 86400,  // 24 hours for PIN session
    ],
    'security' => [
        'rate_limit_attempts' => 5,
        'rate_limit_window' => 900,  // 15 minutes
    ],
    'email' => [
        'driver' => 'smtp',  // smtp, sendmail, mail
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_address' => 'attendance@your-domain.com',
        'from_name' => 'Brigade Attendance',
    ],
];
```

---

## 13. Non-Functional Requirements

### 13.1 Performance
- Page load: < 2 seconds on 3G connection
- Attendance update: < 500ms perceived latency
- Support 20+ concurrent users per callout

### 13.2 Browser Support
- iOS Safari 14+
- Chrome Mobile 90+
- Android WebView 90+
- Desktop browsers (secondary priority)

### 13.3 Accessibility
- Touch targets minimum 44x44px
- High contrast mode support
- Screen reader compatible

### 13.4 Data Retention
- Callout data retained indefinitely (configurable)
- Audit logs retained for 2 years
- Soft delete for members (deactivate, not remove)

---

## 14. Future Considerations (Out of Scope)

The following are not included in initial implementation but noted for future:
- SMS notifications
- Multiple brigades under one organisation
- Role-based position restrictions (e.g., qualified drivers only)
- Integration with paging/dispatch systems
- Native mobile apps
- Multi-language support
