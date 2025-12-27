# Fire Brigade Callout Attendance System

A web-based attendance tracking system designed for volunteer fire brigades to record crew attendance during callouts. Built with PHP 8+ and SQLite for easy deployment with minimal dependencies.

## Features

### Attendance Entry (PIN-Protected)
- **Real-time Callout Management**: Start new callouts with ICAD numbers
- **Truck & Position Assignment**: Assign members to specific positions on trucks (OIC, Driver, Crew 1-4)
- **Station Standby**: Track members remaining at the station
- **Live Synchronisation**: Multiple devices can update attendance simultaneously via Server-Sent Events (SSE)
- **Mobile-Optimised**: Responsive design works on phones, tablets, and desktop
- **PWA Support**: Install as an app on mobile devices for quick access

### Admin Dashboard
- **Member Management**: Add, edit, import (CSV), and deactivate members with rank and join date
- **Truck Configuration**: Configure trucks with customizable positions and drag-to-reorder
- **Callout History**: View, search, filter, and export callout records (CSV/HTML)
- **Settings**: Configure brigade name, email recipients, member sort order, PIN, and admin password
- **QR Code**: Generate a QR code for easy station access
- **Backup & Restore**: Download and restore SQLite database backups
- **Audit Log**: Track all system actions with timestamps and IP addresses

### Member Ordering Options
Members can be sorted by:
- **Rank then Name** (default): CFO → DCFO → SSO → SO → SFF → QFF → FF → RCFF, then alphabetically
- **Rank then Join Date**: By rank, then seniority based on join date
- **Alphabetical**: Simple A-Z ordering

### Security
- PIN protection for attendance entry (4-6 digits)
- Separate admin authentication with username/password
- Rate limiting on login attempts
- Session timeout (30 min admin, 24 hours PIN)
- CSRF protection
- Audit logging

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
│   └── database.sqlite     # SQLite database (auto-created)
├── public/
│   ├── .htaccess           # Apache rewrite rules
│   ├── index.php           # Application entry point
│   └── assets/
│       ├── css/app.css     # Stylesheet
│       └── js/
│           ├── admin.js    # Admin utilities
│           └── attendance.js # Attendance app
├── src/
│   ├── Controllers/        # Route handlers
│   ├── Middleware/         # Auth middleware
│   ├── Models/             # Database models
│   ├── Services/           # Database service
│   └── helpers.php         # Helper functions
└── templates/              # PHP view templates
```

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

## Usage

### Starting a Callout

1. Enter PIN at brigade page
2. Enter ICAD number (e.g., F4363832)
3. Click "Start Callout"

### Recording Attendance

1. Select a member from the "Available Members" list
2. Click a position on a truck to assign them
3. Click an assigned position to remove the member
4. Use "Station" for standby personnel

### Submitting Attendance

1. Click "Submit" when all crew are recorded
2. Confirm submission
3. Email notifications are sent (if configured)
4. Callout becomes read-only

### Admin Tasks

Access admin at `/{brigade-slug}/admin`:
- **Dashboard**: Overview and quick actions
- **Members**: Manage brigade roster
- **Trucks**: Configure vehicles and positions
- **Callouts**: View history and unlock if needed
- **Settings**: Configure brigade options
- **Audit Log**: Review system activity

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

## License

MIT License - Feel free to use and modify for your brigade.

## Support

For issues or feature requests, please open an issue on GitHub.
