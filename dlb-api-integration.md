# DLB API Integration Plan

This document specifies the token-based API additions required for the dlb attendance system to integrate with the Puke Portal app.

**Target Repository:** https://github.com/jtbnz/dlb

---

## Overview

The Portal needs to:
1. Create future musters that remain invisible until training day
2. Pre-populate member attendance status (Leave) for upcoming musters
3. Sync member lists between systems
4. Query attendance history for reporting

---

## Authentication

### API Token System

Add bearer token authentication for API endpoints.

**New Table: `api_tokens`**

```sql
CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,           -- e.g., "Puke Portal Integration"
    permissions TEXT NOT NULL,             -- JSON array of allowed endpoints
    last_used_at DATETIME,
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id)
);
```

**Token Generation (Admin Panel)**

```php
// Generate a secure token
$token = bin2hex(random_bytes(32));  // 64-char hex string
$tokenHash = password_hash($token, PASSWORD_DEFAULT);

// Store hash, return plain token to admin once
// Token format: dlb_{brigade_slug}_{random}
// Example: dlb_puke_a1b2c3d4e5f6...
```

**Request Authentication**

```
Authorization: Bearer dlb_puke_a1b2c3d4e5f6...
```

### Middleware

Add `src/Middleware/ApiAuth.php`:

```php
<?php
declare(strict_types=1);

class ApiAuth {
    public static function verify(string $token, string $requiredPermission): ?array {
        // Extract brigade slug from token prefix
        // Verify token hash against database
        // Check permission in JSON array
        // Update last_used_at
        // Return brigade data or null
    }
}
```

---

## New API Endpoints

All endpoints require `Authorization: Bearer {token}` header.

### 1. Muster Management

#### Create Future Muster

```
POST /{slug}/api/v1/musters
```

**Request:**
```json
{
    "icad_number": "muster",
    "call_date": "2025-01-20",
    "call_time": "19:00",
    "location": "Station",
    "call_type": "Training",
    "visible": false
}
```

**Response:**
```json
{
    "success": true,
    "muster": {
        "id": 123,
        "icad_number": "muster_20250120",
        "status": "active",
        "visible": false,
        "created_at": "2025-01-01T10:00:00+13:00"
    }
}
```

**Permission required:** `musters:create`

---

#### Update Muster Visibility

```
PUT /{slug}/api/v1/musters/{id}/visibility
```

**Request:**
```json
{
    "visible": true
}
```

**Response:**
```json
{
    "success": true,
    "muster": {
        "id": 123,
        "visible": true,
        "updated_at": "2025-01-20T00:00:00+13:00"
    }
}
```

**Permission required:** `musters:update`

---

#### List Future Musters

```
GET /{slug}/api/v1/musters?status=active&from=2025-01-01&to=2025-12-31
```

**Response:**
```json
{
    "success": true,
    "musters": [
        {
            "id": 123,
            "icad_number": "muster_20250120",
            "call_date": "2025-01-20",
            "call_time": "19:00",
            "status": "active",
            "visible": false,
            "attendance_count": 0
        }
    ]
}
```

**Permission required:** `musters:read`

---

### 2. Pre-populate Attendance

#### Set Member Status for Muster

```
POST /{slug}/api/v1/musters/{muster_id}/attendance
```

**Request:**
```json
{
    "member_id": 45,
    "status": "L",
    "notes": "Approved leave - Portal"
}
```

**Response:**
```json
{
    "success": true,
    "attendance": {
        "id": 789,
        "member_id": 45,
        "member_name": "John Smith",
        "status": "L",
        "notes": "Approved leave - Portal",
        "created_at": "2025-01-15T14:30:00+13:00"
    }
}
```

**Permission required:** `attendance:create`

**Notes:**
- Status codes: `I` (In Attendance), `L` (Leave), `A` (Absent)
- Leave status set via API should be distinguishable from manual entry
- Only works for future/active musters, not submitted ones

---

#### Bulk Set Attendance

```
POST /{slug}/api/v1/musters/{muster_id}/attendance/bulk
```

**Request:**
```json
{
    "attendance": [
        {"member_id": 45, "status": "L", "notes": "Annual leave"},
        {"member_id": 46, "status": "L", "notes": "Sick"}
    ]
}
```

**Response:**
```json
{
    "success": true,
    "created": 2,
    "failed": 0,
    "results": [
        {"member_id": 45, "status": "success"},
        {"member_id": 46, "status": "success"}
    ]
}
```

**Permission required:** `attendance:create`

---

### 3. Member Sync

#### List Members

```
GET /{slug}/api/v1/members
```

**Response:**
```json
{
    "success": true,
    "members": [
        {
            "id": 45,
            "name": "John Smith",
            "rank": "FF",
            "is_active": true,
            "created_at": "2023-06-15T00:00:00+12:00"
        }
    ]
}
```

**Permission required:** `members:read`

---

#### Create Member

```
POST /{slug}/api/v1/members
```

**Request:**
```json
{
    "name": "Jane Doe",
    "rank": "QFF",
    "is_active": true
}
```

**Permission required:** `members:create`

---

### 4. Attendance History

#### Get Muster Attendance

```
GET /{slug}/api/v1/musters/{id}/attendance
```

**Response:**
```json
{
    "success": true,
    "muster": {
        "id": 100,
        "icad_number": "muster_20250113",
        "call_date": "2025-01-13",
        "status": "submitted"
    },
    "attendance": [
        {
            "member_id": 45,
            "member_name": "John Smith",
            "status": "I",
            "truck": "Pump 1",
            "position": "Driver"
        },
        {
            "member_id": 46,
            "member_name": "Jane Doe",
            "status": "L",
            "notes": "Approved leave"
        }
    ],
    "summary": {
        "total_members": 25,
        "in_attendance": 18,
        "on_leave": 3,
        "absent": 4
    }
}
```

**Permission required:** `attendance:read`

---

## Database Changes

### New Column: `callouts.visible`

```sql
ALTER TABLE callouts ADD COLUMN visible BOOLEAN DEFAULT 1;
```

- `visible = 1`: Normal behavior, muster appears in attendance UI
- `visible = 0`: Hidden from attendance UI, only accessible via admin/API

### New Column: `attendance.source`

```sql
ALTER TABLE attendance ADD COLUMN source VARCHAR(20) DEFAULT 'manual';
```

- `manual`: Entered via dlb attendance UI
- `api`: Pre-populated via Portal API
- Allows distinguishing between pre-set leave and actual attendance

### New Column: `attendance.status`

```sql
ALTER TABLE attendance ADD COLUMN status CHAR(1) DEFAULT 'I';
```

- `I`: In Attendance (has truck/position assignment)
- `L`: Leave (no truck/position, marked as on leave)
- `A`: Absent (no truck/position, did not attend, no leave)

---

## Implementation Files

### New Files

```
src/
├── Controllers/
│   └── ApiController.php       # Handles all /api/v1/* routes
├── Middleware/
│   └── ApiAuth.php             # Token verification
└── Services/
    └── ApiService.php          # Business logic for API operations
```

### Modified Files

```
public/index.php                # Add routes for /api/v1/*
src/Models/Callout.php          # Add visible column handling
src/Models/Attendance.php       # Add status and source columns
templates/admin/settings.php    # Add API token management UI
```

---

## Route Registration

Add to `public/index.php`:

```php
// API v1 routes (token auth)
$router->addRoute('POST', '/{slug}/api/v1/musters', [ApiController::class, 'createMuster']);
$router->addRoute('GET', '/{slug}/api/v1/musters', [ApiController::class, 'listMusters']);
$router->addRoute('PUT', '/{slug}/api/v1/musters/{id}/visibility', [ApiController::class, 'updateVisibility']);
$router->addRoute('POST', '/{slug}/api/v1/musters/{id}/attendance', [ApiController::class, 'setAttendance']);
$router->addRoute('POST', '/{slug}/api/v1/musters/{id}/attendance/bulk', [ApiController::class, 'bulkSetAttendance']);
$router->addRoute('GET', '/{slug}/api/v1/musters/{id}/attendance', [ApiController::class, 'getAttendance']);
$router->addRoute('GET', '/{slug}/api/v1/members', [ApiController::class, 'listMembers']);
$router->addRoute('POST', '/{slug}/api/v1/members', [ApiController::class, 'createMember']);
```

---

## Admin UI Changes

### API Token Management

Add to brigade admin settings:

1. **Generate Token** button
2. List existing tokens with:
   - Name
   - Permissions (checkboxes)
   - Last used date
   - Expiry date
   - Revoke button
3. Token displayed once on creation (copy to clipboard)

---

## Error Responses

All errors return JSON:

```json
{
    "success": false,
    "error": {
        "code": "INVALID_TOKEN",
        "message": "The provided API token is invalid or expired"
    }
}
```

**Error Codes:**

| Code | HTTP | Description |
|------|------|-------------|
| `INVALID_TOKEN` | 401 | Token missing, invalid, or expired |
| `PERMISSION_DENIED` | 403 | Token lacks required permission |
| `NOT_FOUND` | 404 | Resource not found |
| `VALIDATION_ERROR` | 400 | Invalid request data |
| `MUSTER_SUBMITTED` | 409 | Cannot modify submitted muster |
| `RATE_LIMITED` | 429 | Too many requests |

---

## Rate Limiting

- 100 requests per minute per token
- 1000 requests per hour per token
- Returns `429 Too Many Requests` with `Retry-After` header

---

## Testing

### Manual Testing

```bash
# Create a muster
curl -X POST https://kiaora.tech/dlb/puke/api/v1/musters \
  -H "Authorization: Bearer dlb_puke_abc123..." \
  -H "Content-Type: application/json" \
  -d '{"icad_number":"muster","call_date":"2025-02-03","visible":false}'

# Set leave
curl -X POST https://kiaora.tech/dlb/puke/api/v1/musters/123/attendance \
  -H "Authorization: Bearer dlb_puke_abc123..." \
  -H "Content-Type: application/json" \
  -d '{"member_id":45,"status":"L","notes":"Portal approved leave"}'

# Make visible
curl -X PUT https://kiaora.tech/dlb/puke/api/v1/musters/123/visibility \
  -H "Authorization: Bearer dlb_puke_abc123..." \
  -H "Content-Type: application/json" \
  -d '{"visible":true}'
```

---

## Migration Steps

1. Run database migrations to add new columns
2. Deploy new API controller and middleware
3. Add API token management to admin UI
4. Generate token for Portal integration
5. Configure Portal with token
6. Test integration with a future muster

---

## Security Considerations

- Tokens stored as bcrypt hashes (never plain text)
- Tokens scoped to specific brigade
- Granular permissions per token
- All API calls logged to audit table
- Rate limiting prevents abuse
- HTTPS required for all API calls
